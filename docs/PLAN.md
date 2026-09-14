# elephentity-wordpress: design notes

Carried over from `elephentity`'s `docs/PLAN.md` §12 when
[elephentity#79](https://github.com/hsimah-services/elephentity/issues/79)
moved the runtime adaptor to this repository — the reasoning is the artifact,
not the code, so it moved with the code it explains. Section numbering is as
it stood in the monorepo; nothing here has been re-derived.

## 12. Storage: the adapters

**Custom tables, WP-native where needed.** Real typed columns and indexes for entity
fields; register a post type only where WP ecosystem integration (FacetWP, admin,
permalinks) actually requires it.

### Migrations — a layer custom tables force on us

DDL is generated deterministically from the schema, but a diff of two schema versions
cannot infer intent: `title` disappearing while `heading` appears is either a rename
or a drop-plus-add, and guessing destroys data.

- Additive changes (new nullable column, new index) → generated and auto-applied.
- Destructive or ambiguous changes → **generation fails** and demands an explicit,
  checked-in migration file.

Our own migration runner, not `dbDelta()`.

**Migration is a runtime call, not an `eleph` command**, and `SchemaInstaller` is the
entry point. This was listed as a CLI verb from the start and could never have been
one: migrating means diffing against a live database, and the CLI runs at build time
with no WordPress loaded and no credentials. A build-time `migrate` could only have
emitted a fresh install's DDL and called it a migration, which is the guess this whole
section exists to refuse. Plugin activation is where the database is.

**Nothing is applied when anything is refused.** Applying the safe half of a plan leaves
a schema that is two states away from the spec, on which the application boots and some
queries work — strictly worse to diagnose than one that was never migrated.

The manifest carries **join tables** as well as entity tables. Keyed by entity, a
many-to-many link table belonged to nobody and was dropped, so an edge compiled to a
placement pointing at a table nothing would ever create.

### Registration is compiled, like everything else

`register_post_type()`'s arguments are derived from the spec at build time and written
to `post-types.php`, beside the storage manifest. They were once derived at *run* time
from the compiled `Schema` — which meant a plugin that registered post types shipped the
spec compiler and parsed YAML on every request, the one cost every manifest here exists
to remove, and a dev-only package in production besides.

`OrphanGuard` was the same shape and is fixed the same way, without needing anything new
compiled: the storage manifest already carries a field-to-column map per entity, which
answers "does this entity project to a post row" directly.

### Post-row divergence

When a post type is registered, the custom table row and the post row are two records
that can diverge. The **custom table is authoritative**.

**Nothing in the framework writes the post row.** This was once stated the other way
round — "a projection the Mutator writes as part of the same unit of work" — and no
such code ever existed, which is worse than the gap itself: an entity declaring a
`postId` looked like it would be filled and silently was not.

The projection stays the application's, for a reason that outlives the missing code. A
post row is a WordPress-shaped side effect of a commit, and side effects on commit are
already a thing the framework has: a `postCommit` trigger. Building a second,
adaptor-level mechanism for the one platform that needs it would put a WordPress
concept inside the unit of work, which is exactly what the storage port exists to
prevent.

Two consequences, both deliberate:

- **`postId` is nullable**, in the example pattern and anywhere else. A non-null column
  nothing fills is a create that either fails or stores zero, depending on the
  installation's SQL mode.
- **Delete events fire for cascades.** An application maintaining a projection has to
  see every row that goes, not only the one it asked to delete, so the unit of work
  announces every planned removal — and announces it *before* the DELETE, since a
  trigger that cannot read the row it is being told about cannot project it.

### Taxonomy-backed entities

A classification vocabulary — a `name` field and a many-to-many edge to whatever it
classifies, nothing else — gets a custom table, a post type and a full WPGraphQL type
by default, which is a lot of machinery for something `register_taxonomy()` already is.
An entity can opt out of all of it instead.

**The marker is pattern configuration, the same extension point `visibility` and
`adminMenu` already use.** A project's own pattern (shaped like `ClogPost`, not a
pattern this framework ships) sets `taxonomy: true` in its `config:`, and the entity's
`storage.handle` is read as the taxonomy slug rather than a post type slug. Nothing in
`packages/schema` learns what a taxonomy is — `EdgePlanner::isTaxonomy()` is the one
place that reads the flag, and every other WordPress-package class downstream of it
either skips such an entity or treats it specially, without any of them changing what
the core IR carries.

