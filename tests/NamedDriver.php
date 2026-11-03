<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3WorkflowDb\Tests;

use PDO;
use Yiisoft\Db\Driver\Pdo\PdoDriverInterface;

/**
 * A SQLite driver that reports another driver name, so the migration's
 * per-platform branches run against a real SQLite database.
 *
 * @internal
 */
final readonly class NamedDriver implements PdoDriverInterface
{
    public function __construct(
        private PdoDriverInterface $inner,
        private string $name,
    ) {}

    #[\Override]
    public function attributes(array $attributes): void
    {
        $this->inner->attributes($attributes);
    }

    #[\Override]
    public function createConnection(): PDO
    {
        return $this->inner->createConnection();
    }

    #[\Override]
    public function charset(?string $charset): void
    {
        $this->inner->charset($charset);
    }

    #[\Override]
    public function getCharset(): ?string
    {
        return $this->inner->getCharset();
    }

    #[\Override]
    public function getDsn(): string
    {
        return $this->inner->getDsn();
    }

    #[\Override]
    public function getDriverName(): string
    {
        return $this->name;
    }

    #[\Override]
    public function getPassword(): string
    {
        return $this->inner->getPassword();
    }

    #[\Override]
    public function getUsername(): string
    {
        return $this->inner->getUsername();
    }

    #[\Override]
    public function password(string $password): void
    {
        $this->inner->password($password);
    }

    #[\Override]
    public function username(string $username): void
    {
        $this->inner->username($username);
    }
}
