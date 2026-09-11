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
`api.json` is the complete extracted catalog. `coverage.json` reports every class
member as generated or unsupported, including its reasons. These numbers measure
PHP declarations, not runtime correctness or test coverage. Root exports are
reported separately. No native-backend implementation or test exclusions carry over.

## Contract limitations

* Remote methods return resolved values, not Future. Promise<void> becomes void;
  undefined values become null. Parallel callers use Amp\async().
* Read-only properties use typed PHP 8.4 getters through the same transport.
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
* Binary buffers become PHP strings. Node process APIs, readable streams, writable
  properties, static methods and unsupported TS types require later bridge work.
* Unsupported declarations remain visible in the report. The dynamic runtime
  escape hatch is not evidence that these APIs are supported or tested.

The framework reuses the TypeScript extractor and structural mapper from the
`native` branch, while its model and PHP bodies target the QuickJS bridge.

`src/compatibility.php` is generated from the same class model, plus the historical
Rialto JsFunction alias. Composer loads it through `autoload.files`. Existing old
classes are not replaced. Removing an upstream wrapper removes its alias too.
