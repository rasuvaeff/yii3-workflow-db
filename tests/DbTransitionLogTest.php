<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3WorkflowDb\Tests;

use Rasuvaeff\Yii3Workflow\Audit\DuplicateIdempotencyKey;
use Rasuvaeff\Yii3Workflow\Audit\TransitionLog;
use Rasuvaeff\Yii3Workflow\Audit\TransitionRecord;
use Rasuvaeff\Yii3WorkflowDb\DbTransitionLog;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;
use Yiisoft\Db\Cache\SchemaCache;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Exception\IntegrityException;
use Yiisoft\Db\Sqlite\Connection as SqliteConnection;
use Yiisoft\Db\Sqlite\Driver as SqliteDriver;
use Yiisoft\Test\Support\SimpleCache\MemorySimpleCache;

#[Test]
#[Covers(DbTransitionLog::class)]
final class DbTransitionLogTest
{
    private ConnectionInterface $db;

    private DbTransitionLog $log;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->db = new SqliteConnection(
            driver: new SqliteDriver(dsn: 'sqlite::memory:'),
            schemaCache: new SchemaCache(psrCache: new MemorySimpleCache()),
        );
        $this->db->open();
        $this->createTable();
        $this->log = new DbTransitionLog($this->db);
    }

    #[AfterTest]
    public function tearDown(): void
    {
        $this->db->close();
    }

    public function isATransitionLog(): void
    {
        Assert::instanceOf($this->log, TransitionLog::class);
    }

    public function roundTripsARecord(): void
    {
        $this->log->append($this->record(key: 'req-1'));

        $records = $this->log->forSubject('order', 'o-1');

        Assert::same(\count($records), 1);
        Assert::same($records[0]->workflow, 'order');
        Assert::same($records[0]->subjectId, 'o-1');
        Assert::same($records[0]->transition, 'pay');
        Assert::same($records[0]->from, 'pending');
        Assert::same($records[0]->to, 'paid');
        Assert::same($records[0]->at->format(\DateTimeInterface::ATOM), '2026-07-22T12:00:00+00:00');
        Assert::same($records[0]->idempotencyKey, 'req-1');
    }

    public function keepsHistoryInInsertionOrderPerSubject(): void
    {
        // Ordering is by id, not by `at`: two transitions inside one request
        // share a timestamp, and their order still has to be stable.
        $this->log->append($this->record(transition: 'pay', at: '2026-07-22T12:00:00+00:00'));
        $this->log->append($this->record(subjectId: 'o-2', transition: 'pay'));
        $this->log->append($this->record(transition: 'ship', at: '2026-07-22T12:00:00+00:00'));

        Assert::same(
            \array_map(
                static fn(TransitionRecord $r): string => $r->transition,
                $this->log->forSubject('order', 'o-1'),
            ),
            ['pay', 'ship'],
        );
    }

    public function separatesWorkflows(): void
    {
        $this->log->append($this->record());
        $this->log->append($this->record(workflow: 'invoice'));

        Assert::same(\count($this->log->forSubject('order', 'o-1')), 1);
    }

    public function storesARecordWithoutAKey(): void
    {
        $this->log->append($this->record());

        Assert::null($this->log->forSubject('order', 'o-1')[0]->idempotencyKey);
    }

    public function findsAKeyOnlyForItsOwnWorkflowAndSubject(): void
    {
        $this->log->append($this->record(key: 'req-1'));

        Assert::true($this->log->hasIdempotencyKey('order', 'o-1', 'req-1'));
        Assert::false($this->log->hasIdempotencyKey('order', 'o-2', 'req-1'));
        Assert::false($this->log->hasIdempotencyKey('invoice', 'o-1', 'req-1'));
        Assert::false($this->log->hasIdempotencyKey('order', 'o-1', 'req-2'));
    }

    public function theUniqueIndexRejectsARepeatedKey(): void
    {
        // The whole point of the package: the constraint decides the race that
        // hasIdempotencyKey() cannot.
        $this->log->append($this->record(key: 'req-1'));

        Expect::exception(DuplicateIdempotencyKey::class)
            ->withMessageContaining('already recorded for subject "o-1" of workflow "order"');

        $this->log->append($this->record(transition: 'ship', key: 'req-1'));
    }

    public function propagatesAnIntegrityErrorThatIsNotADuplicateKey(): void
    {
        $this->db->createCommand(sql: 'CREATE UNIQUE INDEX uq_workflow_transitions_transition ON workflow_transitions (transition)')->execute();
        $this->log->append($this->record(transition: 'pay'));

        Expect::exception(IntegrityException::class);

        $this->log->append($this->record(transition: 'pay', key: 'req-2'));
    }

    public function theSameKeyIsFreeForAnotherSubject(): void
    {
        $this->log->append($this->record(key: 'req-1'));
        $this->log->append($this->record(subjectId: 'o-2', key: 'req-1'));

        Assert::true($this->log->hasIdempotencyKey('order', 'o-2', 'req-1'));
    }

    public function recordsWithoutAKeyRepeatFreely(): void
    {
        $this->log->append($this->record());
        $this->log->append($this->record());

        Assert::same(\count($this->log->forSubject('order', 'o-1')), 2);
    }

    public function latestReturnsTheNewestFirstWithPaging(): void
    {
        foreach (['pay', 'ship', 'deliver'] as $transition) {
            $this->log->append($this->record(transition: $transition));
        }

        Assert::same(
            \array_map(static fn(TransitionRecord $r): string => $r->transition, $this->log->latest('order', 2)),
            ['deliver', 'ship'],
        );
        Assert::same(
            \array_map(
                static fn(TransitionRecord $r): string => $r->transition,
                $this->log->latest('order', 2, offset: 2),
            ),
            ['pay'],
        );
        Assert::same($this->log->latest('invoice'), []);
    }

    public function rejectsInvalidLatestPaging(): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('Limit');
        $this->log->latest('order', 0);

        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('Offset');
        $this->log->latest('order', offset: -1);
    }

    public function rejectsAnInvalidTableName(): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('Invalid table name');

        new DbTransitionLog($this->db, 'workflow transitions');
    }

    public function pruneDeletesOnlyOlderRecords(): void
    {
        $this->log->append($this->record(at: '2026-01-01T00:00:00+00:00'));
        $this->log->append($this->record(at: '2026-07-22T12:00:00+00:00'));

        $deleted = $this->log->prune(new \DateTimeImmutable('2026-06-01T00:00:00+00:00'));

        Assert::same($deleted, 1);
        Assert::same(\count($this->log->forSubject('order', 'o-1')), 1);
    }

    public function honoursACustomTableName(): void
    {
        $this->db->createCommand(sql: 'ALTER TABLE workflow_transitions RENAME TO wf_log')->execute();
        $log = new DbTransitionLog($this->db, 'wf_log');

        $log->append($this->record());

        Assert::same(\count($log->forSubject('order', 'o-1')), 1);
    }

    public function rejectsARowWithAMissingColumnValue(): void
    {
        // A schema drifted away from the migration (here: a nullable timestamp)
        // must fail loudly instead of hydrating a broken record.
        $this->db->createCommand(sql: <<<'SQL'
            CREATE TABLE wf_loose (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                workflow VARCHAR(64) NOT NULL,
                subject_id VARCHAR(128) NOT NULL,
                transition VARCHAR(64) NOT NULL,
                from_place VARCHAR(512) NOT NULL,
                to_place VARCHAR(512) NOT NULL,
                at VARCHAR(30),
                idempotency_key VARCHAR(128)
            )
            SQL)->execute();
        $this->db->createCommand()->insert('wf_loose', [
            'workflow' => 'order',
            'subject_id' => 'o-1',
            'transition' => 'pay',
            'from_place' => 'pending',
            'to_place' => 'paid',
            'at' => null,
        ])->execute();

        Expect::exception(\RuntimeException::class)->withMessageContaining('must hold a string, null given');

        (new DbTransitionLog($this->db, 'wf_loose'))->forSubject('order', 'o-1');
    }

    private function record(
        string $workflow = 'order',
        string $subjectId = 'o-1',
        string $transition = 'pay',
        ?string $key = null,
        string $at = '2026-07-22T12:00:00+00:00',
    ): TransitionRecord {
        return new TransitionRecord(
            workflow: $workflow,
            subjectId: $subjectId,
            transition: $transition,
            from: 'pending',
            to: 'paid',
            at: new \DateTimeImmutable($at),
            idempotencyKey: $key,
        );
    }

    /** Mirrors migrations/M260722000000CreateWorkflowTransitionsTable — keep both in sync. */
    private function createTable(): void
    {
        $this->db->createCommand(sql: <<<'SQL'
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
        $this->db->createCommand(
            sql: 'CREATE INDEX idx_workflow_transitions_subject ON workflow_transitions (workflow, subject_id, id)',
        )->execute();
        $this->db->createCommand(
            sql: 'CREATE UNIQUE INDEX uq_workflow_transitions_idempotency
                  ON workflow_transitions (workflow, subject_id, idempotency_key)',
        )->execute();
    }
}
