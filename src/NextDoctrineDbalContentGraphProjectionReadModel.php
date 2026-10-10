<?php

declare(strict_types=1);

namespace Neos\ContentGraph\DoctrineDbalAdapterForwardCompatibility;

use Neos\ContentRepository\Core\Projection\ContentGraph\ContentGraphReadModelInterface;
use Neos\ContentRepository\Core\Projection\ProjectionStateInterface;

final class NextDoctrineDbalContentGraphProjectionReadModel implements ProjectionStateInterface
{
    public function __construct(
        public ContentGraphReadModelInterface $contentGraphReadModel
    ) {
    }
}
