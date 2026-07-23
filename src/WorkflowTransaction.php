<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3WorkflowDb;

use Rasuvaeff\Yii3Workflow\IdempotentWorkflow;
use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * Runs `applyOnce()` and the caller's persistence in ONE database transaction.
 *
 * The audit row is written inside `apply()`. If the entity save that follows
 * fails outside a transaction, the idempotency key is burnt: the log says the
 * transition happened, the entity never changed, and every retry is skipped as
 * a replay. This helper makes the documented recipe a single call — the `then`
 * closure runs inside the same transaction and only when the transition was
 * actually applied.
 *
 * @api
 */
final readonly class WorkflowTransaction
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    /**
     * @param array<string, mixed> $context
     * @param (\Closure(): void)|null $then Persistence to commit atomically with the audit row
     *
     * @return bool Whether the transition was applied (false = replay skipped)
     */
    public function applyOnce(
        IdempotentWorkflow $workflow,
        object $subject,
        string $transitionName,
        ?string $idempotencyKey = null,
        array $context = [],
        ?\Closure $then = null,
    ): bool {
        return $this->db->transaction(
            static function () use ($workflow, $subject, $transitionName, $idempotencyKey, $context, $then): bool {
                $applied = $workflow->applyOnce($subject, $transitionName, $idempotencyKey, $context);

                if ($applied && $then !== null) {
                    $then();
                }

                return $applied;
            },
        );
    }
}
