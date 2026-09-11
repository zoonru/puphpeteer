# Contributing

The main implementation runs Puppeteer in the php_quickjs extension. See the
root README for the implementation plan and current limitations.

From the repository root:

```sh
composer update --prefer-stable --ignore-platform-req=ext-php_quickjs
npm ci
npm run build
composer check
```

The extension exception above is for unit tests and Psalm only. Integration
requires the async/native-bridge php-quickjs fork with `dispatch()`.
Run `composer test-integration` with that extension loaded. For browser tests,
set `QUICKJS_EXTENSION` and `CHROME_BIN`, then run `npm run test-smoke`.
See `docs/quickjs.md` for runner details.

Commit package-lock.json and resources/puppeteer.js + resources/manifest.json when
changing JS sources or build dependencies. Run npm run build to regenerate them;
CI uses npm run build:check to verify the checked-in files without modifying them.
composer.lock remains local: CI installs latest supported dependencies on each PHP
version and runs unit tests and Psalm. Browser integration is a separate local check.
