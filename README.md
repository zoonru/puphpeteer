# PuPHPeteer v3

Tools for maintaining a native PHP port of Puppeteer's public API. The generator
updates declarations and PHPUnit scaffolds; developers implement their bodies.
The current client supports CDP connections, isolated browser contexts, page creation,
navigation and JavaScript evaluation. The remaining API is selected through ignore files.

## Setup

Development requires PHP 8.1+, Composer, Node.js 18+, npm, Git and Chrome for browser tests.

```sh
composer install
npm ci --ignore-scripts
```

Commit `package-lock.json`; keep `composer.lock` local. Node is a development dependency.
The PHP client runs with Amp/Fibers. Node is used for development tools only.

## Using the current client

Connect to a Chrome instance started with remote debugging enabled:

```php
use Nesk\Puphpeteer\Puppeteer;

$browser = (new Puppeteer())->connect([
    'browserWSEndpoint' => $endpoint,
])->await();
try {
    $context = $browser->createBrowserContext()->await();
    try {
        $page = $context->newPage()->await();
        $page->goto('https://example.com')->await();
        $title = $page->evaluate('document.title')->await();
    } finally {
        $context->close()->await();
    }
} finally {
    $browser->disconnect()->await();
}
```

Methods return `Amp\Future`; start several operations before awaiting them to run
concurrently. `goto` accepts an `Amp\Cancellation` in its `signal` option. Disconnecting
releases the connection and pending operations; closing a context disposes its pages.

`evaluate` accepts a JavaScript expression or function source. Function source such as
`'(a, b) => a + b'` receives variadic PHP arguments. Results use PHP arrays for JSON
objects/arrays, `Value\UndefinedValue::Value` for JavaScript `undefined`, and
`Value\BigInt` for arbitrary precision integers (both under `Nesk\Puphpeteer`).
JavaScript exceptions reject the future and preserve their JS name, value and stack.
The current implementation uses CDP; WebDriver BiDi is not implemented.

## Browser tests

`composer test-unit` starts isolated headless Chrome processes and local HTTP/HTTPS
fixtures. Set `PUPHPETEER_CHROME` to choose a Chrome executable; otherwise the test
harness uses the cached Chrome for Testing matching the installed Puppeteer version.
Install that browser once with `npx puppeteer browsers install chrome`. `PUPHPETEER_TEST_ENDPOINT` can point to
a dedicated test browser instead. Tests create and close targets and contexts in that
browser. The PHP test process has a 512 MiB memory limit for the upstream 100 MiB
serialization scenario.

Some upstream serialization scenarios have known CDP failures recorded in Puppeteer's
`test/TestExpectations.json`. Their PHP ports assert the actual pinned JS/CDP behavior
and link to those expectations; they remain executable tests.

## Development workflow

1. Commit your work before generation; Git is the recovery mechanism.
2. Prepare and commit the inputs. To upgrade Puppeteer, change its exact version in
   `package.json` and run `npm install --ignore-scripts`. To expand the port, remove
   exclusions from `upstream/ignore.json`.
3. Run `update-php` once, then inspect `git status` and `git diff`.
4. Run Psalm and PHPUnit, implement missing behavior and assertions, and fix failures.
   Adjust mappings when necessary; commit work before regenerating. Keep the intended
   API scope and assertions when fixing failures.
5. Review and commit code, tests and catalogs when the intended scope passes.
   Release a new package version after an upstream upgrade.

Run each command yourself, in order:

```sh
composer update-php
# Review git status and git diff before continuing.
composer psalm
composer test-unit
```

The commands are independent. Psalm and PHPUnit run directly through PHP and print
their own results. PHPUnit includes generated client tests; incomplete/skipped tests
return a nonzero code. A successful generation does not mean the client is implemented.

The generator writes files directly without backups, locks or rollback; run one updater
at a time. After a failed or unwanted update, inspect the diff, restore affected tracked
files with `git restore --source=<commit> -- <paths>`, and manually remove unwanted new
files. It does not enforce a clean Git state or recover uncommitted work.

