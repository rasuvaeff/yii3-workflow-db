<?php

declare(strict_types=1);

use Rasuvaeff\Yii3WorkflowDb\Command\WorkflowTransitionsPruneCommand;

return [
    'yiisoft/yii-console' => [
        'commands' => [
            'workflow:transitions:prune' => WorkflowTransitionsPruneCommand::class,
        ],
    ],
    'rasuvaeff/yii3-workflow-db' => [
        'table' => 'workflow_transitions',
        'retentionDays' => 90,
    ],
];
