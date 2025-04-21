<?php

#
# Run ebookmaker on a project.  Allow the user to specify a few options
#

# Written by Greg Newby for Project Gutenberg and Distributed Proofreaders
# September 2011
# This PHP program is granted to the public domain

ini_set('default_charset', 'UTF-8');

// Get all user inputs.
UserInputs::parse();

// This script supports two modes: HTML & JSON.
// When used in JSON mode all output written to stdout is captured and
// dropped and we only return JSON to the requester -- on error and success.
// This allows the code to interlace messages for a user visiting the web
// without interfering with the JSON-only output we want in JSON mode.
// This is a little kudgey but means we don't need to duplicate logic or
// sprinkle if/else logic on mode everywhere.
if (UserInputs::$output_format == "json") {
    // capture all output to ensure that any errors that are surfaced
    // don't interfere with our HTTP return code
    ob_start();

    // everything is a JSON response
    header("Content-Type: application/json");

    set_exception_handler('json_exception_handler');
} else {
    set_exception_handler('html_exception_handler');

    output_header("Project Gutenberg Online Ebookmaker");
}

UserInputs::validate();

// Load and validate configuration
try {
    require __DIR__ . "/config.php";

    $required_variables = [
        "mybaseurl", "prog", "validator", "tmpdir",
        "ebookmaker_version", "validator_version", "epubcheck_version",
    ];
    foreach ($required_variables as $var_name) {
        if (!isset($$var_name)) {
            throw new RuntimeException("$var_name config variable not set.");
        }
    }
} catch (Exception | Error $exception) {
    throw new RuntimeException("ebookmaker web interface is misconfigured.");
}

// Main script
if (! UserInputs::$make) {
    echo <<<EOPARA
    <h2>Quick Start</h2>

    <p>This is
    <a href="https://github.com/gutenbergtools/ebookmaker">ebookmaker</a> version $ebookmaker_version
    with
    <a href="https://validator.w3.org/nu/">Nu HTML Checker</a> version $validator_version
    and
    <a href="https://github.com/w3c/epubcheck">epubcheck</a> version $epubcheck_version
    .</p>

    <p>Please upload a <strong>single file</strong>.
    If your submission has more than
    one file, upload a .zip of all the needed files.
    Any images should be in a subdirectory (i.e., folder)
    named "images", and cannot be omitted if they are referenced by the source.</p>
    EOPARA;

    do_form();

    echo <<<EOPARA
    <p>Ebookmaker will try to identify author, title, encoding and
    eBook number from your file if it includes the standard Project
    Gutenberg metadata as found in the published collection.  Otherwise,
    you can provide values. UTF-8 characters may be used for metadata.
    Missing metadata values are not usually a problem.</p>

    <p>After your file has transferred, processing can take as long
    as a few minutes for large files.</p>

    <h2>Usage Details</h2>

    <p>This is for the <strong>required</strong> pre-submission
    checks of Project Gutenberg eBooks.
    Project Gutenberg uses ebookmaker to generate several formats
    from a single source file. HTML5 is preferred, but earlier
    HTML versions also work, as well as plain text. If you wish
    to submit a different format, first contact the production team
    (contact information is below).</p>

    <p>Submitters should use this ebookmaker site repeatedly
    during the digitization process, to ensure automated
    conformance requirements are met and to review the
    generated files to confirm they appear as expected.</p>

    <p>You can test how well mobile output looks (EPUB and Kindle)
    without needing a ereader device.  Instead, try one of the many free
    browser plug-ins or ereader software such as
    <a href="https://calibre-ebook.com">Calibre</a>.</p>

    <p>HTML guidance, including procedures for HTML5 and other versions,
    may be found in the <a href="https://www.pgdp.net/wiki/DP_Official_Documentation:PP_and_PPV/Post-processing_HTML5">Distributed Proofreaders Wiki</a>.</p>

    <p>If you upload HTML version 5, the W3C's
    <a href="https://validator.w3.org/nu/#file">Nu validator</a>
    is run. For previous versions of HTML, you should run the
    <a href="https://validator.w3.org">legacy HTML validator</a>
    and CSS check, in addition to ebookmaker.

    <strong>Your submission to Project Gutenberg must have no
    HTML validation errors</strong>.

    <p>Once ebookmaker completes, review the "output.txt" file.
    Revise and reupload you work as needed. <strong>Your submission to
    Project Gutenberg must have no CRITICAL or ERROR messages from
    ebookmaker</strong>.</p>

    <p>The source code for ebookmaker is <a href="https://github.com/gutenbergtools/ebookmaker">online at github</a>. The Nu HTML validator is available as a compiled <a href="https://github.com/validator">Java JAR download</a> and you can find source code via the same link.</p>

    <p>Information about ebookmaker best practices for Project Gutenberg
    is available at <a
    href="https://www.pgdp.net/wiki/The_Proofreader's_Guide_to_EPUB">https://www.pgdp.net/wiki/The_Proofreader's_Guide_to_EPUB</a>.
    </p>
    EOPARA;

    exit;
}

