<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Tests\History;

use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Author;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Post;
use DH\AuditorBundle\History\ObjectUrl;
use DH\AuditorBundle\History\ObjectUrlResolverInterface;
use DH\AuditorBundle\History\Part\ObjectReference;
use DH\AuditorBundle\History\ViewerObjectUrlResolver;
use PHPUnit\Framework\Attributes\Small;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * @internal
 */
#[Small]
final class ViewerObjectUrlResolverTest extends KernelTestCase
{
    public function testHistoryOfTheObjectInTheViewer(): void
    {
        $url = $this->resolver()->resolve(new ObjectReference(Post::class, '12', 'Why audit?'));

        $this->assertSame('/audit/DH-Auditor-Tests-Provider-Doctrine-Fixtures-Entity-Standard-Blog-Post/12', $url?->url);
        $this->assertTrue($url->visible);
    }

    public function testHiddenFromReadersWithoutTheViewRole(): void
    {
        $url = $this->resolver()->resolve(new ObjectReference(Author::class, '1', 'John'));

        $this->assertInstanceOf(ObjectUrl::class, $url);
        $this->assertFalse($url->visible, 'Author can only be viewed with ROLE1 or ROLE2.');
    }

    public function testNoUrlForAClassTheViewerCannotShow(): void
    {
        $this->assertNull($this->resolver()->resolve(new ObjectReference(\stdClass::class, '1', 'x')), 'Not audited.');
        $this->assertNull($this->resolver()->resolve(new ObjectReference('App\Entity\Gone', '1', 'x')), 'No such class.');
    }

    private function resolver(): ViewerObjectUrlResolver
    {
        $resolver = self::getContainer()->get(ObjectUrlResolverInterface::class);
        $this->assertInstanceOf(ViewerObjectUrlResolver::class, $resolver);

        return $resolver;
    }
}
