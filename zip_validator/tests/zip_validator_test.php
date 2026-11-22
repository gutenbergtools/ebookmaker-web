<?php

require_once __DIR__ . '/../zip_validator.php';

$temp = sys_get_temp_dir() . '/zip-validation-' . bin2hex(random_bytes(8));
mkdir($temp, 0700);
$checks = 0;
function check_zip_test(bool $ok, string $message): void
{
    global $checks;
    $checks++;
    if (!$ok) {
        throw new RuntimeException($message);
    }
}
try {
    $run = static function (array $entries, string $rules = "*.txt\n*.json\n*.jpg\n*.svg\n*/*.txt", bool $strictSvg = false) use ($temp): array {
        $zip = new ZipArchive();
        $zip->open($temp . '/input.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($entries as $name => $data) {
            if (str_ends_with($name, '/') && $data === '') {
                $zip->addEmptyDir($name);
            } else {
                $zip->addFromString($name, $data);
            }
        }
        $zip->close();
        file_put_contents($temp . '/allowed.txt', $rules);
        return validate_secure_zip($temp . '/input.zip', $temp . '/allowed.txt', strictSvg: $strictSvg);
    };
    check_zip_test($run(['a.txt' => 'hello', 'a.json' => '{"ok":true}'])['success'], 'Valid text rejected');
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" style="%s"/>';
    check_zip_test($run(['a.svg' => sprintf($svg, 'vertical-align: -0.838ex; margin-top: -0.222ex; fill: url(#paint)')])['success'], 'Passive SVG inline style rejected');
    check_zip_test(!$run(['a.svg' => sprintf($svg, 'fill: url(https://example.test/paint.svg)')])['success'], 'External SVG style reference accepted');
    check_zip_test(!$run(['a.svg' => sprintf($svg, 'animation: example 1s')])['success'], 'Unsupported SVG style property accepted');
    foreach ([
        '<svg/>' => 'Invalid SVG:',
        '<svg xmlns="http://www.w3.org/2000/svg"><use/></svg>' => 'SVG contains an unsupported or active element: use.',
        '<svg xmlns="http://www.w3.org/2000/svg" class="figure"/>' => 'SVG contains unsupported attribute "class".',
    ] as $markup => $message) {
        check_zip_test($run(['a.svg' => $markup])['success'], 'Default SVG policy rejected: ' . $message);
        $result = $run(['a.svg' => $markup], strictSvg: true);
        check_zip_test(!$result['success'] && str_contains(implode("\n", $result['errors']), $message), 'Strict SVG policy did not report: ' . $message);
    }
    $blocked = $run(['a.log' => 'hello', 'sub/b.txt' => 'world', 'README.TXT' => 'hello'], '*.txt');
    check_zip_test($blocked['disallowed_files'] === ['a.log', 'sub/b.txt', 'README.TXT'] && !$blocked['success'], 'Missing disallowed files');
    check_zip_test($run(['a.txt' => 'hello'], "# comment\r\n\r\n*.txt\r\n")['success'], 'CRLF policy rejected');
    check_zip_test(!$run(['a.txt' => 'hello'], '')['success'], 'Empty policy accepted a file');
    check_zip_test(!$run(['a.php' => 'plain text'], '*.php')['success'], 'Pattern bypassed content validation');
    $blocked = $run(['this_is_actually_php.txt' => '<?php echo 1;', 'valid.txt' => 'hello']);
    check_zip_test(
        !$blocked['success'] && $blocked['disallowed_files'] === ['this_is_actually_php.txt'],
        'Content rejection missing from disallowed files'
    );
    foreach ([
        ['../a.txt' => 'hello'],
        ['C:/a.txt' => 'hello'],
        ['a\\b.txt' => 'hello'],
        ['a.txt' => "bad\0text"],
        ['a.json' => '{oops'],
        ['a.jpg' => 'not an image'],
        ['a.php' => '<?php echo 1;'],
        ['a.zip' => 'PK'],
        ['a' => 'x'],
        ['a.txt' => 'x', 'A.txt' => 'y'],
        ['a.txt' => 'x', 'a.txt/b.txt' => 'y'],
        ['a.txt' => str_repeat('a', 1024 * 1024)],
    ] as $entries) {
        check_zip_test(!$run($entries)['success'], 'Unsafe archive accepted: ' . implode(', ', array_keys($entries)));
    }
    $jpeg = base64_decode(
        '/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwc'
        . 'KDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAACP/EABQQAQAAAAAAAAAAAA'
        . 'AAAAAAAAD/2gAIAQEAAD8AP7//2Q=='
    );
    $comment = 'Literal <% in JPEG metadata';
    $jpeg = substr_replace($jpeg, "\xff\xfe" . pack('n', strlen($comment) + 2) . $comment, 2, 0);
    check_zip_test($run(['a.jpg' => $jpeg, 'b.jpeg' => $jpeg], "*.jpg\n*.jpeg")['success'], 'Valid JPEG metadata rejected');

    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aGD8AAAAASUVORK5CYII=');
    $pngChunk = static function (string $type, string $data): string {
        return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    };
    $pngWithMarker = substr($png, 0, 33)
        . $pngChunk('IDAT', gzcompress("\0<%", 0))
        . $pngChunk('IEND', '');
    check_zip_test($run(['a.png' => $pngWithMarker], '*.png')['success'], 'Valid PNG image data rejected');
    $imageLimit = 5 * 1024 * 1024;
    $chunkData = random_bytes($imageLimit - strlen($png) - 12);
    foreach ([0, 1] as $extraBytes) {
        $image = substr($png, 0, -12) . $pngChunk('vpAg', $chunkData . str_repeat('x', $extraBytes)) . substr($png, -12);
        $result = $run(['a.png' => $image], '*.png');
        if ($extraBytes === 0) {
            check_zip_test($result['success'], 'Image at the 5 MiB limit rejected');
        } else {
            check_zip_test(!$result['success'] && $result['disallowed_files'] === ['a.png']
                && str_contains(implode("\n", $result['errors']), '5 MiB file size limit'), 'Image over the 5 MiB limit accepted');
        }
    }
    check_zip_test(!$run(['a.png' => '<?php echo 1;'], '*.png')['success'], 'PHP text accepted as PNG');
    $submissionRules = file_get_contents(__DIR__ . '/../allowed.txt');
    check_zip_test($run(['114/old/' => '', '114/old/legacy/data.bin' => "\0data"], $submissionRules)['success'], 'Archival subtree rejected');
    check_zip_test(!$run(['114/older/data.bin' => "\0data"], $submissionRules)['success'], 'Archival rule matched a different directory');
    check_zip_test(!$run(['114/old/../data.bin' => "\0data"], $submissionRules)['success'], 'Archival rule bypassed path validation');
    $gif = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
    $gifFrame = substr($gif, strpos($gif, "\x2c"), -1);
    $gifWithComment = substr($gif, 0, -1) . "\x21\xfe\x02\x2c\x3b\x00\x3b";
    check_zip_test($run(['images/pixel.gif' => $gifWithComment], $submissionRules)['success'], 'GIF comment bytes were mistaken for frame or trailer markers');
    $gifWithScriptLikeBytes = substr($gif, 0, -1) . "\x21\xfe\x02<%\x00\x3b";
    check_zip_test($run(['images/pixel.gif' => $gifWithScriptLikeBytes], $submissionRules)['success'], 'GIF bytes were mistaken for executable content');
    $result = $run(['images/animated.gif' => substr($gif, 0, -1) . $gifFrame . "\x3b"], $submissionRules);
    check_zip_test(!$result['success'] && str_contains(implode("\n", $result['errors']), 'GIF must contain exactly one frame.'), 'Two-frame GIF was not rejected by the frame check');
    check_zip_test(!$run(['images/truncated.gif' => substr($gif, 0, -1)], $submissionRules)['success'], 'GIF without trailer accepted');
    $html = '<!doctype html><html><title>Book</title><p>Text</p></html>';
    $bmp = pack('a2VvvVVVVvvVVVVVV', 'BM', 58, 0, 0, 54, 40, 1, 1, 1, 24, 0, 4, 0, 0, 0, 0)
        . "\0\0\0\0";
    $submission = $run([
        'book.htm' => $html,
        'source/book.html' => $html,
        'images/' => '',
        'images/pixel.png' => $png,
        'images/pixel.bmp' => $bmp,
        'page-images/scan.png' => $png,
        '12345/12345-h/9876/images/pixel.jpg' => $jpeg,
    ], $submissionRules);
    check_zip_test($submission['success'], implode("\n", $submission['errors']));
    $blocked = $run(['pixel.jpg' => $jpeg, 'images/9876/pixel.jpg' => $jpeg], $submissionRules);
    check_zip_test($blocked['disallowed_files'] === ['pixel.jpg', 'images/9876/pixel.jpg'], 'Image accepted outside its required immediate parent');
    $blocked = $run([
        'images/nested/pixel.png' => $png,
        '12345-x/book.txt' => 'hello',
        '123x/book.txt' => 'hello',
        'unlisted/' => '',
    ], $submissionRules);
    check_zip_test(
        $blocked['disallowed_files'] === [
            'images/nested/pixel.png', '12345-x/book.txt', '123x/book.txt', 'unlisted/',
        ] && str_contains(implode("\n", $blocked['errors']), 'directory name "nested"'),
        'Unlisted directory names were not reported'
    );
    check_zip_test(!$run(['book.html' => $png], $submissionRules)['success'], 'Binary data accepted as HTML');
    foreach (['<script></script>', '<ScRiPt src="book.js"></sCrIpT>', "<script\nsrc=book.js></script>", '<script/>'] as $tag) {
        foreach (['book.htm', 'source/book.html'] as $name) {
            $result = $run([$name => '<!doctype html>' . $tag], $submissionRules);
            check_zip_test(!$result['success'] && $result['disallowed_files'] === [$name]
                && str_contains(implode("\n", $result['errors']), 'HTML contains a script tag.'), 'Script tag accepted in HTML');
        }
    }
    check_zip_test($run(['book.html' => '<!doctype html><p>&lt;script&gt;</p><script-example></script-example>'],
        $submissionRules)['success'], 'Escaped script example or similarly named element rejected');
    $mxlPath = $temp . '/score.mxl';
    $runMxl = static function (string $declaration) use ($mxlPath, $run): array {
        $zip = new ZipArchive();
        $zip->open($mxlPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('META-INF/container.xml', '<container><rootfiles><rootfile full-path="score.xml"/></rootfiles></container>');
        $zip->addFromString('score.xml', $declaration . '<score-partwise version="4.0"/>');
        $zip->close();
        return $run(['score.mxl' => file_get_contents($mxlPath)], '*.mxl');
    };
    $musicXmlDoctype = '<!DOCTYPE score-partwise PUBLIC "-//Recordare//DTD MusicXML 4.0 Partwise//EN" "http://www.musicxml.org/dtds/partwise.dtd">';
    check_zip_test($runMxl($musicXmlDoctype)['success'], 'Standard MusicXML declaration rejected');
    check_zip_test(!$runMxl(str_replace('www.musicxml.org', 'example.test', $musicXmlDoctype))['success'], 'Unknown MusicXML DTD accepted');
    check_zip_test(!$runMxl(substr_replace($musicXmlDoctype, ' []>', -1))['success'], 'MusicXML internal subset accepted');

    $zip = new ZipArchive();
    $zip->open($temp . '/input.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('link.txt', 'target.txt');
    $zip->setExternalAttributesName('link.txt', 3, 0120777 << 16);
    $zip->close();
    check_zip_test(!validate_secure_zip($temp . '/input.zip', $temp . '/allowed.txt')['success'], 'Symlink accepted');
    file_put_contents($temp . '/input.zip', 'not zip');
    check_zip_test(!validate_secure_zip($temp . '/input.zip', $temp . '/allowed.txt')['success'], 'Invalid ZIP accepted');
    check_zip_test(!validate_secure_zip($temp . '/missing', $temp . '/allowed.txt')['success'], 'Missing file accepted');
    echo "Passed $checks checks.\n";
} finally {
    foreach (glob($temp . '/*') as $path) {
        unlink($path);
    }
    rmdir($temp);
}