// Create new output directory:
$tmpsubdir = date('YmdHis');
$dirname = $tmpdir . "/" . $tmpsubdir;

// where our output will go
if (!@mkdir($dirname)) {
    throw new RuntimeException("Error creating output directory.");
}

// We will redirect some messages to this file, for the user to read:
$outfile = "$tmpdir/$tmpsubdir/output.txt";

// Put a BOM at the start (it should always be UTF-8) (temporarily open as a stream):
$bom = (chr(0xEF) . chr(0xBB) . chr(0xBF));
if (!@file_put_contents($outfile, $bom)) {
    throw new RuntimeException("An error occurred opening $outfile.\nPlease report this.");
}

process_uploaded_file($dirname);

// Find the file to operate on and validate it
$basename = locate_file_for_ebookmaker($dirname);

// Make sure we found a file to operate on:
if (!$basename) {
    echo <<<EOSTRING
    <p>Sorry, but we could not identify a file ending in txt,
    htm, html or xhtml. Follow this link to see what was identified.  You
    might need to give a more Unix-friendly filename (no spaces, lower
    case, no special characters). Please try again, or send email if
    you need help or things seem amiss.</p>
    <p>
    <ul>
    <li><a href="$mybaseurl/cache/$tmpsubdir">$mybaseurl/cache/$tmpsubdir</a></li>
    </ul>
    </p>
    EOSTRING;

    exit;
}

append_output_log("\nInput file: $basename\n");

if (UserInputs::$validate_html) {
    validate_html($basename);
}

[$retval, $ebookmaker_log] = run_ebookmaker($basename, "$tmpdir/$tmpsubdir", $outfile);

if (UserInputs::$output_format == "json") {
    if ($retval) {
        throw new RuntimeException("Error running ebookmaker. See output.txt for more information.");
    } else {
        return_json_response("$mybaseurl/cache/$tmpsubdir", $ebookmaker_log);
    }
}

if (!$retval) {
    print "<p><span style='color: green'>Done</span>: ebookmaker has completed. This does not mean all desired output was successfully generated.  The output.txt file provides detail on the processing that occurred, and any error or informational messages.\n";
} else {
    print "<p><span style='color: red'>Sorry, ebookmaker ended with an error code.</span> Send email if this seems to be an actual problem, not just a temporary glitch or a problem with your file.</p>\n";
}
echo <<<EOPARA
<p>Please follow this link to view the input file you uploaded
(possibly it was renamed) and any output files.  This link
will stay available for around three days:
<ul>
    <li><a href="$mybaseurl/cache/$tmpsubdir">$mybaseurl/cache/$tmpsubdir</a></li>
</ul>
</p>
EOPARA;

print "<h2>Submit another</h2>\n";

do_form();

print "<p><a href=\"?\">Return to main page</a></p>\n";


//----------------------------------------------------------------------------

class UserInputs
{
    public static ?string $ebook_number;
    public static ?string $title;
    public static ?string $author;
    public static ?string $file_field_name;
    public static array $make_formats;
    public static string $output_format = "html";
    public static bool $validate_html = false;
    public static bool $debug_verbosity = false;
    public static bool $make = false;

