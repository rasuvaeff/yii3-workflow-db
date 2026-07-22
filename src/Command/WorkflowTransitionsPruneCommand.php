<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3WorkflowDb\Command;

use Psr\Clock\ClockInterface;
use Rasuvaeff\Yii3WorkflowDb\DbTransitionLog;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Deletes transition history older than N days.
 *
 * The table grows one row per transition forever and nothing in the machine
 * reads it back, so retention is a policy the application owns. Without a
 * schedule for this command the table is an unbounded log.
 *
 * @api
 */
#[AsCommand(name: 'workflow:transitions:prune', description: 'Delete workflow transition history older than N days')]
final class WorkflowTransitionsPruneCommand extends Command
{
    public function __construct(
        private readonly DbTransitionLog $log,
        private readonly ClockInterface $clock,
        private readonly int $defaultDays = 90,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addOption(
            'older-than',
            'o',
            InputOption::VALUE_REQUIRED,
            'Age in days; records older than this are deleted',
        );
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report the cut-off without deleting');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $before = $this->clock->now()->modify(\sprintf('-%d days', $this->days($input->getOption('older-than'))));

        if ($input->getOption('dry-run') === true) {
            $output->writeln(\sprintf('Would delete records before %s', $before->format(\DateTimeInterface::ATOM)));

            return Command::SUCCESS;
        }

        $output->writeln(\sprintf(
            'Deleted %d record(s) before %s',
            $this->log->prune($before),
            $before->format(\DateTimeInterface::ATOM),
        ));

        return Command::SUCCESS;
    }

    private function days(mixed $option): int
    {
        if ($option === null) {
            return $this->defaultDays;
        }

        if (!\is_numeric($option) || (int) $option < 1) {
            throw new \InvalidArgumentException('Option --older-than must be a positive number of days');
        }

        return (int) $option;
    }
}
