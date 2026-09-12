# Test suites

`composer test` runs PHP-only PHPUnit unit tests without Chrome or the QuickJS
extension. Native extension stubs are static-analysis declarations only and are
never loaded as runtime substitutes.

Client codec/lifecycle tests construct a client without its constructor to isolate
PHP behavior; they do not claim to verify dispatch, Promise pumping or the
browser transport. `composer test-integration` checks the native batch bridge
with the compatible extension loaded; missing extension support is an error.
The [extension contract](../docs/extension-contract.md) defines supported values,
batch limits, error recovery, callback ownership and Fiber boundaries. Run it
against the release build from the fork, not a separately patched prototype:

```sh
php -n -d "extension=$QUICKJS_EXTENSION" vendor/bin/phpunit tests/Integration
```
`composer test-browser` runs the PHP smoke and runtime lifecycle suites and the local examples. It
requires the optimized extension (`QUICKJS_EXTENSION`), the built JavaScript bundle and the project
Chrome installed under `node_modules`. Each browser script starts Chrome through the public
`launch()` API and uses the static fixtures in `examples/pages`; no HTTP fixture server is started.
`PUPPETEER_EXECUTABLE_PATH` can explicitly override the browser; system installations are never
selected automatically. See the [installation instructions](../README.md#requirements-and-installation).
Missing prerequisites must not be treated as an integration pass.

`composer test-release` adds repeated browser workloads and resource-retention
checks to the PHP suites; see [release validation](../docs/release.md).

`composer benchmark` launches Chrome through the public `launch()` API and measures a separate PHP
process with `/usr/bin/time` and `ps`; the parent Chrome process is excluded. `BENCH_TRIALS` defaults
to 5 and `BENCH_ITERATIONS` to 1000.
`PHP_BIN` overrides the PHP executable used by isolated test and benchmark processes.

`composer test-generator` runs PHP generator fixtures: signatures, omitted and
explicit-null arguments, variadics, full regeneration and removal of obsolete methods.
These fixtures also run as part of the default PHPUnit suite. `npm run test-generator`
checks extraction/type mapping and upstream method/class removal, including read-only checks; `composer verify-php` checks generated artifacts.

`composer psalm` checks `src/`, `tools/php/`, `tests/Generator/`, `tests/Unit/`,
`tests/Integration/` and `tests/Support/` at level 3 without a baseline. The browser smoke script
is executed separately and is not included in static analysis.
