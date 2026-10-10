<?php

declare(strict_types=1);

namespace Neos\ContentGraph\DoctrineDbalAdapterForwardCompatibility\Tests\Functional;

use Neos\ContentGraph\DoctrineDbalAdapterForwardCompatibility\NextDoctrineDbalContentGraphProjectionReadModel;
use Neos\ContentRepository\Core\Feature\WorkspaceCreation\Command\CreateRootWorkspace;
use Neos\ContentRepository\Core\Projection\ProjectionStatus;
use Neos\ContentRepository\Core\SharedModel\Workspace\ContentStreamId;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepository\Core\Subscription\DetachedSubscriptionStatus;
use Neos\ContentRepository\Core\Subscription\Engine\SubscriptionEngineCriteria;
use Neos\ContentRepository\Core\Subscription\ProjectionSubscriptionStatus;
use Neos\ContentRepository\Core\Subscription\SubscriptionId;
use Neos\ContentRepository\Core\Subscription\SubscriptionStatus;
use Neos\ContentRepository\Core\Subscription\SubscriptionStatusCollection;
use Neos\EventStore\Model\Event\SequenceNumber;

class RenameTablesMigrationTest extends AbstractContentRepositoryProjectionTestCase
{
    use RenameTablesMigrationTrait;

    /** @test */
    public function renameTablesNoop()
    {
        $this->configureContentRepositories(<<<YAML
        contentRepositories:
          t_compatibility:
            eventStore:
              factoryObjectName: Neos\ContentRepositoryRegistry\Factory\EventStore\DoctrineEventStoreFactory
            nodeTypeManager:
              factoryObjectName: Neos\ContentRepository\TestSuite\Fakes\FakeNodeTypeManagerFactory
            contentDimensionSource:
              factoryObjectName: Neos\ContentRepository\TestSuite\Fakes\FakeContentDimensionSourceFactory
            authProvider:
              factoryObjectName: Neos\ContentRepository\TestSuite\Fakes\FakeAuthProviderFactory
            clock:
              factoryObjectName: Neos\ContentRepositoryRegistry\Factory\Clock\SystemClockFactory
            subscriptionStore:
              factoryObjectName: Neos\ContentRepositoryRegistry\Factory\SubscriptionStore\SubscriptionStoreFactory
            propertyConverters: {}
            contentGraphProjection:
              factoryObjectName: Neos\ContentGraph\DoctrineDbalAdapter\DoctrineDbalContentGraphProjectionFactory
              catchUpHooks: {}
        YAML);

        $this->eventStore->setup();
        $this->subscriptionEngine->setup();
        $this->subscriptionEngine->boot();

        $this->contentRepository->handle(CreateRootWorkspace::create(WorkspaceName::fromString('live'), ContentStreamId::fromString('cs-live')));
        self::assertNotNull($this->contentRepository->findWorkspaceByName(WorkspaceName::fromString('live')));

        # only old projection exists or tables were already migration nothing to do
        $this->getRenameTablesMigration(self::$contentRepositoryId)->executeUp();
        self::assertNotNull($this->contentRepository->findWorkspaceByName(WorkspaceName::fromString('live')));

        $this->getRenameTablesMigration(self::$contentRepositoryId)->executeDown();
        self::assertNotNull($this->contentRepository->findWorkspaceByName(WorkspaceName::fromString('live')));
    }

