<?php

namespace Neos\ContentGraph\DoctrineDbalAdapterForwardCompatibility\Tests\Functional;

use Doctrine\DBAL\Connection;
use Neos\ContentGraph\DoctrineDbalAdapterForwardCompatibility\Schema\Command\RenameContentGraphTablesMigrationBuilder;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Psr\Log\LoggerInterface;

trait RenameTablesMigrationTrait
{
    final protected function getRenameTablesMigration(ContentRepositoryId $contentRepositoryId): TestingDoctrineMigrationRunner
    {
        $version = 'Eval' . random_int(10000, 99999);

        $migrationCode = (new RenameContentGraphTablesMigrationBuilder(
            migrationVersion: $version,
            contentRepositoryId: $contentRepositoryId
        ))->build();

        eval(substr($migrationCode, strlen('<?php')));

        $className = "\Neos\Flow\Persistence\Doctrine\Migrations\Version$version";

        return TestingDoctrineMigrationRunner::create(
            $this->getObject(Connection::class),
            $this->getMockBuilder(LoggerInterface::class)->getMock(),
            $className
        );
    }
}
