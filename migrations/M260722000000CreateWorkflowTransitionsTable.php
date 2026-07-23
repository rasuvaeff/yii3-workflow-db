<?php

declare(strict_types=1);

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;
use Yiisoft\Db\Migration\TransactionalMigrationInterface;

/**
 * Table behind rasuvaeff/yii3-workflow-db.
 *
 * The unique index on (workflow, subject_id, idempotency_key) is what makes
 * replay protection an invariant instead of a check: a NULL key repeats freely
 * on every supported driver, while a real key can be inserted once.
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
        $b->createIndex(
            $this->table,
            'uq_workflow_transitions_idempotency',
            ['workflow', 'subject_id', 'idempotency_key'],
            'UNIQUE',
        );
    }

    #[\Override]
    public function down(MigrationBuilder $b): void
    {
        $b->dropTable($this->table);
    }
}
