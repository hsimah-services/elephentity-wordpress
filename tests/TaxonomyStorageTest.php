<?php

declare(strict_types=1);

namespace Eleph\WordPress\Tests;

use Eleph\Runtime\Identity\EntityId;
use Eleph\Runtime\Identity\PendingId;
use Eleph\Runtime\Storage\Criteria;
use Eleph\Runtime\Storage\Filter;
use Eleph\Runtime\Storage\Order;
use Eleph\Runtime\Storage\Write\Delete;
use Eleph\Runtime\Storage\Write\Insert;
use Eleph\Runtime\Storage\Write\Update;
use Eleph\WordPress\Taxonomy\TaxonomyStorage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(TaxonomyStorage::class)]
final class TaxonomyStorageTest extends TestCase
{
    private const TAXONOMY = 'aka';

    public function testPagesHonorExplicitIdOrder(): void
    {
        [$terms, $storage] = $this->storage();
        $first = $terms->insert(self::TAXONOMY, 'Zulu');
        $second = $terms->insert(self::TAXONOMY, 'Alpha');
        $criteria = Criteria::for('Aka')->orderBy(Order::descending('id'));
        $page = $storage->query('Aka', self::TAXONOMY, $criteria->take(1));
        self::assertSame($second, $page->items[0]->id->raw());
        $next = $storage->query('Aka', self::TAXONOMY, $criteria->take(1, $page->next));
        self::assertSame($first, $next->items[0]->id->raw());
        self::assertFalse($next->hasMore());
    }

    public function testGetReturnsARecordForAnExistingTerm(): void
    {
        [$terms, $storage] = $this->storage();
        $id = $terms->insert(self::TAXONOMY, 'Sparky');

        $record = $storage->get('Aka', self::TAXONOMY, EntityId::of($id));

        self::assertNotNull($record);
        self::assertSame('Sparky', $record->value('name'));
        self::assertSame($id, $record->id->raw());
    }

    public function testGetReturnsNullForAMissingTerm(): void
    {
        [, $storage] = $this->storage();

        self::assertNull($storage->get('Aka', self::TAXONOMY, EntityId::of(999)));
    }

    public function testGetManySilentlyDropsMissingIds(): void
    {
        [$terms, $storage] = $this->storage();
        $id = $terms->insert(self::TAXONOMY, 'Sparky');

        $records = $storage->getMany('Aka', self::TAXONOMY, [EntityId::of($id), EntityId::of(999)]);

        self::assertCount(1, $records);
        self::assertSame('Sparky', $records[0]->value('name'));
    }

    public function testInsertCreatesATermAndReturnsItsId(): void
    {
        [$terms, $storage] = $this->storage();

        $id = $storage->insert(self::TAXONOMY, new Insert('Aka', new PendingId('Aka'), ['name' => 'Sparky']));

        self::assertSame('Sparky', $terms->name(self::TAXONOMY, (int) $id->raw()));
    }

    public function testInsertRequiresAStringName(): void
    {
        [, $storage] = $this->storage();

        $this->expectException(RuntimeException::class);

        $storage->insert(self::TAXONOMY, new Insert('Aka', new PendingId('Aka'), ['name' => null]));
    }

    public function testUpdateChangesTheName(): void
    {
        [$terms, $storage] = $this->storage();
        $id = $terms->insert(self::TAXONOMY, 'Sparky');

        $storage->update(self::TAXONOMY, new Update('Aka', EntityId::of($id), ['name' => 'Rex']));

        self::assertSame('Rex', $terms->name(self::TAXONOMY, $id));
    }

    public function testUpdateWithNoNameTouchesNothing(): void
    {
        [$terms, $storage] = $this->storage();
        $id = $terms->insert(self::TAXONOMY, 'Sparky');

        $storage->update(self::TAXONOMY, new Update('Aka', EntityId::of($id), []));

        self::assertSame('Sparky', $terms->name(self::TAXONOMY, $id));
    }

    public function testDeleteRemovesTheTerm(): void
    {
        [$terms, $storage] = $this->storage();
        $id = $terms->insert(self::TAXONOMY, 'Sparky');

        $storage->delete(self::TAXONOMY, new Delete('Aka', EntityId::of($id)));

        self::assertNull($terms->name(self::TAXONOMY, $id));
    }

    public function testQueryWithNoLinksListsEveryTermAlphabeticallyByInsertion(): void
    {
        [$terms, $storage] = $this->storage();
        $terms->insert(self::TAXONOMY, 'B');
        $terms->insert(self::TAXONOMY, 'A');

        $page = $storage->query('Aka', self::TAXONOMY, Criteria::for('Aka'));

        self::assertCount(2, $page->items);
        self::assertFalse($page->hasMore());
    }

    public function testQueryPaginatesAndReportsMore(): void
    {
        [$terms, $storage] = $this->storage();
        $a = $terms->insert(self::TAXONOMY, 'A');
        $terms->insert(self::TAXONOMY, 'B');
        $terms->insert(self::TAXONOMY, 'C');

        $page = $storage->query('Aka', self::TAXONOMY, Criteria::for('Aka')->take(2));

        self::assertCount(2, $page->items);
        self::assertTrue($page->hasMore());
        self::assertSame($a, $page->items[0]->id->raw());

        $next = $storage->query('Aka', self::TAXONOMY, Criteria::for('Aka')->take(2, $page->next));

        self::assertCount(1, $next->items);
        self::assertFalse($next->hasMore());
    }

    public function testQueryFieldFiltersAreRefused(): void
    {
        [, $storage] = $this->storage();

        $this->expectException(RuntimeException::class);

        $storage->query('Aka', self::TAXONOMY, Criteria::for('Aka')->where(Filter::equals('name', 'x')));
    }

    public function testCountWithNoLinksCountsEveryTerm(): void
    {
        [$terms, $storage] = $this->storage();
        $terms->insert(self::TAXONOMY, 'A');
        $terms->insert(self::TAXONOMY, 'B');

        self::assertSame(2, $storage->count(self::TAXONOMY, Criteria::for('Aka')));
    }

    /**
     * @return array{0: FakeTerms, 1: TaxonomyStorage}
     */
    private function storage(): array
    {
        $terms = new FakeTerms();

        return [$terms, new TaxonomyStorage($terms)];
    }
}
