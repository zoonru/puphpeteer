# Test suites

`composer test` runs PHP-only PHPUnit unit tests without Chrome or the QuickJS
extension. Native extension stubs are static-analysis declarations only and are
never loaded as runtime substitutes.

Client codec/lifecycle tests construct a client without its constructor to isolate
PHP behavior; they do not claim to verify dispatch, Promise pumping or the
browser transport. `composer test-integration` checks the native batch bridge
with the optimized extension loaded; missing extension support is an error.
Browser integration must be run separately using the smoke test under `tests/Browser/`; it requires the optimized extension,
the built JavaScript bundle and a Chrome endpoint. Missing prerequisites must not
be treated as an integration pass.

`composer psalm` checks the PHP runtime and unit tests at level 3, as in the native
branch, without a baseline. Broader API coverage belongs to the runtime and
generator implementation steps.

Psalm covers `src/`, `tests/Unit/` and `tests/Integration/`. The browser smoke script is executed separately; it is not included in static analysis.
