<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3WorkflowDb\Tests\Integration;

use Rasuvaeff\Yii3Workflow\Audit\TransitionLog;
use Rasuvaeff\Yii3WorkflowDb\DbTransitionLog;
use Rasuvaeff\Yii3WorkflowDb\WorkflowTransitionsTableName;
use Testo\Assert;
use Testo\Codecov\CoversNothing;
use Testo\Test;
use Yiisoft\Db\Cache\SchemaCache;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Sqlite\Connection as SqliteConnection;
use Yiisoft\Db\Sqlite\Driver as SqliteDriver;
use Yiisoft\Test\Support\SimpleCache\MemorySimpleCache;

/**
 * Exercises both installed vendor config files together. The backend owns the
 * swappable TransitionLog key; the core must leave that key unbound.
 */
#[Test]
#[CoversNothing]
final class ConfigWiringTest
{
    public function coreAndBackendHaveNoDuplicateDiKeys(): void
    {
        Assert::same(
            array_intersect_key($this->coreDi(), $this->backendDi()),
            [],
        );
    }

    public function backendFactoryBuildsTheBoundTransitionLog(): void
    {
        $definitions = $this->backendDi([
            'rasuvaeff/yii3-workflow-db' => ['table' => 'custom_transitions'],
        ]);
        $factory = $definitions[DbTransitionLog::class];
        $tableFactory = $definitions[WorkflowTransitionsTableName::class];

        Assert::true(is_callable($factory));
        Assert::true(is_callable($tableFactory));
        Assert::instanceOf($factory($this->sqlite(), $tableFactory()), DbTransitionLog::class);
        Assert::same($definitions[TransitionLog::class], DbTransitionLog::class);
    }

    /** @param array<string, mixed> $params */
    private function backendDi(array $params = []): array
    {
        return (static function (array $params): array {
            return require dirname(__DIR__, 2) . '/config/di.php';
        })($params);
    }

    /** @return array<string, mixed> */
    private function coreDi(): array
    {
        $params = ['rasuvaeff/yii3-workflow' => ['workflows' => []]];

        return require dirname(__DIR__, 2) . '/vendor/rasuvaeff/yii3-workflow/config/di.php';
    }

    private function sqlite(): ConnectionInterface
    {
        return new SqliteConnection(
            driver: new SqliteDriver(dsn: 'sqlite::memory:'),
            schemaCache: new SchemaCache(psrCache: new MemorySimpleCache()),
        );
    }
}
