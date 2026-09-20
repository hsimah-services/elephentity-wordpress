<?php

declare(strict_types=1);

namespace Eleph\WordPress\Tests;

use Eleph\Runtime\Identity\EntityId;
use Eleph\Runtime\Identity\PendingId;
use Eleph\Runtime\Storage\Write\Insert;
use Eleph\Runtime\Storage\Write\WriteBatch;
use Eleph\WordPress\Database\Database;
use Eleph\WordPress\Post\PostStorage;
use Eleph\WordPress\Sql\Column;
use Eleph\WordPress\Sql\FieldMap;
use Eleph\WordPress\Sql\TableSchema;
use Eleph\WordPress\WordPressAdaptor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(WordPressAdaptor::class)]
final class PostLinkTest extends TestCase
{
    public function testLinkedInsertUsesSeparatePostAndEntityIds(): void
    {
        $database = new FakeDatabase();
        $database->nextInsertId = 7;
        $post = $this->createMock(PostStorage::class);
        $post->expects(self::once())->method('create')->with('item', 'Item', ['title' => 'Hello'])->willReturn(902);
        $adaptor = $this->adaptor($database, $post, true);
        $pending = new PendingId('Item');
        $result = $adaptor->write(new WriteBatch(new Insert('Item', $pending, ['title' => 'Hello'])));
        self::assertSame(7, $result->idFor($pending)->raw());
        self::assertSame(['title' => 'Hello', 'wp_post_id' => 902], $database->inserts[0]['values']);
        $database->rows = [['id' => 7, 'title' => 'Hello', 'wp_post_id' => 902]];
        self::assertSame(['title' => 'Hello'], $adaptor->get('Item', EntityId::of(7))?->values);
    }

    public function testUnlinkedInsertNeverCallsWordPress(): void
    {
        $database = new FakeDatabase();
        $post = $this->createMock(PostStorage::class);
        $post->expects(self::never())->method('create');
        $this->adaptor($database, $post, false)->write(new WriteBatch(new Insert('Item', new PendingId('Item'), ['title' => 'Hello'])));
        self::assertSame(['title' => 'Hello'], $database->inserts[0]['values']);
    }

    public function testPostFailurePreventsEntityInsertionAndRollsBack(): void
    {
        $database = new FakeDatabase();
        $post = $this->createMock(PostStorage::class);
        $post->expects(self::once())->method('create')->willThrowException(new RuntimeException('Post failed'));
        $adaptor = $this->adaptor($database, $post, true);

        try {
            $adaptor->transaction(fn () => $adaptor->write(new WriteBatch(new Insert('Item', new PendingId('Item'), []))));
            self::fail('Expected the post creation failure.');
        } catch (RuntimeException $exception) {
            self::assertSame('Post failed', $exception->getMessage());
        }

        self::assertSame([], $database->inserts);
        self::assertSame(['begin', 'rollback'], $database->transactionLog);
    }

    public function testEntityInsertFailureCleansUpTheNewPost(): void
    {
        $database = $this->createMock(Database::class);
        $database->expects(self::once())->method('insert')->willThrowException(new RuntimeException('Insert failed'));
        $post = $this->createMock(PostStorage::class);
        $post->expects(self::once())->method('create')->willReturn(902);
        $post->expects(self::once())->method('delete')->with(902);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Insert failed');
        $this->adaptor($database, $post, true)->write(new WriteBatch(new Insert('Item', new PendingId('Item'), [])));
    }

    public function testCallerCannotSupplyTheManagedPostColumn(): void
    {
        $post = $this->createMock(PostStorage::class);
        $post->expects(self::never())->method('create');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('managed');
        $this->adaptor(new FakeDatabase(), $post, true)->write(new WriteBatch(new Insert('Item', new PendingId('Item'), ['wp_post_id' => 10])));
    }

    private function adaptor(Database $database, PostStorage $post, bool $linked): WordPressAdaptor
    {
        return new WordPressAdaptor($database, ['Item' => new TableSchema('wp_item', ['id' => new Column('id', 'BIGINT UNSIGNED')])], new FieldMap(['Item' => ['title' => 'title']]), posts: $linked ? ['Item' => 'item'] : [], post: $post);
    }
}
