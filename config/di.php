<?php

declare(strict_types=1);

use Psr\Clock\ClockInterface;
use Rasuvaeff\Yii3Workflow\Audit\TransitionLog;
use Rasuvaeff\Yii3WorkflowDb\Command\WorkflowTransitionsPruneCommand;
use Rasuvaeff\Yii3WorkflowDb\DbTransitionLog;
use Yiisoft\Db\Connection\ConnectionInterface;

/** @var array $params */

$config = $params['rasuvaeff/yii3-workflow-db'] ?? [];
$config = is_array($config) ? $config : [];

$table = $config['table'] ?? 'workflow_transitions';
$table = is_string($table) && $table !== '' ? $table : 'workflow_transitions';

$retentionDays = $config['retentionDays'] ?? 90;
$retentionDays = is_int($retentionDays) && $retentionDays > 0 ? $retentionDays : 90;

return [
    DbTransitionLog::class => static fn (ConnectionInterface $db): DbTransitionLog => new DbTransitionLog(
        db: $db,
        table: $table,
    ),

    // This package is the ONE source binding the swappable audit backend; the
    // core (rasuvaeff/yii3-workflow) deliberately leaves it unbound.
    TransitionLog::class => DbTransitionLog::class,

    WorkflowTransitionsPruneCommand::class => static fn (
        DbTransitionLog $log,
        ClockInterface $clock,
    ): WorkflowTransitionsPruneCommand => new WorkflowTransitionsPruneCommand(
        log: $log,
        clock: $clock,
        defaultDays: $retentionDays,
    ),
];
