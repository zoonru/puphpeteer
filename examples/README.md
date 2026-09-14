# Examples

Build the prepared environment:

```sh
docker compose build php chrome
docker compose run --rm chrome php examples/01_page_open.php
docker compose run --rm chrome php examples/02_page_screenshot.php
docker compose run --rm chrome php examples/04_form_intercept.php
```

Examples 01, 02 and 04 use `launch()` and local HTML fixtures. Example 04 starts
waiting for a form POST before clicking the button, prints the captured data and
aborts the request. No HTTP server is required.

For the remote browser, the base PHP image is sufficient:

```sh
docker compose up -d browserless
docker compose run --rm php php examples/03_browserless.php
docker compose down
```

Compose supplies `BROWSER_WS`. Outside Compose, set it to your browserless endpoint.
The extension is enabled through PHP ini; no extension-path variable is needed.
Images contain the source and dependencies; rebuild after edits. Screenshot output
is inside the container; use `docker compose run --name screenshot chrome php
examples/02_page_screenshot.php`, then `docker cp screenshot:/app/example.png .`
and `docker rm screenshot` to keep it.
