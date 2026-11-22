<?php

require_once __DIR__ . '/zip_formats.php';

// Maximum size of the ZIP file and, separately, of all entries combined when expanded.
const ZIP_ARCHIVE_LIMIT_MIB = 300;

// Maximum uncompressed size of a single ZIP entry.
const ZIP_ENTRY_LIMIT_MIB = 50;

// Maximum number of entries in the ZIP, including directory entries.
const ZIP_ENTRY_COUNT_LIMIT = 10000;

// Maximum ratio of uncompressed to compressed bytes for one entry.
const ZIP_ENTRY_COMPRESSION_RATIO_LIMIT = 200;

// Maximum size of the local allowlist and of a single rule.
const ZIP_RULE_FILE_LIMIT_KIB = 64;
const ZIP_RULE_LENGTH_LIMIT_BYTES = 1024;

// Maximum length of a ZIP entry name, including any directory path.
const ZIP_ENTRY_NAME_LIMIT_BYTES = 1024;

// Shared expanded inspection budget for nested MusicXML archives in one ZIP.
const ZIP_MXL_BUDGET_BYTES = 100 * 1024 * 1024;

// Maximum nesting depth passed to the JSON parser.
const ZIP_JSON_DEPTH_LIMIT = 64;

// Maximum file size of a raster image before ZIP compression.
const ZIP_IMAGE_LIMIT_MIB = 5;

/**
 * Inspect an untrusted ZIP without extracting or executing its contents (PHP 8.1+).
 *
 * Each regular file must match a pattern in the local accepted-types file
 * and pass its format-specific content checks unless covered by an archive-dir rule.
 * SVG uses a passive allowlist; MXL containers receive bounded inspection.
 * Text must be UTF-8. MIME/signature checks are identification,
 * not malware detection. Never serve accepted uploads as executable content;
 * keep them outside the web root and use attachment disposition and nosniff.
 * For stronger assurance, scan/quarantine with an isolated malware service.
 * Do not modify either input while validation runs; process the same ZIP bytes.
 *
 * @return array{success: bool, errors: array, disallowed_files: array}
 * Errors contain untrusted filenames: HTML-escape them before displaying.
 */