    public static function parse(): void
    {
        // Note: these $_POST and $_FILES key names are part of the API
        // and should not be changed without care. See API.md
        UserInputs::$ebook_number = $_POST['ebook_number'] ?? $_POST['myebook'] ?? null;
        UserInputs::$title = $_POST['title'] ?? $_POST['mytitle'] ?? null;
        UserInputs::$author = $_POST['author'] ?? $_POST['myauthor'] ?? null;
        UserInputs::$file_field_name = null;
        foreach (['file', 'upfile1'] as $field_name) {
            if (isset($_FILES[$field_name])) {
                UserInputs::$file_field_name = $field_name;
                break;
            }
        }

        if (isset($_POST["make_formats"])) {
            UserInputs::$make_formats = explode(",", $_POST["make_formats"]);
        } else {
            UserInputs::$make_formats = [
                "txt.utf-8", "epub", "epub3", "kf8", "kindle", "html",
            ];
        }

        UserInputs::$output_format = $_POST["out"] ?? "html";
        if (!in_array(UserInputs::$output_format, ["html", "json"])) {
            UserInputs::$output_format = "html";
        }

        if (isset($_POST["validate_html"])) {
            UserInputs::$validate_html = filter_var($_POST["validate_html"], FILTER_VALIDATE_BOOL);
        }

        if (isset($_POST["debug_verbosity"])) {
            UserInputs::$debug_verbosity = filter_var($_POST["debug_verbosity"], FILTER_VALIDATE_BOOL);
        }

        UserInputs::$make = isset($_POST["make"]) || UserInputs::$output_format == "json";
    }

    public static function validate(): void
    {
        if (UserInputs::$make && !UserInputs::$file_field_name) {
            throw new ValueError("Ebookmaker was requested to process files, but no file was received. Please try again, or send email for help.");
        }
    }
}

function do_form(): void
{
    echo <<<EOFORM
    <blockquote>
    <form enctype="multipart/form-data" method="POST" accept-charset="UTF-8">
    <input type="hidden" name="validate_html" value="true">
    <input type="file" name="file" required> Your file (any of: zip/txt/htm/html)
    <br><input type="text" size="50" name="title" placeholder="Title"> eBook title
    <br><input type="text" size="50" name="author" placeholder="Author"> eBook author
    <br><input type="number" size="10" name="ebook_number" placeholder="10001"> eBook number (must be an integer)

    <br><input type="submit" value="Make it!" name="make">
    </form>
    </blockquote>
    EOFORM;
}

function output_header(string $title): void
{
    echo <<<EOHEADER
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <META HTTP-EQUIV="Content-Type" CONTENT="text/html; charset=utf-8">
        <title>$title</title>
    </head>

    <body>
    <h1>$title</h1>
    EOHEADER;

    // Call output_footer at the end of the script to close the page
    register_shutdown_function('output_footer');
}

function output_footer(): void
{
    $year = date('Y');
    echo <<<EOFOOTER
    <hr>

    <p>Trouble or questions? Please email <i>copyright$year AT pglaf.org</i>
    for clearance questions or <i>pgww AT lists.pglaf.org</i> for
    production or status questions.
    </p>

    <p>This online tool can return a well-structured JSON response from a form
    POST by including the field "out" with value "json". This is useful for
    running ebookmaker from automation. See <a href="API.md">API.md</a> for
    more details.</p>

    <p>The ebookmaker software is available as a free download,
    if you would rather run it on your own system.
    You can find the software for download at <a href="https://github.com/gutenbergtools/ebookmaker">https://github.com/gutenbergtools/ebookmaker</a></p>

    </body>
    </html>
    EOFOOTER;
}

// People use some weird-ass filenames.  Make more Unix-friendly:
function fix_filename(?string $inname): string
{
    if (!isset($inname)) {
        $inname = "";
    }

    // Get just the filename part, not the directory part (if any):
    $inname = str_replace("\\", "/", $inname); // Any dos directories?
    $inname = basename($inname); // Just the filename part

    $inname = preg_replace("/[^-_a-zA-Z0-9.]/", "", $inname);
    // Safety: If that removed everything, just give it a random
    if (0 == strlen($inname)) {
        $inname = time() . rand(1000, 9999);
    }

    return($inname);
}

