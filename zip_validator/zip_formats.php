<?php

/**
 * Format-specific content checks for uploaded ZIP entries.
 *
 * The ZIP validator calls this class to enforce the single-frame GIF rule
 * and inspect SVG, MP3, MIDI, WAV, MusicXML (MXL), and TTF/OTF fonts.
 * Checks inspect file structure and return an error message or null on success.
 */
final class SecureZipFormats
{
    /** Check the MIME type and format structure, returning an error message or null. */
    public static function check(string $ext, string $data, string|false $mime, int &$nestedBudget = 104857600, bool $strictSvg = false): ?string
    {
        $mimes = [
            'svg' => ['image/svg+xml', 'application/xml', 'text/xml', 'text/plain'],
            'mp3' => ['audio/mpeg', 'audio/mp3', 'audio/x-mp3', 'application/octet-stream'],
            'midi' => ['audio/midi', 'audio/x-midi', 'application/x-midi', 'application/octet-stream'],
            'wav' => ['audio/wav', 'audio/x-wav', 'audio/vnd.wave', 'application/octet-stream'],
            'mxl' => ['application/zip', 'application/vnd.recordare.musicxml', 'application/octet-stream'],
            'ttf' => ['font/ttf', 'font/sfnt', 'application/x-font-ttf', 'application/x-font-truetype', 'application/font-sfnt', 'application/octet-stream'],
            'otf' => ['font/otf', 'font/sfnt', 'application/vnd.ms-opentype', 'application/x-font-opentype', 'application/font-sfnt', 'application/octet-stream'],
        ];
        if (!in_array($mime, $mimes[$ext], true)) {
            return 'Detected MIME type does not match .' . $ext . '.';
        }
        return match ($ext) {
            'svg' => self::svg($data, $strictSvg),
            'mp3' => self::mp3($data),
            'midi' => self::midi($data),
            'wav' => self::wav($data),
            'mxl' => self::mxl($data, $nestedBudget),
            'ttf', 'otf' => self::font($data, $ext),
        };
    }

    /** Parse UTF-8 XML with restricted parser options and return null if it fails validation. */
    private static function xml(string $data, bool $musicXmlScore = false): ?DOMDocument
    {
        if (!class_exists('DOMDocument') || $data === '' || !preg_match('//u', $data)
            || strpos($data, "\0") !== false || preg_match('/<!\s*(?:DOCTYPE|ENTITY)/i', $data)) {
            return null;
        }
        $old = libxml_use_internal_errors(true);
        try {
            $doc = new DOMDocument();
            $doc->resolveExternals = false;
            $doc->substituteEntities = false;
            if (!$doc->loadXML($data, LIBXML_NONET | LIBXML_COMPACT) || $doc->doctype !== null) {
                return null;
            }
            // Reject non-UTF-8 declarations even when all current bytes are ASCII.
            if ($doc->encoding && !in_array(strtoupper($doc->encoding), ['UTF-8', 'UTF8', 'US-ASCII'], true)) {
                return null;
            }
            $nodes = [$doc];
            $count = 0;
            while ($nodes) {
                $node = array_pop($nodes);
                if (++$count > 100000 || $node->nodeType === XML_ENTITY_REF_NODE) {
                    return null;
                }
                if ($node instanceof DOMProcessingInstruction
                    && (!$musicXmlScore || $node->target !== 'SmartMusic' || $node->data !== 'maxVoice=1')) {
                    return null;
                }
                if ($node instanceof DOMElement && $node->namespaceURI === 'http://www.w3.org/2001/XInclude') {
                    return null;
                }
                foreach ($node->childNodes as $child) {
                    $nodes[] = $child;
                }
            }
            return $doc;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($old);
        }
    }

