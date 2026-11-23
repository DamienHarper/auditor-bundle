<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Tests\Twig;

use DH\Auditor\History\HistoryItem;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Post;
use DH\AuditorBundle\History\HistoryPhraser;
use DH\AuditorBundle\Tests\Fixtures\History\HistoryFixtures;
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

        $item = new HistoryItem('01JTX', [self::entry('update', Post::class, '1', ['body' => ['old' => 'A & B', 'new' => 'C']])]);
        $template = $twig->createTemplate('{{ item|dh_auditor_author }} {{ item|dh_auditor_phrase }} | {{ (item|dh_auditor_phrase).html|raw }}');

        $this->assertSame('alice edited Body | edited Body', $template->render(['item' => $item]));

        $item = new HistoryItem('01JTX', [self::entry('update', Post::class, '1', ['coauthor' => ['old' => null, 'new' => ['class' => 'App\Entity\Author', 'id' => 1, 'label' => 'A & B']]])]);

        $this->assertSame('alice set Coauthor: A &amp; B | set Coauthor: <strong>A &amp; B</strong>', $template->render(['item' => $item]), 'The text is escaped, the HTML is safe.');
    }

    public function testEntityUnknownToDoctrine(): void
    {
        $phraser = self::getContainer()->get(HistoryPhraser::class);
        $this->assertInstanceOf(HistoryPhraser::class, $phraser);

        $item = new HistoryItem('01JTX', [self::entry('update', 'App\Entity\Removed', '1', ['name' => ['old' => 'A', 'new' => 'B']])]);

        $this->assertSame('changed Name: A → B', (string) $phraser->phrase($item), 'An audited class that no longer exists keeps its raw values.');
    }

    public function testDescribersOfTheApplication(): void
    {
        $phraser = self::getContainer()->get(HistoryPhraser::class);
        $this->assertInstanceOf(HistoryPhraser::class, $phraser);

        $item = new HistoryItem('01JTX', [self::entry('update', Post::class, '1', ['title' => ['old' => 'A', 'new' => 'B']])]);

        $this->assertSame('retitled the post', (string) $phraser->phrase($item), 'A service with #[AsChangeDescriber] is registered.');
    }
}
