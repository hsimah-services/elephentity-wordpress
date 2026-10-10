<?php

declare(strict_types=1);

namespace Eleph\WordPress\Taxonomy;

/**
 * Legacy native-object placement, retained so stale manifests fail with a regeneration message.
 */
final readonly class TaxonomyPlacement
{
    public function __construct(
        /** The entity declaring the edge — the one whose rows carry terms. */
        public string $entity,
        public string $edge,
        /** The taxonomy-backed entity this edge points at. */
        public string $target,
        /** The `register_taxonomy()` slug, from the target's own storage handle. */
        public string $taxonomy,
    ) {
    }
}