    /** Check SVG markup against the allowlist of passive elements and attributes. */
    private static function svg(string $data, bool $strictSvg): ?string
    {
        $doc = self::xml($data);
        if ($strictSvg && (!$doc || $doc->documentElement->localName !== 'svg'
            || $doc->documentElement->namespaceURI !== 'http://www.w3.org/2000/svg')) {
            return 'Invalid SVG: requires UTF-8 SVG XML without DTDs, entities, or processing instructions.';
        }
        if (!$doc) {
            return null;
        }
        // Deliberately passive SVG subset. No stylesheets, scripting, animation,
        // foreignObject, image, use, or externally loadable resources.
        $elements = explode(' ', 'svg g defs title desc metadata path rect circle ellipse line polyline polygon text tspan textPath linearGradient radialGradient stop clipPath mask pattern marker symbol');
        $attributes = explode(' ', 'id version width height viewBox preserveAspectRatio x y x1 y1 x2 y2 dx dy cx cy r rx ry d points transform fill fill-opacity fill-rule stroke stroke-width stroke-opacity stroke-linecap stroke-linejoin stroke-miterlimit stroke-dasharray stroke-dashoffset opacity color clip-path clip-rule mask marker-start marker-mid marker-end gradientUnits gradientTransform spreadMethod offset stop-color stop-opacity fx fy fr patternUnits patternContentUnits patternTransform markerWidth markerHeight markerUnits refX refY orient font-family font-size font-weight font-style text-anchor dominant-baseline alignment-baseline letter-spacing word-spacing textLength lengthAdjust startOffset method spacing role aria-label');
        foreach ($doc->getElementsByTagName('*') as $element) {
            if ($strictSvg && ($element->namespaceURI !== 'http://www.w3.org/2000/svg'
                || !in_array($element->localName, $elements, true))) {
                return 'SVG contains an unsupported or active element: ' . $element->tagName . '.';
            }
            foreach ($element->attributes as $attr) {
                if ($attr->namespaceURI === 'http://www.w3.org/2000/xmlns/') {
                    continue;
                }
                if ($attr->namespaceURI === 'http://www.w3.org/XML/1998/namespace'
                    && in_array($attr->localName, ['lang', 'space'], true)) {
                    continue;
                }
                if (!$attr->namespaceURI && $attr->name === 'style') {
                    if (!self::svgInlineStyleIsSafe($attr->value)) {
                        return 'SVG contains an unsupported or unsafe inline style.';
                    }
                    continue;
                }
                if ($strictSvg && ($attr->namespaceURI || !in_array($attr->name, $attributes, true))) {
                    return 'SVG contains unsupported attribute "' . $attr->nodeName . '".';
                }
                $value = $attr->value;
                // Only literal local fragment references inside paint/clip values.
                $value = preg_replace('/url\(#[A-Za-z_][A-Za-z0-9_.-]*\)/', '', $value);
                if (preg_match('/[\\\\\x00-\x1f]|url\s*\(|expression\s*\(|@import|(?:javascript|data|https?|file|behavior|-moz-binding)\s*:|[&<>]/i', $value)) {
                    return 'SVG contains an external or unsafe attribute value.';
                }
            }
        }
        return null;
    }

    /** Accept only passive properties and a restricted CSS value syntax. */
    private static function svgInlineStyleIsSafe(string $style): bool
    {
        $properties = explode(' ', 'fill fill-opacity fill-rule stroke stroke-width stroke-opacity stroke-linecap stroke-linejoin stroke-miterlimit stroke-dasharray stroke-dashoffset opacity color clip-path clip-rule mask marker-start marker-mid marker-end stop-color stop-opacity font-family font-size font-weight font-style text-anchor dominant-baseline alignment-baseline letter-spacing word-spacing vertical-align margin-top margin-right margin-bottom margin-left margin');
        $referenceProperties = ['fill', 'stroke', 'clip-path', 'mask', 'marker-start', 'marker-mid', 'marker-end'];
        foreach (explode(';', $style) as $declaration) {
            if (trim($declaration, " \t\r\n") === '') {
                continue;
            }
            $parts = explode(':', $declaration, 2);
            $property = strtolower(trim($parts[0], " \t\r\n"));
            if (count($parts) !== 2 || !in_array($property, $properties, true)) {
                return false;
            }
            $value = $parts[1];
            if (in_array($property, $referenceProperties, true)) {
                $value = preg_replace('/\burl\(#[A-Za-z_][A-Za-z0-9_.-]*\)/i', 'local', $value);
            }
            $value = preg_replace('/\b(?:rgba?|hsla?)\([0-9.,%+ \t\r\n-]+\)/i', 'color', $value);
            if (!preg_match('/\A[ \t\r\n]*[A-Za-z0-9#.,%+-][A-Za-z0-9#.,%+ \t\r\n-]*\z/', $value)) {
                return false;
            }
        }
        return true;
    }

