<?php

declare(strict_types=1);

namespace Neos\ContentGraph\DoctrineDbalAdapterForwardCompatibility\Tests\Functional;

use Doctrine\DBAL\Connection;
use Doctrine\Migrations\AbstractMigration;
use Psr\Log\LoggerInterface;
use Neos\Flow\Annotations as Flow;

#[Flow\Proxy(false)]
class TestingDoctrineMigrationRunner
{
    private function __construct(
        private Connection $connection,
        private AbstractMigration $migration,
    ) {
    }

    public static function create(
        Connection $connection,
        LoggerInterface $logger,
        string $className,
    ): self {
        return new self(
            connection: $connection,
            migration: new $className(
                $connection,
                $logger
            )
        );
    }

    public function executeUp(): void
    {
        $schema = $this->connection->createSchemaManager()->introspectSchema();

        $this->migration->up($schema);

        foreach ($this->migration->getSql() as $query) {
            $this->connection->executeQuery($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }


    public function executeDown(): void
    {
        $schema = $this->connection->createSchemaManager()->introspectSchema();

        $this->migration->down($schema);

        foreach ($this->migration->getSql() as $query) {
            $this->connection->executeQuery($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }
}
