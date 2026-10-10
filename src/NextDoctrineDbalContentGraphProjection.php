<?php

declare(strict_types=1);

namespace Neos\ContentGraph\DoctrineDbalAdapterForwardCompatibility;

use Neos\ContentGraph\DoctrineDbalAdapterForwardCompatibility\Generated\ContentGraph\DoctrineDbalContentGraphProjection;
use Neos\ContentRepository\Core\Projection\ProjectionInterface;

/**
 * @implements ProjectionInterface<NextDoctrineDbalContentGraphProjectionReadModel>
 */
final class NextDoctrineDbalContentGraphProjection extends DoctrineDbalContentGraphProjection implements ProjectionInterface
{
    public function getState(): NextDoctrineDbalContentGraphProjectionReadModel
    {
        return new NextDoctrineDbalContentGraphProjectionReadModel($this->contentGraphReadModel);
    }
}