    /** Read an unsigned 16-bit big-endian integer at the given byte offset. */
    private static function u16(string $data, int $offset): int
    {
        return unpack('n', substr($data, $offset, 2))[1];
    }

    /** Read an unsigned 32-bit big-endian integer at the given byte offset. */
    private static function u32(string $data, int $offset): int
    {
        return unpack('N', substr($data, $offset, 4))[1];
    }

    /** Check RIFF/WAVE chunk structure and PCM or IEEE float encoding fields. */
    private static function wav(string $data): ?string
    {
        $n = strlen($data);
        if ($n < 12 || substr($data, 0, 4) !== 'RIFF' || substr($data, 8, 4) !== 'WAVE'
            || unpack('V', substr($data, 4, 4))[1] !== $n - 8) {
            return 'Invalid RIFF/WAVE header or length.';
        }
        $fmt = $audio = false;
        $block = 0;
        for ($p = 12; $p < $n;) {
            if ($p + 8 > $n) {
                return 'Truncated WAVE chunk.';
            }
            $tag = substr($data, $p, 4);
            $size = unpack('V', substr($data, $p + 4, 4))[1];
            $p += 8;
            if ($size > $n - $p || $p + $size + ($size & 1) > $n) {
                return 'Invalid WAVE chunk length.';
            }
            if ($tag === 'fmt ') {
                if ($fmt || $size < 16) {
                    return 'Invalid WAVE format chunk.';
                }
                $f = unpack('vformat/vchannels/Vrate/Vbytes/vblock/vbits', substr($data, $p, 16));
                if (!in_array($f['format'], [1, 3], true) || $f['channels'] < 1 || $f['channels'] > 32
                    || $f['rate'] < 1 || $f['rate'] > 768000 || !in_array($f['bits'], [8, 16, 24, 32, 64], true)
                    || ($f['format'] === 3 && !in_array($f['bits'], [32, 64], true))
                    || $f['block'] !== $f['channels'] * ($f['bits'] / 8)
                    || $f['bytes'] !== $f['rate'] * $f['block']) {
                    return 'Unsupported or inconsistent WAVE encoding (PCM/IEEE float only).';
                }
                $block = $f['block'];
                $fmt = true;
            } elseif ($tag === 'data') {
                if (!$fmt || $audio || $size === 0 || $size % $block !== 0) {
                    return 'Invalid WAVE audio data.';
                }
                $audio = true;
            }
            $p += $size + ($size & 1);
        }
        return $fmt && $audio ? null : 'WAVE requires format and audio chunks.';
    }

    /** Check the optional ID3 tag and walk the MPEG Layer III frame headers. */
    private static function mp3(string $data): ?string
    {
        $n = strlen($data);
        $p = 0;
        if (substr($data, 0, 3) === 'ID3') {
            if ($n < 10 || !in_array(ord($data[3]), [2, 3, 4], true) || ord($data[4]) === 255) {
                return 'Invalid ID3 header.';
            }
            $size = 0;
            for ($i = 6; $i < 10; $i++) {
                if (ord($data[$i]) & 128) {
                    return 'Invalid ID3 synchsafe length.';
                }
                $size = ($size << 7) | ord($data[$i]);
            }
            $p = 10 + $size;
            if (ord($data[3]) === 4 && (ord($data[5]) & 16)) {
                if (substr($data, $p, 3) !== '3DI') {
                    return 'Missing ID3 footer.';
                }
                $p += 10;
            }
        }
        if ($n >= 128 && substr($data, $n - 128, 3) === 'TAG') {
            $n -= 128;
        }
        $frames = 0;
        while ($p + 4 <= $n) {
            $h = self::u32($data, $p);
            $version = ($h >> 19) & 3;
            $layer = ($h >> 17) & 3;
            $rateIndex = ($h >> 10) & 3;
            $bitIndex = ($h >> 12) & 15;
            if (($h & 0xffe00000) !== 0xffe00000 || $version === 1 || $layer !== 1
                || $rateIndex === 3 || $bitIndex === 0 || $bitIndex === 15 || ($h & 3) === 2) {
                return 'Invalid MPEG Layer III frame header.';
            }
            $rates = [44100, 48000, 32000];
            $rate = $rates[$rateIndex] / ($version === 3 ? 1 : ($version === 2 ? 2 : 4));
            $bitrates = $version === 3
                ? [0, 32, 40, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320]
                : [0, 8, 16, 24, 32, 40, 48, 56, 64, 80, 96, 112, 128, 144, 160];
            $size = (int) floor(($version === 3 ? 144 : 72) * $bitrates[$bitIndex] * 1000 / $rate) + (($h >> 9) & 1);
            if ($size < 4 || $size > $n - $p) {
                return 'Truncated MP3 frame.';
            }
            $p += $size;
            $frames++;
        }
        return $frames > 0 && $p === $n ? null : 'Missing MP3 frames or unexpected trailing data.';
    }

