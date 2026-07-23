<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3WorkflowDb\Tests\Command;

use Rasuvaeff\Yii3Workflow\Audit\TransitionRecord;
use Rasuvaeff\Yii3WorkflowDb\Command\WorkflowTransitionsPruneCommand;
use Rasuvaeff\Yii3WorkflowDb\DbTransitionLog;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;
use Yiisoft\Db\Cache\SchemaCache;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Sqlite\Connection as SqliteConnection;
use Yiisoft\Db\Sqlite\Driver as SqliteDriver;
use Yiisoft\Test\Support\Clock\StaticClock;
use Yiisoft\Test\Support\SimpleCache\MemorySimpleCache;

#[Test]
#[Covers(WorkflowTransitionsPruneCommand::class)]
final class WorkflowTransitionsPruneCommandTest
{
    private const string NOW = '2026-07-22T12:00:00+00:00';

    private ConnectionInterface $db;

    private DbTransitionLog $log;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->db = new SqliteConnection(
            driver: new SqliteDriver(dsn: 'sqlite::memory:'),
            schemaCache: new SchemaCache(psrCache: new MemorySimpleCache()),
        );
        $this->db->open();
        $this->db->createCommand(sql: <<<'SQL'
            CREATE TABLE workflow_transitions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                workflow VARCHAR(64) NOT NULL,
                subject_id VARCHAR(128) NOT NULL,
                transition VARCHAR(64) NOT NULL,
                from_place VARCHAR(512) NOT NULL,
                to_place VARCHAR(512) NOT NULL,
                at VARCHAR(30) NOT NULL,
                idempotency_key VARCHAR(128)
            )
            SQL)->execute();
        $this->log = new DbTransitionLog($this->db);

        foreach (['2025-01-01T00:00:00+00:00', self::NOW] as $at) {
            $this->log->append(new TransitionRecord(
                workflow: 'order',
                subjectId: 'o-1',
                transition: 'pay',
                from: 'pending',
                to: 'paid',
                at: new \DateTimeImmutable($at),
            ));
        }
    }

    #[AfterTest]
    public function tearDown(): void
    {
        $this->db->close();
    }

    public function prunesWithTheConfiguredDefault(): void
    {
        $tester = $this->tester();

        Assert::same($tester->execute([]), Command::SUCCESS);
        Assert::string($tester->getDisplay())->contains('Deleted 1 record(s) before 2026-04-23');
        Assert::same(\count($this->log->forSubject('order', 'o-1')), 1);
    }

    public function honoursAnExplicitAge(): void
    {
        $tester = $this->tester();
        $tester->execute(['--older-than' => '10000']);

        Assert::string($tester->getDisplay())->contains('Deleted 0 record(s)');
        Assert::same(\count($this->log->forSubject('order', 'o-1')), 2);
    }

    public function dryRunReportsTheCutOffWithoutDeleting(): void
    {
        $tester = $this->tester();

        Assert::same($tester->execute(['--dry-run' => true]), Command::SUCCESS);
        Assert::string($tester->getDisplay())->contains('Would delete records before 2026-04-23');
        Assert::same(\count($this->log->forSubject('order', 'o-1')), 2);
    }

    public function acceptsTheSmallestValidAge(): void
    {
        $tester = $this->tester();
        $tester->execute(['--older-than' => '1']);

        Assert::string($tester->getDisplay())->contains('Deleted 1 record(s) before 2026-07-21');
    }

    public function rejectsAnAgeThatIsNotAPositiveNumber(): void
    {
        Expect::exception(\InvalidArgumentException::class)
            ->withMessageContaining('must be a positive whole number of days');

        $this->tester()->execute(['--older-than' => 'yesterday']);
    }

    public function rejectsAZeroAge(): void
    {
        Expect::exception(\InvalidArgumentException::class)
            ->withMessageContaining('must be a positive whole number of days');

        $this->tester()->execute(['--older-than' => '0']);
    }

    public function rejectsAFractionalAge(): void
    {
        // is_numeric() would accept "2.9" and silently truncate it to 2 days.
        Expect::exception(\InvalidArgumentException::class)
            ->withMessageContaining('must be a positive whole number of days');

        $this->tester()->execute(['--older-than' => '2.9']);
    }

    public function rejectsATrailingNewlineAge(): void
    {
        // Without the /D modifier `$` would match before a trailing newline.
        Expect::exception(\InvalidArgumentException::class)
            ->withMessageContaining('must be a positive whole number of days');

        $this->tester()->execute(['--older-than' => "30\n"]);
    }

    private function tester(): CommandTester
    {
        return new CommandTester(new WorkflowTransitionsPruneCommand(
            log: $this->log,
            clock: new StaticClock(new \DateTimeImmutable(self::NOW)),
            defaultDays: 90,
        ));
    }
}
