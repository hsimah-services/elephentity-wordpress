<?php

declare(strict_types=1);

namespace Eleph\WordPress\Registration;

use RuntimeException;

/**
 * Registers compiled post types. The storage adaptor creates linked posts.
 */
final readonly class PostTypeRegistrar
{
    /**
     * @param array<string, array<string, mixed>> $types Post type slug => registration arguments.
     */
    public function __construct(private array $types)
    {
    }

    /**
     * Load the compiled post types from disk.
     */
    public static function fromManifest(string $path): self
    {
        if (!is_file($path)) {
            throw new RuntimeException(sprintf(
                'No post type manifest at %s. Run `eleph generate`, and check the project '
                . 'configures a "wordpress" target.',
                $path,
            ));
        }

        /** @var mixed $types */
        $types = require $path;

        if (!is_array($types)) {
            throw new RuntimeException(sprintf('%s did not return an array of post types.', $path));
        }

        /** @var array<string, array<string, mixed>> $types */
        return new self($types);
    }

    /**
     * Hook this on `init`.
     */
    public function register(): void
    {
        foreach ($this->types as $handle => $arguments) {
            // The schema compiler has already rejected any handle that is not a legal
            // post type slug — lowercase, non-empty, within 20 characters — and the
            // argument shape is derived, not user-supplied.
            // @phpstan-ignore argument.type, argument.type
            register_post_type($handle, $arguments);
        }
    }

    /**
     * The registration arguments, for anything that wants to inspect them.
     *
     * @return array<string, array<string, mixed>>
     */
    public function arguments(): array
    {
        return $this->types;
    }
}
