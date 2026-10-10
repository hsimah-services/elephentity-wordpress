<?php

declare(strict_types=1);

namespace Eleph\WordPress\Tests;

use Eleph\Runtime\Identity\EntityId;
use Eleph\Runtime\Storage\Criteria;
use Eleph\Runtime\Storage\EdgeFilter;
use Eleph\Runtime\Storage\RelationKind;
use Eleph\Runtime\Storage\Write\Link;
use Eleph\Runtime\Storage\Write\Unlink;
use Eleph\Runtime\Storage\Write\WriteBatch;
use Eleph\WordPress\Sql\Column;
use Eleph\WordPress\Sql\EdgePlacement;
use Eleph\WordPress\Sql\FieldMap;
use Eleph\WordPress\Sql\QueryCompiler;
use Eleph\WordPress\Sql\TableSchema;
use Eleph\WordPress\Taxonomy\TaxonomyPlacement;
use Eleph\WordPress\Taxonomy\TaxonomyStorage;
use Eleph\WordPress\WordPressAdaptor;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class TaxonomyEdgesTest extends TestCase
{
    public function testDomainEdgesAreIsolatedAndPageInBothDirections(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE location (id INTEGER PRIMARY KEY)');
        $pdo->exec('CREATE TABLE inventory (id INTEGER PRIMARY KEY)');
        $pdo->exec('INSERT INTO location VALUES (1), (2)');
        $pdo->exec('INSERT INTO inventory VALUES (1)');
        foreach (['location_sites', 'inventory_site', 'location_other'] as $table) {
            $pdo->exec("CREATE TABLE $table (source_id INTEGER, term_id INTEGER, UNIQUE(source_id, term_id))");
        }
        $database = new class ($pdo) extends FakeDatabase {
            public function __construct(private PDO $pdo)
            {
            }

            public function select(string $sql, array $bindings = []): array
            {
                $statement = $this->pdo->prepare(str_replace('%d', '?', $sql));
                $statement->execute($bindings);
                /** @var list<array<string, scalar|null>> $rows */
                $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

                return $rows;
            }

            public function execute(string $sql, array $bindings = []): int
            {
                $statement = $this->pdo->prepare(str_replace(['INSERT IGNORE', '%d'], ['INSERT OR IGNORE', '?'], $sql));
                $statement->execute($bindings);

                return $statement->rowCount();
            }
        };
        $terms = new FakeTerms();
        $a = $terms->insert('site', 'Cave');
        $b = $terms->insert('site', 'Pantry');
        $placements = [];
        foreach (['Location.sites' => 'location_sites', 'Inventory.site' => 'inventory_site', 'Location.other' => 'location_other'] as $key => $table) {
            [$entity, $edge] = explode('.', $key);
            $placements[$key] = new EdgePlacement($entity, $edge, 'Site', RelationKind::ManyToMany, $table, 'source_id', 'term_id');
        }
        $adapter = new WordPressAdaptor(
            $database,
            [
            'Location' => new TableSchema('location', ['id' => new Column('id', 'BIGINT')]),
            'Inventory' => new TableSchema('inventory', ['id' => new Column('id', 'BIGINT')]),
        ],
            new FieldMap([]),
            $placements,
            new QueryCompiler(placements: $placements),
            taxonomies: ['Site' => 'site'],
            taxonomy: new TaxonomyStorage($terms),
        );
        $id = EntityId::of(1);
        $adapter->write(new WriteBatch(
            new Link('Location', 'sites', $id, EntityId::of($a)),
            new Link('Location', 'sites', EntityId::of(2), EntityId::of($b)),
            new Link('Inventory', 'site', $id, EntityId::of($b)),
            new Link('Location', 'other', $id, EntityId::of($b)),
        ));
        $location = Criteria::for('Site')->linkedTo(EdgeFilter::along('Location', 'sites', $id));
        self::assertSame('Cave', $adapter->query($location)->items[0]->value('name'));
        self::assertSame(1, $adapter->count($location->take(0)));
        $batched = Criteria::for('Site')->linkedTo(EdgeFilter::along('Location', 'sites', $id, EntityId::of(2)));
        $page = $adapter->query($batched->take(1));
        self::assertSame(1, $page->items[0]->value(EdgeFilter::PARENT_COLUMN));
        self::assertNotNull($page->next);
        $next = $adapter->query($batched->take(1, $page->next));
        self::assertSame(2, $next->items[0]->value(EdgeFilter::PARENT_COLUMN));
        self::assertFalse($next->hasMore());
        $back = $adapter->query(Criteria::for('Location')->linkedTo(EdgeFilter::back('Location', 'sites', EntityId::of($a))));
        self::assertSame(1, $back->items[0]->id->raw());
        $adapter->write(new WriteBatch(new Unlink('Inventory', 'site', $id)));
        self::assertSame([], $adapter->query(Criteria::for('Site')->linkedTo(EdgeFilter::along('Inventory', 'site', $id)))->items);
        self::assertSame('Cave', $adapter->query($location)->items[0]->value('name'));
        $adapter->write(new WriteBatch(new Unlink('Location', 'other', $id, EntityId::of($b))));
        self::assertSame('Cave', $adapter->query($location)->items[0]->value('name'));
        self::assertSame([], $terms->relationships, 'Domain relationships must never use native object IDs.');
    }

    public function testOldManifestFailsExplicitly(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Regenerate');
        new WordPressAdaptor(new FakeDatabase(), [], new FieldMap([]), taxonomyPlacements: [
            'Location.sites' => new TaxonomyPlacement('Location', 'sites', 'Site', 'site'),
        ]);
    }
}
