<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3WorkflowDb\Tests;

use Rasuvaeff\Yii3Workflow\IdempotentWorkflow;
use Rasuvaeff\Yii3Workflow\SubjectIdentity;
use Rasuvaeff\Yii3Workflow\WorkflowFactory;
use Rasuvaeff\Yii3WorkflowDb\DbTransitionLog;
use Rasuvaeff\Yii3WorkflowDb\WorkflowTransaction;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;
use Yiisoft\Db\Cache\SchemaCache;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Sqlite\Connection as SqliteConnection;
use Yiisoft\Db\Sqlite\Driver as SqliteDriver;
use Yiisoft\Test\Support\Clock\StaticClock;
use Yiisoft\Test\Support\SimpleCache\MemorySimpleCache;

enum TxOrderStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
}

final class TxOrder implements SubjectIdentity
{
    private TxOrderStatus $status = TxOrderStatus::Pending;

    public function __construct(private readonly string $id = 'order-1') {}

    #[\Override]
    public function workflowSubjectId(): string
    {
        return $this->id;
    }

    public function status(): TxOrderStatus
    {
        return $this->status;
    }
}

#[Test]
#[Covers(WorkflowTransaction::class)]
final class WorkflowTransactionTest
{
    private ConnectionInterface $db;

    private DbTransitionLog $log;

    private WorkflowTransaction $transaction;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->db = new SqliteConnection(
            driver: new SqliteDriver(dsn: 'sqlite::memory:'),
            schemaCache: new SchemaCache(psrCache: new MemorySimpleCache()),
        );
        $this->db->open();
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
            sql: 'CREATE UNIQUE INDEX uq_workflow_transitions_idempotency
                  ON workflow_transitions (workflow, subject_id, idempotency_key)',
        )->execute();
        $this->log = new DbTransitionLog($this->db);
        $this->transaction = new WorkflowTransaction($this->db);
    }

    #[AfterTest]
    public function tearDown(): void
    {
        $this->db->close();
    }

    public function commitsTheAuditRowAndThePersistenceTogether(): void
    {
        $order = new TxOrder();
        $saved = false;

        $applied = $this->transaction->applyOnce(
            $this->workflow(),
            $order,
            'pay',
            'req-1',
            then: static function () use (&$saved): void {
                $saved = true;
            },
        );

        Assert::true($applied);
        Assert::true($saved);
        Assert::same($order->status(), TxOrderStatus::Paid);
        Assert::same(\count($this->log->forSubject('order', 'order-1')), 1);
    }

    public function rollsTheAuditRowBackWhenThePersistenceFails(): void
    {
        // The whole point of the helper: a failed save must take the audit row
        // (and the idempotency key) down with it, so a retry can succeed.
        $workflow = $this->workflow();
        $thrown = false;

        try {
            $this->transaction->applyOnce($workflow, new TxOrder(), 'pay', 'req-1', then: static function (): void {
                throw new \RuntimeException('save failed');
            });
        } catch (\RuntimeException) {
            $thrown = true;
        }

        Assert::true($thrown);
        Assert::same(\count($this->log->forSubject('order', 'order-1')), 0);
        Assert::true($this->transaction->applyOnce($workflow, new TxOrder(), 'pay', 'req-1'));
    }

    public function aReplaySkipsThePersistence(): void
    {
        $workflow = $this->workflow();
        $this->transaction->applyOnce($workflow, new TxOrder(), 'pay', 'req-1');
        $saved = false;

        $applied = $this->transaction->applyOnce(
            $workflow,
            new TxOrder(),
            'pay',
            'req-1',
            then: static function () use (&$saved): void {
                $saved = true;
            },
        );

        Assert::false($applied);
        Assert::false($saved);
        Assert::same(\count($this->log->forSubject('order', 'order-1')), 1);
    }

    public function worksWithoutAPersistenceClosure(): void
    {
        Assert::true($this->transaction->applyOnce($this->workflow(), new TxOrder(), 'pay', 'req-1'));
        Assert::same(\count($this->log->forSubject('order', 'order-1')), 1);
    }

    private function workflow(): IdempotentWorkflow
    {
        return (new WorkflowFactory(
            clock: new StaticClock(new \DateTimeImmutable('2026-07-23T12:00:00+00:00')),
            log: $this->log,
        ))->create('order', [
            'initial' => TxOrderStatus::Pending,
            'places' => TxOrderStatus::cases(),
            'transitions' => [
                ['name' => 'pay', 'from' => TxOrderStatus::Pending, 'to' => TxOrderStatus::Paid],
            ],
            'markingStore' => ['type' => 'enum', 'enum' => TxOrderStatus::class, 'property' => 'status'],
        ]);
    }
}
