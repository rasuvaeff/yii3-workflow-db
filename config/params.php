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
        // one source of truth: both DbTransitionLog and the bundled migration
        // read the resulting name through WorkflowTransitionsTableName
        'table' => 'workflow_transitions',
        // prepended to `table`; set it once to keep every rasuvaeff table out
        // of the way of your application's own
        'table_prefix' => '',
        'retentionDays' => 90,
    ],
];