function append_output_log(string $message): void
{
    global $outfile;
    if ($outfile) {
        file_put_contents($outfile, "$message\n", FILE_APPEND);
    }
}

function process_uploaded_file(string $dirname): void
{
    // Rename uploaded file
    $upfile1_name = fix_filename($_FILES[UserInputs::$file_field_name]['name']);
    $newname = $dirname . "/" . $upfile1_name;

    // remove from php spool area
    if (!@rename($_FILES[UserInputs::$file_field_name]['tmp_name'], $newname)) {
        throw new RuntimeException("Error renaming uploaded file");
    }
    if (!@chmod($newname, 0644)) {
        throw new RuntimeException("Error changing uploaded file permissions");
    }

    // Unzip the input file, if needed:
    if (str_ends_with($newname, ".zip")) {
        append_output_log("unzipping $newname");
        $args = [
            "/usr/bin/unzip",
            "-o",
            "-U", escapeshellarg($newname),
            "-d", escapeshellarg($dirname),
        ];
        exec(join(" ", $args), $output, $retval);
        append_output_log(join("\n", $output));
        if ($retval) {
            throw new RuntimeException("Error extracting zip file.");
        }
    }
}

function locate_file_for_ebookmaker(string $dirname): ?string
{
    // We'll get all files but . and ..:
    $dir = new RecursiveDirectoryIterator($dirname, RecursiveDirectoryIterator::SKIP_DOTS);

    // This populates array $files:
    $files = new RecursiveIteratorIterator($dir);

    // Order of the directory listing is arbitrary. We want to capture
    // .html, .htm, .xhtml, and .txt in priority order, taking the last of each
    // we find:
    $basename_txt = null;
    $basename_htm = null;
    $basename_html = null;
    $basename_xhtml = null;

    foreach ($files as $file) {
        # skip __MACOSX resource fork contents
        if (stripos($file->getPath(), "__MACOSX") !== false) {
            continue;
        }

        if ($file->getExtension() == "txt" && $file->getFilename() != "output.txt") {
            $basename_txt = $file->getPathname();
        } elseif ($file->getExtension() == "htm") {
            $basename_htm = $file->getPathname();
        } elseif ($file->getExtension() == "html") {
            $basename_html = $file->getPathname();
        } elseif ($file->getExtension() == "xhtml") {
            $basename_xhtml = $file->getPathname();
        }
    }

    // return the first non-null value or null if none are found
    return $basename_html ?? $basename_htm ?? $basename_xhtml ?? $basename_txt;
}

function validate_html($basename)
{
    global $validator, $outfile;

    ##### Figure out HTML version if needed, and generate output for user:
    $not_html5 = 0;
    if (str_ends_with($basename, ".html") || str_ends_with($basename, ".htm")) {
        # Did we get HTML5? Look at the first line:
        $tmpin = fopen($basename, "r");
        if ($tmpin == false) {
            throw new RuntimeException("Error opening file $basename for validation.");
        }
        $line = fgets($tmpin);
        $regex = '/<\!DOCTYPE html/i';
        if (! preg_match($regex, $line)) {
            $not_html5 = 1;
        }
        # We also need to look for xhtml in the doctype line:
        $regex = '/xhtml/i';
        if (preg_match($regex, $line)) {
            $not_html5 = 1;
        }
        fclose($tmpin);

        # We got HTML5. Validate it:
        if ($not_html5 == 0) {
            print "<p style='color: green'>Validating your uploaded file...</p>";
            append_output_log("Validating your uploaded file");
            # TODO: what should we do if the validator fails? Log it? Error out?
            $args = [
                $validator,  # trusted input
                escapeshellarg($basename),
                ">>",
                escapeshellarg($outfile),
                "2>&1",
            ];
            system(join(" ", $args), $retval);
            append_output_log("Validation complete");
        }
    }

    if ($not_html5 || str_ends_with($basename, "xhtml")) {
        echo <<<EOPARA
        <p style='color: blue'>Your HTML does not seem to be HTML version
        5. Be sure to run the appropriate validator, probably the W3C's
        <a href="https://validator.w3.org">legacy HTML &amp; CSS
        validator</a>.</p>
        EOPARA;

        append_output_log("--- HTML validation not done");
        append_output_log("HTML validation not done since your source is not HTML5.");
        append_output_log("You must use a validator such as https://validator.w3.org in addition to ebookmaker");
        append_output_log("---");
    }
}

