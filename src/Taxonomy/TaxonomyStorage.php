<?php

declare(strict_types=1);

namespace Eleph\WordPress\Taxonomy;

use Eleph\Runtime\Identity\EntityId;
use Eleph\Runtime\Identity\Identifier;
use Eleph\Runtime\Storage\Criteria;
use Eleph\Runtime\Storage\Offset;
use Eleph\Runtime\Storage\Page;
use Eleph\Runtime\Storage\Record;
use Eleph\Runtime\Storage\Write\Delete;
use Eleph\Runtime\Storage\Write\Insert;
use Eleph\Runtime\Storage\Write\Update;
use RuntimeException;

/**
 * Reads and writes a taxonomy-backed entity's own rows — the terms themselves.
 * Domain edges are stored separately by WordPressAdaptor.
 *
 * The pure counterpart to `WordPressAdaptor`'s SQL half: given a `Terms` port, this
 * decides *what* to ask for, and is tested the same way `QueryCompiler` is, with a
 * fake standing in for WordPress.
 *
 * A taxonomy entity has exactly one field, `name` — the shape every real motivating
 * entity for this pattern has (a classification vocabulary is a label and nothing
 * else). Anything else declared on it is a spec error this class has no way to report,
 * so it is silently dropped rather than guessed at.
 */
final readonly class TaxonomyStorage
{
    private const NAME_FIELD = 'name';

    public function __construct(private Terms $terms = new WordPressTerms())
    {
    }

    public function get(string $entity, string $taxonomy, EntityId $id): ?Record
    {
        $name = $this->terms->name($taxonomy, $this->termId($id));

        return null === $name ? null : $this->record($entity, $id, $name);
    }

    /**
     * @param list<EntityId> $ids
     *
     * @return list<Record>
     */
    public function getMany(string $entity, string $taxonomy, array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        $names = $this->terms->names($taxonomy, array_map($this->termId(...), $ids));
        $records = [];

        foreach ($ids as $id) {
            $name = $names[$this->termId($id)] ?? null;

            if (null !== $name) {
                $records[] = $this->record($entity, $id, $name);
            }
        }

        return $records;
    }

    /**
     * @return Page<Record>
     */
    public function query(string $entity, string $taxonomy, Criteria $criteria): Page
    {
        $records = $this->listAll($entity, $taxonomy, $criteria);

        return $this->paginate($records, $criteria);
    }

    public function count(string $taxonomy, Criteria $criteria): int
    {
        $this->assertPlainListing($criteria);

        return $this->terms->count($taxonomy);
    }

    public function insert(string $taxonomy, Insert $operation): EntityId
    {
        return EntityId::of($this->terms->insert($taxonomy, $this->requireName($operation->values)));
    }

    public function update(string $taxonomy, Update $operation): void
    {
        if (!array_key_exists(self::NAME_FIELD, $operation->values)) {
            return;
        }

        $target = $operation->target();

        if (!$target instanceof EntityId) {
            throw new RuntimeException('A taxonomy update needs a persisted id.');
        }

        $this->terms->update($taxonomy, $this->termId($target), $this->requireName($operation->values));
    }

    public function delete(string $taxonomy, Delete $operation): void
    {
        $target = $operation->target();

        if ($target instanceof EntityId) {
            $this->terms->delete($taxonomy, $this->termId($target));
        }
    }

    /**
     * @return list<Record>
     */
    private function listAll(string $entity, string $taxonomy, Criteria $criteria): array
    {
        $this->assertPlainListing($criteria);

        $offset = Offset::fromCursor($criteria->after)->value;
        $ids = $this->terms->list($taxonomy, null === $criteria->limit ? null : $criteria->limit + 1, $offset);
        $names = $this->terms->names($taxonomy, $ids);

        $records = [];

        foreach ($ids as $id) {
            if (isset($names[$id])) {
                $records[] = $this->record($entity, EntityId::of($id), $names[$id]);
            }
        }

        return $records;
    }

    /**
     * @param list<Record> $records
     *
     * @return Page<Record>
     */
    private function paginate(array $records, Criteria $criteria): Page
    {
        if (null === $criteria->limit) {
            return new Page($records);
        }

        $offset = Offset::fromCursor($criteria->after)->value;
        $hasMore = count($records) > $criteria->limit;

        return new Page(
            array_slice($records, 0, $criteria->limit),
            $hasMore ? (new Offset($offset + $criteria->limit))->toCursor() : null,
        );
    }

    private function assertPlainListing(Criteria $criteria): void
    {
        if ([] !== $criteria->filters || [] !== $criteria->order || [] !== $criteria->links) {
            throw new RuntimeException(
                'A taxonomy entity supports only name and id; field filters and ordering are not available.',
            );
        }
    }

    /**
     * @param array<string, scalar|null> $values
     */
    private function requireName(array $values): string
    {
        $name = $values[self::NAME_FIELD] ?? null;

        if (!is_string($name)) {
            throw new RuntimeException('A taxonomy term needs a string "name".');
        }

        return $name;
    }

    private function record(string $entity, EntityId $id, string $name): Record
    {
        return new Record($entity, $id, [self::NAME_FIELD => $name]);
    }

    private function termId(Identifier $identifier): int
    {
        $raw = $identifier instanceof EntityId ? $identifier->raw() : (string) $identifier;

        return is_int($raw) ? $raw : (int) $raw;
    }
}