function validate_secure_zip(string $zipPath, string $acceptedTypesFile, bool $strictSvg = false): array
{
    $errors = [];
    $disallowed = [];
    // Build the result from the errors and disallowed filenames collected so far.
    $result = static function () use (&$errors, &$disallowed): array {
        return ['success' => !$errors, 'errors' => $errors, 'disallowed_files' => $disallowed];
    };
    if (!class_exists('ZipArchive') || !class_exists('finfo')) {
        $errors[] = 'PHP zip and fileinfo extensions are required.';
        return $result();
    }
    foreach ([$zipPath, $acceptedTypesFile] as $path) {
        if (strpos($path, '://') !== false || !is_file($path) || !is_readable($path)) {
            $errors[] = 'Inputs must be readable local files.';
            return $result();
        }
    }
    $ruleFileLimit = ZIP_RULE_FILE_LIMIT_KIB * 1024;
    $rulesText = @file_get_contents($acceptedTypesFile, false, null, 0, $ruleFileLimit + 1);
    if ($rulesText === false || strlen($rulesText) > $ruleFileLimit) {
        $errors[] = 'Cannot read accepted-types file, or it exceeds ' . ZIP_RULE_FILE_LIMIT_KIB . ' KiB.';
        return $result();
    }
    try {
        $rules = SecureZipRules::parse($rulesText);
    } catch (InvalidArgumentException $e) {
        $errors[] = $e->getMessage();
        return $result();
    }
    $archiveLimit = ZIP_ARCHIVE_LIMIT_MIB * 1024 * 1024;
    $entryLimit = ZIP_ENTRY_LIMIT_MIB * 1024 * 1024;
    if (filesize($zipPath) > $archiveLimit) {
        $errors[] = 'ZIP exceeds the ' . ZIP_ARCHIVE_LIMIT_MIB . ' MiB archive limit.';
        return $result();
    }
    $zip = new ZipArchive();
    if (@$zip->open($zipPath, ZipArchive::CHECKCONS) !== true) {
        $errors[] = 'Cannot open ZIP or ZIP is inconsistent.';
        return $result();
    }
    try {
        if ($zip->numFiles > ZIP_ENTRY_COUNT_LIMIT) {
            $errors[] = 'ZIP exceeds the ' . number_format(ZIP_ENTRY_COUNT_LIMIT) . ' entry limit.';
            return $result();
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $seen = [];
        $files = [];
        $total = 0;
        $nestedBudget = ZIP_MXL_BUDGET_BYTES;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                $errors[] = 'Cannot inspect ZIP entry.';
                continue;
            }
            $name = $stat['name'];
            $label = json_encode($name, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES);
            $dir = substr($name, -1) === '/';
            $path = $dir ? substr($name, 0, -1) : $name;
            // Reject aliases, Windows special paths/streams, and traversal on either OS.
            if (strlen($name) > ZIP_ENTRY_NAME_LIMIT_BYTES || !preg_match('//u', $name)
                || preg_match('/[\x00-\x1f\x7f\\\\:]/', $name) || $path === '') {
                $errors[] = "$label: unsafe entry name.";
                continue;
            }
            $unsafe = false;
            $parts = explode('/', $path);
            foreach ($parts as $part) {
                if ($part === '' || $part === '.' || $part === '..'
                    || preg_match('/[. ]$/', $part)
                    || preg_match('/^(con|prn|aux|nul|com[0-9]|lpt[0-9])(?:\.|$)/i', $part)) {
                    $unsafe = true;
                }
            }
            if ($unsafe) {
                $errors[] = "$label: unsafe path component.";
                continue;
            }
            // ASCII-only names avoid filesystem Unicode/case normalization ambiguity.
            if (preg_match('/[^\x20-\x7e]/', $path)) {
                $errors[] = "$label: only ASCII entry names are supported.";
                continue;
            }
            $key = strtolower($path);
            if (isset($seen[$key])) {
                $errors[] = "$label: duplicate or case-colliding path.";
                continue;
            }
            $seen[$key] = true;
            $files[$key] = !$dir;
            $archiveOnly = SecureZipRules::isArchiveOnly($parts, $dir, $rules['archiveDirectories']);
            $policyError = $archiveOnly ? null : SecureZipRules::rejection($path, $parts, $dir, $rules);
            if ($policyError !== null) {
                $disallowed[] = $name;
                $errors[] = "$label: $policyError";
            }
            $opsys = $attrs = 0;
            if (!$zip->getExternalAttributesIndex($i, $opsys, $attrs)) {
                $errors[] = "$label: cannot inspect entry attributes.";
                continue;
            }
            $mode = ($attrs >> 16) & 0170000;
            if ($opsys === 3 && $mode !== 0 && $mode !== ($dir ? 0040000 : 0100000)) {
                $errors[] = "$label: links and special files are forbidden.";
                continue;
            }
            if (!isset($stat['encryption_method']) || $stat['encryption_method'] !== 0) {
                $errors[] = "$label: encrypted or uninspectable entry.";
                continue;
            }
            if (!in_array($stat['comp_method'], [0, 8], true)) {
                $errors[] = "$label: unsupported compression method.";
                continue;
            }
            $total += $stat['size'];
            if ($total > $archiveLimit) {
                $errors[] = 'ZIP exceeds the ' . ZIP_ARCHIVE_LIMIT_MIB . ' MiB expanded size limit.';
                break;
            }
            if ($stat['size'] > $entryLimit
                || $stat['size'] / max(1, $stat['comp_size']) > ZIP_ENTRY_COMPRESSION_RATIO_LIMIT) {
                $errors[] = "$label: exceeds " . ZIP_ENTRY_LIMIT_MIB . ' MiB entry or '
                    . ZIP_ENTRY_COMPRESSION_RATIO_LIMIT . ':1 compression ratio limit.';
                continue;
            }
            if ($dir) {
                if ($stat['size'] !== 0) {
                    $errors[] = "$label: directory contains data.";
                }
                continue;
            }
            if ($policyError !== null) {
                continue;
            }
            // Names are unique before reading by name, for PHP 8.1 compatibility.
            $stream = @$zip->getStream($name);
            if ($stream === false) {
                $errors[] = "$label: cannot read entry.";
                continue;
            }
            try {
                $data = @stream_get_contents($stream, min($stat['size'], $entryLimit) + 1);
            } finally {
                fclose($stream);
            }
            if ($data === false || strlen($data) !== $stat['size']
                || strtolower(hash('crc32b', $data)) !== sprintf('%08x', $stat['crc'])) {
                $errors[] = "$label: entry size or CRC integrity check failed.";
                continue;
            }
            if ($archiveOnly) {
                continue;
            }
            $reason = secure_zip_content_error($name, $data, $finfo, $nestedBudget, $strictSvg);
            if ($reason !== null) {
                $disallowed[] = $name;
                $errors[] = "$label: $reason";
            }
        }
        foreach ($files as $path => $isFile) {
            $parent = dirname($path);
            while ($parent !== '.') {
                if (isset($files[$parent]) && $files[$parent]) {
                    $errors[] = json_encode($path) . ': parent path is also a file.';
                    break;
                }
                $parent = dirname($parent);
            }
        }
    } catch (RuntimeException $e) {
        $errors[] = $e->getMessage();
    } finally {
        $zip->close();
    }
    return $result();
}

