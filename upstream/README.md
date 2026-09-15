# QuickJS PHP API generation

`node tools/upstream/update.cjs` extracts the installed, exact `puppeteer-core`
TypeScript declarations and generates PHP wrappers with automatic awaiting.
Use `--check` to compare without writing tracked files, or `--offline` to require
an existing matching source cache. A normal clean run may download the pinned
upstream repository into `.build/upstream/` to verify its identity.

`config.json` owns explicit wrapper selection, overload selection and PHP type
adaptations. `lock.json` records the npm integrity, declaration hash, tooling and
matching immutable Puppeteer source revision. Install dependencies using `npm ci`.
PHP wrappers are fully regenerated; manual edits are overwritten. A single file
header marks generator-owned wrappers. Removed upstream methods and classes are
removed from PHP, while runtime files are left intact. `--check` reports pending
deletions without deleting files. Stale config mappings are reported and ignored.
The extracted catalog and coverage stay in memory. Generation prints summary counts; `--verbose` also prints diagnostics
for unsupported declarations; no report or diagnostic baseline
is saved. Unsupported PHP mappings are skipped with reasons. TypeScript syntax,
resolution and type errors, invalid config and PHP emitter failures stop generation
with a nonzero exit code before generated files are written.

## Contract limitations

* Remote methods return resolved values, not Future. Promise<void> becomes void;
  undefined values become null. Parallel callers use Amp\async().
* Properties use typed PHP 8.4 getters and, when writable, setters through the same transport.
* Compatible overloads merge parameter and return types; incompatible parameter names,
  generics or rest positions require explicit selection in config.
* JS static methods use PHP instance methods on the same runtime. Use
  `getStaticClass(Locator::class)->race($locators)` without constructing a Locator;
  an existing Locator can call `race()` directly. Puppeteer exposes custom query
  handler methods directly. Symbols remain unsupported.
* Browser functions use `JsFunction`; host callbacks use `Closure`.
  `new JsFunction(source)` accepts JS source. Legacy factories `create`,
  `createWithBody`, `createWithParameters`, `createWithScope`, `createWithAsync`
  support immutable `body/parameters/scope/async` chaining. Scope/defaults accept
  scalars, arrays and nested JsFunction; pass remote handles as evaluate arguments.
  `evaluate()` results are `mixed`: PHP cannot infer a JS function's return type.
* JS DOM generics are erased for PHP wrapper classes. `ElementHandle` remains a
  subtype of `JSHandle`. Typed wrappers do not claim to represent browser DOM nodes.
* Optional arguments default to PHP `null` but are omitted from the JS call when
  absent. Explicitly passing `null` forwards a JS null; it is not JS undefined.
* Optional `AbortSignal` fields are typed `never`: omit them. Amp cancellation is
  not translated to JS AbortSignal. Unsupported fields have not been silently
  widened to `mixed`.
* String events are supported; symbol events are excluded. Event lifecycle and
  callback identity are tested for PHP closures and JsFunction.
* `$`, `$$`, `$eval`, `$$eval` use PHP names `querySelector`, `querySelectorAll`,
  `querySelectorEval`, `querySelectorAllEval` and retain the original JS method name.
* Binary buffers become PHP strings. Readable streams become Amp readable streams.
  Node process APIs, writable Node streams and unsupported TS types require later bridge work.
* Unsupported declarations remain visible with `--verbose`. The dynamic runtime
  escape hatch is not evidence that these APIs are supported or tested.

The framework reuses the TypeScript extractor and structural mapper from the
`native` branch, while its model and PHP bodies target the QuickJS bridge.

Wrappers and the entry point live in `src/Puppeteer/`, under
`Nesk\Puphpeteer\Puppeteer`. `JsFunction` and the transport remain in
`Nesk\Puphpeteer`. `src/compatibility.php` is generated from the same class model:
it preserves only `Nesk\Puphpeteer\Resources\…` imports. The Puppeteer entry
point and JsFunction require their current namespaces. Composer loads it through `autoload.files`.
Existing old classes are not replaced. Removing an upstream wrapper removes both
its PHP file and aliases.
