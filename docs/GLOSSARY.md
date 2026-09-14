# elephentity-wordpress: glossary

Carried over from `elephentity`'s `docs/GLOSSARY.md` when
[elephentity#79](https://github.com/hsimah-services/elephentity/issues/79)
moved the runtime adaptor to this repository. The core glossary still defines
the platform-neutral vocabulary (Storage adaptor, Capability, Link/Unlink); this
is only the WordPress-specific terms that were never used outside this package.

## WordPress

**Projection.** The `wp_posts` row standing in for an entity. The custom table is
authoritative; the post row exists so the ecosystem has something to hold on to.

**Orphan guard.** The `before_delete_post` hook. Nothing in the framework sees someone
empty the trash in wp-admin, and without it the post row goes while the custom row
survives pointing at nothing.

**Refusal.** A migration the planner will not make unattended. A column in the database
but not the spec is either a rename or a drop, and the diff cannot tell which — so it
stops and asks for an explicit migration rather than guessing and destroying data.
