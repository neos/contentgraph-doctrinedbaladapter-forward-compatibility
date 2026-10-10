<?php

declare(strict_types=1);

namespace Neos\ContentGraph\DoctrineDbalAdapterForwardCompatibility\Tests\Functional;

use Neos\ContentGraph\DoctrineDbalAdapterForwardCompatibility\NextDoctrineDbalContentGraphProjectionReadModel;
use Neos\ContentRepository\Core\Feature\WorkspaceCreation\Command\CreateRootWorkspace;
use Neos\ContentRepository\Core\Feature\WorkspaceCreation\Command\CreateWorkspace;
use Neos\ContentRepository\Core\Projection\ProjectionStatus;
use Neos\ContentRepository\Core\Projection\ProjectionStatusType;
use Neos\ContentRepository\Core\SharedModel\Workspace\ContentStreamId;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepository\Core\Subscription\DetachedSubscriptionStatus;
use Neos\ContentRepository\Core\Subscription\Engine\SubscriptionEngineCriteria;
use Neos\ContentRepository\Core\Subscription\ProjectionSubscriptionStatus;
use Neos\ContentRepository\Core\Subscription\SubscriptionId;
use Neos\ContentRepository\Core\Subscription\SubscriptionStatus;
use Neos\ContentRepository\Core\Subscription\SubscriptionStatusCollection;
use Neos\EventStore\Model\Event\SequenceNumber;

final class SwitchOldToNewProjectionTest extends AbstractContentRepositoryProjectionTestCase
{
    use RenameTablesMigrationTrait;

    /** @test */
    public function migrateFromOldToNew()
    {
        // 1.) only the old projection exists
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

        self::assertEquals(
            SubscriptionStatusCollection::fromArray([
                ProjectionSubscriptionStatus::create(
                    subscriptionId: SubscriptionId::fromString('contentGraph'),
                    subscriptionStatus: SubscriptionStatus::ACTIVE,
                    subscriptionPosition: SequenceNumber::none(),
                    subscriptionError: null,
                    setupStatus: ProjectionStatus::ok(),
                )
            ]),
            $this->subscriptionEngine->subscriptionStatus()
        );

        $this->contentRepository->handle(CreateRootWorkspace::create(WorkspaceName::fromString('live'), ContentStreamId::fromString('cs-live')));

        self::assertEquals(
            SubscriptionStatusCollection::fromArray([
                ProjectionSubscriptionStatus::create(
                    subscriptionId: SubscriptionId::fromString('contentGraph'),
                    subscriptionStatus: SubscriptionStatus::ACTIVE,
                    subscriptionPosition: SequenceNumber::fromInteger(2),
                    subscriptionError: null,
                    setupStatus: ProjectionStatus::ok(),
                )
            ]),
            $this->subscriptionEngine->subscriptionStatus()
        );
        self::assertNotNull($this->contentRepository->findWorkspaceByName(WorkspaceName::fromString('live')));

        // 2.) the new projections is installed
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
              subscription: 'contentGraph'
              factoryObjectName: Neos\ContentGraph\DoctrineDbalAdapter\DoctrineDbalContentGraphProjectionFactory
              catchUpHooks: {}
            projections:
              "contentGraph_92":
                factoryObjectName: Neos\ContentGraph\DoctrineDbalAdapterForwardCompatibility\NextDoctrineDbalContentGraphProjectionFactory
        YAML);

        $newContentGraphSubscriptionStatus = $this->subscriptionEngine->subscriptionStatus(SubscriptionEngineCriteria::create(['contentGraph_92']))->first();

        // NEW is discovered
        self::assertEquals('contentGraph_92', $newContentGraphSubscriptionStatus?->subscriptionId->value);
        self::assertEquals(0, $newContentGraphSubscriptionStatus?->subscriptionPosition->value);
        self::assertEquals(
            SubscriptionStatus::NEW,
            $newContentGraphSubscriptionStatus->subscriptionStatus,
        );
        self::assertEquals(
            ProjectionStatusType::SETUP_REQUIRED,
            $newContentGraphSubscriptionStatus->setupStatus->type,
        );
        self::assertEquals(
            SubscriptionStatusCollection::fromArray([
                ProjectionSubscriptionStatus::create(
                    subscriptionId: SubscriptionId::fromString('contentGraph'),
                    subscriptionStatus: SubscriptionStatus::ACTIVE,
                    subscriptionPosition: SequenceNumber::fromInteger(2),
                    subscriptionError: null,
                    setupStatus: ProjectionStatus::ok(),
                )
            ]),
            $this->subscriptionEngine->subscriptionStatus(SubscriptionEngineCriteria::create(['contentGraph']))
        );

        // main content graph still works with NEW
        $this->contentRepository->handle(CreateWorkspace::create(WorkspaceName::fromString('user-one'), WorkspaceName::fromString('live'), ContentStreamId::fromString('cs-user-one')));
        self::assertNotNull($this->contentRepository->findWorkspaceByName(WorkspaceName::fromString('user-one')));

        // setup new graph
        $this->subscriptionEngine->setup();

        self::assertEquals(
            SubscriptionStatusCollection::fromArray([
                ProjectionSubscriptionStatus::create(
                    subscriptionId: SubscriptionId::fromString('contentGraph'),
                    subscriptionStatus: SubscriptionStatus::ACTIVE,
                    subscriptionPosition: SequenceNumber::fromInteger(4),
                    subscriptionError: null,
                    setupStatus: ProjectionStatus::ok(),
                ),
                ProjectionSubscriptionStatus::create(
                    subscriptionId: SubscriptionId::fromString('contentGraph_92'),
                    subscriptionStatus: SubscriptionStatus::BOOTING,
                    subscriptionPosition: SequenceNumber::none(),
                    subscriptionError: null,
                    setupStatus: ProjectionStatus::ok(),
                )
            ]),
            $this->subscriptionEngine->subscriptionStatus()
        );

