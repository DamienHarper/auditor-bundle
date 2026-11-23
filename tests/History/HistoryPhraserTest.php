<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Tests\History;

use DH\Auditor\History\Change;
use DH\Auditor\History\HistoryItem;
use DH\Auditor\Model\Entry;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Comment;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Post;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Tag;
use DH\AuditorBundle\History\AuthorNameResolverInterface;
use DH\AuditorBundle\History\ChangeDescriberInterface;
use DH\AuditorBundle\History\Fragment;
use DH\AuditorBundle\History\HistoryPhraser;
use DH\AuditorBundle\History\Phrase;
use DH\AuditorBundle\History\TranslatedFieldLabelResolver;
use DH\AuditorBundle\History\ValueFormatter;
use DH\AuditorBundle\Tests\Fixtures\History\HistoryFixtures;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;

/**
 * @internal
 */
#[Small]
final class HistoryPhraserTest extends TestCase
{
    use HistoryFixtures;

    public function testCreationWithoutConfiguration(): void
    {
        $phrase = $this->phraser()->phrase($this->item(self::entry('insert', Post::class, '1', ['title' => ['old' => null, 'new' => 'Why audit?']], 'Why audit?')));

        $this->assertSame('created Post “Why audit?”', $phrase->text());
        $this->assertSame('created', $phrase->icon);
    }

    public function testEntityLevelChanges(): void
    {
        $phraser = $this->phraser('fr');

        $this->assertSame("a enregistré l'état initial de Tag « php »", (string) $phraser->phrase($this->item(self::entry('insert', Tag::class, '3', ['title' => ['old' => null, 'new' => 'php']], 'php', snapshot: true))));
        $this->assertSame('a supprimé Comment « spam »', (string) $phraser->phrase($this->item(self::entry('remove', Comment::class, '9', [], 'spam'))));
        $this->assertSame('a supprimé Comment « #9 »', (string) $phraser->phrase($this->item(self::entry('remove', Comment::class, '9'))), 'An object without label is named by its id.');
    }

    public function testFieldChangesWithoutConfiguration(): void
    {
        $phrase = $this->phraser('fr')->phrase($this->item(self::entry('update', Post::class, '1', [
            'status' => ['old' => 'draft', 'new' => 'published'],
            'publishedAt' => ['old' => null, 'new' => '2026-11-22 18:41:00'],
            'deadline' => ['old' => '2026-11-30', 'new' => null],
            'body' => ['old' => 'Old text', 'new' => 'New text'],
            'featured' => ['old' => false, 'new' => true],
            'metadata' => ['seo' => ['description' => ['old' => 'a', 'new' => 'b']], 'tags' => ['new' => 'x']],
        ])));

        $this->assertSame([
            'a modifié Body',
            'a changé Status : Brouillon → Publié',
            'a changé Featured : non → oui',
            'a renseigné Published at : 22 nov. 2026, 18:41',
            'a vidé Deadline (30 nov. 2026)',
            'a modifié Metadata (seo.description et tags)',
        ], array_map(strval(...), $phrase->fragments), 'Fields in the order of their mapping, unmapped ones last.');
        $this->assertSame(['text', 'status', 'toggle', 'edited', 'edited', 'json'], array_map(static fn (Fragment $fragment): ?string => $fragment->icon, $phrase->fragments));
        $this->assertSame('text', $phrase->icon, 'The icon of the phrase is the one of its main change.');
        $this->assertStringEndsWith(', a vidé Deadline (30 nov. 2026) et a modifié Metadata (seo.description et tags)', $phrase->text());
    }

    public function testLongTextIsEditedWithoutValues(): void
    {
        $long = str_repeat('word ', 20);
        $phrase = $this->phraser()->phrase($this->item(self::entry('update', Post::class, '1', ['title' => ['old' => 'Short', 'new' => $long]])));

        $this->assertSame('edited Title', $phrase->text(), 'A value longer than a phrase allows is not repeated in it.');
    }

    public function testTranslatedPhrases(): void
    {
        $phraser = $this->phraser('fr', [
            Post::class.'.created' => "a créé l'article « {label} »",
            Post::class.'.status.changed' => 'a passé le statut de {old} à {new}',
            Post::class.'.tags.added' => '{count, plural, one {a ajouté le tag {names}} other {a ajouté les tags {names}}}',
        ], [
            Post::class.'.status' => 'Statut',
            Post::class.'.tags' => 'Tags',
        ]);

        $this->assertSame("a créé l'article « Why audit? »", (string) $phraser->phrase($this->item(self::entry('insert', Post::class, '1', [], 'Why audit?'))));
        $this->assertSame('a passé le statut de Brouillon à Publié', (string) $phraser->phrase($this->item(self::entry('update', Post::class, '1', ['status' => ['old' => 'draft', 'new' => 'published']]))));
        $this->assertSame('a ajouté les tags symfony et audit', (string) $phraser->phrase($this->item(
            $this->tagEntry('associate', 2, 'audit', 2),
            $this->tagEntry('associate', 1, 'symfony', 1),
        )));
        $this->assertSame('a changé Featured : non → oui', (string) $phraser->phrase($this->item(self::entry('update', Post::class, '1', ['featured' => ['old' => false, 'new' => true]]))), 'A field without phrase keeps the neutral one.');
    }