    /** Read a MIDI variable-length quantity of up to four bytes and advance the offset. */
    private static function vlq(string $data, int &$p, int $end): ?int
    {
        $value = 0;
        for ($i = 0; $i < 4 && $p < $end; $i++) {
            $byte = ord($data[$p++]);
            $value = ($value << 7) | ($byte & 127);
            if (!($byte & 128)) {
                return $value;
            }
        }
        return null;
    }

    /** Check the Standard MIDI header, track boundaries, and event structure. */
    private static function midi(string $data): ?string
    {
        $n = strlen($data);
        if ($n < 14 || substr($data, 0, 4) !== 'MThd' || self::u32($data, 4) !== 6) {
            return 'Invalid Standard MIDI header.';
        }
        $format = self::u16($data, 8);
        $tracks = self::u16($data, 10);
        $division = self::u16($data, 12);
        if ($format > 2 || $tracks < 1 || $tracks > 1024 || ($format === 0 && $tracks !== 1)
            || $division === 0 || (($division & 0x8000)
                && (!in_array(($division >> 8) & 255, [232, 231, 227, 226], true) || ($division & 255) === 0))) {
            return 'Invalid MIDI format, track count, or time division.';
        }
        $p = 14;
        for ($track = 0; $track < $tracks; $track++) {
            if ($p + 8 > $n || substr($data, $p, 4) !== 'MTrk') {
                return 'Missing MIDI track.';
            }
            $size = self::u32($data, $p + 4);
            $p += 8;
            if ($size > $n - $p) {
                return 'Truncated MIDI track.';
            }
            $end = $p + $size;
            $running = 0;
            $ended = false;
            while ($p < $end) {
                if (self::vlq($data, $p, $end) === null || $p >= $end) {
                    return 'Invalid MIDI delta time.';
                }
                $status = ord($data[$p]);
                if ($status & 128) {
                    $p++;
                } else {
                    $status = $running;
                }
                if ($status >= 0x80 && $status <= 0xef) {
                    $running = $status;
                    $bytes = in_array($status >> 4, [12, 13], true) ? 1 : 2;
                    if ($p + $bytes > $end) {
                        return 'Truncated MIDI channel event.';
                    }
                    for ($i = 0; $i < $bytes; $i++) {
                        if (ord($data[$p++]) > 127) {
                            return 'Invalid MIDI event data.';
                        }
                    }
                } elseif (in_array($status, [0xff, 0xf0, 0xf7], true)) {
                    $running = 0;
                    $type = $status === 0xff && $p < $end ? ord($data[$p++]) : -1;
                    $length = self::vlq($data, $p, $end);
                    if ($length === null || $length > $end - $p || ($status === 0xff && ($type < 0 || $type > 127))) {
                        return 'Invalid MIDI meta/SysEx event length.';
                    }
                    $p += $length;
                    if ($status === 0xff && $type === 0x2f) {
                        if ($length !== 0 || $p !== $end) {
                            return 'Invalid MIDI end-of-track event.';
                        }
                        $ended = true;
                    }
                } else {
                    return 'Invalid MIDI status or running status.';
                }
            }
            if (!$ended) {
                return 'MIDI track lacks end-of-track event.';
            }
        }
        return $p === $n ? null : 'Unexpected trailing MIDI data.';
    }

