<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Tests\Viewer;

use DH\Auditor\Provider\Doctrine\DoctrineProvider;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Post;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Tag;
use DH\AuditorBundle\Viewer\AuditViewerUrlGenerator;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Small;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * @internal
 */
#[Small]
final class AuditViewerUrlGeneratorTest extends KernelTestCase
{
    private const string POST_PARAM = 'DH-Auditor-Tests-Provider-Doctrine-Fixtures-Entity-Standard-Blog-Post';

    public function testEntityClassStream(): void
    {
        $generator = $this->getGenerator();

        $this->assertSame('/audit/'.self::POST_PARAM, $generator->generateEntityUrl(Post::class));
        $this->assertSame('/audit/'.self::POST_PARAM.'/42', $generator->generateEntityUrl(Post::class, 42));
        $this->assertSame('/audit/'.self::POST_PARAM.'/abc', $generator->generateEntityUrl(Post::class, 'abc'));
    }

    public function testEntityObjectStream(): void
    {
        $generator = $this->getGenerator();

        $this->assertSame('/audit/'.self::POST_PARAM.'/7', $generator->generateEntityUrl($this->createPost(7)));
        $this->assertSame('/audit/'.self::POST_PARAM.'/8', $generator->generateEntityUrl($this->createPost(7), 8), 'An explicit id wins.');
        $this->assertNull($generator->generateEntityUrl(new Post()), 'An entity without an identifier has no stream yet.');
    }

    public function testUninitializedReferenceIsIdentifiedWithoutBeingLoaded(): void
    {
        $generator = $this->getGenerator();

        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get('doctrine.orm.default_entity_manager');
        $reference = $entityManager->getReference(Post::class, 12);

        $this->assertInstanceOf(Post::class, $reference);
        $this->assertSame('/audit/'.self::POST_PARAM.'/12', $generator->generateEntityUrl($reference));
        $this->assertTrue(new \ReflectionClass(Post::class)->isUninitializedLazyObject($reference), 'The reference is not loaded.');
    }

    public function testFiltersAndReferenceType(): void
    {
        $generator = $this->getGenerator();

        $this->assertSame(
            '/audit/'.self::POST_PARAM.'/42?type=update&user=__anonymous__',
            $generator->generateEntityUrl(Post::class, 42, ['type' => 'update', 'user' => '__anonymous__', 'field' => null, 'date_from' => '']),
        );
        $this->assertSame(
            'http://localhost/audit/'.self::POST_PARAM.'?field=title',
            $generator->generateEntityUrl(Post::class, null, ['field' => 'title'], UrlGeneratorInterface::ABSOLUTE_URL),
        );
    }

    public function testNotAuditedEntity(): void
    {
        $generator = $this->getGenerator();

        $this->assertNull($generator->generateEntityUrl('App\Entity\NotAudited'));
        $this->assertNull($generator->generateEntityUrl(new \stdClass()));
    }

    public function testTransactionAndUserStreams(): void
    {
        $generator = $this->getGenerator();

        $this->assertSame('/audit/transaction/01JABCDEFGHJKMNPQRSTVWXYZ0', $generator->generateTransactionUrl('01JABCDEFGHJKMNPQRSTVWXYZ0'));
        $this->assertSame('/audit/user/42', $generator->generateUserUrl(42));
        $this->assertSame('/audit/user/john@example.com', $generator->generateUserUrl('john@example.com'));
        $this->assertSame('http://localhost/audit/user/42', $generator->generateUserUrl('42', UrlGeneratorInterface::ABSOLUTE_URL));
        $this->assertNull($generator->generateTransactionUrl(''));
        $this->assertNull($generator->generateUserUrl(''));
    }

    public function testNoUrlWhenTheAuditorIsDisabled(): void
    {
        $generator = $this->getGenerator();
        self::getContainer()->get(DoctrineProvider::class)->getAuditor()->getConfiguration()->disable();

        $this->assertNull($generator->generateEntityUrl(Post::class, 1));
        $this->assertNull($generator->generateTransactionUrl('01JABCDEFGHJKMNPQRSTVWXYZ0'));
        $this->assertNull($generator->generateUserUrl(42));
    }

    public function testNoUrlWhenTheViewerIsDisabled(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        // The viewer routes are not registered when the viewer is disabled: nothing may be generated.
        $generator = new AuditViewerUrlGenerator(
            $container->get(DoctrineProvider::class),
            $this->createStub(UrlGeneratorInterface::class),
            false,
        );

        $this->assertNull($generator->generateEntityUrl(Tag::class));
        $this->assertNull($generator->generateTransactionUrl('01JABCDEFGHJKMNPQRSTVWXYZ0'));
        $this->assertNull($generator->generateUserUrl(42));
    }

    private function getGenerator(): AuditViewerUrlGenerator
    {
        self::bootKernel();

        return self::getContainer()->get(AuditViewerUrlGenerator::class);
    }

    private function createPost(int $id): Post
    {
        $post = new Post();
        new \ReflectionProperty(Post::class, 'id')->setValue($post, $id);

        return $post;
    }
}
