# Release validation

The automated gate is available locally:

```sh
docker compose run --rm chrome composer test-release
docker compose run --rm chrome composer benchmark -- --trials=5
```

Build the prepared environment with `docker compose build php chrome`.
The extension is enabled through PHP ini. A missing or incompatible extension fails validation.
The Chrome image pins the browser and extension source; rebuild the image after code changes.

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
docker compose run --rm chrome composer test-release -- --cycles=1000 --timeout=3600
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
`--trials` and `--iterations` with positive integers.

The bundle SHA-256 is checked for each trial; changing it during a trial fails
the benchmark. Keep the Docker image fixed to use the same extension build. The runner prints JSON measurements to the process output. Compare
medians from several trials
on the same hardware, Chrome, PHP, extension revision and dependency set. CPU
and sampled RSS exclude Chrome; the 25 ms sampler can miss short peaks. Sampler
errors, no steady-state samples or unparseable time output fail the benchmark.
Resource figures are diagnostic observations, not portable performance budgets.

## CI and remaining release blockers

Every push, pull request and manual dispatch runs the functional suite:

- `unit-and-types`: PHPUnit and Psalm directly on GitHub runners for PHP 8.4 and 8.5, with Composer download caching.
- `javascript`: bundle reproducibility, JS generator tests, plugin tests and generated PHP drift checks. Chrome downloading is disabled in this job.
- `docker`: PHP 8.4 and 8.5 images, unit/generator tests, extension contract tests, browser tests, local examples and the browserless example.

Jobs are independent and matrix failures do not cancel other versions. Runtime
coverage here is Linux amd64; macOS compatibility still requires a separate native run.

BuildKit caches intermediate layers separately for each image and PHP version.
The extension's Rust and PHP tests run when its build layer is rebuilt; project
functional tests run on every workflow execution, including image cache hits.
The published extension SHA is pinned in `Dockerfile`; no repository variable
or secret is needed. Update that build argument when adopting a newer fork revision.

Publishing a GitHub Release (`release: published`, including prereleases) runs
the full release gate with 50 workload cycles and a 600-second workload timeout,
then five benchmark trials of 1,000 evaluations on each PHP version. These extra
checks do not run on push, PR or manual dispatch. The release event occurs after
publication; this workflow does not block publication itself.
Benchmark has no performance thresholds on shared runners; use a controlled
host for performance comparisons.

Before release, obtain green image builds and runtime checks on the target
platforms and review the current dependency audit. Cached image builds do not
replace dependency or platform validation.

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