**Placement, not declaration, decides where an edge to one lives.** A many-to-many edge
whose target is taxonomy-backed is diverted at the same point `EdgePlanner` decides
every other edge's placement: `plan()` skips it (there is no column or join table to
place — WordPress's own `wp_term_relationships` already is one), and
`planTaxonomies()` records a `TaxonomyPlacement` instead. `SchemaBuilder` never creates
a table for the taxonomy entity itself, for the same reason.

**Term relationships are keyed by the entity's own id, not its `postId`.** The obvious
alternative — using the real `wp_posts.ID` so WordPress's own `tax_query` and admin
term lists see the relationship — was rejected because nothing in this framework
reliably has that id at write time: `postId`, per "Post-row divergence" above, is
filled by an application's own `postCommit` trigger, asynchronously, and possibly never.
Keying by the framework's own id keeps this correct and fully framework-owned, at the
cost of WordPress-native `tax_query`/admin-list integration needing the application to
keep the two in sync itself — the identical tradeoff `postId` already makes.

**Only `name` is supported.** Every real motivating case for this pattern is exactly a
label; a taxonomy entity with any other field is a spec error this package has no way
to report today, so it is silently ignored by `TaxonomyStorage` rather than guessed at.

**Reading the edge forwards and backwards go through different code**, because they
are genuinely different queries. Forwards — "this Tutorial's Akas" — queries the
taxonomy entity itself and is `TaxonomyStorage`'s job, batched across many parents via
`Terms::termsOfMany()` the same way the SQL edge loader batches. Backwards — "which
Tutorials carry this Aka" — queries Tutorial's own table, so it stays in
`QueryCompiler`, joining WordPress's own `wp_term_relationships`/`wp_term_taxonomy`
tables directly (their names carry only `$wpdb`'s prefix, never this schema's own
additional layer).

