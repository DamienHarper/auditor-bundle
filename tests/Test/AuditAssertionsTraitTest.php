<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Tests\Test;

use DH\Auditor\Provider\Doctrine\DoctrineProvider;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Post;
use DH\Auditor\Tests\Provider\Doctrine\Traits\Schema\BlogSchemaSetupTrait;
use DH\AuditorBundle\Test\AuditAssertionsTrait;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Small;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * @internal
 */
#[Small]
final class AuditAssertionsTraitTest extends KernelTestCase
{
    use AuditAssertionsTrait;
    use BlogSchemaSetupTrait;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();

        $this->createAndInitDoctrineProvider();
        $this->configureEntities();
        $this->setupEntitySchemas();
        $this->setupAuditSchemas();
        $this->setupEntities();
    }

    public function testEntriesAreFilteredByObjectAndType(): void
    {
        $post = $this->post();

        $this->assertNotEmpty(self::getAuditEntries(Post::class));
        foreach (self::getAuditEntries(Post::class, $post->getId(), 'insert') as $entry) {
            $this->assertSame((string) $post->getId(), $entry->objectId);
            $this->assertSame('insert', $entry->type);
        }

        self::assertAuditCount(1, Post::class, $post->getId(), 'insert');
    }

    public function testAFieldChangeIsAsserted(): void
    {
        $post = $this->post();
        $title = $post->getTitle();
        $updates = \count(self::getAuditEntries(Post::class, $post->getId(), 'update'));

        $post->setTitle('A new title');
        $this->flush();

        self::assertAuditCount($updates + 1, Post::class, $post->getId(), 'update');
        self::assertAuditFieldChanged(Post::class, (string) $post->getId(), 'title', $title, 'A new title');
    }

    public function testAWrongChangeFails(): void
    {
        $post = $this->post();
        $post->setTitle('A new title');
        $this->flush();

        $this->expectException(AssertionFailedError::class);

        self::assertAuditFieldChanged(Post::class, (string) $post->getId(), 'title', 'not the old title', 'A new title');
    }

    public function testAFieldThatNeverChangedFails(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessageIsOrContains('changed "unknown_field"');

        self::assertAuditFieldChanged(Post::class, (string) $this->post()->getId(), 'unknown_field', null, 'x');
    }

    private function createAndInitDoctrineProvider(): void
    {
        $provider = self::getContainer()->get(DoctrineProvider::class);
        $this->assertInstanceOf(DoctrineProvider::class, $provider);
        $this->provider = $provider;
    }

    private function post(): Post
    {
        $post = $this->provider->getAuditingServiceForEntity(Post::class)->getEntityManager()->getRepository(Post::class)->findOneBy([]);
        $this->assertInstanceOf(Post::class, $post);

        return $post;
    }

    private function flush(): void
    {
        $this->provider->getAuditingServiceForEntity(Post::class)->getEntityManager()->flush();
    }
}
