# zip_validator
Checks the contents of an archive without extracting. Allowed file types are defined in a supplied `allowed.txt` file.

Within accepted types, the contents are checked to verify that the contents match the type. For example, a PHP file with a `.txt` extension will be rejected.

## Usage
On Linux, use PHP 8.1 or later with `zip` and `fileinfo` enabled, plus `dom`/`libxml` for SVG and MXL:

```php
require_once __DIR__ . '/zip_validator.php';

$result = validate_secure_zip('/local/upload.zip', '/local/allowed.txt');
if (!$result['success']) {
    $message = implode("\n", $result['errors']);
    // For HTML output: echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
}
```

## JSON output

`validate_secure_zip()` returns a PHP associative array. Encode it with `json_encode()` to produce a JSON object with three fields.

| Field | JSON type | Meaning |
| --- | --- | --- |
| `success` | Boolean | `true` when validation reports no errors, otherwise `false`. |
| `errors` | Array of strings | Validation messages for inputs, the archive, or individual entries. Empty on success. |
| `disallowed_files` | Array of strings | ZIP-relative entry names rejected by allowlist rules or content checks. This array can be empty on failure, for example when the ZIP cannot be opened. |

```php
echo json_encode($result, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL;
```

A rejected HTML entry produces a result such as this one.

```json
{
  "success": false,
  "errors": ["\"book.html\": HTML contains a script tag."],
  "disallowed_files": ["book.html"]
}
```

## allowed.txt
Pass the repository's [allowed.txt](allowed.txt) as the second argument, or supply your own local policy file. Rules are case-sensitive. Blank lines and lines beginning with `#` are skipped.

- A file glob such as `*.txt` matches the basename at any depth. A rule such as `images/*.jpg` matches a JPEG whose immediate parent is `images` at any depth. A file is allowed if it matches any file rule.

- Every directory component must match a `dir:` rule, including implicit parents and explicit empty directories. For example, `dir: images` matches that exact name and `dir: *page-images` matches names ending in `page-images`. A directory rule starting with `{digits}` matches one or more ASCII digits followed by the literal suffix in the rule, if any. Use `dir: {digits}` for names such as `12345` and `dir: {digits}-h` for names such as `12345-h`. Add one rule per permitted suffix.

- Existing policies without `dir:` rules retain ZIP-relative path matching. For example, `images/*.png` matches PNGs directly inside `images`, and directory entries undergo structural checks. An empty policy accepts no files. Entries rejected by the policy or by content validation appear in both `disallowed_files` and `errors`. Inspection limits can stop reporting early.

- An `archive-dir: old` rule accepts a directory named `old` at any depth and its entire subtree without file pattern or format-specific content checks. ZIP safety limits, path checks, and entry integrity checks still apply.

## Limits
- 10,000 entries
- 300 MiB archive/expanded size
- 50 MiB per entry
- 5 MiB image size
- 200:1 compression ratio
- 64 KiB rule file
- ASCII-only names up to 1024 bytes.

Stored and deflated entries are supported. Nothing is extracted or executed. Entry size and CRC are checked against bounded reads; unsafe names, links, special files, encryption, duplicates, and file/directory conflicts fail validation.

## Validation
Files that match a pattern must also pass content validation. Supported formats are `txt`, `md`, `csv`, `json`, `htm`, `html`, `png`, `jpg`, `jpeg`, `gif`, `bmp`, `svg`, `mp3`, `mxl`, `midi`, `wav`, `ttf`, and `otf`. MXL is the only supported nested archive format.

- HTML is checked as UTF-8 text using fileinfo and common executable markers. Literal `<script>` opening tags are rejected regardless of case or attributes.
- Text is checked for UTF-8, control bytes, MIME type, and common executable markers.
- JSON must parse with at most 64 nesting levels.
- Markdown has no unique signature and is checked as plain text. Sanitize with an appropriate renderer before rendering it as HTML.
- Images are checked using fileinfo and image headers, with dimension limits; **they are not fully decoded or sanitized**.

These checks do not establish that a file is harmless or malware-free. Use an isolated scanner and, where needed, image decoding/re-encoding before publication.

## Additional format policies
(structural inspection, not full decoding or sanitization):
- GIF must contain exactly one image frame. The validator walks GIF blocks   through the trailer without decoding pixels.
- SVG defaults to compatibility mode. Pass `strictSvg: true` to `validate_secure_zip()` to require UTF-8 SVG XML with the SVG namespace and enforce the passive element and attribute allowlists. Both modes check MIME type and inspect inline styles and attribute values when restricted XML parsing succeeds. Compatibility mode accepts files the restricted parser
declines, so treat accepted SVGs as untrusted downloads.
- Restricted XML parsing for SVG and MXL disables external resolution and network access, applies node limits, and declines DTDs, entity declarations, XInclude, and processing instructions. MusicXML scores allow the exact SmartMusic `maxVoice=1` processing instruction.
- MP3 requires a sequence of bounded MPEG Layer III frames, optionally surrounded by ID3v2/ID3v1 tags. Free-bitrate MP3 and other trailing tag formats are rejected. Compressed audio payloads and metadata are not decoded.
- MIDI requires a Standard MIDI header, bounded tracks, valid event framing and running status, and end-of-track markers. This adds `.midi`; `.mid` is not in the requested allowlist.
- WAV requires consistent RIFF/WAVE chunk lengths and PCM or IEEE float format with consistent channel count, sample rate, block alignment, and data length. Compressed WAV and WAVE_FORMAT_EXTENSIBLE are currently rejected.
- TTF/OTF require matching sfnt signatures, a bounded non-overlapping table directory, required tables, and the font header magic. OTF accepts TrueType or CFF outlines; TTF requires TrueType outlines. Embedded SVG tables are rejected. Individual glyph programs and table internals are not fully validated. Use a sandboxed font sanitizer before exposing untrusted fonts to a renderer.
- MXL is inspected via a private temporary container file, removed afterward; entries are never extracted. It requires `META-INF/container.xml`, included MusicXML rootfiles, and safe local XML references. Only unnamespaced MusicXML score/opus documents, the container XML, and the optional stored `mimetype` marker are permitted. Packages with PDFs, images, other attachments, nested ZIPs, or external resource references are rejected. Standard Recordare public DTD declarations for partwise and timewise scores are discarded before parsing. Other DTD declarations are rejected. Each MXL is limited to 256 entries, 10 MiB per entry, 20 MiB expanded, and 200:1 compression. All MXL containers together have a 100 MiB expanded inspection budget per outer ZIP validation. Outer accepted file patterns match outer ZIP paths; the internal MXL package uses this separate restrictive policy.

Format references:
- [MusicXML container specification](https://www.w3.org/2021/06/musicxml40/listings/container.xsd/)
- [OpenType file structure](https://learn.microsoft.com/en-us/typography/opentype/spec/otff)
- [PHP XML parser flags](https://www.php.net/manual/en/libxml.constants.php).

Keep accepted files outside the web root, serve downloads with attachment disposition and `X-Content-Type-Options: nosniff`, and HTML-escape error messages. Ensure the ZIP cannot change between validation and subsequent processing.

## Run regression checks:
```sh
php tests/secure_zip_validator_test.php
```

## Notes for user debug
- Main files and supporting directories should be at top level, not in a subdirectory.
- Extensions and directory names are lower case - "Images" will fail.
- Supporting files must be in the proper parent directory - "music/image.jpg" will fail.
