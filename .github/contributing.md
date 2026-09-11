# Contributing to v3

Read the [framework design](../docs/v3-framework-design.md) and
[ignore rules](../upstream/README.md) before changing the tools.

Install dependencies with `composer install` and `npm ci --ignore-scripts`.
Run `composer test-tooling` and `composer upstream-report` before submitting changes.
Current tests exercise the development tools; they do not verify browser control.

For a bug report, include the failing command, its output, package version,
relevant ignore entries, and a minimal reproduction. Keep changes to implementation
logic separate from automatic declaration synchronization. Preserve existing PHP
bodies and user-maintained ignore entries when extending the generator.
