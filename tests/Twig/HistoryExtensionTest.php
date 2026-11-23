<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Tests\Twig;

use DH\Auditor\History\ChangeKind;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Reader;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Author;
use DH\Auditor\Tests\Provider\Doctrine\Traits\Schema\BlogSchemaSetupTrait;
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
        $this->assertSame('John Doe', $extension->lastChange($author, 'fullname')?->new);
    }

    public function testNothingWhenTheAuditsCannotBeViewed(): void
    {
        $this->provider->getAuditor()->getConfiguration()->setRoleChecker(static fn (): bool => false);
        $author = $this->provider->getAuditingServiceForEntity(Author::class)->getEntityManager()->find(Author::class, 1);
        $this->assertInstanceOf(Author::class, $author);

        $this->assertSame(['fullname' => null], $this->extension()->lastChanges($author, ['fullname']));
    }

    public function testFieldLabel(): void
    {
        $this->assertSame('Full name', $this->extension()->fieldLabel(Author::class, 'full_name'));
    }

    private function extension(): HistoryExtension
    {
        return new HistoryExtension(new Reader($this->provider), $this->provider, new TranslatedFieldLabelResolver());
    }
}