        // main content graph still works with BOOTING
        $this->contentRepository->handle(CreateWorkspace::create(WorkspaceName::fromString('user-two'), WorkspaceName::fromString('live'), ContentStreamId::fromString('cs-user-two')));
        self::assertNotNull($this->contentRepository->findWorkspaceByName(WorkspaceName::fromString('user-two')));

        // new content graph is empty
        $newContentGraphReadModel = $this->contentRepository->projectionState(NextDoctrineDbalContentGraphProjectionReadModel::class)->contentGraphReadModel;
        self::assertNull($newContentGraphReadModel->findWorkspaceByName(WorkspaceName::fromString('live')));
        self::assertCount(0, $newContentGraphReadModel->findWorkspaces());

        // 3. Replay new content graph
        $this->subscriptionEngine->boot(SubscriptionEngineCriteria::create(['contentGraph_92']));
        self::assertEquals(
            SubscriptionStatusCollection::fromArray([
                ProjectionSubscriptionStatus::create(
                    subscriptionId: SubscriptionId::fromString('contentGraph'),
                    subscriptionStatus: SubscriptionStatus::ACTIVE,
                    subscriptionPosition: SequenceNumber::fromInteger(6),
                    subscriptionError: null,
                    setupStatus: ProjectionStatus::ok(),
                ),
                ProjectionSubscriptionStatus::create(
                    subscriptionId: SubscriptionId::fromString('contentGraph_92'),
                    subscriptionStatus: SubscriptionStatus::ACTIVE,
                    subscriptionPosition: SequenceNumber::fromInteger(6),
                    subscriptionError: null,
                    setupStatus: ProjectionStatus::ok(),
                )
            ]),
            $this->subscriptionEngine->subscriptionStatus()
        );
        // new content graph can be queried
        $newContentGraphReadModel = $this->contentRepository->projectionState(NextDoctrineDbalContentGraphProjectionReadModel::class)->contentGraphReadModel;
        self::assertNotNull($newContentGraphReadModel->findWorkspaceByName(WorkspaceName::fromString('live')));
        self::assertNotNull($newContentGraphReadModel->findWorkspaceByName(WorkspaceName::fromString('user-two')));

        // both new and old content graph are caught up
        $newContentGraphReadModel = $this->contentRepository->projectionState(NextDoctrineDbalContentGraphProjectionReadModel::class)->contentGraphReadModel;
        $this->contentRepository->handle(CreateWorkspace::create(WorkspaceName::fromString('user-three'), WorkspaceName::fromString('live'), ContentStreamId::fromString('cs-user-three')));
        self::assertNotNull($this->contentRepository->findWorkspaceByName(WorkspaceName::fromString('user-three')));
        self::assertNotNull($newContentGraphReadModel->findWorkspaceByName(WorkspaceName::fromString('user-three')));

        // 4.) the old projections is uninstalled
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
              # note this part of the test is not entirely correct and reflects real world, as we intent that the user installs Neos 9.2 and renames the tables - this testing projection should not be used
              factoryObjectName: Neos\ContentGraph\DoctrineDbalAdapterForwardCompatibility\Testing\TestingNextDoctrineDbalContentGraphProjectionFactory
              catchUpHooks: {}
        YAML);

        $this->getRenameTablesMigration(self::$contentRepositoryId)->executeUp();
        // TODO indexes are not stable - probably because we would need to encode the crId and rename them in the above migration
        $this->subscriptionEngine->setup(SubscriptionEngineCriteria::create(['contentGraph']));

        // old content graph subscription will be renamed and will be marked as detached
        self::assertEquals(
            SubscriptionStatusCollection::fromArray([
                ProjectionSubscriptionStatus::create(
                    subscriptionId: SubscriptionId::fromString('contentGraph'),
                    subscriptionStatus: SubscriptionStatus::ACTIVE,
                    subscriptionPosition: SequenceNumber::fromInteger(8),
                    subscriptionError: null,
                    setupStatus: ProjectionStatus::ok(),
                ),
                DetachedSubscriptionStatus::create(
                    subscriptionId: SubscriptionId::fromString('contentGraph_90'),
                    subscriptionStatus: SubscriptionStatus::ACTIVE,
                    subscriptionPosition: SequenceNumber::fromInteger(8),
                )
            ]),
            $this->subscriptionEngine->subscriptionStatus()
        );

        // only new content graph is caught up
        $this->contentRepository->handle(CreateWorkspace::create(WorkspaceName::fromString('user-four'), WorkspaceName::fromString('live'), ContentStreamId::fromString('cs-user-four')));
        self::assertNotNull($this->contentRepository->findWorkspaceByName(WorkspaceName::fromString('user-four')));

        // old content graph is detached
        self::assertEquals(
            SubscriptionStatusCollection::fromArray([
                ProjectionSubscriptionStatus::create(
                    subscriptionId: SubscriptionId::fromString('contentGraph'),
                    subscriptionStatus: SubscriptionStatus::ACTIVE,
                    subscriptionPosition: SequenceNumber::fromInteger(10),
                    subscriptionError: null,
                    setupStatus: ProjectionStatus::ok(),
                ),
                DetachedSubscriptionStatus::create(
                    subscriptionId: SubscriptionId::fromString('contentGraph_90'),
                    subscriptionStatus: SubscriptionStatus::DETACHED,
                    subscriptionPosition: SequenceNumber::fromInteger(8),
                ),
            ]),
            $this->subscriptionEngine->subscriptionStatus()
        );
    }
}