    /** Check the font signature, table directory, and required TrueType or OpenType tables. */
    private static function font(string $data, string $ext): ?string
    {
        $n = strlen($data);
        $signature = substr($data, 0, 4);
        if ($n < 12 || ($ext === 'ttf' ? $signature !== "\0\1\0\0" : !in_array($signature, ["\0\1\0\0", 'OTTO'], true))) {
            return 'Font signature does not match extension.';
        }
        $count = self::u16($data, 4);
        if ($count < 1 || $count > 256 || 12 + 16 * $count > $n) {
            return 'Invalid font table directory.';
        }
        $tables = [];
        $ranges = [];
        for ($i = 0; $i < $count; $i++) {
            $p = 12 + 16 * $i;
            $tag = substr($data, $p, 4);
            $offset = self::u32($data, $p + 8);
            $size = self::u32($data, $p + 12);
            if (isset($tables[$tag]) || !preg_match('/^[\x20-\x7e]{4}$/D', $tag)
                || $offset < 12 + 16 * $count || $offset % 4 !== 0 || $offset > $n || $size > $n - $offset) {
                return 'Invalid, duplicate, or out-of-bounds font table.';
            }
            // OpenType SVG tables can contain active XML; excluded by this policy.
            if ($tag === 'SVG ') {
                return 'Fonts containing SVG tables are not permitted.';
            }
            $tables[$tag] = [$offset, $size];
            if ($size) {
                $ranges[] = [$offset, $offset + $size];
            }
        }
        sort($ranges);
        $end = 0;
        foreach ($ranges as [$start, $next]) {
            if ($start < $end) {
                return 'Overlapping font tables.';
            }
            $end = $next;
        }
        foreach (['cmap', 'head', 'hhea', 'hmtx', 'maxp', 'name', 'OS/2', 'post'] as $required) {
            if (!isset($tables[$required]) || $tables[$required][1] === 0) {
                return 'Missing required font table: ' . $required . '.';
            }
        }
        if ($tables['head'][1] < 54 || self::u32($data, $tables['head'][0] + 12) !== 0x5f0f3cf5
            || ($signature === 'OTTO' ? !isset($tables['CFF ']) && !isset($tables['CFF2']) : !isset($tables['glyf']) || !isset($tables['loca']))) {
            return 'Invalid font header or missing outline tables.';
        }
        return null;
    }

