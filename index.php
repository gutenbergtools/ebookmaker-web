<?php

#
# Run ebookmaker on a project.  Allow the user to specify a few options
#

# Written by Greg Newby for Project Gutenberg and Distributed Proofreaders
# September 2011
# This PHP program is granted to the public domain

# Change history:
# 2011-09-25: First version for internal review
# 2011-10-06: First public release
# 2012-05-06: documentation updates, default eBook #
# 2014-10-10: Marcello: switch to ebookmaker/python3
# 2017-03-04: gbn: Update links to ebookmaker source
# 2019-11-10: gbn: moved to dante.pglaf.org with new ebookmaker & pipenv
# 2019-11-29: gbn: slight UI & logic updates
# 2021-07-11: gbn: removed reference to RST (it's deprecated, though will work if submitted)
# 2021-07-28: gbn: small updates to align instructions with reality
# 2022-04-19: gbn: more small updates for HTML5 validation
# 2022-09-05: gbn: changed to hosting on inferno.pglaf.org
# 2022-09-29: gbn: allow .xhtml; bump validator & ebm versions
# 2022-10-01: gbn: new version into production on inferno
# 2022-10-15: gbn: updated directory handling in the .zip
# 2023-07-17: gbn: add vnu.jar validation checker on input file
# 2023-07-17: gbn: vnu.jar for HTML5 only
# 2025-01-01: gbn: added --verbose to /opt/ebookmaker to get INFO messages
# 2025-01-02: gbn: added FILEDIR to env

include ("pglaf.phh"); // Marcello's functions with additions/customizations
ini_set('default_charset', 'UTF-8');

$myname ="index.php";
$mybaseurl = "https://ebookmaker.pglaf.org";
# 2022-11-29: Something changed...
# $prog = "export LC_ALL=C.UTF-8; export LANG=C.UTF-8; cd /opt/ebookmaker; /var/www/.local/bin/pipenv run ebookmaker";
$prog = "export FILESDIR=/var/tmp; export LC_ALL=C.UTF-8; export LANG=C.UTF-8; cd /opt/ebookmaker; /usr/local/bin/pipenv run ~www-data/.local/share/virtualenvs/ebookmaker-sLvSrXRz/bin/ebookmaker --verbose";
$validator = "export LC_ALL=C.UTF-8; export LANG=C.UTF-8; /usr/bin/java -jar /usr/local/bin/vnu.jar --verbose --stdout";

$pbase = "ebookmaker"; # do not show users the whole prog line.
$tmpdir = "/htdocs/ebookmaker/cache"; # this overrides pglaf.phh

$myhead="Project Gutenberg Online Ebookmaker";

?>
<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.0 Transitional//EN"
   "http://www.w3.org/TR/1998/REC-html40-19980424/loose.dtd">

<html lang="en">
<head>
   <META HTTP-EQUIV="Content-Type" CONTENT="text/html; charset=utf-8">
   <title>
<?php echo $myhead ?>
   </title>
</head>

<body>

  <h1>
<?php echo $myhead ?>
  </h1>

<?php

