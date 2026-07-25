<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3WorkflowDb\Tests\Integration;

use DateTimeImmutable;
use Rasuvaeff\Yii3Workflow\Audit\TransitionRecord;
use Rasuvaeff\Yii3Workflow\SubjectIdentity;
use Rasuvaeff\Yii3Workflow\WorkflowFactory;
use Rasuvaeff\Yii3WorkflowDb\DbTransitionLog;
use Rasuvaeff\Yii3WorkflowDb\Migration\M260722000000CreateWorkflowTransitionsTable;
use Testo\Assert;
use Testo\Codecov\CoversNothing;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;
use Yiisoft\Db\Cache\SchemaCache;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Migration\Informer\NullMigrationInformer;
use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Sqlite\Connection as SqliteConnection;
use Yiisoft\Db\Sqlite\Driver as SqliteDriver;
use Yiisoft\Test\Support\Clock\StaticClock;
use Yiisoft\Test\Support\SimpleCache\MemorySimpleCache;

enum IntegrationOrderStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
}

final class IntegrationOrder implements SubjectIdentity
{
    private IntegrationOrderStatus $status = IntegrationOrderStatus::Pending;

    public function __construct(private readonly string $id = 'order-1') {}

    public function workflowSubjectId(): string
    {
        return $this->id;
    }

    public function status(): IntegrationOrderStatus
    {
        return $this->status;
    }
}

#[Test]
#[CoversNothing]
final class SqliteIntegrationTest
{
    private ConnectionInterface $db;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->db = new SqliteConnection(
            driver: new SqliteDriver(dsn: 'sqlite::memory:'),
            schemaCache: new SchemaCache(psrCache: new MemorySimpleCache()),
        );
        $this->db->open();
        (new M260722000000CreateWorkflowTransitionsTable())->up(
            new MigrationBuilder(db: $this->db, informer: new NullMigrationInformer()),
        );
    }

    #[AfterTest]
    public function tearDown(): void
    {
        $this->db->close();
    }

    public function appliesWorkflowAndPersistsAuditThroughTheDatabase(): void
    {
        $log = new DbTransitionLog(db: $this->db);
        $workflow = (new WorkflowFactory(
            clock: new StaticClock(new DateTimeImmutable('2026-07-23T12:00:00+00:00')),
            log: $log,
        ))->create('order', [
            'initial' => IntegrationOrderStatus::Pending,
            'places' => IntegrationOrderStatus::cases(),
            'transitions' => [
                ['name' => 'pay', 'from' => IntegrationOrderStatus::Pending, 'to' => IntegrationOrderStatus::Paid],
            ],
            'markingStore' => ['type' => 'enum', 'enum' => IntegrationOrderStatus::class, 'property' => 'status'],
        ]);
        $order = new IntegrationOrder();

        Assert::true($workflow->applyOnce($order, 'pay', idempotencyKey: 'request-1'));
        Assert::same($order->status(), IntegrationOrderStatus::Paid);

        $records = $log->forSubject('order', 'order-1');
        Assert::count($records, 1);
        Assert::same($records[0]->transition, 'pay');
        Assert::same($records[0]->idempotencyKey, 'request-1');
        Assert::same($records[0]->at->format(DateTimeImmutable::ATOM), '2026-07-23T12:00:00+00:00');

        Assert::false($workflow->applyOnce($order, 'pay', idempotencyKey: 'request-1'));
        Assert::count($log->forSubject('order', 'order-1'), 1);
    }

    public function migrationAndLogRoundTripARecord(): void
    {
        $log = new DbTransitionLog(db: $this->db);
        $record = new TransitionRecord(
            workflow: 'invoice',
            subjectId: 'invoice-1',
            transition: 'issue',
            from: 'draft',
            to: 'issued',
            at: new DateTimeImmutable('2026-07-23T12:00:00+00:00'),
        );

        $log->append($record);

        $loaded = $log->forSubject('invoice', 'invoice-1')[0];
        Assert::same($loaded->workflow, $record->workflow);
        Assert::same($loaded->subjectId, $record->subjectId);
        Assert::same($loaded->transition, $record->transition);
        Assert::same($loaded->from, $record->from);
        Assert::same($loaded->to, $record->to);
        Assert::same($loaded->at->format(DateTimeImmutable::ATOM), $record->at->format(DateTimeImmutable::ATOM));
    }
}