/** Return a content validation error for the named ZIP entry, or null if it passes. */
function secure_zip_content_error(string $name, string $data, finfo $finfo, int &$nestedBudget = ZIP_MXL_BUDGET_BYTES, bool $strictSvg = false): ?string
{
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $mime = $finfo->buffer($data);
    // Binary PNG, JPEG, GIF, font, audio, and compressed data can contain script-like byte pairs
    // accidentally. Their format-specific checks handle identification instead.
    if (preg_match('/^(?:\x7fELF|MZ)/', $data)
        || (!in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'mp3', 'midi', 'wav', 'ttf', 'otf', 'mxl'], true)
            && preg_match('/<\?(?:php|=)|<%/i', $data))) {
        return 'Executable content detected.';
    }
    if (in_array($ext, ['svg', 'mp3', 'mxl', 'midi', 'wav', 'ttf', 'otf'], true)) {
        return SecureZipFormats::check($ext, $data, $mime, $nestedBudget, $strictSvg);
    }
    $html = in_array($ext, ['htm', 'html'], true);
    if ($html || in_array($ext, ['txt', 'md', 'csv', 'json'], true)) {
        if (!preg_match('//u', $data) || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $data)) {
            return 'Text must be UTF-8 without binary control bytes.';
        }
        if ($html && preg_match('~<script(?=[\t\n\r />])~i', $data)) {
            return 'HTML contains a script tag.';
        }
        $textMimes = $html
            ? ['text/html', 'text/plain']
            : ['text/plain', 'text/csv', 'application/csv', 'application/json', 'application/x-empty'];
        // Fileinfo can mistake bracketed lines or leading spaces for binary-format signatures.
        if ($ext === 'txt') {
            $textMimes[] = 'application/x-wine-extension-ini';
            $textMimes[] = 'application/SIMH-tape-data';
            // Recheck prose that fileinfo mistook for JavaScript or C keywords.
            $prosePattern = match ($mime) {
                'application/javascript' => '/^[ \t]*\Kimport(?=[a-z])/m',
                'text/x-c' => '/^[ \t]*\Kunion(?=[ \t]+ought\b)/m',
                default => null,
            };
            if ($prosePattern !== null) {
                $sample = preg_replace($prosePattern, 'xxxxxx', $data);
                if ($sample !== null && $sample !== $data && $finfo->buffer($sample) === 'text/plain') {
                    $mime = 'text/plain';
                }
            }
        }
        if ($data !== '' && !in_array($mime, $textMimes, true)) {
            return 'Detected MIME type does not match the expected text format.';
        }
        if ($ext === 'json') {
            json_decode($data, true, ZIP_JSON_DEPTH_LIMIT);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return 'Invalid JSON or nesting exceeds ' . ZIP_JSON_DEPTH_LIMIT . ' levels.';
            }
        }
        // CSV/Markdown have no unique signature; these are validated as plain text.
        return null;
    }
    $images = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'bmp' => 'image/bmp'];
    if (!isset($images[$ext])) {
        return 'Unsupported extension for content validation.';
    }
    if (strlen($data) > ZIP_IMAGE_LIMIT_MIB * 1024 * 1024) {
        return 'Image exceeds the ' . ZIP_IMAGE_LIMIT_MIB . ' MiB file size limit.';
    }
    $info = @getimagesizefromstring($data);
    if ($mime !== $images[$ext] || $info === false || $info['mime'] !== $images[$ext]) {
        return 'Image signature/MIME does not match extension.';
    }
    if ($info[0] < 1 || $info[1] < 1) {
        return 'Image dimensions must be positive.';
    }
    return $ext === 'gif' ? SecureZipFormats::checkGif($data) : null;
}