if (! isset($_REQUEST['make'])) {

  print "<h2>Quick Start</h2>\n";

  print "<p>This is <a href=\"https://github.com/gutenbergtools/ebookmaker\">ebookmaker</a> version 0.13.4";
  print " with <a href=\"https://validator.w3.org/nu/\">Nu HTML Checker</a> version 24.7.30 and <a href=\"https://github.com/w3c/epubcheck\">epubcheck</a> version 5.2.1.</p>";
  print "<p>Please upload a <strong>single file</strong>.  ";
  print "If your submission has more than ";
  print "one file, upload a .zip of all the needed files.  ";
  print "Any images should be in a subdirectory (i.e., folder) ";
  print "named \"images\", and cannot be omitted if they are referenced by the source.  ";

  do_form();

  print "<p>Ebookmaker will try to identify author, title, encoding and ";
  print "eBook number from your file if it includes the standard Project ";
  print "Gutenberg metadata as found in the published collection.  Otherwise, ";
  print "you can provide values. UTF-8 characters may be used for metadata. ";
  print "Missing metadata values are not usually a problem.";
  print "You must provide an encoding if you upload a plain text file, ";
  print "and this should almost always be UTF-8. </p>";

  print "<p>After your file has transferred, processing can take as long ";
  print "a few minutes for large files.</p>";

  print "<h2>Usage Details</h2>";

  print "<p>This is for the <strong>required</strong> pre-submission ";
  print "checks of Project Gutenberg eBooks. ";
  print "Project Gutenberg uses ebookmaker to generate several formats ";
  print "from a single source file. HTML5 is preferred, but earlier ";
  print "HTML versions also work, as well as plain text. If you wish ";
  print "to submit a different format, first contact the production team ";
  print "(contact information is below).</p>";

  print "<p>Submitters should use this ebookmaker site repeatedly ";
  print "during the digitization process, to ensure automated ";
  print "conformance requirements are met and to review the ";
  print "generated files to confirm they appear as expected.</p>";

  print "<p>You can test how well mobile output looks (EPUB and Kindle) ";
  print "without needing a ereader device.  Instead, try one of the many free ";
  print "browser plug-ins or ereader software such as ";
  print "<a href=\"https://calibre-ebook.com\">Calibre</a>.</p>\n";

  print "<p>HTML guidance, including procedures for HTML5 and other versions, ";
  print "may be found in the <a href=\"https://www.pgdp.net/wiki/DP_Official_Documentation:PP_and_PPV/Post-processing_HTML5\">Distributed Proofreaders Wiki</a>.</p>\n";

  print "<p>If you upload HTML version 5, the W3C's ";
  print "<a href=\"https://validator.w3.org/nu/#file\">Nu validator</a> ";
  print "is run. For previous versions of HTML, you should run the ";
  print "<a href=\"https://validator.w3.org\">legacy HTML validator</a> ";
  print "and CSS check, in addition to ebookmaker.";
 
  print "<strong>Your submission to Project Gutenberg must have no ";
  print "HTML validation errors</strong>.";

  print "<p>Once ebookmaker completes, review the \"output.txt\" file. ";
  print "Revise and reupload you work as needed. <strong>Your submission to ";
  print "Project Gutenberg must have no CRITICAL or ERROR messages from ";
  print "ebookmaker</strong>.</p>";
?>

<p>The source code for ebookmaker is <a href="https://github.com/gutenbergtools/ebookmaker">online at github</a>. The Nu HTML validator is available as a compiled <a href="https://github.com/validator">Java JAR download</a> and you can find source code via the same link.</p>

<p>Information about ebookmaker best practices for Project Gutenberg
is available at <a
href="https://www.pgdp.net/wiki/The_Proofreader's_Guide_to_EPUB">https://www.pgdp.net/wiki/The_Proofreader's_Guide_to_EPUB</a>.
</p>

<?php
  require('plaintail.inc');
  exit;
} 

// Did we get input?
if (strlen($_FILES['upfile1']['name']) == 0) {
  print "<p><font color=\"red\">Error: Ebookmaker was requested to process files, but no file ";
  print "was received.  Please try again, or send email for help.";
  print "</font></p>\n\n";

  require('plaintail.inc');
  exit;
}

// Rename uploaded file, create new output directory:
$upfile1_name = fix_filename($_FILES['upfile1']['name']);
$tmpsubdir = date ('YmdHis');
$dirname = $tmpdir . "/" . $tmpsubdir;
$newname = $dirname . "/" . $upfile1_name;
mkdir ($dirname); // where our output will go
// debug:
// print "about to rename " . $_FILES['upfile1']['name']  . " to " . $newname . "\n"; 
// rename( $_FILES['upfile1']['name'], $newname);// remove from php spool area
// print "about to rename " . $_FILES['upfile1']['tmp_name']  . " to " . $newname . "\n"; 
rename( $_FILES['upfile1']['tmp_name'], $newname);// remove from php spool area
chmod ("$newname", 0644);

// We will redirect some messages to this file, for the user to read:
$outfile = $tmpdir . "/" . $tmpsubdir . "/output.txt";

// Put a BOM at the start (it should always be UTF-8) (temporarily open as a stream):
$bom = ( chr(0xEF) . chr(0xBB) . chr(0xBF) );
if (file_put_contents($outfile, "$bom") === false) {
  print "<p color=\"red\">An error occurred opening ${outfile}.\nPlease report this.</p>\n";
  }

