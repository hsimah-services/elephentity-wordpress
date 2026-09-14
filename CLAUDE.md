# elephentity-wordpress

Read [README.md](README.md) first, then [docs/PLAN.md](docs/PLAN.md) — the design
record for the adaptor, carried over from `elephentity`'s own plan when this package
moved out (elephentity#79). [docs/GLOSSARY.md](docs/GLOSSARY.md) has the
WordPress-specific vocabulary; the platform-neutral terms are defined in
`elephentity`'s own glossary.

## Split out of `elephentity`'s `packages/wordpress`

This repository holds the runtime half only: the adaptor, the manifest loaders,
the migration planner, the registrars, the verifier. Everything build-time — the
manifest builders, `EdgePlanner`, `SchemaBuilder`, the `bin/eleph-gen-wordpress`
binary — lives in
[`elephentity-codegen-wordpress`](https://github.com/hsimah-services/elephentity-codegen-wordpress)
instead, mirroring what `elephentity-codegen-php` already did for the PHP target.

That repository's `src/Runtime.php` names this package's classes as **string
constants**, not imports — it has no dependency on `elephentity/wordpress` at all, by
design. Renaming or moving a class here does not fail there; it fails in a real
project, at boot, when the exported manifest names a class that no longer exists.
Regenerating `clog` in `elephentity-examples` is the check that catches it.

## Working in this repository

There is no local PHP. Everything runs in a container:

```bash
./tools/php composer ci          # style, static analysis, tests
./tools/php composer style:fix
./tools/php vendor/bin/phpunit --filter SomeTest
```

`composer ci` must pass before committing. PHPStan runs at **level max** with no
baseline exclusions, scanning `php-stubs/wordpress-stubs` so real WP symbols
type-check without a live WordPress install.

## Conventions that are load bearing

- **This is the only package permitted to name a `WP_*` symbol, call `$wpdb`, or use a
  WordPress hook function.** Every other Elephentity package is checked against this by
  `tools/check-architecture.php` in `elephentity`; there is nothing to enforce it *here*
  since everything in `src/` is expected to.
- **Naming is derived, never declared**, and `src/Sql/Naming.php` has to agree with
  `elephentity-codegen-wordpress`'s own copy of the same rules — not an oversight, the
  same reasoning as the builder split generally: two independent copies held in step by
  a version gate, not a shared classpath.
- **The conformance suite lives in `elephentity/runtime`**
  (`Storage\Testing\AdaptorConformance`), not here, so any third-party adaptor can prove
  itself against the same assertions this one does. It is not currently run for real
  against `WordPressAdaptor` — its tests use `FakeDatabase`, a spy that records the SQL
  `QueryCompiler` produced rather than executing it — which is a known gap, not an
  oversight; fixing it is real integration infrastructure (MariaDB in CI) and belongs to
  a change of its own.

## Before you commit anything that crosses a repository boundary

**[`.llms/cross-repo.md`](.llms/cross-repo.md) is the closed list of what crosses.** If
you changed something on it, open an issue on each repository it reaches, before or
with the push. [`.llms/README.md`](.llms/README.md) has the rule and
[`.llms/issue-template.md`](.llms/issue-template.md) the shape.

## Before you commit

- `./tools/php composer ci`
- If you changed anything `elephentity-codegen-wordpress` names in its own
  `src/Runtime.php`, open an issue there — a rename here is invisible to its tests.
- Regenerate `clog` in `elephentity-examples` and read the diff if the change is one a
  real project would see.
