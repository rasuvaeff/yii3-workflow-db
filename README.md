# rasuvaeff/yii3-workflow-db

![Stable Version](https://img.shields.io/packagist/v/rasuvaeff/yii3-workflow-db?label=stable)
![Total Downloads](https://img.shields.io/packagist/dt/rasuvaeff/yii3-workflow-db?label=downloads)
![Build](https://github.com/rasuvaeff/yii3-workflow-db/actions/workflows/build.yml/badge.svg)
![Static analysis](https://github.com/rasuvaeff/yii3-workflow-db/actions/workflows/static-analysis.yml/badge.svg)
![License](https://img.shields.io/packagist/l/rasuvaeff/yii3-workflow-db?label=license)

Database backend for [`rasuvaeff/yii3-workflow`](https://github.com/rasuvaeff/yii3-workflow):
stores the transition history on `yiisoft/db` and turns replay protection from a
check into a constraint.

> Using an AI coding assistant? [llms.txt](llms.txt) is a compact API reference
> designed for LLMs.

[Русская версия](README.ru.md)

## What it does

- **Binds `TransitionLog`.** The core package leaves that interface unbound on
  purpose; installing this one makes the audit trail and idempotency real.
- **Persists one row per committed transition** — `symfony/workflow` keeps only
  the current marking, so the history has to live somewhere.
- **Makes idempotency a database invariant.** A unique index on
  `(workflow, subject_id, idempotency_key)` decides the race that a
  check-then-act lookup cannot: the loser's insert fails, the core translates
  that into "this was a replay".
- **Ships the migration** and a `workflow:transitions:prune` command, because a
  table nothing reads back grows forever.

## Requirements

- PHP 8.3 / 8.4 / 8.5
- `rasuvaeff/yii3-workflow` ^1.0
- `yiisoft/db` ^2.0, `yiisoft/db-migration` ^2.0
- `symfony/console` (for the prune command), `psr/clock`

## Installation

```bash
composer require rasuvaeff/yii3-workflow-db
```

`yiisoft/config` wires `TransitionLog` to `DbTransitionLog` automatically.
Register the bundled migration (`Rasuvaeff\Yii3WorkflowDb\Migration\M260722000000CreateWorkflowTransitionsTable`)
**by namespace** — no vendor paths:

```php
// config/common/di/migration.php
use Yiisoft\Db\Migration\Service\MigrationService;

return [
    MigrationService::class => [
        'setSourceNamespaces()' => [['App\\Migration', 'Rasuvaeff\\Yii3WorkflowDb\\Migration']],
    ],
];
```

```bash
./yii migrate:up
```

`yiisoft/db-migration` resolves the migration through `Injector::make()`, so
it picks up the table-name value object from the container the same way the
log does — no manual wiring needed beyond `setSourceNamespaces()` above.

> **Do not configure the migration through the DI container.**
> `M...::class => ['__construct()' => ['table' => ...]]` does not work: the
> migration is built by `Injector::make()`, which resolves arguments by type
> and never reads a container definition keyed by the migration's own class.
> Worse, adding that definition makes the container fatal at build time in
> **every** request, because the class is not autoloadable until the migration
> runner requires it. That recipe was documented in 1.x; it never worked.

## Configuration

```php
// config/common/params.php
return [
    'rasuvaeff/yii3-workflow-db' => [
        'table' => 'workflow_transitions',
        'table_prefix' => '',    // prepended to `table`; e.g. 'rsv_' → rsv_workflow_transitions
        'retentionDays' => 90,   // default for workflow:transitions:prune
    ],
];
```

The same name reaches `DbTransitionLog` **and** the bundled migration, as a
`WorkflowTransitionsTableName`. Index names follow it
(`idx_<table>_subject`, `idx_<table>_at`, `uq_<table>_idempotency`), so two
installations can share one PostgreSQL schema — index names are unique per
schema there, not per table.

## Schema

| Column | Type | Note |
|---|---|---|
| `id` | bigint PK | insertion order = chronological order |
| `workflow` | string(64) | machine name |
| `subject_id` | string(128) | from `SubjectIdentity::workflowSubjectId()` |
| `transition` | string(64) | applied transition |
| `from_place` / `to_place` | string(512) | comma-joined for Petri-net transitions |
| `at` | string(30) | ATOM normalised to UTC, so ordering is lexicographic on every driver |
| `idempotency_key` | string(128), nullable | NULL repeats freely; a value is unique per subject |

Indexes: `(workflow, subject_id, id)` for a subject's history, `(at)` for
pruning and reports, and the **unique** `(workflow, subject_id, idempotency_key)`.

Timestamps are converted to UTC before they are stored: with a varying UTC
offset (a DST switch, app servers in different timezones) the string ordering
that pruning relies on would break.

The migration adapts the unique index to the driver. MySQL, PostgreSQL and
SQLite treat NULLs in a unique index as distinct, so a plain index already lets
key-less rows repeat; MSSQL and Oracle compare them as equal, so there the
index covers keyed rows only (a filtered index on MSSQL, a function-based one
on Oracle). Tests run against SQLite — treat the MSSQL/Oracle paths as
best-effort and verify them in your environment.

## Usage

Nothing changes at the call site — the core API keeps working, only now it
records and enforces:

```php
$workflow = $registry->get('order');

if (!$workflow->applyOnce($order, 'ship', idempotencyKey: $requestId)) {
    return;   // replay: either the lookup or the unique index said so
}
```

Reading the history back, beyond the `TransitionLog` interface:

```php
use Rasuvaeff\Yii3WorkflowDb\DbTransitionLog;

$log->forSubject('order', $order->getId());   // chronological
$log->latest('order', limit: 50, offset: 0);  // newest first, for an admin screen
$log->count('order');                         // total rows, for the pager
$log->prune(new DateTimeImmutable('-90 days'));
```

### Transactions

A transition writes its audit row inside `apply()`, i.e. **after** the subject
has already been mutated in memory. When the unique index rejects that write,
`applyOnce()` returns `false` while the object in memory has moved on. Wrap the
call and the entity save in one transaction — or discard the object — so a lost
race cannot be persisted:

```php
$this->db->transaction(function () use ($order, $requestId): void {
    if (!$this->registry->get('order')->applyOnce($order, 'ship', $requestId)) {
        return;
    }

    $this->orders->save($order);
});
```

`WorkflowTransaction` makes the recipe a single call — the `then` closure runs
inside the same transaction and only when the transition was actually applied:

```php
use Rasuvaeff\Yii3WorkflowDb\WorkflowTransaction;

final readonly class ShipOrderHandler
{
    public function __construct(
        private WorkflowRegistry $registry,
        private WorkflowTransaction $transaction,
    ) {}

    public function handle(Order $order, string $requestId): void
    {
        $this->transaction->applyOnce(
            $this->registry->get('order'),
            $order,
            'ship',
            $requestId,
            then: fn() => $this->orders->save($order),
        );
    }
}
```

A failed save rolls the audit row back with everything else, so the key is not
burnt and a retry can succeed.

### Pruning

```bash
./yii workflow:transitions:prune                      # the configured retention
./yii workflow:transitions:prune --older-than=30
./yii workflow:transitions:prune --older-than=30 --dry-run
```

Pruning also forgets idempotency keys: a request replayed with a key older than
the retention window is applied again. Keep the window longer than the longest
plausible replay (client retries, queue redeliveries).

## Security

Rows are written from workflow events, never from user input, and the package
runs only parameterised queries through `yiisoft/db`. The history is an audit
source: give the application's DB user no `DELETE` on this table unless it runs
the prune command, and remember that `subject_id` may identify a person — the
retention policy is a privacy decision, not just a storage one.

## Examples

See [`examples/`](examples/) for a runnable script on in-memory SQLite.

## Development

```bash
make install
make build       # validate + normalize + require-checker + cs + psalm + test
make cs-fix
make psalm
make test
make test-integration
make mutation    # requires pcov; the Makefile bootstraps it
```

Unit and integration tests run against in-memory SQLite, so the database and
migration paths need no server. `make build` runs the unit suite; run
`make test-integration` for the SQLite integration suite. No PHP or Composer on
the host: every target runs inside the `composer:2` Docker image.

## License

BSD-3-Clause. See [`LICENSE.md`](LICENSE.md).
