<?php

declare(strict_types=1);

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;
use Yiisoft\Db\Migration\TransactionalMigrationInterface;

/**
 * Table behind rasuvaeff/yii3-workflow-db.
 *
 * The unique index on (workflow, subject_id, idempotency_key) is what makes
 * replay protection an invariant instead of a check: a NULL key must repeat
 * freely (most audit rows carry no key), while a real key can be inserted once.
 * MySQL, PostgreSQL and SQLite treat NULLs in a unique index as distinct;
 * MSSQL and Oracle compare them as equal, so there the index covers keyed rows
 * only — a filtered index on MSSQL, a function-based one on Oracle.
 */
final class M260722000000CreateWorkflowTransitionsTable implements
    RevertibleMigrationInterface,
    TransactionalMigrationInterface
{
    /** @param non-empty-string $table */
    public function __construct(private readonly string $table = 'workflow_transitions')
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/D', $table) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid table name "%s"', $table));
        }
    }

    #[\Override]
    public function up(MigrationBuilder $b): void
    {
        $b->createTable($this->table, [
            'id' => 'bigprimarykey',
            'workflow' => 'string(64) NOT NULL',
            'subject_id' => 'string(128) NOT NULL',
            'transition' => 'string(64) NOT NULL',
            // A Petri-net transition touches several places at once, joined by
            // commas by the core AuditListener.
            'from_place' => 'string(512) NOT NULL',
            'to_place' => 'string(512) NOT NULL',
            'at' => 'string(30) NOT NULL',
            'idempotency_key' => 'string(128)',
        ]);

        $b->createIndex($this->table, 'idx_workflow_transitions_subject', ['workflow', 'subject_id', 'id']);
        $b->createIndex($this->table, 'idx_workflow_transitions_at', 'at');
        $this->createIdempotencyIndex($b);
    }

    private function createIdempotencyIndex(MigrationBuilder $b): void
    {
        // The table name is safe to interpolate: the constructor rejects
        // anything but [schema.]identifier.
        switch ($b->getDb()->getDriverName()) {
            case 'sqlsrv':
                // MSSQL allows a single NULL per unique index, so the second
                // key-less audit row of a subject would be rejected; filter the
                // index down to keyed rows.
                $b->execute(sprintf(
                    'CREATE UNIQUE INDEX uq_workflow_transitions_idempotency'
                    . ' ON %s (workflow, subject_id, idempotency_key)'
                    . ' WHERE idempotency_key IS NOT NULL',
                    $this->table,
                ));

                return;
            case 'oci':
                // Oracle compares partially-NULL composite entries as equal and
                // has no filtered indexes; NULL out every expression for
                // key-less rows so they are not indexed at all.
                $b->execute(sprintf(
                    'CREATE UNIQUE INDEX uq_workflow_transitions_idempotency ON %s ('
                    . 'CASE WHEN idempotency_key IS NOT NULL THEN workflow END, '
                    . 'CASE WHEN idempotency_key IS NOT NULL THEN subject_id END, '
                    . 'idempotency_key)',
                    $this->table,
                ));

                return;
            default:
                // MySQL, PostgreSQL and SQLite treat NULLs as distinct: a plain
                // unique index already lets key-less rows repeat freely.
                $b->createIndex(
                    $this->table,
                    'uq_workflow_transitions_idempotency',
                    ['workflow', 'subject_id', 'idempotency_key'],
                    'UNIQUE',
                );
        }
    }

    #[\Override]
    public function down(MigrationBuilder $b): void
    {
        $b->dropTable($this->table);
    }
}
