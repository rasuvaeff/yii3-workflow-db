<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3WorkflowDb\Tests;

use Rasuvaeff\Yii3WorkflowDb\Migration\M260722000000CreateWorkflowTransitionsTable;
use Rasuvaeff\Yii3WorkflowDb\WorkflowTransitionsTableName;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;
use Yiisoft\Db\Cache\SchemaCache;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Migration\Informer\NullMigrationInformer;
use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Sqlite\Connection as SqliteConnection;
use Yiisoft\Db\Sqlite\Driver as SqliteDriver;
use Yiisoft\Test\Support\SimpleCache\MemorySimpleCache;

/**
 * The MSSQL and Oracle branches of the migration are plain SQL that SQLite can
 * also execute (partial index, expression index), so they run here under a
 * driver name that selects the branch, against a real database.
 */
#[Test]
#[Covers(M260722000000CreateWorkflowTransitionsTable::class)]
final class MigrationDriverBranchTest
{
    public function sqlServerGetsAFilteredUniqueIndex(): void
    {
        [$db, $builder] = $this->setUpFor('sqlsrv');

        (new M260722000000CreateWorkflowTransitionsTable())->up($builder);

        Assert::same(
            $this->indexSql($db, 'uq_workflow_transitions_idempotency'),
            'CREATE UNIQUE INDEX uq_workflow_transitions_idempotency'
            . ' ON workflow_transitions (workflow, subject_id, idempotency_key)'
            . ' WHERE idempotency_key IS NOT NULL',
        );
    }

    public function oracleGetsAFunctionBasedUniqueIndex(): void
    {
        [$db, $builder] = $this->setUpFor('oci');

        (new M260722000000CreateWorkflowTransitionsTable())->up($builder);

        Assert::same(
            $this->indexSql($db, 'uq_workflow_transitions_idempotency'),
            'CREATE UNIQUE INDEX uq_workflow_transitions_idempotency ON workflow_transitions ('
            . 'CASE WHEN idempotency_key IS NOT NULL THEN workflow END, '
            . 'CASE WHEN idempotency_key IS NOT NULL THEN subject_id END, '
            . 'idempotency_key)',
        );
    }

    #[DataProvider('platformProvider')]
    public function theIndexNameFollowsTheTableName(string $driver): void
    {
        [$db, $builder] = $this->setUpFor($driver);

        (new M260722000000CreateWorkflowTransitionsTable(new WorkflowTransitionsTableName('custom_tbl')))->up($builder);

        $sql = $this->indexSql($db, 'uq_custom_tbl_idempotency');
        Assert::string($sql)->contains('ON custom_tbl ');
    }

    #[DataProvider('platformProvider')]
    public function keyLessRowsRepeatButAKeyedRowIsUnique(string $driver): void
    {
        [$db, $builder] = $this->setUpFor($driver);
        (new M260722000000CreateWorkflowTransitionsTable())->up($builder);

        $insert = static function (?string $key) use ($db): void {
            $db->createCommand()->insert('workflow_transitions', [
                'workflow' => 'order',
                'subject_id' => 'order-1',
                'transition' => 'pay',
                'from_place' => 'a',
                'to_place' => 'b',
                'at' => '2026-07-23T12:00:00+00:00',
                'idempotency_key' => $key,
            ])->execute();
        };

        $insert(null);
        $insert(null);
        $insert('request-1');

        try {
            $insert('request-1');
            Assert::fail('Expected a duplicate keyed row to be rejected');
        } catch (\Yiisoft\Db\Exception\IntegrityException) {
            Assert::same((int) $db->createCommand('SELECT COUNT(*) FROM workflow_transitions')->queryScalar(), 3);
        }
    }

    public static function platformProvider(): iterable
    {
        yield 'sqlsrv' => ['sqlsrv'];
        yield 'oci' => ['oci'];
    }

    /**
     * @return array{ConnectionInterface, MigrationBuilder}
     */
    private function setUpFor(string $driverName): array
    {
        $db = new SqliteConnection(
            driver: new NamedDriver(new SqliteDriver(dsn: 'sqlite::memory:'), $driverName),
            schemaCache: new SchemaCache(psrCache: new MemorySimpleCache()),
        );
        $db->open();

        return [$db, new MigrationBuilder(db: $db, informer: new NullMigrationInformer())];
    }

    private function indexSql(ConnectionInterface $db, string $name): string
    {
        return (string) $db
            ->createCommand('SELECT sql FROM sqlite_master WHERE type = \'index\' AND name = :name', ['name' => $name])
            ->queryScalar();
    }
}