    /** Check a compressed MusicXML container and its internal references within the shared expansion budget. */
    private static function mxl(string $data, int &$nestedBudget): ?string
    {
        if (!str_starts_with($data, "PK\x03\x04")) {
            return 'MXL must be a ZIP-based MusicXML container.';
        }
        // ZipArchive requires a filename. Only the bounded container bytes are
        // written to a private temporary file; entries are never extracted.
        $temp = tempnam(sys_get_temp_dir(), 'mxl-');
        if ($temp === false) {
            return 'Cannot create temporary MXL inspection file.';
        }
        $zip = new ZipArchive();
        $opened = false;
        try {
            if (file_put_contents($temp, $data) !== strlen($data)
                || @$zip->open($temp, ZipArchive::CHECKCONS) !== true) {
                return 'Invalid MXL ZIP container.';
            }
            $opened = true;
            if ($zip->numFiles < 2 || $zip->numFiles > 256) {
                return 'MXL must contain between 2 and 256 entries.';
            }
            $seen = $documents = $references = [];
            $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $s = $zip->statIndex($i);
                if (!$s) {
                    return 'Cannot inspect MXL entry.';
                }
                $name = $s['name'];
                $dir = str_ends_with($name, '/');
                $path = $dir ? substr($name, 0, -1) : $name;
                if (strlen($name) > 1024 || !preg_match('~^[A-Za-z0-9_. /-]+$~D', $path)) {
                    return 'Unsafe MXL entry name.';
                }
                foreach (explode('/', $path) as $part) {
                    if ($part === '' || $part === '.' || $part === '..' || preg_match('/[. ]$/', $part)
                        || preg_match('/^(con|prn|aux|nul|com[0-9]|lpt[0-9])(?:\.|$)/i', $part)) {
                        return 'Unsafe MXL path component.';
                    }
                }
                $key = strtolower($path);
                if (isset($seen[$key])) {
                    return 'Duplicate MXL path.';
                }
                $seen[$key] = !$dir;
                $os = $attrs = 0;
                if (!$zip->getExternalAttributesIndex($i, $os, $attrs)) {
                    return 'Cannot inspect MXL attributes.';
                }
                $mode = ($attrs >> 16) & 0170000;
                if (($os === 3 && $mode !== 0 && $mode !== ($dir ? 0040000 : 0100000))
                    || !isset($s['encryption_method']) || $s['encryption_method'] !== 0
                    || !in_array($s['comp_method'], [0, 8], true)) {
                    return 'MXL contains encryption, links, special files, or unsupported compression.';
                }
                $total += $s['size'];
                $nestedBudget -= $s['size'];
                if ($s['size'] > 10 * 1024 * 1024 || $total > 20 * 1024 * 1024
                    || $nestedBudget < 0 || $s['size'] / max(1, $s['comp_size']) > 200) {
                    return 'MXL exceeds entry, expanded size, or compression ratio limits.';
                }
                if ($dir) {
                    if ($s['size'] !== 0) {
                        return 'MXL directory contains data.';
                    }
                    continue;
                }
                // A conservative MusicXML-only package: no PDFs, nested archives,
                // embedded fonts/images, or other arbitrary attachments.
                if ($name !== 'mimetype' && !in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), ['xml', 'musicxml'], true)) {
                    return 'MXL permits only XML/MusicXML entries and its mimetype marker.';
                }
                $stream = @$zip->getStream($name);
                if (!$stream) {
                    return 'Cannot read MXL entry.';
                }
                try {
                    $content = @stream_get_contents($stream, $s['size'] + 1);
                } finally {
                    fclose($stream);
                }
                if ($content === false || strlen($content) !== $s['size'] || hash('crc32b', $content) !== sprintf('%08x', $s['crc'])) {
                    return 'MXL entry integrity failure.';
                }
                if ($name === 'mimetype') {
                    if ($i !== 0 || $s['comp_method'] !== ZipArchive::CM_STORE || $content !== 'application/vnd.recordare.musicxml') {
                        return 'Invalid MXL mimetype marker.';
                    }
                    continue;
                }
                $doctype = [];
                $xml = $content;
                if ($name !== 'META-INF/container.xml'
                    && preg_match(
                        '~\A(?:\xEF\xBB\xBF)?(?:<\?xml\s[^?]*\?>)?\s*\K<!DOCTYPE\s+score-(partwise|timewise)\s+PUBLIC\s+"-//Recordare//DTD MusicXML [1-4]\.[01] (Partwise|Timewise)//EN"\s+"https?://www\.musicxml\.org/dtds/\1\.dtd"\s*>~',
                        $content,
                        $doctype,
                        PREG_OFFSET_CAPTURE
                    )
                    && $doctype[1][0] === strtolower($doctype[2][0])) {
                    // Discard the standard declaration before parsing so libxml never sees the DTD.
                    [$declaration, $offset] = $doctype[0];
                    $xml = substr_replace($content, '', $offset, strlen($declaration));
                }
                $doc = self::xml($xml, $name !== 'META-INF/container.xml');
                if (!$doc || preg_match('/<\?(?:php|=)|<%/i', $content)) {
                    return 'MXL contains invalid XML, DTDs, entities, or active content.';
                }
                $root = $doc->documentElement->localName;
                if ($xml !== $content && $root !== 'score-' . $doctype[1][0]) {
                    return 'MusicXML declaration does not match the score root.';
                }
                if ($name !== 'META-INF/container.xml' && !in_array($root, ['score-partwise', 'score-timewise', 'opus'], true)) {
                    return 'MXL entry is not a MusicXML score or opus.';
                }
                foreach ($doc->getElementsByTagName('*') as $element) {
                    if ($element->namespaceURI || in_array(strtolower($element->localName), ['script', 'iframe', 'object', 'embed'], true)) {
                        return 'MXL contains active elements.';
                    }
                    foreach ($element->attributes as $attr) {
                        if (str_starts_with(strtolower($attr->localName), 'on')) {
                            return 'MXL contains event attributes.';
                        }
                        if (($attr->namespaceURI === 'http://www.w3.org/XML/1998/namespace' && $attr->localName === 'base')) {
                            return 'MXL xml:base is not permitted.';
                        }
                        if (in_array(strtolower($attr->localName), ['href', 'src', 'source'], true)) {
                            if (!preg_match('~^(?:[A-Za-z0-9_./ -]+)?(?:#[A-Za-z0-9_.-]+)?$~D', $attr->value) || $attr->value === '') {
                                return 'MXL contains external or unsafe resource references.';
                            }
                            $target = explode('#', $attr->value, 2)[0];
                            if ($target !== '') {
                                foreach (explode('/', $target) as $part) {
                                    if ($part === '' || $part === '.' || $part === '..') {
                                        return 'MXL resource reference contains unsafe path components.';
                                    }
                                }
                                $references[] = (dirname($name) === '.' ? '' : dirname($name) . '/') . $target;
                            }
                        }
                    }
                }
                $documents[$name] = $doc;
            }
            foreach ($seen as $path => $isFile) {
                for ($parent = dirname($path); $parent !== '.'; $parent = dirname($parent)) {
                    if (!empty($seen[$parent])) {
                        return 'MXL parent path is also a file.';
                    }
                }
            }
            foreach ($references as $reference) {
                if (!isset($documents[$reference])) {
                    return 'MXL resource reference must target an included XML document.';
                }
            }
            $container = $documents['META-INF/container.xml'] ?? null;
            if (!$container || $container->documentElement->localName !== 'container') {
                return 'MXL lacks META-INF/container.xml.';
            }
            $roots = $container->getElementsByTagName('rootfile');
            if ($roots->length === 0) {
                return 'MXL container has no rootfile.';
            }
            foreach ($roots as $root) {
                $path = $root->getAttribute('full-path');
                if (!isset($documents[$path]) || $path === 'META-INF/container.xml') {
                    return 'MXL rootfile does not reference an included MusicXML document.';
                }
            }
            return null;
        } finally {
            if ($opened) {
                $zip->close();
            }
            unlink($temp);
        }
    }

    private static function gifColorTableSize(int $flags): int
    {
        return ($flags & 0x80) !== 0 ? 3 * (2 << ($flags & 7)) : 0;
    }

    /** Count image descriptors by walking GIF blocks without decoding pixels. */
    public static function checkGif(string $data): ?string
    {
        $length = strlen($data);
        if ($length < 13 || !in_array(substr($data, 0, 6), ['GIF87a', 'GIF89a'], true)) {
            return 'Invalid GIF header.';
        }

        $offset = 13 + self::gifColorTableSize(ord($data[10]));
        $frames = 0;
        while ($offset < $length) {
            $block = ord($data[$offset++]);
            if ($block === 0x3b) {
                if ($offset !== $length) {
                    return 'GIF contains trailing data.';
                }
                return $frames === 1 ? null : 'GIF must contain exactly one frame.';
            }
            if ($block === 0x2c && $offset + 9 <= $length) {
                if (++$frames > 1) {
                    return 'GIF must contain exactly one frame.';
                }
                // Skip the image descriptor, local color table, and LZW code size.
                $offset += 9 + self::gifColorTableSize(ord($data[$offset + 8])) + 1;
            } elseif ($block === 0x21 && $offset < $length) {
                ++$offset; // Extension label.
            } else {
                return 'Invalid or truncated GIF block.';
            }

            // Image data and extensions are sequences of length-prefixed sub-blocks.
            do {
                if ($offset >= $length) {
                    return 'Truncated GIF sub-block.';
                }
                $size = ord($data[$offset++]);
                $offset += $size;
            } while ($size !== 0);
        }
        return 'Missing GIF trailer.';
    }
}