// Unzip the input file, if needed:
$whichmatch = preg_match("/\.zip$/", $newname);
if ($whichmatch != 0) {
  $retval = system ("/bin/echo unzipping $newname >> $outfile");
  $retval = shell_exec ("USER=www-data;LOGNAME=www-data;HOME=$newname;/usr/bin/unzip -o -U $newname -d $dirname >> $outfile");
}

// Options to ebookmaker:
$gopts=""; $basename="";

// debug:
// print "<p color=\"blue\">dirname is " . $dirname . "</p>\n";

// We'll get all files but . and ..:
$dir = new RecursiveDirectoryIterator($dirname, RecursiveDirectoryIterator::SKIP_DOTS);

// This populates array $files:
$files = new RecursiveIteratorIterator($dir);

// Order of the directory listing is arbitrary. We want to capture
// .htm, .html and .txt in priority order, taking the last of each
// we find:
$gothtm=0; $gothtml=0; $gotxhtml=0; $gottxt=0;
$basename_txt=""; $basename_htm=""; $basename_html=""; $basename_xhtml="";

foreach($files as $file){
   if ( preg_match("/^.+\.txt$/i", $file->getFileName()) ) {
      if ( ! preg_match('/output.txt/', $file->getFileName()) ){
         $basename_txt = $file->getPath()."/".$file->getFileName(); $gottxt = 1;
      }
   }
   if ( preg_match("/^.+\.htm$/i", $file->getFileName()) ) {
      $basename_htm = $file->getPath()."/".$file->getFileName(); $gothtm = 1;
   }
   if ( preg_match("/^.+\.html$/i", $file->getFileName()) ) {
      $basename_html = $file->getPath()."/".$file->getFileName(); $gothtml = 1;
   }
   if ( preg_match("/^.+\.xhtml$/i", $file->getFileName()) ) {
      $basename_xhtml = $file->getPath()."/".$file->getFileName(); $gotxhtml = 1;
   }
// debug:
// echo "<p>Loop: ".$file->getPath()."/".$file->getFileName();
}

if ( $basename_txt  ) { $basename = $basename_txt;  }
if ( $basename_htm  ) { $basename = $basename_htm;  }
if ( $basename_html ) { $basename = $basename_html; }
if ( $basename_xhtml ) { $basename = $basename_xhtml; }

// debug:
// echo "<p>basename is: ". $basename. "</p>\n";

// Make sure we found a file to operate on:
if ("$basename" == "") {
  print "<p>Sorry, but we could not identify a file ending in rst, txt, ";
  print "htm, html or xhtml. Follow this link to see what was identified.  You ";
  print "might need to give a more Unix-friendly filename (no spaces, lower ";
  print "case, no special characters). Please try again, or send email if ";
  print "you need help or things seem amiss.</p>";
  print "<p><ul>\n  <li><a href=\"$mybaseurl/cache/$tmpsubdir\">$mybaseurl/cache/$tmpsubdir</a></li>\n</ul>\n</p>";
  require('plaintail.inc');
  exit;
}

// I'm not sure this works with .txt, but it's good for HTML:
// $gopts = $gopts . "--make=epub --make=epub3 --make=kf8 --make=kindle --make=html --validate ";
$gopts = $gopts . "--make=txt.utf-8 --make=epub --make=epub3 --make=kf8 --make=kindle --make=html --validate ";
$retval = system ("/bin/echo >> $outfile ; /bin/echo Input file: $basename >> $outfile");
$retval = system ("/bin/echo >> $outfile");

// $basename = $dirname . "/" . $basename;

$gopts = $gopts . "--max-depth=3 ";
$gopts = $gopts . "--output-dir=$tmpdir" . "/" . $tmpsubdir . " ";

if (strlen($_REQUEST['mytitle'])) {
  $gopts = $gopts . "--title=" . escapeshellarg($_REQUEST['mytitle']) . " ";
}
if (strlen($_REQUEST['myauthor'])) {
  $gopts = $gopts . "--author=" . escapeshellarg($_REQUEST['myauthor']) . " ";
}
# TODO: must properly escape $_REQUEST['myencoding'] before using
#if (strlen($_REQUEST['myencoding'])) {
#  $gopts = $gopts . "--input-mediatype=\"text/plain;charset=" . $_REQUEST['myencoding'] . "\" ";
#}
if (strlen($_REQUEST['myebook'])) {
  $gopts = $gopts . "--ebook=" . escapeshellarg($_REQUEST['myebook']) . " ";
} else {
  $gopts = $gopts . "--ebook=10001 "; # Required
}

