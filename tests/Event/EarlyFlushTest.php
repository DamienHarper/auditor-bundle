<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Tests\Event;

use DH\Auditor\Provider\Doctrine\DoctrineProvider;
use DH\Auditor\Provider\Doctrine\Persistence\Schema\SchemaManager;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Tag;
use DH\Auditor\Tests\Provider\Doctrine\Traits\ReaderTrait;
use DH\AuditorBundle\DependencyInjection\Compiler\RegisterDoctrineListenersCompilerPass;
use DH\AuditorBundle\DHAuditorBundle;
use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Doctrine\ORM\Tools\SchemaTool;
use Nyholm\BundleTest\TestKernel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * A flush run before anything builds the provider (a login, a kernel.request listener) is audited, even when the
 * metadata come from a warm cache and no loadClassMetadata event builds it.
 *
 * @internal
 */
#[Small]
final class EarlyFlushTest extends KernelTestCase
{
    use ReaderTrait;

    private ?DoctrineProvider $provider = null;

    private string $config = 'same_storage';

    protected function tearDown(): void
    {
        if ($this->provider instanceof DoctrineProvider) {
            $this->dropDatabases($this->provider);
        }

        parent::tearDown();
    }

    #[DataProvider('provideFlushBeforeTheProviderIsBuiltIsAuditedCases')]
    public function testFlushBeforeTheProviderIsBuiltIsAudited(string $config): void
    {
        $this->setUpSchemas($config);

        // a metadata cache warmed by another container, as in production
        $metadataCache = new ArrayAdapter();
        $this->reboot();
        $this->entityManager()->getMetadataFactory()->setCache($metadataCache);
        $this->entityManager()->getMetadataFactory()->getAllMetadata();

        $this->reboot();
        $entityManager = $this->entityManager();
        $entityManager->getMetadataFactory()->setCache($metadataCache);
        $entityManager->persist(new Tag()->setTitle('early'));

        $this->assertFalse(self::getContainer()->initialized(DoctrineProvider::class), 'Nothing has built the provider before the flush.');

        $entityManager->flush();

        $entries = $this->createReader($this->doctrineProvider())->createQuery(Tag::class)->execute();
        $this->assertCount(1, $entries, 'The flush is audited.');
        $this->assertSame('insert', $entries[0]->type);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideFlushBeforeTheProviderIsBuiltIsAuditedCases(): iterable
    {
        yield 'audits stored by the audited entity manager' => ['same_storage'];

        // the first flush builds the provider, which builds the storage entity manager of another connection
        yield 'audits stored by another entity manager' => ['separate_storage'];
    }

    public function testDeclaredSubscriberIsTheOneOfTheProvider(): void
    {
        $this->setUpSchemas('same_storage');

        $subscriber = self::getContainer()->get(RegisterDoctrineListenersCompilerPass::SUBSCRIBER_SERVICE_ID);

        // the subscriber reset by the provider (kernel.reset) is the one that listens, once
        $this->assertSame($this->doctrineProvider()->getDoctrineSubscriber(), $subscriber);
        $eventManager = $this->entityManager()->getEventManager();
        $this->assertSame([$subscriber], array_values($eventManager->getListeners(Events::onFlush)));
        $this->assertSame([$subscriber], array_values($eventManager->getListeners(Events::postFlush)));
    }

    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    protected static function createKernel(array $options = []): KernelInterface
    {
        /**
         * @var TestKernel $kernel
         */
        $kernel = parent::createKernel($options);
        $kernel->addTestBundle(DHAuditorBundle::class);
        $kernel->handleOptions($options);

        return $kernel;
    }

    /**
     * Boots the kernel with a configuration, then creates the entity and audit schemas.
     */
    private function setUpSchemas(string $config): void
    {
        $this->config = $config;
        $this->reboot();

        $this->provider = $this->doctrineProvider();
        $this->dropDatabases($this->provider);

        $entityManager = $this->entityManager();
        new SchemaTool($entityManager)->createSchema($entityManager->getMetadataFactory()->getAllMetadata());
        new SchemaManager($this->provider)->updateAuditSchema();
    }

    private function dropDatabases(DoctrineProvider $provider): void
    {
        foreach ([...$provider->getAuditingServices(), ...$provider->getStorageServices()] as $service) {
            new SchemaTool($service->getEntityManager())->dropDatabase();
        }
    }

    private function reboot(): void
    {
        $config = $this->config;
        self::ensureKernelShutdown();
        self::bootKernel(['config' => static function (TestKernel $kernel) use ($config): void {
            $kernel->setClearCacheAfterShutdown(false);
            $kernel->addTestBundle(DoctrineBundle::class);
            $kernel->addTestBundle(SecurityBundle::class);
            $kernel->addTestBundle(TwigBundle::class);
            $kernel->addTestConfig(__DIR__.'/../Fixtures/Resources/config/early_flush/'.$config.'.yaml');
            $kernel->addTestConfig(__DIR__.'/../Fixtures/Resources/config/doctrine.yaml');
            $kernel->addTestConfig(__DIR__.'/../Fixtures/Resources/config/security.yaml');
        }]);

        // the SQLite files of the fixture
        $directory = self::$kernel->getProjectDir().'/var';
        if (!is_dir($directory)) {
            mkdir($directory, 0o777, true);
        }
    }

    private function doctrineProvider(): DoctrineProvider
    {
        $provider = self::getContainer()->get(DoctrineProvider::class);
        $this->assertInstanceOf(DoctrineProvider::class, $provider);

        return $provider;
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = self::getContainer()->get('doctrine.orm.default_entity_manager');
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }
}
