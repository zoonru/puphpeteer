# Contributing

The main implementation runs Puppeteer in the php_quickjs extension. See the
root README for the implementation plan and current limitations.

From the repository root:

```sh
composer update --prefer-stable --ignore-platform-req=ext-php_quickjs
npm ci
npm run build
php vendor/bin/phpunit
composer psalm
```

The extension exception above is for unit tests and Psalm only. Integration
requires the async/native-bridge php-quickjs fork with `dispatch()`.
Run `php bin/console test integration` with that extension loaded; see the
[extension contract](../README.md#extension-contract) for boundary tests. For browser tests,
run `docker compose build php chrome`, then `docker compose run --rm chrome php bin/console test browser`. The Chrome image sets `PUPPETEER_EXECUTABLE_PATH` to its installed browser.
Install project dependencies through the Compose commands in the root README. Smoke and benchmark runners use PHP.
See [release validation](../README.md#release-validation) for runner details.

Commit package-lock.json and resources/puppeteer.js when
changing JS sources or build dependencies. Run npm run build to regenerate them;
CI uses npm run build:check to verify the checked-in files without modifying them.
composer.lock remains local: CI installs latest supported dependencies on each PHP
version and runs unit tests and Psalm. Functional tests and the browserless example run in Docker on every push and PR. Load testing and benchmark run only when a GitHub Release is published.