final class SecureZipRules
{
    /** Read case-sensitive file and directory patterns. */
    public static function parse(string $text): array
    {
        $patterns = [];
        $directories = [];
        $archiveDirectories = [];
        foreach (explode("\n", $text) as $line) {
            $pattern = trim($line, " \t\r");
            if ($pattern === '' || $pattern[0] === '#') {
                continue;
            }
            if (strlen($pattern) > ZIP_RULE_LENGTH_LIMIT_BYTES || preg_match('/[^\x20-\x7e]/', $pattern)) {
                throw new InvalidArgumentException('Invalid or oversized accepted file pattern.');
            }
            if (str_starts_with($pattern, 'dir:') || str_starts_with($pattern, 'archive-dir:')) {
                [$directive, $directory] = explode(':', $pattern, 2);
                $directory = trim($directory);
                if ($directory === '' || str_contains($directory, '/')) {
                    throw new InvalidArgumentException('Invalid accepted directory pattern.');
                }
                if ($directive === 'dir') {
                    $directories[] = $directory;
                } else {
                    $archiveDirectories[] = $directory;
                }
                continue;
            }
            $patterns[] = $pattern;
        }
        if ($directories !== []) {
            foreach ($patterns as $pattern) {
                if (substr_count($pattern, '/') > 1 || str_starts_with($pattern, '/') || str_ends_with($pattern, '/')) {
                    throw new InvalidArgumentException('File patterns must be basenames or immediate-parent/file patterns when directory rules are present.');
                }
            }
        }
        return ['files' => $patterns, 'directories' => $directories, 'archiveDirectories' => $archiveDirectories];
    }

    /** Match an archival directory component, excluding the filename. */
    public static function isArchiveOnly(array $parts, bool $isDirectory, array $patterns): bool
    {
        if (!$isDirectory) {
            array_pop($parts);
        }
        foreach ($parts as $part) {
            if (self::acceptsDirectory($part, $patterns)) {
                return true;
            }
        }
        return false;
    }

    public static function rejection(string $path, array $parts, bool $isDirectory, array $rules): ?string
    {
        $file = $isDirectory ? null : array_pop($parts);
        if ($rules['directories'] !== []) {
            foreach ($parts as $part) {
                if (!self::acceptsDirectory($part, $rules['directories'])) {
                    return 'directory name ' . json_encode($part) . ' does not match an accepted directory pattern.';
                }
            }
            if ($isDirectory || self::matchesGlob($file, $rules['files'])) {
                return null;
            }
            $parent = end($parts);
            $path = $parent === false ? $file : $parent . '/' . $file;
        }
        if (!$isDirectory && !self::matchesGlob($path, $rules['files'])) {
            return 'does not match an accepted file pattern.';
        }
        return null;
    }

    private static function acceptsDirectory(string $name, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (str_starts_with($pattern, '{digits}')) {
                $suffix = preg_quote(substr($pattern, strlen('{digits}')), '~');
                if (preg_match('~\A[0-9]+' . $suffix . '\z~', $name)) {
                    return true;
                }
            } elseif (fnmatch($pattern, $name, FNM_PATHNAME)) {
                return true;
            }
        }
        return false;
    }

    private static function matchesGlob(string $path, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (fnmatch($pattern, $path, FNM_PATHNAME)) {
                return true;
            }
        }
        return false;
    }
}
