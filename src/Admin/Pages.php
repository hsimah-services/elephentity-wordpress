<?php

declare(strict_types=1);

namespace Eleph\WordPress\Admin;

use Closure;
use Eleph\Runtime\Gateway\EntityGateway;
use Eleph\Runtime\Identity\EntityId;
use Eleph\Runtime\Policy\AccessDenied;
use Eleph\Runtime\Storage\Offset;
use RuntimeException;

/** Registers generated read-only views; every lookup goes through runtime read policies. */
final readonly class Pages
{
    /** @param array<string, array{label: string, slug: string, parent: ?string, list: string, detail: string}> $pages */
    public function __construct(private EntityGateway $runtime, private string $directory, private array $pages)
    {
    }

    public static function fromManifest(string $path, EntityGateway $runtime): self
    {
        if (!is_file($path)) {
            throw new RuntimeException(sprintf('No admin manifest at %s. Run `eleph generate`.', $path));
        }

        /** @var array<string, array{label: string, slug: string, parent: ?string, list: string, detail: string}> $pages */
        $pages = require $path;

        return new self($runtime, dirname($path), $pages);
    }

    /** Hook on admin_menu. Both templates must exist before a page is exposed. */
    public function register(): void
    {
        foreach ($this->pages as $entity => $page) {
            if (!is_file($this->directory . '/' . $page['list']) || !is_file($this->directory . '/' . $page['detail'])) {
                continue;
            }

            $callback = fn () => $this->render($entity);

            if (null !== $page['parent']) {
                add_submenu_page($page['parent'], $page['label'], $page['label'], 'manage_options', $page['slug'], $callback);
            } else {
                add_menu_page($page['label'], $page['label'], 'manage_options', $page['slug'], $callback, 'dashicons-database');
            }
        }
    }

    public function render(string $entity): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('You are not allowed to view these records.', '', ['response' => 403]);
        }

        $page = $this->pages[$entity] ?? throw new RuntimeException('Unknown admin page.');
        $id = $_GET['record'] ?? null;
        $view = new View($page['slug']);

        if (null !== $id) {
            if (!is_string($id) || !ctype_digit($id) || '0' === $id) {
                wp_die('Invalid record ID.', '', ['response' => 400]);
            }

            try {
                $object = $this->runtime->find($entity, EntityId::of($id));
            } catch (AccessDenied) {
                $object = null;
            }

            if (null === $object) {
                wp_die('Record not found.', '', ['response' => 404]);
            }

            $data = ['record' => $object];
            $template = $page['detail'];
        } else {
            $offset = $_GET['offset'] ?? '0';
            $offset = is_string($offset) && ctype_digit($offset) ? min((int) $offset, PHP_INT_MAX - 50) : 0;
            $result = $this->runtime->all($entity)->page(50, (new Offset($offset))->toCursor());
            $data = ['records' => $result->items, 'next' => null === $result->next ? null : Offset::fromCursor($result->next)->value];
            $template = $page['list'];
        }

        /** @var Closure(View, array<string, mixed>): void $render */
        $render = require $this->directory . '/' . $template;
        $render($view, $data);
    }
}