function run_ebookmaker(
    string $input_path,
    string $output_dir,
    string $logfile
): array {
    global $prog;

    $ebookmaker_log = "$output_dir/ebookmaker.log";

    // Options to ebookmaker:
    $opts = [
        "--verbose",
        "--validate",
        "--max-depth", 3,
        "--output-dir", $output_dir,
    ];

    // a second --verbose will give DEBUG-level messages
    if (UserInputs::$debug_verbosity) {
        $opts[] = "--verbose";
    }

    foreach (UserInputs::$make_formats as $format) {
        $opts[] = "--make";
        $opts[] = escapeshellarg(trim($format));
    }

    # required argument
    $opts[] = "--ebook";
    if (UserInputs::$ebook_number) {
        $opts[] = escapeshellarg(UserInputs::$ebook_number);
    } else {
        $opts[] = 10001; # Required
    }
    if (UserInputs::$title) {
        $opts[] = "--title";
        $opts[] = escapeshellarg(UserInputs::$title);
    }
    if (UserInputs::$author) {
        $opts[] = "--author";
        $opts[] = escapeshellarg(UserInputs::$author);
    }

    $gopts = join(" ", $opts);

    // Run ebookmaker
    print "<p><span style='color: green'>Running ebookmaker</span>: ";
    print "<tt>ebookmaker $gopts file://$input_path\n</tt>\n";
    print "</p>";

    append_output_log("\n");
    append_output_log("--- Starting ebookmaker processing");
    system("$prog --version >> $logfile", $retval);
    if ($retval) {
        // if we can't run a basic --version something is very wrong and we should stop
        throw new RuntimeException("Error running ebookmaker version check.");
    }
    append_output_log("Command: ebookmaker $gopts file://$input_path");
    $args = [
        $prog,  # trusted input
        $gopts,  # pre-escaped inputs
        escapeshellarg("file://$input_path"),
        ">>",
        escapeshellarg($ebookmaker_log),
        "2>&1",
    ];
    system(join(" ", $args), $retval);
    // put the contents of the ebookmaker.log file into output.txt
    file_put_contents($logfile, file_get_contents($ebookmaker_log), FILE_APPEND);
    append_output_log("--- ebookmaker complete");

    return [$retval, $ebookmaker_log];
}

function output_json_response(string $data, int $response_code = 200)
{
    // drop the output buffer we've been storing to prevent errant output
    // from violating the JSON response
    ob_end_clean();

    http_response_code($response_code);
    echo $data;
    exit();
}

function return_json_response(string $url_basedir, string $ebookmaker_log): void
{
    # TODO: parse ebookmaker_log file for details

    // NOT: data in this dictionary should be considered an API and changed
    // with care!
    $data = [
        "output_dir" => $url_basedir,
        "output_log" => "$url_basedir/output.txt",
        "ebookmaker_log" => "$url_basedir/ebookmaker.log",
    ];
    $response = json_encode(
        $data,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    output_json_response($response, 200);
}

function json_exception_handler($exception): void
{
    $response = json_encode(
        ["error" => $exception->getMessage()],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    output_json_response($response, 500);

    append_output_log("JSON interface encountered an exception: " . $exception->getMessage());
}

function html_exception_handler($exception): void
{
    echo "<p style='color: red'>\n";
    echo htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8');
    echo "\n</p>";

    append_output_log("Web interface encountered an exception: " . $exception->getMessage());
}
