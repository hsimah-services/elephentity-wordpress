<?php

declare(strict_types=1);

namespace Eleph\WordPress\Post;

use RuntimeException;

/** WordPress API boundary for optional post projections. */
class PostStorage
{
    /** @param array<string, scalar|null> $values */
    public function create(string $postType, string $entity, array $values): int
    {
        $title = $values['title'] ?? $values['name'] ?? $entity;
        $id = wp_insert_post([
            'post_type' => $postType,
            'post_title' => wp_slash((string) $title),
            'post_status' => 'draft',
            'comment_status' => 'closed',
            'ping_status' => 'closed',
        ], true);

        if (is_wp_error($id)) {
            throw new RuntimeException($id->get_error_message());
        }

        if ($id <= 0) {
            throw new RuntimeException('WordPress did not create the linked post.');
        }

        return $id;
    }

    public function delete(int $id): void
    {
        wp_delete_post($id, true);
    }
}
