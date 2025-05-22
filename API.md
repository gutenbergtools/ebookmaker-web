# ebookmaker-web API

This web interface to ebookmaker allows a more automation-friendly access
through a simple HTML/JSON API. By including an `out` form field with value
`json` in the POST, a JSON object is returned with structured access to the
ebookmaker results.

## Fields

The API fields largely match the form on the web interface.

Required parameters:
* `out` - must be `json` to get a JSON response
* `file` - uploaded file

Optional parameters:
* `ebook_number` - ebook number
* `title` - title
* `author` - author
* `make_formats` - comma-separated ebookmaker formats to "make"
  (will return most formats by default)
* `validate_html` - controls if HTML uploads are validated before being
  run through ebookmaker (defaults to False)
* `debug_verbosity` - output DEBUG-level messages from ebookmaker
  (defaults to False)

## Example

An example using python:
```python
import json
import os.path

import requests

# file can be a txt, html, or zip file, just like the web version
file = "/tmp/A_Thoughtles.html"

with open(file, "rb") as fileobj:
    response = requests.post(
        "https://ebookmaker.pglaf.org/",
        files={
            # file to upload
            "file": (os.path.basename(file), fileobj)
        },
        data={
            # required params
            "out": "json",
            # optional params
            "make_formats": "epub,epub3",  # comma-separated list
            "validate_html": True,
            "debug_verbosity": True,
            "ebook_number": 99999,
            "title": "Oh look, an egg!",
            "author": "E. Bunny",
        }
    )

    # response codes will either be 200 (everything ran successfully) or
    # 500 (something went Very Wrong)
    print(response.status_code)

    # all responses are guaranteed to be JSON.
    # error responses will contain an "error" field with more details
    result = response.json()
    print(json.dumps(result, indent=2))
```

Outputs the following:
```json
200
{
  "output_dir": "https://ebookmaker.pglaf.org/cache/20250412213215",
  "output_log": "https://ebookmaker.pglaf.org/cache/20250412213215/output.txt",
  "ebookmaker_log": "https://ebookmaker.pglaf.org/cache/20250412213215/ebookmaker.log",
  "output_artifacts": {
    "epub.noimages": "https://ebookmaker.pglaf.org/cache/20250412213215/99999-epub.epub",
    "epub.images": "https://ebookmaker.pglaf.org/cache/20250412213215/99999-images-epub.epub",
    "epub3.images": "https://ebookmaker.pglaf.org/cache/20250412213215/99999-images-epub3.epub"
  }
}
```
