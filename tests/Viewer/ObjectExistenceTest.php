<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Tests\Viewer;

use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Author;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Tag;
use DH\Auditor\Tests\Provider\Doctrine\Traits\Schema\BlogSchemaSetupTrait;
use DH\AuditorBundle\History\Part\ObjectReference;
use DH\AuditorBundle\Viewer\ObjectExistence;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[Small]
final class ObjectExistenceTest extends TestCase
{
    use BlogSchemaSetupTrait;

    public function testDeletedObjects(): void
    {
        $existence = new ObjectExistence($this->provider);
        $existence->prime([
            new ObjectReference(Author::class, '1', 'John'),
            new ObjectReference(Author::class, '999', 'Gone'),
            new ObjectReference(Tag::class, '1', 'php'),
            new ObjectReference('App\Entity\Removed', '1', 'x'),
        ]);

        $this->assertFalse($existence->isDeleted(new ObjectReference(Author::class, '1', 'John')));
        $this->assertTrue($existence->isDeleted(new ObjectReference(Author::class, '999', 'Gone')));
        $this->assertFalse($existence->isDeleted(new ObjectReference(Tag::class, '1', 'php')));
        $this->assertFalse($existence->isDeleted(new ObjectReference('App\Entity\Removed', '1', 'x')), 'A class unknown to Doctrine cannot be checked.');
        $this->assertFalse($existence->isDeleted(new ObjectReference(Author::class, '2', 'Not primed')), 'Unknown until primed.');
    }

    public function testNotANumber(): void
    {
        $existence = new ObjectExistence($this->provider);
        $existence->prime([new ObjectReference(Author::class, 'abc', 'x')]);

        $this->assertTrue($existence->isDeleted(new ObjectReference(Author::class, 'abc', 'x')), 'No integer identifier can match it.');
    }

    public function testReset(): void
    {
        $existence = new ObjectExistence($this->provider);
        $existence->prime([new ObjectReference(Author::class, '999', 'Gone')]);
        $existence->reset();

        $this->assertFalse($existence->isDeleted(new ObjectReference(Author::class, '999', 'Gone')));
    }
}
