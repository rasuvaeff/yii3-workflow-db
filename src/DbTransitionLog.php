<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3WorkflowDb;

use Rasuvaeff\Yii3Workflow\Audit\DuplicateIdempotencyKey;
use Rasuvaeff\Yii3Workflow\Audit\TransitionLog;
use Rasuvaeff\Yii3Workflow\Audit\TransitionRecord;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Exception\IntegrityException;
use Yiisoft\Db\Query\Query;

/**
 * {@see TransitionLog} on top of `yiisoft/db`.
 *
 * The table carries a unique index on
 * `(workflow, subject_id, idempotency_key)`, which is the point of this
 * package: the pre-flight {@see hasIdempotencyKey()} lookup cannot decide a
 * race between two concurrent requests, but the insert can. A violation is
 * translated into {@see DuplicateIdempotencyKey}, which
 * `IdempotentWorkflow::applyOnce()` reports as a replay.
 *
 * Timestamps are stored as ATOM strings so ordering is lexicographic and
 * identical across drivers.
 *
 * @api
 */
final readonly class DbTransitionLog implements TransitionLog
{
    /** @param non-empty-string $table */
    public function __construct(
        private ConnectionInterface $db,
        private string $table = 'workflow_transitions',
    ) {}

    /** @throws DuplicateIdempotencyKey */
    #[\Override]
    public function append(TransitionRecord $record): void
    {
        try {
            $this->db->createCommand()->insert($this->table, [
                'workflow' => $record->workflow,
                'subject_id' => $record->subjectId,
                'transition' => $record->transition,
                'from_place' => $record->from,
                'to_place' => $record->to,
                'at' => $record->at->format(\DateTimeInterface::ATOM),
                'idempotency_key' => $record->idempotencyKey,
            ])->execute();
        } catch (IntegrityException $exception) {
            if ($record->idempotencyKey === null) {
                throw $exception;
            }

            throw new DuplicateIdempotencyKey(
                $record->workflow,
                $record->subjectId,
                $record->idempotencyKey,
                $exception,
            );
        }
    }

    /** @return list<TransitionRecord> */
    #[\Override]
    public function forSubject(string $workflow, string $subjectId): array
    {
        return $this->hydrateAll(
            $this->query()
                ->where(['workflow' => $workflow, 'subject_id' => $subjectId])
                ->orderBy(['id' => \SORT_ASC])
                ->all(),
        );
    }

    #[\Override]
    public function hasIdempotencyKey(string $workflow, string $subjectId, string $key): bool
    {
        return $this->query()
            ->where(['workflow' => $workflow, 'subject_id' => $subjectId, 'idempotency_key' => $key])
            ->exists();
    }

    /**
     * Most recent records first — the shape an admin screen needs.
     *
     * @return list<TransitionRecord>
     */
    public function latest(string $workflow, int $limit = 50, int $offset = 0): array
    {
        return $this->hydrateAll(
            $this->query()
                ->where(['workflow' => $workflow])
                ->orderBy(['id' => \SORT_DESC])
                ->limit($limit)
                ->offset($offset)
                ->all(),
        );
    }

    /**
     * Delete records older than the given instant and report how many went.
     *
     * History grows one row per transition and is never read back by the
     * machine, so pruning is a policy decision, not a data loss.
     */
    public function prune(\DateTimeImmutable $before): int
    {
        return $this->db->createCommand()->delete($this->table, [
            '<', 'at', $before->format(\DateTimeInterface::ATOM),
        ])->execute();
    }

    private function query(): Query
    {
        return (new Query($this->db))->from($this->table);
    }

    /**
     * `Query::all()` is typed loosely enough that psalm sees rows as `mixed`;
     * anything that is not an associative row is dropped rather than hydrated
     * into a half-empty record.
     *
     * @param array<array-key, mixed> $rows
     *
     * @return list<TransitionRecord>
     */
    private function hydrateAll(array $rows): array
    {
        return \array_values(\array_map($this->hydrate(...), \array_filter($rows, \is_array(...))));
    }

    /** @param array<array-key, mixed> $row */
    private function hydrate(array $row): TransitionRecord
    {
        return new TransitionRecord(
            workflow: $this->string($row, 'workflow'),
            subjectId: $this->string($row, 'subject_id'),
            transition: $this->string($row, 'transition'),
            from: $this->string($row, 'from_place'),
            to: $this->string($row, 'to_place'),
            at: new \DateTimeImmutable($this->string($row, 'at')),
            idempotencyKey: isset($row['idempotency_key']) ? $this->string($row, 'idempotency_key') : null,
        );
    }

    /** @param array<array-key, mixed> $row */
    private function string(array $row, string $column): string
    {
        $value = $row[$column] ?? null;

        if (!\is_string($value)) {
            throw new \RuntimeException(\sprintf(
                'Column "%s" of table "%s" must hold a string, %s given',
                $column,
                $this->table,
                \get_debug_type($value),
            ));
        }

        return $value;
    }
}