    public function testEntityNames(): void
    {
        $phraser = $this->phraser('fr', [], [Post::class => '{count, plural, one {article} other {articles}}']);

        $this->assertSame('a créé article « Why audit? »', (string) $phraser->phrase($this->item(self::entry('insert', Post::class, '1', [], 'Why audit?'))));
    }

    public function testCollectionChangesOfTheSameFieldAreMerged(): void
    {
        $phrase = $this->phraser()->phrase($this->item(
            $this->tagEntry('dissociate', 3, 'php', 3),
            $this->tagEntry('associate', 2, 'audit', 2),
            $this->tagEntry('associate', 1, 'symfony', 1),
        ));

        $this->assertSame('added to Tags: symfony and audit and removed from Tags: php', $phrase->text());
        $this->assertCount(2, $phrase->fragments[0]->changes);
        $this->assertSame('added', $phrase->icon);
    }

    public function testLongListsAreShortened(): void
    {
        $entries = [];
        foreach (['a', 'b', 'c', 'd', 'e', 'f', 'g'] as $index => $name) {
            array_unshift($entries, $this->tagEntry('associate', $index + 1, $name, $index + 1));
        }

        $this->assertSame('added to Tags: a, b, c and 4 others', (string) $this->phraser()->phrase($this->item(...$entries)));
    }

    public function testRootChangesComeFirst(): void
    {
        $phrase = $this->phraser()->phrase($this->item(
            self::entry('update', Post::class, '1', ['title' => ['old' => 'A', 'new' => 'B']], id: 2),
            self::entry('insert', Comment::class, '9', [], 'Reviewed, thanks', rootPath: 'comments', id: 1),
        ));

        $this->assertSame('changed Title: A → B and created Comment “Reviewed, thanks”', $phrase->text());
    }

    public function testBulkChangesAreSummarised(): void
    {
        $entries = [];
        foreach (range(1, 6) as $id) {
            $entries[] = self::entry('remove', Post::class, (string) $id, [], 'Post '.$id, id: $id);
        }

        $entries[] = self::entry('update', Tag::class, '1', ['title' => ['old' => 'a', 'new' => 'b']], id: 10);
        foreach (range(1, 6) as $id) {
            $entries[] = self::entry('update', Comment::class, (string) $id, ['body' => ['old' => 'a', 'new' => 'b']], id: 20 + $id);
        }

        $this->assertSame(
            'changed Body on 6 Comment, changed Title: a → b and deleted 6 Post',
            (string) $this->phraser()->phrase($this->item(...$entries)),
        );
        $this->assertSame(
            'a supprimé 6 articles',
            (string) $this->phraser('fr', [], [Post::class => '{count, plural, one {article} other {articles}}'])->phrase($this->item(...\array_slice($entries, 0, 6))),
        );
        $this->assertSame(
            'a mis 6 articles à la corbeille',
            (string) $this->phraser('fr', [Post::class.'.deleted.bulk' => 'a mis {count} {entity} à la corbeille'], [Post::class => '{count, plural, one {article} other {articles}}'])->phrase($this->item(...\array_slice($entries, 0, 6))),
        );
        $this->assertSame('deleted Post “Post 6”, deleted Post “Post 5”, deleted Post “Post 4”, deleted Post “Post 3” and deleted Post “Post 2”', (string) $this->phraser()->phrase($this->item(...\array_slice($entries, 1, 5))), 'Up to 5 objects, each one is told.');
    }

    public function testValuesAreCut(): void
    {
        $label = str_repeat('Lorem ipsum ', 10);
        $phrase = $this->phraser()->phrase($this->item(self::entry('insert', Post::class, '1', [], $label)));

        $this->assertLessThanOrEqual(ValueFormatter::MAX_LENGTH, mb_strlen(mb_substr($phrase->text(), mb_strlen('created Post “'), -1)));
        $this->assertStringEndsWith('…”', $phrase->text());
    }

    public function testHtml(): void
    {
        $phrase = $this->phraser()->phrase($this->item(
            $this->tagEntry('associate', 2, '<b>audit</b>', 2),
            $this->tagEntry('associate', 1, 'symfony', 1),
            self::entry('update', Post::class, '1', ['title' => ['old' => 'A & B', 'new' => 'C']], id: 0),
        ));

        $this->assertSame('changed Title: <strong>A &amp; B</strong> → <strong>C</strong> and added to Tags: <strong>symfony</strong> and <strong>&lt;b&gt;audit&lt;/b&gt;</strong>', $phrase->html());
    }

    public function testHtmlOfATranslatedPhrase(): void
    {
        $phraser = $this->phraser('en', [Post::class.'.created' => 'created the <article> {label}']);

        $this->assertSame('created the &lt;article&gt; <strong>Why audit?</strong>', $phraser->phrase($this->item(self::entry('insert', Post::class, '1', [], 'Why audit?')))->html());
    }

