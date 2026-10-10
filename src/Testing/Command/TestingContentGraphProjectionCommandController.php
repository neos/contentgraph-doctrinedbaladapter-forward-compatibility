<?php

declare(strict_types=1);

namespace Neos\ContentGraph\DoctrineDbalAdapterForwardCompatibility\Testing\Command;

use Neos\ContentGraph\DoctrineDbalAdapterForwardCompatibility\Generated\ContentGraph\ContentGraphReadModelAdapter;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentGraphReadModelInterface;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Cli\CommandController;
use Neos\Flow\Annotations as Flow;
use Neos\Utility\ObjectAccess;

class TestingContentGraphProjectionCommandController extends CommandController
{
    #[Flow\Inject()]
    protected ContentRepositoryRegistry $contentRepositoryRegistry;

    /**
     * @internal
     */
    public function validateContentRepositoryCommand(string $contentRepository): void
    {
        $contentRepository = $this->contentRepositoryRegistry->get(ContentRepositoryId::fromString($contentRepository));

        /** @var ContentGraphReadModelInterface $contentGraphReadModel */
        $contentGraphReadModel = ObjectAccess::getProperty($contentRepository, 'contentGraphReadModel', true);

        if (!$contentGraphReadModel instanceof ContentGraphReadModelAdapter) {
            $this->outputLine(sprintf('%s is not of expected generated read model', $contentGraphReadModel::class));
            $this->quit(1);
        }

        $this->outputLine(sprintf('Using generated read model: %s', $contentGraphReadModel::class));
    }
}
