<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Tests\Event;

use DH\Auditor\Provider\Doctrine\DoctrineProvider;
use DH\Auditor\Provider\Doctrine\Persistence\Schema\SchemaManager;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Tag;
use DH\Auditor\Tests\Provider\Doctrine\Traits\ReaderTrait;
use DH\AuditorBundle\Event\DoctrineProviderBootSubscriber;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\Small;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * A flush made by a kernel.request listener (or a login) is audited, even when the metadata come from a warm cache and
 * no loadClassMetadata event builds the provider.
 *
 * @internal
 */
#[Small]
final class DoctrineProviderBootSubscriberTest extends KernelTestCase
{
    use ReaderTrait;

    protected function tearDown(): void
    {
        self::bootKernel();
        $this->dropSchemas();

        parent::tearDown();
    }

    public function testFlushMadeDuringKernelRequestIsAudited(): void
    {
        self::bootKernel();
        $this->dropSchemas();
        $entityManager = $this->entityManager();
        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());
        (new SchemaManager($this->doctrineProvider()))->updateAuditSchema();

        // a metadata cache warmed by another container, as in production
        $metadataCache = new ArrayAdapter();
        self::ensureKernelShutdown();
        self::bootKernel();
        $this->entityManager()->getMetadataFactory()->setCache($metadataCache);
        $this->entityManager()->getMetadataFactory()->getAllMetadata();

        self::ensureKernelShutdown();
        self::bootKernel();
        $entityManager = $this->entityManager();
        $entityManager->getMetadataFactory()->setCache($metadataCache);
        $this->assertFalse($entityManager->getEventManager()->hasListeners(Events::onFlush), 'Nothing has attached the listeners of the provider yet.');

        // a listener of kernel.request that flushes, after the firewall
        $dispatcher = self::getContainer()->get('event_dispatcher');
        $this->assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(KernelEvents::REQUEST, static function () use ($entityManager): void {
            $entityManager->persist((new Tag())->setTitle('early'));
            $entityManager->flush();
        });
        $dispatcher->dispatch(new RequestEvent(self::$kernel, Request::create('/audit'), HttpKernelInterface::MAIN_REQUEST), KernelEvents::REQUEST);

        $entries = $this->createReader($this->doctrineProvider())->createQuery(Tag::class)->execute();
        $this->assertCount(1, $entries, 'The flush is audited.');
        $this->assertSame('insert', $entries[0]->getType());
    }

    public function testRunsBeforeTheOtherKernelRequestListeners(): void
    {
        self::bootKernel();
        $dispatcher = self::getContainer()->get('event_dispatcher');
        $this->assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        $priorities = [];
        foreach ($dispatcher->getListeners(KernelEvents::REQUEST) as $listener) {
            $priority = $dispatcher->getListenerPriority(KernelEvents::REQUEST, $listener);
            if (\is_array($listener) && $listener[0] instanceof DoctrineProviderBootSubscriber) {
                $bootPriority = $priority;
            } else {
                $priorities[] = $priority;
            }
        }

        $this->assertTrue(isset($bootPriority), 'The subscriber listens to kernel.request.');
        $this->assertGreaterThan(max($priorities), $bootPriority);
    }

    private function dropSchemas(): void
    {
        $provider = $this->doctrineProvider();
        foreach ([...$provider->getAuditingServices(), ...$provider->getStorageServices()] as $service) {
            (new SchemaTool($service->getEntityManager()))->dropDatabase();
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
