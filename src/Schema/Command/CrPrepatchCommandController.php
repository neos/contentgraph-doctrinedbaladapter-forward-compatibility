<?php

declare(strict_types=1);

namespace Neos\ContentGraph\DoctrineDbalAdapterForwardCompatibility\Schema\Command;

use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\CommandController;
use Neos\Flow\Package\PackageManager;
use Neos\Utility\Files;

class CrPrepatchCommandController extends CommandController
{
    #[Flow\Inject()]
    protected PackageManager $packageManager;

    public function generateMigrationCommand(string $packageKey, string $contentRepository = 'default'): void
    {
        $package = $this->packageManager->getPackage($packageKey);

        $migrationVersion = (new \DateTimeImmutable())->format('YmdHis');

        $migrationCode = (new RenameContentGraphTablesMigrationBuilder(
            migrationVersion: $migrationVersion,
            contentRepositoryId: ContentRepositoryId::fromString($contentRepository)
        ))->build();

        $filePath = Files::concatenatePaths([$package->getPackagePath(), 'Migrations/Mysql', 'Version' . $migrationVersion . '.php']);

        Files::createDirectoryRecursively(dirname($filePath));

        file_put_contents($filePath, $migrationCode);

        $this->outputLine('<success>Generated new migration version %s</success>', [$migrationVersion]);
        /** @phpstan-ignore-next-line constant.notFound */
        $this->outputLine('Wrote migration to <comment>%s</comment>', [str_replace(FLOW_PATH_ROOT, '', $filePath)]);
    }
}
