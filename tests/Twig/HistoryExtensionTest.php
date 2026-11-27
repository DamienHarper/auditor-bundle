<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Tests\Twig;

use DH\Auditor\History\ChangeKind;
use DH\Auditor\Provider\Doctrine\Auditing\Root\ObjectIdentifier;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Reader;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Author;
use DH\Auditor\Tests\Provider\Doctrine\Traits\Schema\BlogSchemaSetupTrait;
use DH\Auditor\User\User;
use DH\AuditorBundle\History\TranslatedFieldLabelResolver;
use DH\AuditorBundle\Twig\HistoryExtension;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[Small]
final class HistoryExtensionTest extends TestCase
{
    use BlogSchemaSetupTrait;

    public function testLastChanges(): void
    {
        $author = $this->provider->getAuditingServiceForEntity(Author::class)->getEntityManager()->find(Author::class, 1);
        $this->assertInstanceOf(Author::class, $author);

        $extension = $this->extension();
        $changes = $extension->lastChanges($author, ['fullname', 'email']);

        $this->assertSame(ChangeKind::Changed, $changes['fullname']?->kind);
        $this->assertSame('John Doe', $changes['fullname']->new);
        $this->assertSame(ChangeKind::Created, $changes['email']?->kind);
        $this->assertSame('john.doe@gmail.com', $changes['email']->getValue('email'), 'The value of a field never changed is read from the creation.');
        $this->assertSame('John Doe', $extension->lastChange($author, 'fullname')?->new);
    }

    public function testNothingWhenTheAuditsCannotBeViewed(): void
    {
        $this->provider->getAuditor()->getConfiguration()->setRoleChecker(static fn (): bool => false);
        $author = $this->provider->getAuditingServiceForEntity(Author::class)->getEntityManager()->find(Author::class, 1);
        $this->assertInstanceOf(Author::class, $author);

        $this->assertSame(['fullname' => null], $this->extension()->lastChanges($author, ['fullname']));
    }

    public function testNothingForAnObjectWithoutAudits(): void
    {
        $extension = $this->extension();

        $this->assertSame(['name' => null], $extension->lastChanges(new \stdClass(), ['name']), 'Not an entity.');
        $this->assertSame(['fullname' => null], $extension->lastChanges(new Author(), ['fullname']), 'Not persisted yet.');
    }

    public function testUserClass(): void
    {
        $extension = $this->extension();

        $this->assertSame('App\Entity\User', $extension->userClass('App\Entity\User'));
        $this->assertNull($extension->userClass(User::class), 'The class of the core User written by former versions tells nothing.');
        $this->assertNull($extension->userClass(null));
    }

    public function testFieldLabel(): void
    {
        $this->assertSame('Full name', $this->extension()->fieldLabel(Author::class, 'full_name'));
    }

    private function extension(): HistoryExtension
    {
        return new HistoryExtension(new Reader($this->provider), $this->provider, new TranslatedFieldLabelResolver(), new ObjectIdentifier($this->provider));
    }
}