    public function testDescribers(): void
    {
        $describer = new class implements ChangeDescriberInterface {
            public function describe(Change $change, HistoryItem $item): ?Phrase
            {
                return 'B' === $change->new ? Phrase::of(new Fragment('renamed the post to B', [$change], 'pencil')) : null;
            }
        };

        $phraser = $this->phraser('en', [], [], new ServiceLocator([Post::class.'::title' => static fn (): ChangeDescriberInterface => $describer]));

        $this->assertSame('renamed the post to B', (string) $phraser->phrase($this->item(self::entry('update', Post::class, '1', ['title' => ['old' => 'A', 'new' => 'B']]))));
        $this->assertSame('pencil', $phraser->phrase($this->item(self::entry('update', Post::class, '1', ['title' => ['old' => 'A', 'new' => 'B']])))->icon);
        $this->assertSame('changed Title: A → C', (string) $phraser->phrase($this->item(self::entry('update', Post::class, '1', ['title' => ['old' => 'A', 'new' => 'C']]))), 'A null description keeps the default phrase.');
    }

    public function testEntityDescriber(): void
    {
        $describer = new class implements ChangeDescriberInterface {
            public function describe(Change $change, HistoryItem $item): Phrase
            {
                return Phrase::of('did something to '.($change->field ?? 'the post'));
            }
        };

        $phraser = $this->phraser('en', [], [], new ServiceLocator([Post::class => static fn (): ChangeDescriberInterface => $describer]));

        $this->assertSame('did something to the post and did something to title', (string) $phraser->phrase($this->item(
            self::entry('update', Post::class, '1', ['title' => ['old' => 'A', 'new' => 'B']], id: 2),
            self::entry('insert', Post::class, '1', [], 'A', id: 1),
        )));
    }

    public function testAuthor(): void
    {
        $phraser = $this->phraser('fr');

        $this->assertSame('alice', $phraser->author($this->item(self::entry('insert', Post::class, '1'))));
        $this->assertSame('Ligne de commande', $phraser->author($this->item(self::entry('insert', Post::class, '1', blame: ['via' => ['type' => 'console', 'label' => 'app:import']], userId: null))));
        $this->assertSame('Système', $phraser->author($this->item(self::entry('insert', Post::class, '1', blame: ['via' => ['type' => 'messenger', 'label' => 'App\Message\Import']], userId: null))));
        $this->assertSame('Système', $phraser->author($this->item(self::entry('insert', Post::class, '1', blame: [], userId: null))));
        $this->assertSame('Utilisateur anonyme', $phraser->author($this->item(self::entry('insert', Post::class, '1', blame: ['user_firewall' => 'main'], userId: null))));
        $this->assertSame('Utilisateur 42', $phraser->author($this->item(self::entry('insert', Post::class, '1', blame: ['user_firewall' => 'main'], userId: 42))), 'A user whose name was not recorded.');
        $this->assertSame('alice', $phraser->author($this->item(self::entry('insert', Post::class, '1', blame: ['username' => 'alice', 'via' => ['type' => 'console']]))), 'A command run on behalf of a user.');
    }

    public function testAuthorNameResolver(): void
    {
        $resolver = new class implements AuthorNameResolverInterface {
            public function resolve(int|string $userId, HistoryItem $item): ?string
            {
                return 1 === $userId ? 'Alice Martin' : null;
            }
        };

        $phraser = $this->phraser('en', authors: $resolver);

        $this->assertSame('Alice Martin', $phraser->author($this->item(self::entry('insert', Post::class, '1'))));
        $this->assertSame('Deleted user', $phraser->author($this->item(self::entry('insert', Post::class, '1', userId: 2))));
        $this->assertSame('Command line', $phraser->author($this->item(self::entry('insert', Post::class, '1', blame: ['via' => ['type' => 'console']], userId: null))), 'No user, nothing to resolve.');
    }

    /**
     * @param array<string, string> $messages
     * @param array<string, string> $fields
     */
    private function phraser(string $locale = 'en', array $messages = [], array $fields = [], ?ServiceLocator $describers = null, ?AuthorNameResolverInterface $authors = null): HistoryPhraser
    {
        $translator = self::translator($locale, $messages, $fields);

        return new HistoryPhraser($translator, new TranslatedFieldLabelResolver($translator), new ValueFormatter($this->registry(), $translator), $describers, $authors);
    }

    /**
     * @param Entry ...$entries entries, most recent first
     */
    private function item(Entry ...$entries): HistoryItem
    {
        return new HistoryItem('01JTX', array_values($entries));
    }

    private function tagEntry(string $type, int $tagId, string $tagLabel, int $id): Entry
    {
        return self::entry($type, Post::class, '1', [], 'Why audit?', id: $id, target: self::target(Tag::class, $tagId, $tagLabel), field: 'tags');
    }
}