    /** @test */
    public function renameTablesUpAndDown()
    {
        $this->configureContentRepositories(<<<YAML
        contentRepositories:
          t_compatibility:
            eventStore:
              factoryObjectName: Neos\ContentRepositoryRegistry\Factory\EventStore\DoctrineEventStoreFactory
            nodeTypeManager:
              factoryObjectName: Neos\ContentRepository\TestSuite\Fakes\FakeNodeTypeManagerFactory
            contentDimensionSource:
              factoryObjectName: Neos\ContentRepository\TestSuite\Fakes\FakeContentDimensionSourceFactory
            authProvider:
              factoryObjectName: Neos\ContentRepository\TestSuite\Fakes\FakeAuthProviderFactory
            clock:
              factoryObjectName: Neos\ContentRepositoryRegistry\Factory\Clock\SystemClockFactory
            subscriptionStore:
              factoryObjectName: Neos\ContentRepositoryRegistry\Factory\SubscriptionStore\SubscriptionStoreFactory
            propertyConverters: {}
            contentGraphProjection:
              factoryObjectName: Neos\ContentGraph\DoctrineDbalAdapter\DoctrineDbalContentGraphProjectionFactory
              catchUpHooks: {}
            projections:
              "contentGraph_92":
                factoryObjectName: Neos\ContentGraph\DoctrineDbalAdapterForwardCompatibility\NextDoctrineDbalContentGraphProjectionFactory
        YAML);

        $this->eventStore->setup();
        $this->subscriptionEngine->setup();
        $this->subscriptionEngine->boot();

        self::assertEquals(
            SubscriptionStatusCollection::fromArray([
                ProjectionSubscriptionStatus::create(
                    subscriptionId: SubscriptionId::fromString('contentGraph'),
                    subscriptionStatus: SubscriptionStatus::ACTIVE,
                    subscriptionPosition: SequenceNumber::fromInteger(0),
                    subscriptionError: null,
                    setupStatus: ProjectionStatus::ok(),
                ),
                ProjectionSubscriptionStatus::create(
                    subscriptionId: SubscriptionId::fromString('contentGraph_92'),
                    subscriptionStatus: SubscriptionStatus::ACTIVE,
                    subscriptionPosition: SequenceNumber::fromInteger(0),
                    subscriptionError: null,
                    setupStatus: ProjectionStatus::ok(),
                )
            ]),
            $this->subscriptionEngine->subscriptionStatus()
        );

        $this->contentRepository->handle(CreateRootWorkspace::create(WorkspaceName::fromString('live'), ContentStreamId::fromString('cs-live')));
        self::assertNotNull($this->contentRepository->findWorkspaceByName(WorkspaceName::fromString('live')));
        $newContentGraphReadModel = $this->contentRepository->projectionState(NextDoctrineDbalContentGraphProjectionReadModel::class)->contentGraphReadModel;
        self::assertNotNull($newContentGraphReadModel->findWorkspaceByName(WorkspaceName::fromString('live')));

        $this->getRenameTablesMigration(self::$contentRepositoryId)->executeUp();

        // renamed
        self::assertInstanceOf(
            DetachedSubscriptionStatus::class,
            $this->subscriptionEngine->subscriptionStatus(SubscriptionEngineCriteria::create(['contentGraph_90']))->first()
        );

        $this->getRenameTablesMigration(self::$contentRepositoryId)->executeDown();

        self::assertEquals(
            SubscriptionStatusCollection::fromArray([
                ProjectionSubscriptionStatus::create(
                    subscriptionId: SubscriptionId::fromString('contentGraph'),
                    subscriptionStatus: SubscriptionStatus::ACTIVE,
                    subscriptionPosition: SequenceNumber::fromInteger(2),
                    subscriptionError: null,
                    setupStatus: ProjectionStatus::ok(),
                ),
                ProjectionSubscriptionStatus::create(
                    subscriptionId: SubscriptionId::fromString('contentGraph_92'),
                    subscriptionStatus: SubscriptionStatus::ACTIVE,
                    subscriptionPosition: SequenceNumber::fromInteger(2),
                    subscriptionError: null,
                    setupStatus: ProjectionStatus::ok(),
                )
            ]),
            $this->subscriptionEngine->subscriptionStatus()
        );

        self::assertNotNull($this->contentRepository->findWorkspaceByName(WorkspaceName::fromString('live')));
        $newContentGraphReadModel = $this->contentRepository->projectionState(NextDoctrineDbalContentGraphProjectionReadModel::class)->contentGraphReadModel;
        self::assertNotNull($newContentGraphReadModel->findWorkspaceByName(WorkspaceName::fromString('live')));
    }
}
