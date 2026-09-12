# Puppeteer plugins

PuPHPeteer bundles the upstream `puppeteer-extra-plugin-stealth` JavaScript modules.
PHP starts Chrome; the plugin hooks and evasions execute inside QuickJS and Chrome.
Node.js is only used when building the bundle.

```php
use Nesk\Puphpeteer\Puppeteer;

$puppeteer = new Puppeteer();
$puppeteer->use('stealth');
$browser = $puppeteer->launch(['headless' => true]);
$page = $browser->newPage();
$page->goto('https://example.com');
$browser->close();
```

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

## Application plugins

Plugins must be bundled in advance. Install the plugin with npm in the build
project, then create a JavaScript registry exporting name-to-factory entries:

```js
// app-plugins.js
import plugin from 'puppeteer-extra-plugin-example';
export default {example: options => plugin(options)};
```

```sh
npm run build -- --plugins=./app-plugins.js
```

Commit/distribute the resulting `resources/puppeteer.js` and manifest with your
application. The no-plugin path uses the smaller `resources/puppeteer-core.js`
automatically; an explicitly configured `bundle` always takes precedence. Use
`new Puppeteer(['bundle' => '/absolute/path/puppeteer.js'])` when keeping an
application bundle separately. Reproducibility checks need the same
`--plugins` argument. Register it with `$puppeteer->use('example', $options)`.
Custom registry names cannot override bundled names. Dependencies must also be
present in the registry. This is build-time dependency resolution: runtime
`require()`, Node filesystem, Node process and network modules are unavailable.

The adapter uses the real `PuppeteerExtraPlugin` base class and its defaults/options.
It supports `onPluginRegistered`, `beforeLaunch`, `afterLaunch`, `beforeConnect`,
`afterConnect`, `onBrowser`, `onTargetCreated`, `onTargetChanged`,
`onTargetDestroyed`, `onPageCreated`, `onPageClose`, `onDisconnected`, and
`onClose` when explicitly closing the browser. Launch/connect option hooks run
sequentially and may return replacement options. `runLast` ordering and
`getDataFromPlugins()` are supported. `launch` and `headful` requirements are
checked; a `headful` requirement is rejected for connect because the remote
launch mode cannot be verified. `dependencyOptions` and unknown
requirements are rejected. OS process signal hooks are outside this adapter;
PHP owns browser shutdown.

## Compatibility limits

- The adapter currently supports CDP Chrome, including browser contexts, existing
  targets, frames and popups. New client-created pages await `onPageCreated`.
  Scripts registered with `evaluateOnNewDocument()` cover future navigations and
  child frames. Existing documents are not reinjected automatically.
- Popups created by page JavaScript can execute their first document before
  `targetcreated` handlers finish. This also affects event-based puppeteer-extra
  integration. Obtain the popup page and navigate after the page is returned if
  injection must precede document scripts. Do not assume first-popup-document
  coverage or retroactive modification of already running scripts.
- Stealth's `user-agent-override` normally depends on a Node plugin that writes
  Chrome profile preferences. Here the upstream CDP `acceptLanguage` override is
  enabled for headful and headless browsers. The profile Preferences file is not
  modified. Locale persistence and HTTP header ordering can differ from the
  Node implementation.
- Hook failures during requested operations propagate to PHP. Background target
  or close hook failures are retained and surfaced by the next client operation.
  Closing an already closed target during initialization is normal teardown.
  `close()` and `disconnect()` remain available after plugin failures.
  User-supplied plugins must return/await their asynchronous work.
- Browser-side stealth patches evolve independently of Chrome. The integration
  tests verify concrete properties and lifecycle behavior; they do not promise
  that a website cannot detect automation.

## Checks

```sh
node --test tests/Plugins/adapter.test.cjs
php -n -d extension="$QUICKJS_EXTENSION" tests/Browser/plugins.php
```

The first suite builds plugins into an isolated JavaScript VM without Node
`process` or `require` globals. It covers upstream defaults/evasions, launch
options, custom registries/dependencies, hook ordering/failures and page readiness.
The PHP suite uses the managed Chrome installed under `node_modules`, testing
launch/connect hooks, page and iframe injections and popup navigation.

Upstream references: [plugin hooks](https://github.com/berstend/puppeteer-extra/tree/master/packages/puppeteer-extra-plugin),
[stealth source](https://github.com/berstend/puppeteer-extra/tree/master/packages/puppeteer-extra-plugin-stealth).
