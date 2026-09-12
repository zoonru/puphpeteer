# Release validation

The automated gate is available locally:

```sh
QUICKJS_EXTENSION=/absolute/path/to/libphp_quickjs.dylib composer test-release
QUICKJS_EXTENSION=/absolute/path/to/libphp_quickjs.dylib BENCH_TRIALS=5 composer benchmark
```

Use `.so` on Linux. If another task is rebuilding the extension, copy the library
to a stable temporary path first and point `QUICKJS_EXTENSION` at that snapshot. Run `composer install` and `npm ci` first, following the
[extension installation instructions](../README.md#build-and-install-php-quickjs).
Chrome comes from the package-managed installation. No system Chrome fallback is
used. The extension path is required; a missing or incompatible extension fails
validation instead of skipping its tests.

The PHP runner executes unit/generator tests, the native extension contract,
browser compatibility smoke tests and all three legacy examples, then 50 repeated
browser-context cycles. Each cycle navigates two pages concurrently, clicks and
reads DOM content through the legacy API, adds/removes listeners, creates and
disposes ten JS handles, and checks recovery after a JavaScript exception. Every
ten cycles it also renders PNG and PDF output. It verifies that contexts return
to their initial count and released PHP handles and removed event callbacks become unreachable. Transport
object, callback, pending-request and timer registry counts must return to their
first-cycle baseline after each cleanup. PHP memory
samples are printed for trend inspection; this is not a proof of absence of all
native leaks, and no machine-dependent absolute RSS threshold is asserted.

For extended soak testing:

```sh
QUICKJS_EXTENSION=/absolute/path/to/libphp_quickjs.so RELEASE_CYCLES=1000 RELEASE_TIMEOUT=3600 composer test-release
```

The timeout bounds the repeated workload and terminates its child process tree.
Unit/contract/smoke stages have separate deadlines. The native integration suite
also checks that transient `JsFunction` cache entries are reclaimed while live
function identity is preserved. A release also requires
`composer psalm`, `composer verify-php`, `npm run build:check` and reviewing the
[API coverage report](../upstream/coverage.json) for the intended application.

## Repeatable performance measurements

`composer benchmark` supports macOS and Linux (`ps` and `/usr/bin/time` required;
Linux needs GNU time). Each trial starts an independent managed Chrome and uses
the same local fixture. The default is five trials of 1,000 evaluates, 100 string
returns of 64 KiB, 20 concurrent waits of 25 ms and 20 navigations. Override
`BENCH_TRIALS` and `BENCH_ITERATIONS` with positive integers.

Bundle and extension SHA-256 hashes are checked for each trial; changing the
extension or bundle during a trial fails
the benchmark. The runner prints JSON measurements to the process output. Compare
medians from several trials
on the same hardware, Chrome, PHP, extension revision and dependency set. CPU
and sampled RSS exclude Chrome; the 25 ms sampler can miss short peaks. Sampler
errors, no steady-state samples or unparseable time output fail the benchmark.
Resource figures are diagnostic observations, not portable performance budgets.

## CI and remaining release blockers

Unit/type/generation checks run on every push and pull request for PHP 8.4/8.5.
The extension/browser job builds the fork for Linux/macOS and both PHP versions,
runs the release gate and stores three-trial benchmark artifacts.

**The compatible extension branch has not been published.** At the time of this
change, `git ls-remote` returns no `async-jobs-fibers` branch. The runtime job now
fails closed until the repository variable `PHP_QUICKJS_REF` contains a
published, compatible full commit SHA. Alternatively, trigger the workflow
manually with the `extension-ref` input pointing at such a SHA. The job always
runs the contract tests; merely loading an older extension is insufficient.
Publishing/configuring that revision and obtaining green Linux/macOS runs is a
release prerequisite, not something local macOS validation establishes.

**Browser downloader dependencies need updating before release.** The current
`@puppeteer/browsers` 2.11.2 / `puppeteer-core` 24.36.1 dependency chain uses
`extract-zip` 2.0.1. `npm audit` reports three high-severity affected packages for
symlink archive path traversal ([GHSA-jmr9-qjv8-65gv](https://github.com/advisories/GHSA-jmr9-qjv8-65gv),
[GHSA-7pqw-9j4j-h8q3](https://github.com/advisories/GHSA-7pqw-9j4j-h8q3)). This affects
browser archive extraction during installation, including malicious archives;
it does not execute in the PHP client. The available audit remediation requires
upgrading the Puppeteer/browser installer major versions. Do that together with
the planned Puppeteer/Chrome update, regenerate the API/bundle, rerun this gate
and `npm audit`. Do not suppress the finding or treat a passing functional gate
as security approval.

## Local validation — 2026-09-12

macOS arm64, PHP 8.5.7 NTS and managed Chrome 144.0.7559.96: the complete
release gate passed (33 unit/generator tests, 119 assertions; native integration and the follow-up compiled-bundle regression:
26 tests, 112 assertions; browser compatibility, plugins, lifecycle
and examples). The 50-cycle workload completed in 17.71 seconds: 100 pages,
500 disposed handles, five PNGs and five PDFs. Registry counts returned to their
baseline after every cycle; peak PHP allocated memory was 6 MiB.

Three benchmark trials produced these medians:

| Measurement | Median |
| --- | ---: |
| 1,000 evaluates | 290.15 ms |
| 100 returns of 64 KiB | 69.12 ms |
| 20 concurrent waits of 25 ms | 28.51 ms |
| 20 navigations and title reads | 1,188.88 ms |
| Total client process tree CPU | 450 ms |
| Sampled peak client process tree RSS | 78.05 MiB |

Extension binary SHA-256:
`7370209ee5a8aeb0c45a6f97f81b263a25e6d43b0850c032327d0f22d382ab7a`.
Bundle SHA-256:
`d49487e9d06331cee0ca5e9c4815eb37a2b2c9e99c1a3ff0fccba3a7f9b42168`.
These are local observations; Linux/PHP 8.4 and the hosted CI matrix remain
unverified until the published extension revision is configured.
