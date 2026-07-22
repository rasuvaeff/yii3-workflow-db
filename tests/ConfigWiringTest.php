<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3WorkflowDb\Tests;

use Rasuvaeff\Yii3Workflow\Audit\TransitionLog;
use Rasuvaeff\Yii3WorkflowDb\Command\WorkflowTransitionsPruneCommand;
use Rasuvaeff\Yii3WorkflowDb\DbTransitionLog;
use Testo\Assert;
use Testo\Codecov\CoversNothing;
use Testo\Test;
use Yiisoft\Db\Cache\SchemaCache;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Sqlite\Connection as SqliteConnection;
use Yiisoft\Db\Sqlite\Driver as SqliteDriver;
use Yiisoft\Test\Support\Clock\StaticClock;
use Yiisoft\Test\Support\SimpleCache\MemorySimpleCache;

/**
 * `config/*.php` is covered by neither psalm (src-only) nor cs, so the build
 * gate exercises the definitions here.
 */
#[Test]
#[CoversNothing]
final class ConfigWiringTest
{
    public function bindsTheAuditBackendAndItsCommand(): void
    {
        Assert::same(\array_keys($this->di()), [
            DbTransitionLog::class,
            TransitionLog::class,
            WorkflowTransitionsPruneCommand::class,
        ]);
    }

    public function theSwappableInterfacePointsAtThisImplementation(): void
    {
        // This package is the single source binding TransitionLog; the core
        // must never define the same key (yiisoft/config forbids duplicates).
        Assert::same($this->di()[TransitionLog::class], DbTransitionLog::class);
    }

    public function theLogFactoryHonoursTheConfiguredTable(): void
    {
        $factory = $this->di(['table' => 'custom_transitions'])[DbTransitionLog::class];

        Assert::true(\is_callable($factory));
        Assert::instanceOf($factory($this->db()), DbTransitionLog::class);
    }

    public function theCommandFactoryUsesTheConfiguredRetention(): void
    {
        $definitions = $this->di(['retentionDays' => 7]);
        $command = $definitions[WorkflowTransitionsPruneCommand::class](
            $definitions[DbTransitionLog::class]($this->db()),
            new StaticClock(new \DateTimeImmutable('2026-07-22T12:00:00+00:00')),
        );

        Assert::instanceOf($command, WorkflowTransitionsPruneCommand::class);
        Assert::same($command->getName(), 'workflow:transitions:prune');
    }

    public function malformedParamsFallBackToTheDefaults(): void
    {
        foreach ([[], ['table' => ''], ['table' => 42], ['retentionDays' => 0], ['retentionDays' => 'ten']] as $config) {
            $definitions = $this->di($config);

            Assert::instanceOf($definitions[DbTransitionLog::class]($this->db()), DbTransitionLog::class);
        }
    }

    public function paramsRegisterThePruneCommand(): void
    {
        $params = require \dirname(__DIR__) . '/config/params.php';

        Assert::same(
            $params['yiisoft/yii-console']['commands']['workflow:transitions:prune'],
            WorkflowTransitionsPruneCommand::class,
        );
        Assert::same($params['rasuvaeff/yii3-workflow-db']['table'], 'workflow_transitions');
        Assert::same($params['rasuvaeff/yii3-workflow-db']['retentionDays'], 90);
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function di(array $config = []): array
    {
        $params = ['rasuvaeff/yii3-workflow-db' => $config];

        return (static fn(array $params): array => require \dirname(__DIR__) . '/config/di.php')($params);
    }

    private function db(): ConnectionInterface
    {
        return new SqliteConnection(
            driver: new SqliteDriver(dsn: 'sqlite::memory:'),
            schemaCache: new SchemaCache(psrCache: new MemorySimpleCache()),
        );
    }
}
