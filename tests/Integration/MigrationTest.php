<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3WorkflowDb\Tests\Integration;

use Rasuvaeff\Yii3WorkflowDb\Migration\M260722000000CreateWorkflowTransitionsTable;
use Rasuvaeff\Yii3WorkflowDb\WorkflowTransitionsTableName;
use Testo\Assert;
use Testo\Codecov\CoversNothing;
use Testo\Expect;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;
use Yiisoft\Db\Cache\SchemaCache;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Migration\Informer\NullMigrationInformer;
use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Sqlite\Connection as SqliteConnection;
use Yiisoft\Db\Sqlite\Driver as SqliteDriver;
use Yiisoft\Test\Support\SimpleCache\MemorySimpleCache;

#[Test]
#[CoversNothing]
final class MigrationTest
{
    private ConnectionInterface $db;

    private MigrationBuilder $builder;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->db = new SqliteConnection(
            driver: new SqliteDriver(dsn: 'sqlite::memory:'),
            schemaCache: new SchemaCache(psrCache: new MemorySimpleCache()),
        );
        $this->db->open();
        $this->builder = new MigrationBuilder(db: $this->db, informer: new NullMigrationInformer());
    }

    #[AfterTest]
    public function tearDown(): void
    {
        $this->db->close();
    }

    public function createsAndDropsTheWorkflowTransitionsTable(): void
    {
        $migration = new M260722000000CreateWorkflowTransitionsTable();

        $migration->up($this->builder);
        $schema = $this->db->getTableSchema('workflow_transitions', true);

        Assert::notNull($schema);
        foreach (['id', 'workflow', 'subject_id', 'transition', 'from_place', 'to_place', 'at', 'idempotency_key'] as $column) {
            Assert::notNull($schema->getColumn($column));
        }
        $this->db->createCommand()->insert('workflow_transitions', [
            'workflow' => 'order',
            'subject_id' => 'order-1',
            'transition' => 'pay',
            'from_place' => 'pending',
            'to_place' => 'paid',
            'at' => '2026-07-23T12:00:00+00:00',
            'idempotency_key' => 'request-1',
        ])->execute();
        Expect::exception(\Yiisoft\Db\Exception\IntegrityException::class);
        $this->db->createCommand()->insert('workflow_transitions', [
            'workflow' => 'order',
            'subject_id' => 'order-1',
            'transition' => 'ship',
            'from_place' => 'paid',
            'to_place' => 'shipped',
            'at' => '2026-07-23T12:01:00+00:00',
            'idempotency_key' => 'request-1',
        ])->execute();

        $migration->down($this->builder);

        Assert::null($this->db->getTableSchema('workflow_transitions', true));
    }

    public function keyLessRowsRepeatFreelyUnderTheUniqueIndex(): void
    {
        // Most audit rows carry no idempotency key; the unique index must not
        // treat two NULLs of one subject as a duplicate.
        (new M260722000000CreateWorkflowTransitionsTable())->up($this->builder);

        foreach (['pay', 'ship'] as $transition) {
            $this->db->createCommand()->insert('workflow_transitions', [
                'workflow' => 'order',
                'subject_id' => 'order-1',
                'transition' => $transition,
                'from_place' => 'pending',
                'to_place' => 'paid',
                'at' => '2026-07-23T12:00:00+00:00',
                'idempotency_key' => null,
            ])->execute();
        }

        Assert::same(
            (int) (new \Yiisoft\Db\Query\Query($this->db))->from('workflow_transitions')->count(),
            2,
        );
    }

    public function createsAUsableCustomTable(): void
    {
        (new M260722000000CreateWorkflowTransitionsTable(table: new WorkflowTransitionsTableName('custom_transitions')))->up($this->builder);

        Assert::notNull($this->db->getTableSchema('custom_transitions', true));
        Assert::null($this->db->getTableSchema('workflow_transitions', true));
    }

    public function rejectsAnInvalidTableName(): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('Invalid table name');

        new M260722000000CreateWorkflowTransitionsTable(table: new WorkflowTransitionsTableName('workflow transitions'));
    }
}
