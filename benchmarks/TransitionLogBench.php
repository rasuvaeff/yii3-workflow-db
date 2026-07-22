<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3WorkflowDb\Benchmarks;

use Rasuvaeff\Yii3Workflow\Audit\TransitionRecord;
use Rasuvaeff\Yii3WorkflowDb\DbTransitionLog;
use Testo\Bench;
use Yiisoft\Db\Cache\SchemaCache;
use Yiisoft\Db\Sqlite\Connection as SqliteConnection;
use Yiisoft\Db\Sqlite\Driver as SqliteDriver;
use Yiisoft\Test\Support\SimpleCache\MemorySimpleCache;

/**
 * SQLite in memory, so the numbers show the package's own cost (query building,
 * hydration) rather than disk or network latency of a real server.
 */
final class TransitionLogBench
{
    private static ?DbTransitionLog $log = null;

    private static int $sequence = 0;

    #[Bench(
        callables: ['append one record' => [self::class, 'append']],
        calls: 2_000,
        iterations: 5,
    )]
    public static function appendRecord(): void
    {
        self::append();
    }

    #[Bench(
        callables: ['idempotency lookup' => [self::class, 'lookup']],
        calls: 5_000,
        iterations: 5,
    )]
    public static function idempotencyLookup(): bool
    {
        return self::lookup();
    }

    public static function append(): void
    {
        self::log()->append(new TransitionRecord(
            workflow: 'order',
            subjectId: 'o-' . ++self::$sequence,
            transition: 'pay',
            from: 'pending',
            to: 'paid',
            at: new \DateTimeImmutable('2026-07-22T12:00:00+00:00'),
            idempotencyKey: 'req-' . self::$sequence,
        ));
    }

    public static function lookup(): bool
    {
        return self::log()->hasIdempotencyKey('order', 'o-1', 'req-1');
    }

    private static function log(): DbTransitionLog
    {
        if (self::$log !== null) {
            return self::$log;
        }

        $db = new SqliteConnection(
            driver: new SqliteDriver(dsn: 'sqlite::memory:'),
            schemaCache: new SchemaCache(psrCache: new MemorySimpleCache()),
        );
        $db->open();
        $db->createCommand(sql: <<<'SQL'
            CREATE TABLE workflow_transitions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                workflow VARCHAR(64) NOT NULL,
                subject_id VARCHAR(128) NOT NULL,
                transition VARCHAR(64) NOT NULL,
                from_place VARCHAR(512) NOT NULL,
                to_place VARCHAR(512) NOT NULL,
                at VARCHAR(30) NOT NULL,
                idempotency_key VARCHAR(128)
            )
            SQL)->execute();
        $db->createCommand(
            sql: 'CREATE UNIQUE INDEX uq_workflow_transitions_idempotency
                  ON workflow_transitions (workflow, subject_id, idempotency_key)',
        )->execute();

        return self::$log = new DbTransitionLog($db);
    }
}
