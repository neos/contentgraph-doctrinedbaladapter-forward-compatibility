<?php

declare(strict_types=1);

namespace Neos\ContentGraph\DoctrineDbalAdapterForwardCompatibility\Tests\Functional;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\Factory\ContentRepositoryServiceFactoryDependencies;
use Neos\ContentRepository\Core\Factory\ContentRepositoryServiceFactoryInterface;
use Neos\ContentRepository\Core\Factory\ContentRepositoryServiceInterface;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\Subscription\Engine\SubscriptionEngine;
use Neos\ContentRepository\TestSuite\Fakes\FakeContentDimensionSourceFactory;
use Neos\ContentRepository\TestSuite\Fakes\FakeNodeTypeManagerFactory;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\EventStore\EventStoreInterface;
use Neos\Flow\Configuration\ConfigurationManager;
use Neos\Flow\Core\Bootstrap;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

abstract class AbstractContentRepositoryProjectionTestCase extends TestCase
{
    protected static ContentRepositoryId $contentRepositoryId;

    protected ContentRepository $contentRepository;

    protected SubscriptionEngine $subscriptionEngine;

    protected EventStoreInterface $eventStore;

    final public static function setUpBeforeClass(): void
    {
        static::$contentRepositoryId = ContentRepositoryId::fromString('t_compatibility');
    }

    final public function setUp(): void
    {
        $this->dropDatabaseSchema($this->getObject(Connection::class), static::$contentRepositoryId);
    }

    final protected function configureContentRepositories(string $configuration): void
    {
        FakeNodeTypeManagerFactory::setConfiguration([]);
        FakeContentDimensionSourceFactory::setWithoutDimensions();
        $this->getObject(ContentRepositoryRegistry::class)->injectSettings(Yaml::parse($configuration));
        $this->getObject(ContentRepositoryRegistry::class)->resetFactoryInstance(static::$contentRepositoryId);
        $this->setupContentRepositoryDependencies(static::$contentRepositoryId);
    }

    final protected function setupContentRepositoryDependencies(ContentRepositoryId $contentRepositoryId)
    {
        $this->contentRepository = $this->getObject(ContentRepositoryRegistry::class)->get(
            $contentRepositoryId
        );

        $subscriptionEngineAndEventStoreAccessor = new class implements ContentRepositoryServiceFactoryInterface {
            public EventStoreInterface|null $eventStore;
            public SubscriptionEngine|null $subscriptionEngine;
            public function build(ContentRepositoryServiceFactoryDependencies $serviceFactoryDependencies): ContentRepositoryServiceInterface
            {
                $this->eventStore = $serviceFactoryDependencies->eventStore;
                $this->subscriptionEngine = $serviceFactoryDependencies->subscriptionEngine;
                return new class implements ContentRepositoryServiceInterface
                {
                };
            }
        };
        $this->getObject(ContentRepositoryRegistry::class)->buildService($contentRepositoryId, $subscriptionEngineAndEventStoreAccessor);
        $this->eventStore = $subscriptionEngineAndEventStoreAccessor->eventStore;
        $this->subscriptionEngine = $subscriptionEngineAndEventStoreAccessor->subscriptionEngine;
    }

    /** @after */
    final public function resetContentRepositoryRegistry(): void
    {
        $originalSettings = $this->getObject(ConfigurationManager::class)->getConfiguration(ConfigurationManager::CONFIGURATION_TYPE_SETTINGS, 'Neos.ContentRepositoryRegistry');
        $this->getObject(ContentRepositoryRegistry::class)->injectSettings($originalSettings);
        $this->getObject(ContentRepositoryRegistry::class)->resetFactoryInstance(static::$contentRepositoryId);
    }

    final protected function dropDatabaseSchema(Connection $connection, ContentRepositoryId $contentRepositoryId): void
    {
        $preDeleteStatement = match (true) {
            $connection->getDatabasePlatform() instanceof AbstractMySQLPlatform => 'SET FOREIGN_KEY_CHECKS = 0;',
            default => '',
        };

        if ($preDeleteStatement !== '') {
            $connection->prepare($preDeleteStatement)->executeStatement();
        }

        if ($connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            $cascade = ' CASCADE';
        } else {
            $cascade = '';
        }

        foreach ($connection->createSchemaManager()->listTableNames() as $tableName) {
            if (!str_starts_with($tableName, sprintf('cr_%s_', $contentRepositoryId->value))) {
                // speedup deletion, only delete current cr
                continue;
            }
            $sql = 'DROP TABLE ' . $connection->quoteIdentifier($tableName) . $cascade;
            $connection->prepare($sql)->executeStatement();
        }

        $postDeleteStatement = match (true) {
            $connection->getDatabasePlatform() instanceof AbstractMySQLPlatform => 'SET FOREIGN_KEY_CHECKS = 1;',
            default => '',
        };

        if ($postDeleteStatement !== '') {
            $connection->prepare($postDeleteStatement)->executeStatement();
        }
    }

    /**
     * @template T of object
     * @param class-string<T> $className
     *
     * @return T
     */
    final protected function getObject(string $className): object
    {
        return Bootstrap::$staticObjectManager->get($className);
    }
}
