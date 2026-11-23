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
use DH\AuditorBundle\History\Part\ObjectReference;
use DH\AuditorBundle\History\Part\PhrasePart;
use DH\AuditorBundle\History\Part\ValuePart;
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

        $this->assertSame('created Post “Why audit?”', (string) $phrase);
        $this->assertSame('created', $phrase->icon);
    }

    public function testEntityLevelChanges(): void
    {
        $phraser = $this->phraser('fr');

        $this->assertSame("a enregistré l'état initial de Tag « php »", (string) $phraser->phrase($this->item(self::entry('insert', Tag::class, '3', ['title' => ['old' => null, 'new' => 'php']], 'php', snapshot: true))));
        $this->assertSame('a supprimé Comment « spam »', (string) $phraser->phrase($this->item(self::entry('remove', Comment::class, '9', [], 'spam'))));
        $this->assertSame('a supprimé Comment « #9 »', (string) $phraser->phrase($this->item(self::entry('remove', Comment::class, '9'))), 'An object without label is named by its id.');
    }

    public function testAnActionWithoutReadableChange(): void
    {
        $phrase = $this->phraser('fr')->phrase($this->item(self::entry('update', Post::class, '1', [], 'Why audit?')));

        $this->assertSame('a modifié Post « Why audit? »', (string) $phrase, 'Never an empty phrase.');
        $this->assertSame('edited', $phrase->icon);
        $this->assertCount(1, $phrase->fragments);
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
        $this->assertStringEndsWith(', a vidé Deadline (30 nov. 2026) et a modifié Metadata (seo.description et tags)', (string) $phrase);
    }

    public function testLongTextIsEditedWithoutValues(): void
    {
        $long = str_repeat('word ', 20);
        $phrase = $this->phraser()->phrase($this->item(self::entry('update', Post::class, '1', ['title' => ['old' => 'Short', 'new' => $long]])));

        $this->assertSame('edited Title', (string) $phrase, 'A value longer than a phrase allows is not repeated in it.');
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

    public function testCatalogueWithoutIcu(): void
    {
        $translator = self::translator('en', [], [], [
            'dh_auditor_fields' => [Post::class => 'Account', Post::class.'.title' => 'New title'],
            HistoryPhraser::DOMAIN => [Post::class.'.created' => 'created a renewed {entity} {label}', Post::class.'.deleted.bulk' => 'deleted %count% {entity}'],
        ]);
        $phraser = new HistoryPhraser($translator, new TranslatedFieldLabelResolver($translator), new ValueFormatter($this->registry(), $translator));

        $this->assertSame('created a renewed Account Why audit?', (string) $phraser->phrase($this->item(self::entry('insert', Post::class, '1', [], 'Why audit?'))), 'Only the parameters are replaced, not the words that hold their names.');

        $entries = [];
        foreach (range(1, 6) as $id) {
            $entries[] = self::entry('remove', Post::class, (string) $id, [], 'Post '.$id, id: $id);
        }

        $this->assertSame('deleted 6 Account', (string) $phraser->phrase($this->item(...$entries)));
        $this->assertSame('changed New title: a → b', (string) $phraser->phrase($this->item(self::entry('update', Post::class, '1', ['title' => ['old' => 'a', 'new' => 'b']]))));
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

        $this->assertSame('added to Tags: symfony and audit and removed from Tags: php', (string) $phrase);
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

        $this->assertSame('changed Title: A → B and created Comment “Reviewed, thanks”', (string) $phrase);
        $this->assertSame('created', $phrase->icon, 'The icon of the phrase is the one of the main change (the creation), not of the first fragment.');
        $this->assertSame('edited', $phrase->fragments[0]->icon);
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

    public function testBulkSummaryOfCollectionChanges(): void
    {
        $entries = [];
        $id = 0;
        foreach (range(1, 6) as $post) {
            foreach ([1, 2] as $tag) {
                $entries[] = self::entry('associate', Post::class, (string) $post, [], 'Post '.$post, id: ++$id, target: self::target(Tag::class, $tag, 'tag '.$tag), field: 'tags');
            }
        }

        $entries[] = self::entry('update', Post::class, '1', ['title' => ['old' => 'a', 'new' => 'b']], id: ++$id);
        $phrase = $this->phraser()->phrase($this->item(...array_reverse($entries)));

        $this->assertSame('added to Tags on 6 Post and changed Title: a → b', (string) $phrase, 'The summary counts objects, and a later change does not join it.');
        $this->assertCount(12, $phrase->fragments[0]->changes);
    }

    public function testSingularOfATranslatedPhrase(): void
    {
        $phraser = $this->phraser('fr', [Post::class.'.tags.added' => '{count, plural, one {a ajouté le tag {names}} other {a ajouté les tags {names}}}']);

        $this->assertSame('a ajouté le tag symfony', (string) $phraser->phrase($this->item($this->tagEntry('associate', 1, 'symfony', 1))));
    }

    public function testValuesAreCut(): void
    {
        $label = str_repeat('Lorem ipsum ', 10);
        $phrase = $this->phraser()->phrase($this->item(self::entry('insert', Post::class, '1', [], $label)));

        $this->assertLessThanOrEqual(ValueFormatter::MAX_LENGTH, mb_strlen(mb_substr((string) $phrase, mb_strlen('created Post “'), -1)));
        $this->assertStringEndsWith('…”', (string) $phrase);
    }

    public function testParts(): void
    {
        $phrase = $this->phraser()->phrase($this->item(
            $this->tagEntry('associate', 2, '<b>audit</b>', 2),
            $this->tagEntry('associate', 1, 'symfony', 1),
            self::entry('update', Post::class, '1', [
                'title' => ['old' => 'A & B', 'new' => 'C'],
                'author' => ['old' => null, 'new' => ['class' => 'App\Entity\Author', 'id' => 7, 'label' => 'Alice']],
            ], id: 0),
            self::entry('insert', Comment::class, '9', [], 'Nice', id: -1),
        ));

        $this->assertSame([
            'created Comment “', '<Comment#9 Nice>', '”, changed Title: ', '[A & B]', ' → ', '[C]', ', set Author: ',
            '<App\Entity\Author#7 Alice>', ' and added to Tags: ', '<Tag#1 symfony>', ' and ', '<Tag#2 <b>audit</b>>',
        ], $this->describeParts($phrase), 'Text, values and object references, joined by the separators of the phraser.');
    }

    public function testAnAssociationWithoutIdentifierIsAValue(): void
    {
        $this->assertSame(['set Author: ', '[Alice]'], self::describeParts($this->phraser()->phrase($this->item(
            self::entry('update', Post::class, '1', ['author' => ['old' => null, 'new' => ['class' => 'App\Entity\Author', 'id' => null, 'label' => 'Alice']]]),
        ))));
    }

    public function testPartsOfATranslatedPhrase(): void
    {
        $phraser = $this->phraser('en', [Post::class.'.created' => 'created the <article> {label}']);

        $this->assertSame(['created the <article> ', '<Post#1 Why audit?>'], $this->describeParts($phraser->phrase($this->item(self::entry('insert', Post::class, '1', [], 'Why audit?')))));
    }

    public function testLongListParts(): void
    {
        $entries = [];
        foreach (['a', 'b', 'c', 'd', 'e', 'f', 'g'] as $index => $name) {
            array_unshift($entries, $this->tagEntry('associate', $index + 1, $name, $index + 1));
        }

        $this->assertSame(['a ajouté les tags ', '<Tag#1 a>', ', ', '<Tag#2 b>', ', ', '<Tag#3 c>', ' et 4 autres'], $this->describeParts($this->phraser('fr', [
            Post::class.'.tags.added' => '{count, plural, one {a ajouté le tag {names}} other {a ajouté les tags {names}}}',
        ])->phrase($this->item(...$entries))), '{count} is the number of elements, the list is cut.');
    }

    public function testDescribers(): void
    {
        $describer = new class implements ChangeDescriberInterface {
            public function describe(Change $change, HistoryItem $item): ?Phrase
            {
                return 'B' === $change->new ? Phrase::of(Fragment::of(['renamed the post to B'], [$change], 'pencil')) : null;
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

    public function testAFieldDescriberFallsBackToTheEntityDescriber(): void
    {
        $field = new class implements ChangeDescriberInterface {
            public function describe(Change $change, HistoryItem $item): ?Phrase
            {
                return match ($change->new) {
                    'B' => null,
                    'C' => new Phrase([]),
                    default => Phrase::of('renamed it'),
                };
            }
        };
        $entity = new class implements ChangeDescriberInterface {
            public function describe(Change $change, HistoryItem $item): ?Phrase
            {
                return 'C' === $change->new ? null : Phrase::of('touched the post');
            }
        };

        $phraser = $this->phraser('en', [], [], new ServiceLocator([
            Post::class.'::title' => static fn (): ChangeDescriberInterface => $field,
            Post::class => static fn (): ChangeDescriberInterface => $entity,
        ]));

        $this->assertSame('touched the post', (string) $phraser->phrase($this->item(self::entry('update', Post::class, '1', ['title' => ['old' => 'A', 'new' => 'B']]))), 'A null field description goes to the entity describer.');
        $this->assertSame('changed Title: A → C', (string) $phraser->phrase($this->item(self::entry('update', Post::class, '1', ['title' => ['old' => 'A', 'new' => 'C']]))), 'An empty phrase is no description.');
    }

    public function testDescribedChangesAreNeitherMergedNorSummed(): void
    {
        $describer = new class implements ChangeDescriberInterface {
            public function describe(Change $change, HistoryItem $item): ?Phrase
            {
                return 'audit' === $change->newLabel || '6' === $change->objectId ? Phrase::of('described '.($change->newLabel ?? $change->objectId)) : null;
            }
        };

        $phraser = $this->phraser('en', [], [], new ServiceLocator([
            Post::class.'::tags' => static fn (): ChangeDescriberInterface => $describer,
            Comment::class => static fn (): ChangeDescriberInterface => $describer,
        ]));

        $this->assertSame('added to Tags: symfony and php and described audit', (string) $phraser->phrase($this->item(
            $this->tagEntry('associate', 2, 'audit', 3),
            $this->tagEntry('associate', 3, 'php', 2),
            $this->tagEntry('associate', 1, 'symfony', 1),
        )), 'Each element is described, not only the first one of a fragment.');
        $this->assertSame('described audit and added to Tags: symfony', (string) $phraser->phrase($this->item(
            $this->tagEntry('associate', 1, 'symfony', 2),
            $this->tagEntry('associate', 2, 'audit', 1),
        )), 'An element is not merged into the fragment of a described one.');

        $entries = [];
        foreach (range(1, 6) as $id) {
            $entries[] = self::entry('remove', Comment::class, (string) $id, [], 'Comment '.$id, id: $id);
        }

        $this->assertStringStartsWith('described 6, deleted Comment “Comment 5”', (string) $phraser->phrase($this->item(...$entries)), 'A described change is not counted in a summary: 5 objects are left.');
    }

    public function testOnlyCollectionChangesOfTheSameObjectAreMerged(): void
    {
        $this->assertSame('changed Title: A → B and changed Title: B → C', (string) $this->phraser()->phrase($this->item(
            self::entry('update', Post::class, '1', ['title' => ['old' => 'B', 'new' => 'C']], id: 2),
            self::entry('update', Post::class, '1', ['title' => ['old' => 'A', 'new' => 'B']], id: 1),
        )));
        $this->assertSame('added to Tags: symfony and added to Tags: php', (string) $this->phraser()->phrase($this->item(
            self::entry('associate', Post::class, '2', [], 'Other', id: 2, target: self::target(Tag::class, 3, 'php'), field: 'tags'),
            $this->tagEntry('associate', 1, 'symfony', 1),
        )), 'Two posts, two fragments.');
    }

    public function testEachChangeIsToldOnce(): void
    {
        $describer = new class implements ChangeDescriberInterface {
            public function describe(Change $change, HistoryItem $item): ?Phrase
            {
                $body = array_find($item->getChanges(), static fn (Change $other): bool => 'body' === $other->field);

                return null === $body ? null : Phrase::of(Fragment::of(['rewrote the post'], [$change, $body], 'text'));
            }
        };

        $phraser = $this->phraser('en', [], [], new ServiceLocator([Post::class.'::title' => static fn (): ChangeDescriberInterface => $describer]));
        $phrase = $phraser->phrase($this->item(self::entry('update', Post::class, '1', [
            'body' => ['old' => 'a', 'new' => 'b'],
            'title' => ['old' => 'A', 'new' => 'B'],
            'featured' => ['old' => false, 'new' => true],
        ])));

        $this->assertSame('rewrote the post and changed Featured: no → yes', (string) $phrase, 'The body change the describer told is not told again.');
        $this->assertSame('text', $phrase->icon, 'The main change (the body, first in the mapping) is told by the described fragment.');
    }

    public function testAToldChangeIsNotDescribedAgain(): void
    {
        $title = new class implements ChangeDescriberInterface {
            public function describe(Change $change, HistoryItem $item): Phrase
            {
                $body = array_find($item->getChanges(), static fn (Change $other): bool => 'body' === $other->field);

                return Phrase::of(Fragment::of(['rewrote the post'], [$change, $body ?? $change]));
            }
        };
        $body = new class implements ChangeDescriberInterface {
            public function describe(Change $change, HistoryItem $item): Phrase
            {
                return Phrase::of('rewrote the body');
            }
        };

        $phraser = $this->phraser('en', [], [], new ServiceLocator([
            Post::class.'::title' => static fn (): ChangeDescriberInterface => $title,
            Post::class.'::body' => static fn (): ChangeDescriberInterface => $body,
        ]));

        $this->assertSame('rewrote the post', (string) $phraser->phrase($this->item(self::entry('update', Post::class, '1', [
            'body' => ['old' => 'a', 'new' => 'b'],
            'title' => ['old' => 'A', 'new' => 'B'],
        ]))));
    }

    public function testAChangeToldByALaterFragmentIsNotToldTwice(): void
    {
        $title = new class implements ChangeDescriberInterface {
            public function describe(Change $change, HistoryItem $item): Phrase
            {
                return Phrase::of('renamed the post');
            }
        };
        $body = new class implements ChangeDescriberInterface {
            public function describe(Change $change, HistoryItem $item): Phrase
            {
                $title = array_find($item->getChanges(), static fn (Change $other): bool => 'title' === $other->field);

                return Phrase::of(Fragment::of(['rewrote the post'], [$change, $title ?? $change]));
            }
        };

        $phraser = $this->phraser('en', [], [], new ServiceLocator([
            Post::class.'::title' => static fn (): ChangeDescriberInterface => $title,
            Post::class.'::body' => static fn (): ChangeDescriberInterface => $body,
        ]));

        $this->assertSame('rewrote the post and changed Featured: no → yes', (string) $phraser->phrase($this->item(self::entry('update', Post::class, '1', [
            'body' => ['old' => 'a', 'new' => 'b'],
            'featured' => ['old' => false, 'new' => true],
            'title' => ['old' => 'A', 'new' => 'B'],
        ]))), 'The title, mapped before the body, is told by the body fragment that lists it.');
    }

    public function testAChangeLeftByADroppedFragmentIsToldByDefault(): void
    {
        $title = new class implements ChangeDescriberInterface {
            public function describe(Change $change, HistoryItem $item): Phrase
            {
                $featured = array_find($item->getChanges(), static fn (Change $other): bool => 'featured' === $other->field);

                return Phrase::of(Fragment::of(['renamed and featured the post'], [$change, $featured ?? $change]));
            }
        };
        $body = new class implements ChangeDescriberInterface {
            public function describe(Change $change, HistoryItem $item): Phrase
            {
                $changes = array_values(array_filter($item->getChanges(), static fn (Change $other): bool => \in_array($other->field, ['body', 'title', 'status'], true)));

                return Phrase::of(Fragment::of(['rewrote the post'], $changes));
            }
        };

        $phraser = $this->phraser('en', [], [], new ServiceLocator([
            Post::class.'::title' => static fn (): ChangeDescriberInterface => $title,
            Post::class.'::body' => static fn (): ChangeDescriberInterface => $body,
        ]));

        $this->assertSame('rewrote the post and changed Featured: no → yes', (string) $phraser->phrase($this->item(self::entry('update', Post::class, '1', [
            'body' => ['old' => 'a', 'new' => 'b'],
            'featured' => ['old' => false, 'new' => true],
            'status' => ['old' => 'draft', 'new' => 'published'],
            'title' => ['old' => 'A', 'new' => 'B'],
        ]))), 'The fragment listing the most changes wins; the featured change its rival listed is told by default.');
    }

    public function testTheIconOfAMainChangeToldByAnotherFragment(): void
    {
        $describer = new class implements ChangeDescriberInterface {
            public function describe(Change $change, HistoryItem $item): Phrase
            {
                $body = array_find($item->getChanges(), static fn (Change $other): bool => 'body' === $other->field);

                return Phrase::of(Fragment::of(['rewrote the post'], [$change, $body ?? $change], 'rewrite'));
            }
        };

        $phraser = $this->phraser('en', [], [], new ServiceLocator([Post::class.'::featured' => static fn (): ChangeDescriberInterface => $describer]));
        $phrase = $phraser->phrase($this->item(self::entry('update', Post::class, '1', [
            'body' => ['old' => 'a', 'new' => 'b'],
            'featured' => ['old' => false, 'new' => true],
            'title' => ['old' => 'A', 'new' => 'B'],
        ])));

        $this->assertSame('changed Title: A → B and rewrote the post', (string) $phrase);
        $this->assertSame('rewrite', $phrase->icon, 'The main change (body) is told by the fragment of the featured describer.');
    }

    public function testAFragmentOfADescriberTellsItsChange(): void
    {
        $describer = new class implements ChangeDescriberInterface {
            public function describe(Change $change, HistoryItem $item): Phrase
            {
                return Phrase::of('renamed it', 'twice');
            }
        };

        $phraser = $this->phraser('fr', [], [], new ServiceLocator([Post::class.'::title' => static fn (): ChangeDescriberInterface => $describer]));
        $phrase = $phraser->phrase($this->item(self::entry('update', Post::class, '1', [
            'title' => ['old' => 'A', 'new' => 'B'],
            'featured' => ['old' => false, 'new' => true],
        ])));

        $this->assertSame('renamed it, twice et a changé Featured : non → oui', (string) $phrase, 'The fragments of a describer are joined by the separators of the phraser.');
        $this->assertSame('title', $phrase->fragments[0]->changes[0]->field ?? null, 'A fragment without changes tells the described one.');
        $this->assertSame('title', $phrase->fragments[1]->changes[0]->field ?? null);
    }

    public function testADescriberServiceMustBeADescriber(): void
    {
        $phraser = $this->phraser('en', [], [], new ServiceLocator([Post::class => static fn (): \stdClass => new \stdClass()]));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains(\sprintf('The change describer of %s is a stdClass, not a %s.', Post::class, ChangeDescriberInterface::class));

        $phraser->phrase($this->item(self::entry('insert', Post::class, '1')));
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
        $this->assertSame('Utilisateur 42', $phraser->author($this->item(self::entry('insert', Post::class, '1', blame: ['username' => '', 'user_firewall' => 'main'], userId: 42))), 'An empty name is no name.');
        $this->assertSame('alice', $phraser->author($this->item(self::entry('insert', Post::class, '1', blame: ['username' => 'alice', 'via' => ['type' => 'console']]))), 'A command run on behalf of a user.');
        $this->assertSame('bob', $phraser->author($this->item(self::entry('insert', Post::class, '1', blame: ['username' => 'bob', 'via' => ['type' => 'console']], userId: null))), 'A name recorded without user id.');
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

    /**
     * Parts as strings: "[value]" for a value, "<Class#id label>" for an object reference (short class name).
     *
     * @return list<string>
     */
    private function describeParts(Phrase $phrase): array
    {
        return array_map(static fn (PhrasePart $part): string => match (true) {
            $part instanceof ValuePart => '['.$part->value.']',
            $part instanceof ObjectReference => '<'.str_replace(['DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\\'], '', $part->class).'#'.$part->id.' '.$part->label.'>',
            default => (string) $part,
        }, $phrase->getParts());
    }
}
