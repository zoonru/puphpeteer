# PuPHPeteer v3

Framework for developing a native PHP client compatible with Puppeteer's public
API. This branch currently contains API inventory and ignore-selection tools.
The PHP client, PHP scaffold generator and browser conformance tests are not yet
implemented. There are no runtime PHP classes in this checkout.

The previous Rialto client is available in Git history. Earlier native and QuickJS
experiments are preserved in the `native-wip` and `quickjs-prototype` branches.

## Setup

Requires PHP 8.1+, Composer, Node.js 18+ and npm.

```sh
composer install
npm ci --ignore-scripts
```

Node.js and Puppeteer are development tools. Installation does not download or
launch a browser. JS tool dependencies are fixed in `package-lock.json` for
reproducible runs. `composer.json` declares the PHP library's dependency constraints;
`composer.lock` is local and is not committed. Consuming applications manage their
own Composer lock files.

## Available commands

All checks currently run locally. CI is not configured.

```sh
composer upstream-report
composer test-tooling
```

`upstream-report` lists the API enabled by the current
[ignore file](upstream/ignore.json), and reports stale exclusions.
`test-tooling` tests public API inventory and exclusion rules; it does not test a
PHP client or start a browser. `composer test` and `npm test` run the same tooling
tests. `npm run upstream-report` provides the report without Composer.

## Development

The current [design](docs/v3-framework-design.md) separates automatic declaration
synchronization, automated checks, and implementation by a developer or agent.
All tools use the current ignore file to select public API. Remove exclusions to
expand the implementation; ordinary updates must preserve those edits.

See [the ignore documentation](upstream/README.md) for details. Upcoming work is
signature/type extraction, PHP scaffolding, test discovery and PHP/JS behavior
comparison. The generator will create PHP scaffold files as these tools are built.
