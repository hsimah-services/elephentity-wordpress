# elephentity-wordpress

The WordPress storage adaptor for [Elephentity](https://github.com/hsimah-services/elephentity)
— custom tables for entity fields, post types where the WordPress ecosystem actually
needs one, taxonomies as edges, `wp_users` as an account backend. The only package
permitted to name a `WP_*` symbol.

```bash
composer require elephentity/wordpress
```

You will also want the build-time builder that produces the manifest this package
loads: `composer require --dev elephentity/codegen-wordpress`.

## What this is

A runtime package: the adaptor, the manifest loaders, the migration planner, the
registrars, the verifier. It implements
[`Eleph\Runtime\Storage\StorageAdaptor`](https://github.com/hsimah-services/elephentity-runtime/blob/main/src/Storage/StorageAdaptor.php)
and requires nothing but `elephentity/runtime`.

## What this is not

Not a code generator. The compiler that turns a spec into the physical schema this
package loads lives in
[`elephentity-codegen-wordpress`](https://github.com/hsimah-services/elephentity-codegen-wordpress)
— a separate, build-time-only repository that depends on nothing of Elephentity's, so
it never ships to production. This package and that one are held in step by a version
gate on the wire format, not a shared classpath: see
[`.llms/cross-repo.md`](.llms/cross-repo.md).

## Working in this repository

There is no local PHP. Everything runs in a container:

```bash
./tools/php composer ci          # style, static analysis, tests
./tools/php composer style:fix
./tools/php vendor/bin/phpunit --filter SomeTest
```

`composer ci` must pass before committing. PHPStan runs at **level max** with no
baseline exclusions — the framework's whole claim is that generated code is provably
typed, and an exception here undermines that for the one package doing real I/O.

## Before you commit anything that crosses a repository boundary

**[`.llms/cross-repo.md`](.llms/cross-repo.md) is the closed list of what crosses.** If
you changed something on it, open an issue on each repository it reaches, before or
with the push. [`.llms/README.md`](.llms/README.md) has the rule and
[`.llms/issue-template.md`](.llms/issue-template.md) the shape.

**Renaming a class here is invisible to every test in this repository or in
`elephentity-codegen-wordpress`.** The builder emits the class name as a string, not an
import — see `Runtime.php` there. It only fails in a real project, at boot, after
generating. Regenerating `clog` in
[`elephentity-examples`](https://github.com/hsimah-services/elephentity-examples) is
what catches it.

## Generated admin pages and optional posts

The WordPress builder's `integrations.wordpress` settings default to
`adminTemplates: true` and `linkPosts: false`. Hook generated pages on `admin_menu`:

```php
use Eleph\WordPress\Admin\Pages;

add_action('admin_menu', static function () use ($runtime): void {
    Pages::fromManifest(__DIR__ . '/generated/wordpress/admin-pages.php', $runtime)->register();
});
```

The views require `manage_options` and load records through the runtime's read
policies. Applications supplying a parent menu slug must register that parent menu.
With templates enabled, the builder hides native post screens for linked entities.

Enabling `linkPosts` on an entity with `storage.handle` adds an integration-owned
`wp_post_id` column. `WordPress::adaptor()` consumes the manifest's `posts` mapping
and creates a draft post alongside each new entity. The entity keeps its own ID;
foreign keys and join tables continue to use Elephentity IDs. WordPress insertion
errors prevent entity insertion. Entity insertion errors clean up the new post.
The runtime's transaction must use the same WordPress database connection; WordPress
hooks can have external effects that a database rollback cannot undo.

`OrphanGuard::onPostDeleted()` clears `wp_post_id` instead of deleting entity rows.
This keeps external post deletion from bypassing Elephentity relationship rules.
Deleting an entity does not delete its linked post; post publication, updates,
backfilling existing records, and post retention remain application decisions.
