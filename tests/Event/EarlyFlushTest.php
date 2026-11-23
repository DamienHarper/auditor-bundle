<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Tests\Event;

use DH\Auditor\Provider\Doctrine\DoctrineProvider;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Tag;
use DH\Auditor\Tests\Provider\Doctrine\Traits\ReaderTrait;
use DH\Auditor\Tests\Provider\Doctrine\Traits\Schema\BlogSchemaSetupTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Small;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * A flush run before anything builds the provider (a login, a kernel.request listener) is audited, even when the
 * metadata come from a warm cache and no loadClassMetadata event builds it.
 *
 * @internal
 */
#[Small]
final class EarlyFlushTest extends KernelTestCase
{
    use BlogSchemaSetupTrait;
    use ReaderTrait;

    private DoctrineProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();

        // provider with 1 em for both storage and auditing
        $this->createAndInitDoctrineProvider();

        // declare audited entites
        $this->configureEntities();

        // setup entity and audit schemas
        $this->setupEntitySchemas();
        $this->setupAuditSchemas();
    }

    public function testFlushBeforeTheProviderIsBuiltIsAudited(): void
    {
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

        $entityManager->persist(new Tag()->setTitle('early'));

        $this->assertFalse(self::getContainer()->initialized(DoctrineProvider::class), 'Nothing has built the provider before the flush.');

        $entityManager->flush();

        $provider = self::getContainer()->get(DoctrineProvider::class);
        $entries = $this->createReader($provider)->createQuery(Tag::class)->execute();
        $this->assertCount(1, $entries, 'The flush is audited.');
        $this->assertSame('insert', $entries[0]->type);
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = self::getContainer()->get('doctrine.orm.default_entity_manager');
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }

    private function createAndInitDoctrineProvider(): void
    {
        $this->provider = self::getContainer()->get(DoctrineProvider::class);
    }
}
