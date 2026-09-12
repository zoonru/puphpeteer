# Examples

Examples 01, 02 and 04 launch the Puppeteer-managed Chrome directly through
`launch()`. The HTML fixtures are local files, so no Node.js process or HTTP
server is required.

With the QuickJS extension loaded:

```sh
export QUICKJS_EXTENSION=/absolute/path/to/libphp_quickjs.dylib
php -d "extension=$QUICKJS_EXTENSION" examples/01_page_open.php
php -d "extension=$QUICKJS_EXTENSION" examples/04_form_intercept.php
```

Set `EXAMPLE_URL` or `FORM_EXAMPLE_URL` to use another page. The form example
demonstrates request interception and concurrent waiting for the POST request;
the reserved endpoint is aborted after its data is captured.

For the Browserless example, start a WebSocket endpoint separately and use
`connect()`:

```sh
docker run --rm --name puphpeteer-browserless -p 3000:3000 \
  browserless/chrome:latest

export BROWSER_WS=ws://127.0.0.1:3000
php -d "extension=$QUICKJS_EXTENSION" examples/03_browserless.php
```

The static files are [index.html](pages/index.html) and [form.html](pages/form.html).