##### Figure out HTML version if needed, and generate output for user:
$not_html5 = 0;
if ($basename_html | $basename_htm) {
   # Did we get HTML5? Look at the first line:
   $tmpin = fopen ($basename, "r");
   if ($tmpin == false) {
      echo ("Error opening file $basename for validation. Exiting.");
      exit(1);
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

   # print "debug: match = $regex\ndebug: target = $line\ndebug: not_html5 = $not_html5\n"; 

   # We got HTML5. Validate it:
   if ($not_html5 == 0) {
      print "<p><font color=\"green\">Validating your uploaded file...</font>";
      $retval = system ("/bin/echo --- Validating your uploaded file >> $outfile");
      $retval = system ("$validator $basename >> $outfile 2>&1"); 
      $retval = system ("/bin/echo --- Validation complete >> $outfile");
   }
}

if ($not_html5 | ($basename_xhtml != "") ) {
   print "<p><font color=\"blue\">Your HTML does not seem to be HTML version ";
   print "5. Be sure to run the appropriate validator, probably the W3C's ";
   print "<a href=\"https://validator.w3.org\">legacy HTML &amp; CSS ";
   print "validator</a>.</font></p>";
   $retval = system ("/bin/echo --- HTML validation not done >> $outfile");
   $retval = system ("/bin/echo HTML validation not done since your source is not HTML5. >> $outfile");
   $retval = system ("/bin/echo You must use a validator such as https://validator.w3.org in addition to ebookmaker >> $outfile");
   $retval = system ("/bin/echo --- >> $outfile");
}

print "<p><font color=\"green\">Running ebookmaker</font>: "; 
print "<tt>$pbase $gopts file://$basename\n</tt>\n";

// Run ebookmaker
$retval = system ("/bin/echo >> $outfile; /bin/echo >> $outfile");
$retval = system ("/bin/echo --- Starting ebookmaker processing >> $outfile");
$retval = system ("$prog --version >> $outfile");
$retval = system ("$prog $gopts file://$basename >> $outfile 2>&1");
$retval = system ("/bin/echo --- ebookmaker complete >> $outfile");
print "</p>";

// gbn: PHP upgrade to 8.1 and a 0 error code isn't working right:
// if ($retval == 0) {
//if ($retval == "0") {  
  print "<p><font color=\"green\">Done</font>: ebookmaker has completed. This does not mean all desired output was successfully generated.  The output.txt file provides detail on the processing that occurred, and any error or informational messages.\n";
//} else {
//  print "<p>Sorry, ebookmaker ended with an error code.  Send email if this seems to be an actual problem, not just a temporary glitch or a problem with your file.</p>\n";
//}
  print "Please follow this link to view the input file you uploaded ";
  print "(possibly it was renamed) and any output files.  This link ";
  print "will stay available for around three days:";
  print "<ul>\n  <li><a href=\"$mybaseurl/cache/$tmpsubdir\">$mybaseurl/cache/$tmpsubdir</a></li>\n</ul>\n</p>";

  print "<h2>Submit another:</h2>\n";

 do_form();

print "<p><a href=\"$myname\">Return to main page</a>\n";

require('plaintail.inc');

##### Print the form
function do_form() {
print "<blockquote><form enctype=\"multipart/form-data\" method=\"POST\" accept-charset=\"UTF-8\" action=\"$myname\">\n";
  print "<input type=\"file\" name=\"upfile1\"> Your file (any of: zip/txt/htm/html)\n";

  print "<br><input type=\"text\" size=\"50\" name=\"mytitle\" value=\"UnknownTitle\"> eBook title";
  print "<br><input type=\"text\" size=\"50\" name=\"myauthor\" value=\"UnknownAuthor\"> eBook author";
  #print "<br><input type=\"text\" size=\"20\" name=\"myencoding\" value=\"\"> File encoding (us-ascii, iso-8859-1, utf-8, etc.; mandatory for plain text files)";
  print "<br><input type=\"text\" size=\"10\" name=\"myebook\" value=\"10001\"> eBook number (must be an integer)";

  print "<br><input type=\"submit\" value=\"Make it!\" name=\"make\">\n";
  print "</form></blockquote>\n\n";
}
exit;

?>
