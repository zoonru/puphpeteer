# PuPHPeteer

[![Puppeteer](https://img.shields.io/badge/Puppeteer-25.11.0-40B5A4?logo=puppeteer)](https://github.com/puppeteer/puppeteer/releases/tag/puppeteer-v25.11.0)
[![Chrome](https://img.shields.io/badge/Chrome-153.0.8010.36-4285F4?logo=googlechrome)](https://googlechromelabs.github.io/chrome-for-testing/)

<img src="https://user-images.githubusercontent.com/817508/100672192-dd258500-3361-11eb-845f-e8b5109752e4.png" style="max-width:100%;" width="190px" align="right">

**English** | [Russian](README-RU.md)

A [Puppeteer](https://github.com/puppeteer/puppeteer) bridge for PHP. Original Puppeteer runs inside the PHP process through **php-quickjs**; Amp handles WebSocket transport, timers and PHP callbacks. Browser operations do not require a Node.js process.

Generated wrappers cover part of Puppeteer's API; bundled stealth and custom plugin support are available within the documented compatibility limits.

## Contents

- [Usage](#usage)
- [Requirements](#requirements)
- [Installation](#installation)
- [Use with browserless](#use-with-browserless)
- [Notable differences from Puppeteer](#notable-differences-from-puppeteer)
- [Puppeteer plugins](#puppeteer-plugins)
- [IDE support and API generation](#ide-support-and-api-generation)
- [Upgrade from v2](#upgrade-from-v2)
- [Development](#development)
- [Runtime lifecycle](#runtime-lifecycle)
- [Extension contract](#extension-contract)
- [Release validation](#release-validation)
- [Benchmark results](#benchmark-results)
- [License](#license)
- [Logo attribution](#logo-attribution)

## Usage

Navigate to a page and save a screenshot:

```php
require 'vendor/autoload.php';

use Nesk\Puphpeteer\Puppeteer\Puppeteer;

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

## Requirements

- PHP **8.4+** and Composer.
- The enabled [php-quickjs extension fork](https://github.com/xtrime-ru/php-quickjs). [Build and installation instructions](https://github.com/xtrime-ru/php-quickjs/blob/async-jobs-fibers/docs/install.md).
- Local Chrome or access to Browserless.
- To download local Chrome: PHP HTTPS streams (`allow_url_fopen=1`, OpenSSL) and the `unzip` command.

Node.js and npm are not required to run the package or install a browser. Ready-to-use JS bundles are included.

## Installation

Run from your application's root directory:

```sh
composer require zoon/puphpeteer
```

If you need local Chrome, install the compatible version:

```sh
php vendor/bin/console browser:install
```

The command downloads pinned Chrome into `.chrome` at the application root. Repeated runs reuse the installed browser. Add `/.chrome/` to your application's `.gitignore`. Skip this step for Browserless or Chrome already provided by `Dockerfile-chrome`.

Composer does not run dependency scripts. For automatic installation, add hooks to your application's `composer.json`:

```json
{
  "scripts": {
    "browser:install": "@php vendor/bin/console browser:install",
    "post-install-cmd": "@browser:install",
    "post-update-cmd": "@browser:install"
  }
}
```

If hooks already exist, append the command to their arrays. Do not use `--no-scripts` for automatic execution; the explicit PHP command also works without hooks. npm approvals and Composer `allow-plugins` are not required.

For Browserless, set `PUPPETEER_SKIP_DOWNLOAD=true`. `Dockerfile-chrome` already sets this flag and `PUPPETEER_EXECUTABLE_PATH`. A nonempty `PUPPETEER_EXECUTABLE_PATH` also disables downloading. These ENV variables must be available during dependency installation too.

### Browser path

The installer and `launch()` share `.chrome` at the application root or `PUPPETEER_CACHE_DIR`. Relative ENV paths are resolved against the application root. `launch()` never downloads Chrome or searches for a system browser.

| Setting | Effect |
| --- | --- |
| `launch(['executablePath' => '/path/to/chrome'])` | Explicit path; highest priority |
| `PUPPETEER_EXECUTABLE_PATH` | Browser path when no explicit option is supplied |
| `PUPPETEER_CACHE_DIR` | Directory for installing and locating locked Chrome |
| `PUPPETEER_SKIP_DOWNLOAD=true` | Skip browser download |
| `PUPPETEER_CHROME_SKIP_DOWNLOAD=true` | Skip Chrome; `PUPPETEER_SKIP_CHROME_DOWNLOAD` is also accepted |

## Use with browserless

```sh
docker compose up -d browserless
docker compose run --rm php php examples/03_browserless.php
docker compose down
```

Compose supplies `BROWSER_WS` with its token. Outside Compose, pass your endpoint to `connect(['browserWSEndpoint' => $url])`; use `browserURL` for an HTTP debugging endpoint. `disconnect()` leaves the browser running; `close()` closes it.

| Timeout | Scope |
| --- | --- |
| Container ENV `TIMEOUT=300000` | Entire session: 5 minutes in Compose; Browserless default is 30 seconds |
| Client `protocolTimeout` | Individual CDP call, milliseconds |
| `goto(..., ['timeout' => 30000])` | Navigation, milliseconds |

Client timeouts do not extend the server session. Browserless v1 used `CONNECTION_TIMEOUT`. Apply ENV changes with `docker compose up -d browserless`. [Browserless reference](https://docs.browserless.io/enterprise/docker/config).

## Notable differences from Puppeteer

Browser API classes live in `Nesk\Puphpeteer\Puppeteer` (for example, `Puppeteer`, `Page`, `Browser`). Shared `JsFunction` remains in `Nesk\Puphpeteer`; old imports are available through aliases.

### Instantiate Puppeteer

Use `new Puppeteer()` in place of JavaScript's Puppeteer import. `launch()` starts Chrome from PHP; `connect()` attaches to an existing browser. The original Puppeteer logic executes in embedded QuickJS.

### Promises are awaited automatically

You do not need to call `Revolt\EventLoop::run()` explicitly: public methods await internal Futures, which let the event loop run while waiting. Concurrency is cooperative: `sleep()` and long synchronous PHP operations block other tasks; use `Amp\delay()` and asynchronous I/O instead.

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

### Static methods and properties

Call JS static methods through an existing PHP object. Without an instance, `getStaticClass()` returns a typed constructor wrapper in the same QuickJS runtime:

```php
use Nesk\Puphpeteer\Puppeteer\Locator;

$names = $puppeteer->customQueryHandlerNames();
$page = $browser->newPage();
$page->getStaticClass(Locator::class)
    ->race([$page->locator('#accept'), $page->locator('#continue')])
    ->click();
// With an existing Locator: $locator->race([$locator, $other])->click();
```

The getter is also available on `$puppeteer`: before `launch()`/`connect()` it creates the next connection's runtime; afterwards it uses the latest connection. Do not mix objects from different connections. Use `$browser` or `$page` to select a specific browser's runtime.

Read and assign properties directly (`$object->property = $value`) when upstream permits writing. IDEs see typed PHP 8.4 property hooks. Compatible overloads are merged; Symbol methods and Symbol events remain unsupported.

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

### Reading streams

`createPDFStream()` returns an `Amp\ByteStream\ReadableStream`. The stream stays in QuickJS; PHP requests at most 64 KiB per read and yields to the event loop between reads. No complete-file buffer is added by the bridge. The source may have its own buffer; synchronous JS production and each chunk transfer still take CPU time.

```php
$stream = $page->createPDFStream();
try {
    while (($chunk = $stream->read()) !== null) {
        // Write to an asynchronous file or HTTP output stream.
    }
} finally {
    $stream->close();
}
```

Amp `pipe()` and `buffer()` work with this stream (`buffer()` intentionally collects everything). `read($cancellation)` cancels and closes only that stream. Early close also releases the PDF's CDP handle. Node.js writable streams are a separate interface and are not provided by this adapter.

## Puppeteer plugins

Register bundled plugins before launching or connecting:

```php
$puppeteer = (new Puppeteer())->use('stealth');
$browser = $puppeteer->launch(['headless' => true]);
```

The bundle includes upstream stealth modules and the puppeteer-extra hook adapter.

`use()` returns the same Puppeteer instance. Registrations apply to subsequent
`launch()` and `connect()` calls; each browser connection gets fresh plugin
instances. Unknown names, missing dependencies and incompatible requirements
fail before launching Chrome. Registering the same name again replaces its options.
The `puppeteer-extra-plugin-` prefix is optional. Launch preparation respects
`timeout` (default 30 seconds); connect preparation uses `protocolTimeout` or
`read_timeout` (default 180 seconds). Zero disables that preparation deadline.

To select evasions, pass their upstream names:

```php
$puppeteer->use('stealth', ['enabledEvasions' => [
    'navigator.webdriver', 'navigator.languages', 'navigator.hardwareConcurrency',
]]);
```

Individual evasions accept their original options:

```php
$puppeteer->use('stealth/evasions/navigator.hardwareConcurrency', [
    'hardwareConcurrency' => 8,
]);
```

### Application plugins

Plugins must be bundled in advance. Install the plugin with npm in the build
project, then create a JavaScript registry exporting name-to-factory entries:

```js
// app-plugins.js
import plugin from 'puppeteer-extra-plugin-example';
export default {example: options => plugin(options)};
```

```sh
docker compose run --rm php npm run build -- --plugins=./app-plugins.js
```

Commit/distribute the resulting `resources/puppeteer.js` with your
application. The no-plugin path uses the smaller `resources/puppeteer-core.js`
automatically; an explicitly configured `bundle` always takes precedence. Use
`new Puppeteer(['bundle' => '/absolute/path/puppeteer.js'])` when keeping an
application bundle separately. Reproducibility checks need the same
`--plugins` argument. Register it with `$puppeteer->use('example', $options)`.
Custom registry names cannot override bundled names. Dependencies must also be
present in the registry. This is build-time dependency resolution: runtime
`require()`, Node filesystem, Node process and network modules are unavailable.

### Compatibility limits

1. **Plugins work with Chrome over CDP.** Firefox and the WebDriver BiDi protocol are not supported. For example, use `launch(['headless' => true])` to start Chrome or `connect(['browserWSEndpoint' => $url])` to connect to a Chrome CDP endpoint. A Firefox endpoint will not work.

2. **Page patches take effect when a document loads.** `newPage()` waits for the plugin's `onPageCreated()` handler, so a following `goto()` loads the document with its registered patches. Connecting to an already open page does not change scripts that have already run. For example, if a page read `navigator.webdriver` before you connected with stealth, that earlier result remains unchanged. Reload the page to apply scripts registered with `evaluateOnNewDocument()` to a new document.

3. **The first popup document may load before the plugin is ready.** A site's `window.open('/check')` can run `/check` scripts before stealth finishes preparing the popup. If those scripts need the patches from the start, obtain the popup page and then navigate it to `/check` again. This affects the new load; it does not undo checks or requests from the first load.

4. **Language overrides apply to the browser connection, not saved profile settings.** For example, `use('stealth/evasions/user-agent-override', ['locale' => 'de-DE,de'])` configures the language through CDP in both headless and visible Chrome. It does not write that language to the profile's `Preferences` file: relaunching the same profile without the plugin does not preserve this setting. The order of HTTP headers may also differ from puppeteer-extra running in Node.js.

5. **A plugin error becomes a PHP exception, sometimes on the next call.** For example, if `onPageCreated()` fails while you call `$browser->newPage()`, that call throws. If a hook fails in the background when a popup appears, the next client operation can throw even though it did not create the popup. Keep browser cleanup in `finally`: `close()` and `disconnect()` remain available after plugin failures. A target disappearing during initialization because its page closed is treated as normal cleanup.

6. **Custom plugins must await their asynchronous work.** For example, write `async onPageCreated(page) { await page.evaluateOnNewDocument(patch); }`. If the handler starts that operation but neither awaits nor returns its Promise, `newPage()` can return before the patch is registered, and the adapter cannot reliably report its later failure.

7. **Stealth does not guarantee that a website will accept the browser as human.** For example, hiding `navigator.webdriver` does not prevent a site from showing a CAPTCHA based on the IP address or repeated requests. Our tests check particular browser properties and plugin execution; they do not certify that automation is undetectable.

## IDE support and API generation

Real PHP classes, methods, property getters, signatures and PHPDoc are generated from the pinned Puppeteer declarations. PhpStorm completion works directly from these classes; Psalm checks their types. JS Promise return types become their resolved PHP types.

Coverage is partial: generation prints skipped declarations and their reasons only with `--verbose`; see [the generator contract](upstream/README.md) for type limitations. Parsing and TypeScript errors stop generation before files are written.

```sh
docker compose run --rm php composer update-php
docker compose run --rm php composer verify-php
# Show reasons for skipped declarations
docker compose run --rm php composer update-php -- --verbose
```

The first command regenerates wrappers and compatibility aliases; the second checks them without changing files. Manual edits to generated files are overwritten. Classes, methods and aliases removed from upstream also disappear from the generated output.

## Upgrade from v2

Install PHP 8.4+, the compatible QuickJS extension and Chrome as described above. Node.js is only needed for development.

Composer loads **best-effort aliases only for `Nesk\Puphpeteer\Resources\…`**. Update the `Puppeteer` and `JsFunction` imports; for new code use:

| v2 import | Current import |
| --- | --- |
| `Nesk\Puphpeteer\Puppeteer` | `Nesk\Puphpeteer\Puppeteer\Puppeteer` |
| `Nesk\Puphpeteer\Resources\Page` (and other wrappers) | `Nesk\Puphpeteer\Puppeteer\Page` |
| `Nesk\Rialto\Data\JsFunction` | `Nesk\Puphpeteer\JsFunction` |

Aliases preserve `instanceof` for available wrappers, but do not restore removed upstream methods or replace classes already loaded by the application. `querySelector*()` names and automatic waiting remain unchanged.

**Errors:** remove `tryCatch` and catch `RuntimeException` instead of Rialto exceptions. An ordinary Puppeteer operation error does not close the client.

```php
// v2
try {
    $page->tryCatch->goto('invalid_url');
} catch (\Nesk\Rialto\Exceptions\Node\Exception $error) {
    echo $error->getMessage();
}

// v3
try {
    $page->goto('invalid_url');
} catch (\RuntimeException $error) {
    echo $error->getMessage();
}
```

**Plugins:** replace JavaScript initialization in `js_extra` with registration before `launch()` or `connect()`.

```php
// v2
$puppeteer = new Puppeteer(['js_extra' => "
    const puppeteer = require('puppeteer-extra');
    puppeteer.use(require('puppeteer-extra-plugin-stealth')());
    instruction.setDefaultResource(puppeteer);
"]);

// v3
$puppeteer = (new Puppeteer())->use('stealth');
```

**Timeouts and HTTPS:** the old options still map automatically; prefer the upstream names. `protocolTimeout` is in milliseconds, whereas `read_timeout` was in seconds. Navigation has its own timeout.

```php
// v2
$puppeteer = new Puppeteer(['read_timeout' => 65]);
$browser = $puppeteer->launch(['ignoreHTTPSErrors' => true]);

// v3
$puppeteer = new Puppeteer();
$browser = $puppeteer->launch([
    'protocolTimeout' => 65000,
    'acceptInsecureCerts' => true,
]);
// Both versions:
$browser->newPage()->goto($url, ['timeout' => 60000]);
```

**JavaScript functions:** existing factories still work. Replace a direct `(parameters, body, scope)` constructor with a factory or a complete function source:

```php
// v2
$function = new JsFunction(['element'], 'return element.textContent;', []);

// v3: factory (also compatible with v2)
$function = JsFunction::createWithParameters(['element'])
    ->body('return element.textContent;');
// Or v3 source syntax:
$function = new JsFunction('(element) => element.textContent');
```

Other behavior changes:

- Node options, the old logger and `js_extra` throw an exception. Local launch uses installed Chrome, `executablePath` or `PUPPETEER_EXECUTABLE_PATH`; system Chrome is not selected automatically.
- Function scope/defaults accept scalars, arrays and `JsFunction`; pass remote handles as separate `evaluate()` arguments. PHP callbacks must be `Closure` objects. Use `Amp\async()` for concurrency; public results need no manual `await()`.
- `undefined` becomes `null`; binary results are PHP strings. Screenshot, PDF and script/style filesystem operations run on the PHP host. A base64 screenshot returns without writing `path`.
- Firefox, pipe transport, Node.js writable streams, video recording and `followSymlinks: false` are unsupported.

## Development

### Development Docker environment

Run the commands below from a repository checkout to develop the package. Generation, builds and JS tests require Node.js **22+** and npm, which are included in the images.

Requires Docker and Compose **2.17+**. The image builds the pinned fork SHA and enables it through PHP ini; no host PHP/Rust toolchain is needed.

```sh
docker compose build php chrome
docker compose run --rm php composer install
docker compose run --rm php npm ci
docker compose run --rm chrome php examples/01_page_open.php
```

`Dockerfile` provides PHP, QuickJS, Composer and Node.js without a browser. `Dockerfile-chrome` installs Chrome from `upstream/lock.json` into `/opt/chrome` using the same PHP installer and sets `PUPPETEER_EXECUTABLE_PATH=/usr/local/bin/chrome`. Neither image contains application code or project dependencies, and neither starts a command automatically. Both images include Node.js and npm for development. Compose mounts the entire checkout at `/app`, including `vendor` and `node_modules`; dependency installation writes directly to the host directory. `npm ci` installs development tooling only. When switching between macOS and Linux, rerun `npm ci` in the target environment: native npm binaries depend on the OS as well as the CPU architecture. Rebuild after Dockerfile, extension or locked Chrome changes.

Images use the host architecture; Chrome availability depends on the locked version. The Chrome service uses `SYS_ADMIN` for its sandbox. The image has no graphical display.

#### Examples

Run `docker compose run --rm chrome php examples/<filename>`.

| File | Demonstrates |
| --- | --- |
| [01_page_open.php](examples/01_page_open.php) | Launch options, viewport, timeout and evaluate |
| [02_page_screenshot.php](examples/02_page_screenshot.php) | Stealth, User-Agent, language, viewport scale and screenshot |
| [04_form_intercept.php](examples/04_form_intercept.php) | Wait for POST before clicking, print data and abort the request |

Local HTML needs no HTTP server; screenshots persist on the host. For a visible browser on a host with PHP/QuickJS and a graphical display: `php examples/01_page_open.php --headful` (`headless => false`).

#### Updating through Docker

Update PHP dependencies and install locked JS dependencies:

```sh
docker compose run --rm php composer update
docker compose run --rm php npm ci
```

Update Puppeteer and its supported Chrome:

```sh
docker compose run --rm php npm install --save-dev --save-exact puppeteer-core@latest
docker compose run --rm php php bin/console generate
docker compose run --rm php npm run build
docker compose build chrome
docker compose run --rm chrome php bin/console test all --no-interaction
```

Rebuild `chrome` after `generate` updates `upstream/lock.json`; its ENV path selects the browser installed in the image. API generation and bundle building are separate commands. Changes to `package.json`, `package-lock.json`, `upstream/` and `resources/` appear in the host Git checkout. Composer's lock file also persists on the host but is not committed in this package.


After Docker setup, run functional and static checks:

```sh
docker compose run --rm chrome composer test
```

PHP style follows Symfony (`PHP CS Fixer`), with spaces around `.` and class imports, including built-in classes. Run `composer cs:check` to check or `composer cs:fix` to format all PHP files (also via `docker compose run --rm php ...`). Generated PHP uses the same rules. Style checks run in `composer test` and CI.

`npm run build` creates minified CDP bundles: `resources/puppeteer-core.js` without plugins and `resources/puppeteer.js` with plugins. `--debug` produces readable JS. Commit resources with source and lock-file changes. `package-lock.json` pins JS dependencies; `composer.lock` stays local, and applications resolve PHP dependency ranges.

Without the extension, only unit tests/Psalm are available: `PUPPETEER_SKIP_DOWNLOAD=true composer install --ignore-platform-req=ext-php_quickjs`, then `php vendor/bin/phpunit` and `composer psalm`.

For a single suite, use `php bin/console test unit` (or `integration` / `browser`); use `php vendor/bin/phpunit --filter=...` for individual tests. `composer test` includes PHP/JS tests, Psalm, API generation and bundle checks; `composer test-release` adds load cycles; `composer benchmark` measures performance separately.

CLI help: `docker compose run --rm php php bin/console`. `doctor` checks the environment; `--no-interaction` disables prompts, and `--json` selects machine-readable output.

## Runtime lifecycle

QuickJS diagnostics use a bounded asynchronous queue: at most 64 pending messages / 1 MiB, with each payload limited to 64 KiB. Overflow is dropped when the reader cannot keep up. Explicit close waits at most one second for pending logs.

- Close pages/contexts with `close()` and JS handles with `dispose()`, preferably in `finally`. `release()` only drops the bridge reference. Do not pass objects between clients.
- `on()`, `once()` and `off()` preserve handler identity. Removing the last registration releases the callback; other callbacks, such as `exposeFunction()`, remain until disconnect.
- Event callback errors go to stderr; callbacks returning a result reject their JS Promise on failure. Logs use Amp streams; `close()`/`disconnect()` allow up to one second to flush pending messages.
- Ordinary JS errors and operation timeouts leave the connection usable. Transport failure clears calls, timers and registries; create a new connection afterward.
- `undefined` becomes `null`, binary becomes a PHP string, empty JS objects and arrays both become `[]`. Exact integers must fit ±9,007,199,254,740,991. BigInt/non-finite results use tagged arrays; cyclic or excessively deep data is rejected.
- CDP Chrome is supported. Firefox/BiDi, public `AbortSignal` and arbitrary Node APIs are unavailable.

## Extension contract

Use the fork SHA pinned in `Dockerfile`: `dispatch()` alone does not establish compatibility. [Integration tests](tests/Integration/) check values, limits, recovery, resource release and Fibers against the installed extension.

The bridge copies values without MessagePack or shared memory. The client runs up to 100 ready JS jobs per batch; Amp handles I/O. Limits: 2 seconds native execution, 256 MiB JS heap, 512 KiB stack; 4,096 messages / 32 MiB queue; 16 MiB conversion budget including structural overhead; depth 64. These do not bound PHP callbacks or Chrome memory.

Dispatch failure discards partial messages but does not roll back JS mutations: the client closes without retrying. PHP callbacks run after native dispatch returns so they can suspend their Fiber.

## Release validation

[GitHub Actions](.github/workflows/tests.yaml) on push, PR and manual dispatch checks PHPUnit/Psalm on PHP 8.4/8.5, JS, bundles, native integration, browser scenarios and examples. Composer and images are cached; project tests run on cache hits too.

Run load tests and benchmarks only for releases:

```sh
docker compose run --rm chrome composer test-release -- --cycles=50 --timeout=600
docker compose run --rm chrome composer benchmark -- --trials=5 --iterations=1000
```

Each load cycle checks two pages, listeners, handle release and error recovery; every tenth renders PNG/PDF. Resource registries must return to baseline. `--timeout` bounds the workload in seconds; increase both arguments for longer runs. These checks do not prove absence of all native leaks.

The release workflow runs **after publication**, including prereleases, and does not block it. Check dependency audits and target platforms before publishing: hosted runtime CI covers Linux amd64.

### Repeatable performance measurements

Each trial measures 1,000 evaluates, 100 × 64 KiB returns, 20 concurrent 25 ms waits and 20 navigations. `--iterations` changes evaluate count. CPU/RSS exclude Chrome; 25 ms sampling may miss peaks. Compare on identical hardware, PHP, Chrome, extension and bundle. macOS/Linux require `ps` and `/usr/bin/time` (GNU time on Linux).

## Benchmark results

The reference QuickJS runs were measured on macOS arm64 with PHP 8.5.7
and managed Chrome 144.0.7559.96. Values below are means; benchmark execution is
documented in [performance measurements](#repeatable-performance-measurements).

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

## License

The MIT License (MIT). See the [License File](LICENSE).

## Logo attribution

PuPHPeteer's logo is composed of:

- [Puppet](https://thenounproject.com/search/?q=puppet&i=52120) by Luis Prado from [the Noun Project](https://thenounproject.com/).
- [Elephant](https://thenounproject.com/search/?q=elephant&i=954119) by Lluisa Iborra from [the Noun Project](https://thenounproject.com/).

Thanks to [Laravel News](https://laravel-news.com/) for picking the icons and colors of the logo.
