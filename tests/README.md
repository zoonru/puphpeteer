# Test suites

`composer test` runs PHP unit/generator, native integration and browser tests, local examples, JS tests, Psalm, generated API and bundle checks. It requires QuickJS, Chrome and npm. For PHP-only unit/generator tests without Chrome or QuickJS, run `php vendor/bin/phpunit`. Native extension stubs are static-analysis declarations only and are
never loaded as runtime substitutes.

Tests are separated by scope within each suite:

| Suite | Puppeteer | Shared |
| --- | --- | --- |
| `Unit` | API, aliases, Chrome launcher | Client, filesystem, JS functions, process runner |
| `Integration` | Shipped bundle, streams, payloads, browser log flushing | Native extension contract and transport with test dispatchers |
| `Browser` | Chrome scenarios and smoke runner | — |
| `Plugins` | JS adapter, Puppeteer filesystem and protocol streams | — |
| `Generator` | Upstream extraction, mapping, aliases and version badges (JS) | PHP emitter and fixtures |
| `Release` | Repeated Chrome workload and release runner | — |
| `Support` | Bundle instrumentation | Process runner |

Directories use `<suite>/Puppeteer/` and `<suite>/Shared/`; future Playwright tests belong in `<suite>/Playwright/`. PHPUnit and Psalm discover nested PHP tests automatically. Existing Composer/npm commands remain the entry points.

Client codec/lifecycle tests construct a client without its constructor to isolate
PHP behavior; they do not claim to verify dispatch, Promise pumping or the
browser transport. `php bin/console test integration` checks the native batch bridge
with the compatible extension loaded; missing extension support is an error.
The [extension contract](../README.md#extension-contract) defines supported values,
batch limits, error recovery, callback ownership and Fiber boundaries. Run it
against the release build from the fork, not a separately patched prototype:

```sh
docker compose run --rm php php vendor/bin/phpunit tests/Integration
```
`php bin/console test browser` runs the PHP smoke and runtime lifecycle suites and the local examples. It
requires the extension loaded through PHP ini, the built JavaScript bundle and the project
Chrome from `PUPPETEER_EXECUTABLE_PATH` (set by the Chrome image), or the managed installation in `.chrome` or `PUPPETEER_CACHE_DIR` outside Docker. Each browser script starts Chrome through the public
`launch()` API and uses the static fixtures in `examples/pages`; no HTTP fixture server is started.
`PUPPETEER_EXECUTABLE_PATH` can explicitly override the browser; system installations are never
selected automatically. See the [installation instructions](../README.md#requirements-and-installation).
Missing prerequisites must not be treated as an integration pass.

`composer test-release` adds repeated browser workloads and resource-retention
checks after `composer test`; see [release validation](../README.md#release-validation).

`composer benchmark` launches Chrome through the public `launch()` API and measures a separate PHP
process with `/usr/bin/time` and `ps`; the parent Chrome process is excluded. `--trials` defaults
to 5 and `--iterations` to 1000.
Child processes use the same PHP binary and ini configuration as the runner.

`php vendor/bin/phpunit --testsuite Generator` runs PHP generator fixtures: signatures, omitted and
explicit-null arguments, variadics, full regeneration and removal of obsolete methods.
These fixtures also run as part of the default PHPUnit suite. `npm run test-generator`
checks extraction/type mapping and upstream method/class removal, including read-only checks; `composer verify-php` checks generated artifacts.

`composer psalm` checks `src/`, `tools/php/`, `tests/Generator/`, `tests/Unit/`,
`tests/Integration/` and `tests/Support/` at level 3 without a baseline. The browser smoke script
is executed separately and is not included in static analysis.

## Large payload regressions and performance

`tests/Integration/Puppeteer/LargePayloadTest.php` uses the real shipped QuickJS bundle:
indexed UTF-8 strings (including NUL and JSON escapes), all-byte binary data,
PHP → JS → PHP round trips, 2 MiB boundary cases, 4/8/12 MiB results,
32 MiB streams, uneven chunks and concurrent readers. Length and SHA-256 must
match; chunks must not exceed 64 KiB. Stream lifecycle/fairness tests also cover
cancellation, early close, producer failure and an event loop tick between reads.
`tests/Browser/Puppeteer/large-payload.php`, included in the browser suite, checks real
`Page::content()` and `JsFunction` results, including concurrent responses.
The fixture hides its large text to measure transport rather than text layout.

The extension limits a single bridge value to 16 MiB **including conversion
overhead**. Oversized results must fail explicitly, without silently truncating
or poisoning the client. Streams can exceed that limit. Large incoming CDP JSON
is delivered in UTF-8-safe chunks: escaped JSON may be larger than its result.
This does not make `content()` / `evaluate()` streaming APIs: final JSON parsing
and returning a whole string still require synchronous work and memory.

```sh
docker compose run --rm php php vendor/bin/phpunit tests/Integration/Puppeteer/LargePayloadTest.php
docker compose run --rm chrome php tests/Browser/Puppeteer/large-payload.php
docker compose run --rm chrome php benchmarks/payloads.php --sizes=4,8,12 --trials=3
docker compose run --rm php php benchmarks/payloads.php --mode=stream --sizes=32 --trials=3
```

The dedicated benchmark reports mean time, PHP + embedded QuickJS CPU,
throughput and maximum event loop delay measured with a 1 ms timer. Timing
includes SHA-256 verification (incremental for streams); the PHP hash baseline
separates verification cost from bridge overhead. Payload creation, browser
startup and one warm-up per case are excluded. Chrome CPU is excluded. Use
`--mode=bridge|browser|stream|all` and `--json` for machine-readable output on
stdout. No benchmark artifacts are written. Compare runs on the same environment;
absolute timing is deliberately not a CI assertion. The older 64 KiB benchmark
remains unchanged for historical comparisons.

## Failure and installation regressions

The browser suite checks launch options, slowMo, WebSocket loss with concurrent requests,
Chrome crashes, and preservation of a supplied user profile. Unit tests exercise forced
process-tree shutdown; logging integration tests cover a slow, absent and closed stderr reader.
The PHP integration test `Puppeteer/InstallationTest.php` creates an isolated Composer consumer
without dev dependencies. It checks application install/update hooks, the autoloader, vendor CLI
and the exact browser path under the application root. An executable fixture replaces an already
installed Chrome; the test does not download or launch a browser. ENV handling is covered by unit tests.

Browserless authentication and server session expiry are checked separately (also in CI):

```sh
docker compose up -d browserless
docker compose run --rm php php tests/Browser/Puppeteer/browserless.php
```

Release retention checks include streams, filesystem handles and pending log messages/bytes,
in addition to object, callback, request and timer registries.
