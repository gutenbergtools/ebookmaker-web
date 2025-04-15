# ebookmaker-web
This is the simple web-based front-end for ebookmaker found at
https://ebookmaker.pglaf.org/

## Deployment

Clone this repo somewhere accessible to your web server.

Create a place for the ebookmaker output. This needs to be accessible from
the web and writeable by the web server. A common location is `./cache/`.

Install [ebookmaker](https://github.com/gutenbergtools/ebookmaker) and its
dependencies and make it accessible to run as the web server.

Configure `ebookmaker-web` by creating a `config.php` file in the base
directory setting the following variables to match your environment.
```php
<?php
// Directory to place ebookmaker output
$tmpdir = "/htdocs/ebookmaker/cache";

// URL to access ebookmaker output. $mybaseurl/cache/ should point to $tmpdir
// and be accessible from the web.
$mybaseurl = "https://ebookmaker.pglaf.org";

// Command to run ebookmaker
$prog = "/path/to/venv/bin/ebookmaker";

// Command to run HTML validator
$validator = "/usr/bin/java -jar /usr/local/bin/vnu.jar --verbose --stdout";

// tool versions to display to the user
$ebookmaker_version = "0.13.4";
$validator_version = "24.7.30";
$epubcheck_version = "5.2.1";
```

Create a crontab to clean up the contents of `$tmpdir`, periodically deleting
directories and files older than a certain period of time (eg 3 days). For example,

10 5 * * * /usr/bin/touch /data/htdocs/ebookmaker/cache/index.htm ; /usr/bin/find /data/htdocs/ebookmaker/cache/ -ctime +3 -name '*' -print | /usr/bin/xargs /bin/rm -rf {} \;

Note that the empty index.htm is so the web server will not expose the entire
cache directory tree. 
