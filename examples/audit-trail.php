<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Rasuvaeff\Yii3Workflow\Audit\DuplicateIdempotencyKey;
use Rasuvaeff\Yii3Workflow\Audit\TransitionRecord;
use Rasuvaeff\Yii3Workflow\SubjectIdentity;
use Rasuvaeff\Yii3Workflow\WorkflowFactory;
use Rasuvaeff\Yii3Workflow\WorkflowRegistry;
use Rasuvaeff\Yii3WorkflowDb\DbTransitionLog;
use Yiisoft\Db\Cache\SchemaCache;
use Yiisoft\Db\Sqlite\Connection as SqliteConnection;
use Yiisoft\Db\Sqlite\Driver as SqliteDriver;
use Yiisoft\Test\Support\Clock\StaticClock;
use Yiisoft\Test\Support\SimpleCache\MemorySimpleCache;

enum OrderStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Shipped = 'shipped';
}

final class Order implements SubjectIdentity
{
    private OrderStatus $status = OrderStatus::Pending;

    public function __construct(private readonly string $id) {}

    #[\Override]
    public function workflowSubjectId(): string
    {
        return $this->id;
    }

    public function status(): OrderStatus
    {
        return $this->status;
    }
}

$db = new SqliteConnection(
    driver: new SqliteDriver(dsn: 'sqlite::memory:'),
    schemaCache: new SchemaCache(psrCache: new MemorySimpleCache()),
);
$db->open();

// Mirrors migrations/M260722000000CreateWorkflowTransitionsTable.
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

$clock = new StaticClock(new \DateTimeImmutable('2026-07-22T12:00:00+00:00'));
$log = new DbTransitionLog($db);

$registry = new WorkflowRegistry(
    [
        'order' => [
            'initial' => OrderStatus::Pending,
            'places' => OrderStatus::cases(),
            'markingStore' => ['type' => 'enum', 'enum' => OrderStatus::class, 'property' => 'status'],
            'transitions' => [
                ['name' => 'pay', 'from' => OrderStatus::Pending, 'to' => OrderStatus::Paid],
                ['name' => 'ship', 'from' => OrderStatus::Paid, 'to' => OrderStatus::Shipped],
            ],
        ],
    ],
    new WorkflowFactory($clock, log: $log),
);

$workflow = $registry->get('order');
$order = new Order('o-1');

$workflow->applyOnce($order, 'pay', idempotencyKey: 'req-1');
\printf("1) status: %s\n", $order->status()->value);

// Same request replayed: the pre-flight lookup answers, nothing happens.
\printf("2) replay applied? %s\n", $workflow->applyOnce($order, 'ship', 'req-1') ? 'yes' : 'no');

// A concurrent request that passed the lookup at the same time would land here:
// the unique index rejects the insert, so the winner is decided by the database.
try {
    $log->append(new TransitionRecord(
        workflow: 'order',
        subjectId: 'o-1',
        transition: 'ship',
        from: 'paid',
        to: 'shipped',
        at: $clock->now(),
        idempotencyKey: 'req-1',
    ));
} catch (DuplicateIdempotencyKey $exception) {
    \printf("3) constraint won the race: %s\n", $exception->getMessage());
}

$workflow->applyOnce($order, 'ship', idempotencyKey: 'req-2');
\printf("4) status: %s\n", $order->status()->value);

\printf("5) audit trail:\n");

foreach ($log->forSubject('order', 'o-1') as $record) {
    \printf(
        "   %s %s: %s -> %s (key %s)\n",
        $record->at->format('H:i'),
        $record->transition,
        $record->from,
        $record->to,
        $record->idempotencyKey ?? '-',
    );
}

\printf("6) pruned %d record(s) older than 2027\n", $log->prune(new \DateTimeImmutable('2027-01-01T00:00:00+00:00')));