**Deferred**: `required: true` on an edge (tracked separately, elephentity#35),
hierarchical taxonomies beyond the registration flag (no parent-edge modelling), and
WPGraphQL-native taxonomy connections — the existing manifest builder already exposes
a taxonomy entity as an ordinary object type, which is enough to query but not to
integrate with WPGraphQL's own taxonomy-aware tooling.

### Account-backed entities

A project's own account or user entity had no way to *be* a WordPress account rather
than duplicate one: the only shapes available were a `ClogPost`-style projection (a
custom table plus a `wp_posts` row) and a taxonomy term, neither of which fits "this
row is the account `wp_users` already tracks." Once a project needs read/write
policies — `Viewer::id()` is a `wp_users.ID` — the gap stopped being cosmetic: nothing
answered "which row of mine is the current viewer" without a hand-added, ungated,
duplicate-identity workaround. Resolves elephentity#51.

**The marker is the same pattern configuration `taxonomy` uses**, `account: true` in
a project's own post-shaped pattern's `config:`. `EdgePlanner::isAccount()` is the one
new reader of it, beside `isTaxonomy()`. Unlike a taxonomy, an account-backed entity
needs no edge-placement changes at all: an edge pointing at one is an ordinary column
or join table storing an id that happens to be a `wp_users.ID`, which every other
relation already knows how to place. Only the entity's *own* rows are special.

**The entity's id is the `wp_users.ID` directly** — `EntityId::of($wpUserId)`, no
separate framework-generated id, no bridge field, no lookup. This is what makes
"is this row the current viewer" a comparison rather than a query, and it is also
exactly how a taxonomy entity's id is already the WordPress term id
(`TaxonomyStorage::insert()` returns `EntityId::of($termId)`) — the same precedent,
applied to the one identity WordPress itself hands out per request.

**Existence is `wp_users` having the row, nothing else.** `SchemaBuilder` creates no
table for an account-backed entity, the same as for a taxonomy. `AccountStorage::get()`
answers null only when `get_userdata()` does; a row this framework has never written
to still loads, because the account existed the moment WordPress registered it, not
the moment this framework first touched it. Declared fields are ordinary
`wp_usermeta`, keyed by the same snake_case name `FieldMap` already gives every SQL
column — no new naming scheme, and a field this framework never writes (`postId`,
inherited from the post pattern like every other field it contributes) is simply
always null, exactly as it already is for every other post-projecting entity.

**The two fields the unit of work stamps need one exception each, not a
special-cased entity.** `Managed::Created` is stamped only on insert, and this class
refuses to insert — an account isn't created here, it already exists — so that field
never arrives as a value to store; it is always answered from `wp_users`'s own
`user_registered`, the one creation timestamp guaranteed to exist for a row this
framework did not write. `Managed::Modified` is stamped on every write, insert
included, so it arrives on every update like any other value and is `wp_usermeta`
once written — falling back to `user_registered` before the first one, so a
non-nullable field never sees a null this class could have avoided. Every other
field, managed or not, is treated identically regardless of which pattern
contributed it — the same "spec error this package has no way to report today"
posture `postId` already lives under for a taxonomy.

**Creation and deletion are refused, on purpose.** `wp_insert_user()` and
`wp_delete_user()` are WordPress-level decisions — registration flows, capability
checks, related-content cleanup — not something a unit of work should attempt on a
row it does not own the lifecycle of. `AccountStorage::insert()` and `::delete()`
throw; the only supported writes are `update()`, against `wp_usermeta`.

**Deferred**: exposing `wp_users` core columns other than the registration date
(`user_login`, `user_email`) as generated fields — the issue's own "ideally" — is a
per-field-name mapping this pass does not attempt; every declared field beyond the
two managed timestamps is `wp_usermeta`, full stop. Also deferred: an account entity
declaring edges of its own (only other entities *pointing at* one is supported,
mirroring a taxonomy's own edge-shape restriction), and a real project's way to seed
the first `wp_usermeta` row for a newly registered account, which is presumably a
`postCommit`-trigger-shaped concern on whatever entity WordPress's own registration
hook fires against — the same shape the post-row-divergence problem already has.

### The memory adapter, and the conformance suite that proves it

`StorageAdaptor` was defined before a second implementation existed, deliberately, to
keep it honest (§12 above). `elephentity/memory` is that second implementation: no
table, no post type, no process boundary — a `Record` per entity in a PHP array — and
it declares only `Capability::Transactions`, which is the honest answer for a store
with no disk, no network and no other process to disagree with it (#52 G6 stays
closed: nothing here forces `Capability` open).

**Edges need no schema because they are stored as facts, not columns.** WordPress
knows whether an edge is a foreign-key column or a join table because a compiled
`TableSchema`/`EdgePlacement` manifest tells it so — knowledge this adaptor has no
manifest to receive, by design. Instead, a `Link($entity, $edge, $from, $to)` is kept
verbatim: an adjacency list keyed by `"$entity.$edge"`, mapping the declaring row's id
to every id it points at. Multiplicity falls out of this for free — a to-one edge just
happens to keep one target at a time, because the unit of work unlinks the old one
before linking the new — so the adaptor never needs to know which kind of edge it is
storing.

Reading it back means recovering `EdgeFilter`'s two directions from first principles,
since there is no `EdgePlacement.keyIsLocal()` to ask. Reverse-engineered from
`QueryCompiler`'s actual SQL (its doc comments alone do not disambiguate this):
`EdgeFilter::back($declaring, $edge, ...$targets)` filters rows *of the declaring
entity* whose own id maps to one of `$targets`; `EdgeFilter::along($declaring, $edge,
...$declaringRowIds)` filters rows *of the target entity* that appear among what those
declaring rows point at. Both are one pass over the adjacency list — a union for
`along`, an intersection per row for `back` — with no join planning at all.

**The conformance suite lives in `elephentity/runtime`**
(`Storage\Testing\AdaptorConformance`), not in a test framework: it returns a
`list<string>` of failures rather than asserting, so a third-party adaptor can run it
from any test tool or none. It is exercised for real, round-trip, against
`MemoryAdaptor` — write, get, getMany, filter, order, page, link, count and a
transaction that genuinely rolls back on throw.

**It is not run for real against `WordPressAdaptor`.** That adaptor's tests
(`FakeDatabase`) are a spy that records the SQL `QueryCompiler` produced; it does not
execute it, so it cannot honestly answer "did a write and a later read agree." Proving
`WordPressAdaptor` against this suite needs a real MySQL-compatible database, which
this repository's test harness does not have. The suite is written to be that proof
the day such a harness exists — most plausibly when a PDO adaptor lands and brings a
reason to stand one up — rather than deferred further.

**`FakeStorage`, in `packages/runtime/tests/UnitOfWork/`, stays.** It answers a
different question than either adaptor: not "does a write round-trip," which it
deliberately fails (`get()` always returns null), but "does `UnitOfWork` call the port
in the right order," via a `$log`/`$batches` a real adaptor has no reason to expose.
Two in-memory adaptors would be the drift #52 M5 exists to stop; a spy and a store are
not the same claim.
