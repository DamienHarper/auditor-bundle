<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Tests\Twig;

use DH\Auditor\History\HistoryItem;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Author;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Post;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Tag;
use DH\AuditorBundle\History\Fragment;
use DH\AuditorBundle\History\HistoryPhraser;
use DH\AuditorBundle\History\ObjectUrlResolverInterface;
use DH\AuditorBundle\History\Part\ObjectReference;
use DH\AuditorBundle\History\Part\ValuePart;
use DH\AuditorBundle\History\Phrase;
use DH\AuditorBundle\History\ViewerObjectUrlResolver;
use DH\AuditorBundle\Tests\Fixtures\History\HistoryFixtures;
use DH\AuditorBundle\Viewer\AuditViewerUrlGenerator;
use PHPUnit\Framework\Attributes\Small;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * @internal
 */
#[Small]
final class PhraseExtensionTest extends KernelTestCase
{
    use HistoryFixtures;

    public function testFilters(): void
    {
        $twig = self::getContainer()->get('twig');
        $this->assertInstanceOf(Environment::class, $twig);
        $urls = self::getContainer()->get(AuditViewerUrlGenerator::class);
        $this->assertInstanceOf(AuditViewerUrlGenerator::class, $urls);

        $template = $twig->createTemplate('{{ item|dh_auditor_author }} {{ item|dh_auditor_phrase }} | {{ item|dh_auditor_phrase_text }}');

        $item = new HistoryItem('01JTX', [self::entry('insert', Post::class, '1', [], 'A & <B>')]);
        $this->assertSame(
            \sprintf('alice created Post “<strong><a href="%s">A &amp; &lt;B&gt;</a></strong>” | created Post “A &amp; &lt;B&gt;”', $urls->generateEntityUrl(Post::class, '1')),
            $this->withoutClasses($template->render(['item' => $item])),
            'An object reference links to its history in the viewer; the text filter has no markup, and Twig escapes it.',
        );

        $item = new HistoryItem('01JTX', [self::entry('update', Post::class, '1', [
            'body' => ['old' => 'A', 'new' => 'B'],
            'coauthor' => ['old' => null, 'new' => ['class' => 'App\Entity\Gone', 'id' => 1, 'label' => 'A & B']],
            'summary' => ['old' => 'x', 'new' => '<i>y</i>'],
        ])]);
        $this->assertSame(
            'alice edited Body, set Coauthor: <strong>A &amp; B</strong> and changed Summary: <strong>x</strong> → <strong>&lt;i&gt;y&lt;/i&gt;</strong> | edited Body, set Coauthor: A &amp; B and changed Summary: x → &lt;i&gt;y&lt;/i&gt;',
            $this->withoutClasses($template->render(['item' => $item])),
            'Values in <strong>; an object without URL stays plain.',
        );
    }

    public function testPhraseOfADescriber(): void
    {
        $twig = self::getContainer()->get('twig');
        $this->assertInstanceOf(Environment::class, $twig);

        $phrase = Phrase::of(Fragment::of(['moved to ', new ObjectReference(Tag::class, '3', '<php>'), ' by ', new ValuePart('<script>')]));

        $this->assertSame(
            \sprintf('moved to <strong><a href="%s">&lt;php&gt;</a></strong> by <strong>&lt;script&gt;</strong>', self::getContainer()->get(AuditViewerUrlGenerator::class)?->generateEntityUrl(Tag::class, '3')),
            $this->withoutClasses($twig->createTemplate('{{ phrase|dh_auditor_phrase }}')->render(['phrase' => $phrase])),
            'A describer links objects without writing HTML.',
        );
    }

    public function testHiddenLink(): void
    {
        $twig = self::getContainer()->get('twig');
        $this->assertInstanceOf(Environment::class, $twig);

        $this->assertInstanceOf(ViewerObjectUrlResolver::class, self::getContainer()->get(ObjectUrlResolverInterface::class));
        $phrase = Phrase::of(Fragment::of([new ObjectReference(Author::class, '1', 'John')]));
        $html = $this->withoutClasses($twig->createTemplate('{{ phrase|dh_auditor_phrase }}')->render(['phrase' => $phrase]));

        $this->assertSame('<strong>John</strong>', $html, 'A reader without the view role of the class gets no link.');
    }

    public function testEntityUnknownToDoctrine(): void
    {
        $phraser = self::getContainer()->get(HistoryPhraser::class);
        $this->assertInstanceOf(HistoryPhraser::class, $phraser);

        $item = new HistoryItem('01JTX', [self::entry('update', 'App\Entity\Removed', '1', ['name' => ['old' => 'A', 'new' => 'B']])]);

        $this->assertSame('changed Name: A → B', (string) $phraser->phrase($item), 'An audited class that no longer exists keeps its raw values.');
    }

    public function testAuthorNameResolverOfTheApplication(): void
    {
        $phraser = self::getContainer()->get(HistoryPhraser::class);
        $this->assertInstanceOf(HistoryPhraser::class, $phraser);

        $this->assertSame('Bob Martin', $phraser->author(new HistoryItem('01JTX', [self::entry('insert', Post::class, '1', blame: ['username' => 'bob'], userId: 2)])), 'The service aliased to the interface gives the current name.');
        $this->assertSame('Deleted user', $phraser->author(new HistoryItem('01JTX', [self::entry('insert', Post::class, '1', blame: ['username' => 'carol'], userId: 3)])));
    }

    public function testDescribersOfTheApplication(): void
    {
        $phraser = self::getContainer()->get(HistoryPhraser::class);
        $this->assertInstanceOf(HistoryPhraser::class, $phraser);

        $item = new HistoryItem('01JTX', [self::entry('update', Post::class, '1', ['title' => ['old' => 'A', 'new' => 'B']])]);

        $this->assertSame('retitled the post', (string) $phraser->phrase($item), 'A service with #[AsChangeDescriber] is registered.');
    }

    /**
     * The markup without its styling: escaping and structure are what is tested.
     */
    private function withoutClasses(string $html): string
    {
        return (string) preg_replace('/ class="[^"]*"/', '', $html);
    }
}
