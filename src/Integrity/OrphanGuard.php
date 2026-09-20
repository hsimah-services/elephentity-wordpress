<?php

declare(strict_types=1);

namespace Eleph\WordPress\Integrity;

use Eleph\WordPress\Database\Database;
use Eleph\WordPress\Manifest\StorageManifest;

/**
 * Detaches deleted WordPress posts. Entity deletion and relationship integrity belong to Elephentity.
 */
final readonly class OrphanGuard
{
    /**
     * The compiled manifest rather than the spec, so this works at run time.
     *
     * It carries a field-to-column map per entity, which already answers the only
     * question here — which entities project to a post row — so nothing new had to be
     * compiled for it. Taking the `Schema` meant shipping the spec compiler to
     * production and parsing YAML to answer it.
     */
    public function __construct(
        private StorageManifest $manifest,
        private Database $database,
    ) {
    }

    /**
     * Hook this on `before_delete_post`.
     */
    public function onPostDeleted(int $postId): void
    {
        foreach ($this->tablesTrackingPosts() as $table) {
            $this->database->execute(
                sprintf('UPDATE `%s` SET `wp_post_id` = NULL WHERE `wp_post_id` = %%d', $table),
                [$postId],
            );
        }
    }

    /**
     * Tables with integration-owned post links.
     *
     * @return list<string>
     */
    public function tablesTrackingPosts(): array
    {
        $tables = [];

        foreach ($this->manifest->posts as $entity => $postType) {
            if (!isset($this->manifest->tables[$entity])) {
                continue;
            }

            $tables[] = $this->manifest->tables[$entity]->name;
        }

        sort($tables);

        return $tables;
    }
}