## Generator output

The updater resolves the pinned package and matching Git revision, caching sources in
`.build/upstream/`. It preserves method bodies, manual comments, initializers and ignore
files. Removed declarations remain for manual review. Unchanged inputs produce no changes.

Output shows the version/commit, a TTY progress bar, changed files and grouped diagnostics.
Percentages measure synchronized declarations and API-to-test associations, not implemented
behavior. Use `composer update-php -- --json` for full machine-readable output;
`-- --offline` requires matching installed dependencies and cached sources.

Exit codes: **0** generation completed, **1** declaration conflicts, **2** configuration,
source, tool or write failure. Unresolved mappings remain diagnostics even with exit 0.

## Configuration

Edit [upstream/config.json](upstream/config.json), then rerun the generator. Paths are
relative to the project root. JSON files use `schemaVersion: 1`; formats are described
in [upstream/schemas](upstream/schemas). Commit config and the generated API/test/lock catalogs.

| Setting | Meaning |
| --- | --- |
| `namespace` | Default namespace; classes are generated under `src/` |
| `classes` | Class name → `{fqcn, file}` |
| `members` | Member ID → PHP name/kind/static, overload, parameter types/defaults, return type, templates |
| `types` | Exact TypeScript type text → `{native, psalm}` |
| `testMappings` | Upstream test ID → API IDs and PHPUnit file/method |
| `ownTests` | Own PHP scenarios using the same mapping fields and an `own:` ID |

Data interfaces become array shapes. Types needed by active signatures receive supporting
stubs even when ignored, without enabling all their methods. Optional keys, null and
undefined are distinct; defaults and ambiguous overloads need explicit decisions.
Recursive shapes, dependent utilities and variadic tuples may require a nominal or other
explicit mapping. Unsupported types are diagnosed, not silently accepted as `mixed`.

Current mappings: `Page.evaluate` accepts JS source and returns `Amp\Future<mixed>`;
host callbacks remain typed PHP callables; `AbortSignal` becomes `Amp\Cancellation`.
PHP 8.1 limitations can require wider native types while Psalm retains precise types.
See the [design](docs/v3-framework-design.md) for the complete rules and numbered plan.

## Test maintenance

`update-php` maps upstream JS scenarios to PHPUnit classes/methods in `upstream/tests.json`.
For an ambiguous scenario, add a `testMappings` entry keyed by its exact ID, for example:

```json
{
  "test/src/example.spec.ts::Browser > disconnects": {
    "api": ["Browser.disconnect"],
    "phpTest": "tests/Parity/BrowserTest.php",
    "phpMethod": "testDisconnects",
    "fixtures": ["tests/Parity/data/input.json"]
  }
}
```

New test methods contain `markTestIncomplete()`. Existing assertions are preserved;
changes to upstream tests/helpers/fixtures produce diagnostics for manual review.
Names follow upstream suites/scenarios; hashes only resolve collisions. PHPDoc `@see`
links pin tests and API declarations to a Git commit and source line and update on regeneration.

`required` scenarios need implementations; `excluded-api`/`excluded-test` record exclusions;
`unresolved` entries are candidates for manual classification, not mandatory tests.
[test-ignore.json](upstream/test-ignore.json) stores exact scenario IDs and reasons.
If exclusions are added, previously generated tests must be removed manually. See
[ignore selection](upstream/README.md) for API rules.

Port setup, actions and assertions into PHP and review their equivalence to upstream.
Commit the catalog with the reviewed tests. PHPUnit does not synchronize the catalog or
run JS; use upstream's own runner separately when investigating reference behavior.
HTML and data may be shared.

## Checks without Chrome

```sh
composer psalm
composer test-unit -- --testsuite Framework
```

The Framework suite contains generator and protocol unit tests; the Client suite runs
real-browser scenarios. Standard options pass through directly,
for example `composer test-unit -- --filter=Name`. Previous implementations remain in
Git history and the `native-wip` / `quickjs-prototype` branches.
