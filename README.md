# PuPHPeteer

<img src="https://user-images.githubusercontent.com/817508/100672192-dd258500-3361-11eb-845f-e8b5109752e4.png" style="max-width:100%;" width="190px" align="right">

**English** | [Русский](README-RU.md)

A [Puppeteer](https://github.com/puppeteer/puppeteer) bridge for PHP. Original Puppeteer runs inside the PHP process through **php-quickjs**; Amp handles WebSocket transport, timers and PHP callbacks. Browser operations do not require a Node.js process.

This version is **under development and has not been released**. Generated wrappers cover part of Puppeteer's API; bundled stealth and custom plugin support are available within the documented compatibility limits.

## Contents

- [Usage](#usage)
- [Requirements and installation](#requirements-and-installation)
- [Build and install php-quickjs](#build-and-install-php-quickjs)
- [Use with browserless](#use-with-browserless)
- [Notable differences from Puppeteer](#notable-differences-from-puppeteer)
- [Puppeteer plugins](#puppeteer-plugins)
- [IDE support and API generation](#ide-support-and-api-generation)
- [Upgrade from v2](#upgrade-from-v2)
- [Interactive CLI](#interactive-cli)
- [Development](#development)
- [Implementation plan](#implementation-plan)
- [License](#license)
- [Logo attribution](#logo-attribution)

## Usage

Navigate to a page and save a screenshot:

```php
require 'vendor/autoload.php';

use Nesk\Puphpeteer\Puppeteer;

$puppeteer = new Puppeteer();
$browser = $puppeteer->launch();
try {
    $page = $browser->newPage();
    $page->goto('https://example.com');
    $page->screenshot(['path' => 'example.png']);
} finally {
    $browser->close();
}
```

Evaluate JavaScript in the page, using the same `$page` before closing the browser:

```php
use Nesk\Puphpeteer\JsFunction;

$dimensions = $page->evaluate(JsFunction::createWithBody('
    return {
        width: document.documentElement.clientWidth,
        height: document.documentElement.clientHeight,
        deviceScaleFactor: window.devicePixelRatio
    };
'));

printf('Dimensions: %s', print_r($dimensions, true));
```

See also the runnable [examples](examples/), including [form submission interception with concurrent request waiting](examples/04_form_intercept.php). Their [run instructions](examples/README.md) use the bundled static pages; browserless is a separate Docker example.

## Requirements and installation

- PHP **8.4+**, Composer and the compatible [**php_quickjs fork**](https://github.com/xtrime-ru/php-quickjs) with `Js\Callback::dispatch()` and `__quickjsEmit`. An upstream build without these bridge APIs is insufficient.
- Chrome for local launches, or a remote Chrome exposing a browser WebSocket endpoint.
- Node.js **22+** and npm for installing the bundled browser and development tools. The PHP client and test runners do not execute Node.js.

Build and enable the extension [as described below](#build-and-install-php-quickjs) before installing PHP dependencies.

The QuickJS version is not published yet. Work from a checkout of this branch:

```sh
composer install
npm ci
```

Load the compatible extension in your PHP configuration before running the client. Composer checks the extension's presence; the client checks the required bridge methods. The JS bundle is committed in `resources/`, so normal use does not require rebuilding it. If you use this checkout as a Composer dependency of another application, run the npm setup in the package directory; PHP also finds the managed browser there.

`npm ci` downloads the matching Chrome for Testing into `node_modules/.puphpeteer/` through its postinstall script. Run `npm run browser:install` to repeat the installation.

By default, `launch()` finds that managed browser in the package or an ancestor application directory; it does not search system browsers. Override it with Puppeteer's standard environment variable:

```sh
export PUPPETEER_EXECUTABLE_PATH=/absolute/path/to/chrome
```

An explicit `launch(['executablePath' => '/absolute/path/to/chrome'])` takes precedence. The legacy `CHROME_BIN` variable is also accepted when `PUPPETEER_EXECUTABLE_PATH` is unset. Installing or downloading Chrome is a separate setup step, never an implicit action of `launch()`.

## Build and install php-quickjs

Use our [php-quickjs fork](https://github.com/xtrime-ru/php-quickjs), including the direct bridge changes (`dispatch` and `__quickjsEmit`). These changes must be present in your checkout; upstream release binaries do not provide the required API.

The reviewed changes are currently on the local `async-jobs-fibers` branch and have not been pushed. For now, build from that local checkout; cloning the remote default branch is insufficient. Once this branch is published, obtain it with:

```sh
git clone --branch async-jobs-fibers https://github.com/xtrime-ru/php-quickjs.git
```

Building on Linux or macOS requires **Rust 1.96+** with Cargo, a C compiler and clang/libclang, and **PHP 8.4+ NTS development headers** with `php-config`. `PHP` and `PHP_CONFIG` must refer to the same PHP installation. The binary must match the deployment OS, architecture, PHP minor version and thread-safety mode. QuickJS is bundled; `phpize` is not needed.

```sh
cd /path/to/php-quickjs
export PHP="$(command -v php)"
export PHP_CONFIG="$(command -v php-config)"
cargo build --release --locked

case "$(uname -s)" in
    Darwin) export QUICKJS_EXTENSION="$PWD/target/release/libphp_quickjs.dylib" ;;
    Linux) export QUICKJS_EXTENSION="$PWD/target/release/libphp_quickjs.so" ;;
esac
```

Use a release build for performance. If libclang is not found, set `LIBCLANG_PATH` to the directory containing its shared library. Verify the actual bridge, not only that PHP loads the extension:

```sh
"$PHP" -n -d "extension=$QUICKJS_EXTENSION" <<'PHP'
<?php
if (!extension_loaded('php_quickjs') || !method_exists(Js\Callback::class, 'dispatch')) {
    throw new RuntimeException('The PuPHPeteer-compatible php-quickjs fork is required.');
}
$js = new QuickJS();
$callback = $js->eval('(value) => { __quickjsEmit("check", value); }');
if ($callback->dispatch([42])['messages'] !== [['check', 42]]) {
    throw new RuntimeException('QuickJS bridge check failed.');
}
echo "QuickJS bridge OK\n";
PHP
```

For persistent installation, keep the binary at a stable absolute path (or copy it into the directory reported by `php-config --extension-dir`). Find the active CLI configuration and print the line to add:

```sh
php --ini
printf 'extension=%s\n' "$QUICKJS_EXTENSION"
```

Add that `extension=/absolute/path/...` line once to `php.ini` or a scanned `.ini` file. Configure the PHP-FPM SAPI separately if used, and restart its workers. Verify the configured CLI with `php --ri php_quickjs`; the bridge check above can then also run without `-n -d ...`. The `QUICKJS_EXTENSION` variable is used by our test runners; normal PHP applications load the extension through PHP configuration.

Return to the PuPHPeteer directory and run `composer install`, `npm ci`, then `composer test-browser` with `QUICKJS_EXTENSION` still exported. See the fork's [build documentation](https://github.com/xtrime-ru/php-quickjs/blob/main/docs/install.md) and [PuPHPeteer test details](docs/quickjs.md).

## Use with browserless

Connect to a browserless instance using its browser WebSocket URL:

```php
$puppeteer = new Nesk\Puphpeteer\Puppeteer();
$browser = $puppeteer->connect([
    'browserWSEndpoint' => getenv('BROWSER_WS'),
]);
try {
    $page = $browser->newPage();
    $page->goto('https://example.com');
    $page->screenshot(['path' => 'example.png']);
} finally {
    $browser->disconnect();
}
```

Use the URL and authentication options supplied by your browserless deployment. For a Chrome debugging HTTP endpoint, use `connect(['browserURL' => 'http://localhost:9222'])` instead. `disconnect()` detaches the client; `close()` closes the browser. See [the browserless example](examples/03_browserless.php).

## Notable differences from Puppeteer

### Instantiate Puppeteer

Use `new Puppeteer()` in place of JavaScript's Puppeteer import. `launch()` starts Chrome from PHP; `connect()` attaches to an existing browser. The original Puppeteer logic executes in embedded QuickJS.

### Promises are awaited automatically

Public methods return their result or throw an exception directly. Properties use normal PHP syntax, for example `$page->keyboard->press('Enter')`. Futures remain internal to the transport. Run independent operations concurrently with Amp:

```php
$results = Amp\Future\await([
    Amp\async(fn() => $firstPage->title()),
    Amp\async(fn() => $secondPage->title()),
]);
```

### Some methods have PHP aliases

PHP cannot represent Puppeteer's `$` method names:

| Puppeteer | PHP |
| --- | --- |
| `$` | `querySelector` |
| `$$` | `querySelectorAll` |
| `$eval` | `querySelectorEval` |
| `$$eval` | `querySelectorAllEval` |

```php
$divs = $page->querySelectorAll('div');
$headings = $page->querySelectorAll('::-p-xpath(//h2)');
```

### JavaScript functions use JsFunction

Pass a complete function source, or build it using the compatible factories:

```php
$pageFunction = new JsFunction('(element) => element.textContent');
$pageFunction = JsFunction::createWithParameters(['element'])
    ->body('return element.textContent;');
```

`JsFunction` executes in JavaScript: in the browser for `evaluate()`, in QuickJS for Puppeteer events. PHP `Closure` callbacks execute in PHP. Event methods `on()`, `once()` and `off()` preserve the identity of the same handler object; PHP handlers can call client methods and suspend through Amp.

### Catch exceptions directly

No `->tryCatch` modifier is required:

```php
try {
    $page->goto('invalid_url');
} catch (\RuntimeException $exception) {
    // Handle the error; a JavaScript error does not by itself close the client.
}
```

## Puppeteer plugins

Register bundled plugins before launching or connecting:

```php
$puppeteer = (new Puppeteer())->use('stealth');
$browser = $puppeteer->launch(['headless' => true]);
```

The bundle includes upstream stealth modules and an adapter for puppeteer-extra
hooks. Custom plugins are registered at build time. Node APIs are unavailable
inside QuickJS; the old `js_extra` configuration remains unsupported.
See [plugin configuration, custom bundles and compatibility limits](docs/plugins.md),
including popup first-document timing and the locale adaptation.

## IDE support and API generation

Real PHP classes, methods, property getters, signatures and PHPDoc are generated from the pinned Puppeteer declarations. PhpStorm completion works directly from these classes; Psalm checks their types. JS Promise return types become their resolved PHP types.

Coverage is partial: see [the coverage report](upstream/coverage.json) for skipped declarations and [the generator contract](upstream/README.md) for type limitations. Declaration coverage does not establish runtime support for every method.

```sh
composer update-php
composer verify-php
```

The first command regenerates wrappers and compatibility aliases; the second checks them without changing files. Manual edits to generated files are overwritten. Classes, methods and aliases removed from upstream also disappear from the generated output.

## Upgrade from v2

Compatibility is **best effort**. Canonical classes now live in `Nesk\Puphpeteer`. Composer automatically loads generated aliases for `Nesk\Puphpeteer\Resources\*` and `Nesk\Rialto\Data\JsFunction`. Old imports and `instanceof` work for available wrappers. An old name already provided by the application is preserved; compatibility of that external class is not guaranteed.

- PHP 8.4+ and the compatible QuickJS extension replace the Rialto/Node runtime. Old implementations remain in Git history and the `zoon`, `native` and `native-wip` branches.
- `new JsFunction(source)` takes a complete function. The old `(parameters, body, scope)` constructor is unsupported. Keep using `create()`, `createWithBody()`, `createWithParameters()`, `createWithScope()`, `createWithAsync()` and immutable `body()/parameters()/scope()/async()` chains.
- Function scope and default values accept scalars, arrays and `JsFunction`. Pass remote handles as separate `evaluate()` arguments; PHP callbacks must be `Closure` objects.
- Wrappers follow the pinned Puppeteer API. Aliases do not restore removed or unsupported upstream methods.
- Replace `->tryCatch` and Rialto exception imports with ordinary PHP `try/catch`; JavaScript errors currently become `\RuntimeException`.
- Unsupported options, including `js_extra`, Node options and the old logger, throw an exception. `read_timeout` maps seconds to `protocolTimeout` milliseconds; `ignoreHTTPSErrors` maps to `acceptInsecureCerts`. Firefox and pipe transport are not implemented.
- Local launch uses the Chrome in `node_modules`, an explicit `executablePath`, or `PUPPETEER_EXECUTABLE_PATH`. System Chrome is not selected automatically.
- `undefined` becomes `null`; binary results become PHP strings. `screenshot()` and `pdf()` write `path` output through PHP. Awaiting public calls manually is unnecessary; use `Amp\async()` for concurrency.

## Interactive CLI

The development commands are available through `bin/console` and use Symfony
Console for animated indicators, progress bars, tables and interactive choices:

```sh
bin/console                 # show the command list
bin/console doctor          # inspect PHP, QuickJS, Node.js, npm and bundle
bin/console build           # build the production bundle
bin/console generate --check
bin/console test            # choose a suite interactively
bin/console benchmark --trials=5 --iterations=1000
```

Use `--no-interaction` in CI. Add `--json` for agent and CI integrations; it
disables decorations and prints one machine-readable line. `QUICKJS_EXTENSION` is read automatically for
integration, browser and benchmark commands; `--extension=/path/to/module`
overrides it. Existing Composer scripts remain available.

## Development

For development without the extension loaded:

```sh
composer install --ignore-platform-req=ext-php_quickjs
npm ci
npm run build
composer test-unit
composer psalm
npm run test-generator
composer verify-php
```

Ignoring the platform requirement only permits dependency installation; the client still requires the extension. For browser checks, install Chrome as described above and provide a compatible extension binary:

```sh
QUICKJS_EXTENSION=/absolute/path/to/libphp_quickjs.so composer test-browser
QUICKJS_EXTENSION=/absolute/path/to/libphp_quickjs.so composer test-release
QUICKJS_EXTENSION=/absolute/path/to/libphp_quickjs.so composer benchmark
```

On macOS the extension may use `.dylib`. `PHP_BIN` overrides the PHP executable used by isolated processes. Smoke runs the browser scenarios and all three examples. The benchmark supports macOS and Linux and measures the PHP workload separately from Chrome and the wrapper process. See [QuickJS internals and test details](docs/quickjs.md).

`npm run build` creates minified CDP-only bundles: `resources/puppeteer-core.js` for the default no-plugin path and `resources/puppeteer.js` with plugin support, plus version metadata and launch defaults. Commit these resources with source and lock-file changes. `npm run build:check` verifies reproducibility; `npm run build -- --debug` creates readable bundles for debugging. PHP dependency ranges are resolved by the consuming application; `composer.lock` is local. JS tooling is pinned in `package-lock.json`.

## Benchmark results

The latest optimized QuickJS runs were measured on macOS arm64 with PHP 8.5.7
and managed Chrome 144.0.7559.96. Values below are means; benchmark execution is
documented in [QuickJS test details](docs/quickjs.md).

- **Rialto** — PHP controls a separate Node.js process over a socket; Puppeteer runs in Node.js.
- **Native PHP** — a PHP implementation speaks CDP directly, without Node.js or QuickJS; API coverage is partial.
- **QuickJS** — the Puppeteer bundle runs inside an embedded QuickJS runtime in PHP; Amp handles CDP WebSocket I/O.

| Measurement | Rialto | Native PHP | QuickJS optimized |
| --- | ---: | ---: | ---: |
| 1,000 `evaluate` calls | 413.02 ms | **331.68 ms** | 354.34 ms |
| 100 returns of 64 KiB | 114.60 ms | 103.78 ms | **76.92 ms** |
| 20 waits of 25 ms | 545.30 ms | **29.64 ms** | 29.86 ms |
| Total client CPU | 830 ms | **360 ms** | 428 ms |
| Peak client RSS | 123.55 MiB | **38.14 MiB** | 56.53 MiB |

## Implementation plan

1. **Foundation:** remove old backends; configure Composer, PHPUnit, Psalm, reproducible bundle and CI. Completed.
2. **Generator:** real methods, properties, PHP types, PHPDoc and compatibility aliases. Implemented; API coverage remains partial.
3. **Extension:** direct bridge, `dispatch`, type contract, queue limits, callback/handle release and Fiber boundaries are implemented and covered by [contract tests](docs/extension-contract.md). Cross-platform release validation remains in step 6.
4. **Runtime:** transport shutdown, internal cancellation, timeout recovery and object/browser cleanup are implemented with [lifecycle tests and documented boundaries](docs/runtime.md).
5. **Plugins:** bundled stealth modules, custom plugin registries and lifecycle hooks are implemented with isolated and browser tests; [compatibility limits](docs/plugins.md) are documented.
6. **Release:** a PHP validation runner, repeated browser workloads, retention checks, portable benchmarks and an extension/browser CI matrix are implemented. See [release checks and remaining blockers](docs/release.md).

CI checks unit tests, Psalm, API generation, plugins and the JS build. The extension/browser matrix requires a published fork SHA configured as `PHP_QUICKJS_REF`. Publishing that revision, obtaining green platform runs and updating the vulnerable browser downloader remain release prerequisites.

## License

The MIT License (MIT). See the [License File](LICENSE).

## Logo attribution

PuPHPeteer's logo is composed of:

- [Puppet](https://thenounproject.com/search/?q=puppet&i=52120) by Luis Prado from [the Noun Project](https://thenounproject.com/).
- [Elephant](https://thenounproject.com/search/?q=elephant&i=954119) by Lluisa Iborra from [the Noun Project](https://thenounproject.com/).

Thanks to [Laravel News](https://laravel-news.com/) for picking the icons and colors of the logo.
