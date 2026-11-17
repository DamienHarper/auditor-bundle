<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Tests\Viewer;

use DH\Auditor\Model\Entry;
use DH\AuditorBundle\Viewer\RevisionComparator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[Small]
#[CoversClass(RevisionComparator::class)]
final class RevisionComparatorTest extends TestCase
{
    private const array AUTHOR_1 = ['class' => 'App\Entity\Author', 'id' => 1, 'label' => 'John', 'table' => 'author'];

    private const array AUTHOR_2 = ['class' => 'App\Entity\Author', 'id' => 2, 'label' => 'Jane', 'table' => 'author'];

    #[Test]
    public function itComposesSuccessiveChangesOfAField(): void
    {
        $comparison = new RevisionComparator()->compare([
            $this->update(2, ['title' => ['old' => 'A', 'new' => 'B']]),
            $this->update(3, ['title' => ['old' => 'B', 'new' => 'C'], 'body' => ['old' => 'x', 'new' => 'y']]),
        ]);

        $this->assertSame([
            'body' => ['old' => 'x', 'new' => 'y'],
            'title' => ['old' => 'A', 'new' => 'C'],
        ], $comparison->fields);
        $this->assertSame([], $comparison->inconsistent);
        $this->assertFalse($comparison->removed);
    }

    #[Test]
    public function itDoesNotNeedTheHistoryBeforeTheOlderRevision(): void
    {
        // The insertion and the first updates were purged: the value before still comes from the retained entry.
        $comparison = new RevisionComparator()->compare([
            $this->update(42, ['title' => ['old' => 'A', 'new' => 'C']]),
        ]);

        $this->assertSame(['title' => ['old' => 'A', 'new' => 'C']], $comparison->fields);
    }

    #[Test]
    public function itDropsFieldsThatEndUpWithTheirInitialValue(): void
    {
        $comparison = new RevisionComparator()->compare([
            $this->update(2, ['title' => ['old' => 'A', 'new' => 'B']]),
            $this->update(3, ['title' => ['old' => 'B', 'new' => 'A']]),
        ]);

        $this->assertSame([], $comparison->fields);
    }

    #[Test]
    public function itReportsMissingEntriesBetweenTheRevisions(): void
    {
        $comparison = new RevisionComparator()->compare([
            $this->update(2, ['title' => ['old' => 'A', 'new' => 'B']]),
            $this->update(5, ['title' => ['old' => 'C', 'new' => 'D']]),
        ]);

        $this->assertSame(['title' => ['old' => 'A', 'new' => 'D']], $comparison->fields);
        $this->assertSame(['title'], $comparison->inconsistent);
    }

    #[Test]
    public function itComposesJsonFieldsPathByPath(): void
    {
        $comparison = new RevisionComparator()->compare([
            $this->update(2, ['settings' => ['theme' => ['old' => 'light', 'new' => 'dark'], 'lang' => ['new' => 'fr']]]),
            $this->update(3, ['settings' => ['theme' => ['old' => 'dark', 'new' => 'blue'], 'nested' => ['a' => ['old' => 1]]]]),
        ]);

        $this->assertSame([
            'settings.lang' => ['old' => null, 'new' => 'fr'],
            'settings.nested.a' => ['old' => 1, 'new' => null],
            'settings.theme' => ['old' => 'light', 'new' => 'blue'],
        ], $comparison->fields);
    }

    #[Test]
    public function itReportsChangesThatCannotBeComposed(): void
    {
        // A JSON value set to null is recorded without any old/new pair.
        $comparison = new RevisionComparator()->compare([
            $this->update(2, ['settings' => [], 'title' => ['old' => 'A', 'new' => 'B']]),
        ]);

        $this->assertSame(['title' => ['old' => 'A', 'new' => 'B']], $comparison->fields);
        $this->assertSame(['settings'], $comparison->unreadable);
    }

    #[Test]
    public function itComparesDiffLabelValuesByValue(): void
    {
        $comparison = new RevisionComparator()->compare([
            $this->update(2, [
                'status' => ['old' => ['label' => 'Draft', 'value' => 1], 'new' => ['label' => 'Published', 'value' => 2]],
                'category' => ['old' => ['label' => 'News', 'value' => 7], 'new' => ['label' => 'Sport', 'value' => 8]],
            ]),
            // The label of a same value changed between two writes (e.g. a renamed category): not a change.
            $this->update(3, ['category' => ['old' => ['label' => 'Sports', 'value' => 8], 'new' => ['label' => 'Renamed', 'value' => 7]]]),
        ]);

        $this->assertSame([
            'status' => ['old' => ['label' => 'Draft', 'value' => 1], 'new' => ['label' => 'Published', 'value' => 2]],
        ], $comparison->fields);
        $this->assertSame([], $comparison->inconsistent);
    }

    #[Test]
    public function itComparesToOneAssociationsByIdentifier(): void
    {
        $renamed = ['label' => 'John Doe'] + self::AUTHOR_1;

        $comparison = new RevisionComparator()->compare([
            $this->update(2, ['author' => ['old' => self::AUTHOR_1, 'new' => self::AUTHOR_2]]),
            $this->update(3, ['author' => ['old' => self::AUTHOR_2, 'new' => $renamed]]),
        ]);

        $this->assertSame([], $comparison->fields);
    }

    #[Test]
    public function itComposesToManyAssociations(): void
    {
        $tag = static fn (int $id): array => ['class' => 'App\Entity\Tag', 'field' => 'posts', 'id' => $id, 'label' => 'tag'.$id, 'table' => 'tag'];

        $comparison = new RevisionComparator()->compare([
            $this->link(2, 'associate', 'tags', $tag(1)),
            $this->link(3, 'associate', 'tags', $tag(2)),
            $this->link(4, 'dissociate', 'tags', $tag(1)),
            $this->link(5, 'dissociate', 'tags', $tag(3)),
            $this->link(6, 'associate', 'comments', ['class' => 'App\Entity\Comment', 'code' => 'c1', 'label' => 'c1', 'pkName' => 'code', 'table' => 'comment']),
        ]);

        $this->assertSame([
            'comments' => ['added' => [['class' => 'App\Entity\Comment', 'code' => 'c1', 'label' => 'c1', 'pkName' => 'code', 'table' => 'comment']], 'removed' => []],
            'tags' => ['added' => [$tag(2)], 'removed' => [$tag(3)]],
        ], $comparison->associations);
        $this->assertSame([], $comparison->fields);
    }

    #[Test]
    public function itReportsARemovalWithoutSnapshot(): void
    {
        $comparison = new RevisionComparator()->compare([
            $this->entry(2, 'remove', ['source' => self::AUTHOR_1, 'changes' => []]),
        ]);

        $this->assertTrue($comparison->removed);
        $this->assertFalse($comparison->recreated);
        $this->assertSame([], $comparison->fields);
    }

    #[Test]
    public function itShowsTheSnapshotOfARemoval(): void
    {
        $comparison = new RevisionComparator()->compare([
            $this->update(2, ['title' => ['old' => 'A', 'new' => 'B']]),
            $this->entry(3, 'remove', ['source' => self::AUTHOR_1, 'changes' => ['title' => ['old' => 'B', 'new' => null]]]),
        ]);

        $this->assertTrue($comparison->removed);
        $this->assertSame(['title' => ['old' => 'A', 'new' => null]], $comparison->fields);
    }

    #[Test]
    public function itReportsAnObjectCreatedAgainAfterItsRemoval(): void
    {
        $comparison = new RevisionComparator()->compare([
            $this->entry(2, 'remove', ['source' => self::AUTHOR_1, 'changes' => []]),
            $this->entry(3, 'insert', ['source' => self::AUTHOR_1, 'changes' => ['title' => ['old' => null, 'new' => 'Z']]]),
        ]);

        $this->assertTrue($comparison->removed);
        $this->assertTrue($comparison->recreated);
        $this->assertSame(['title' => ['old' => null, 'new' => 'Z']], $comparison->fields);
        $this->assertSame([], $comparison->inconsistent);
    }

    #[Test]
    public function itReadsLegacyEntries(): void
    {
        $comparison = new RevisionComparator()->compare([
            $this->entry(2, 'update', ['@source' => self::AUTHOR_1, 'title' => ['old' => 'A', 'new' => 'B']], 1),
            $this->entry(3, 'associate', ['source' => self::AUTHOR_1, 'target' => self::AUTHOR_2, 'is_owning_side' => true, 'table' => 'post_author'], 1),
            $this->entry(4, 'remove', ['class' => 'App\Entity\Post', 'id' => 1, 'label' => 'Post#1', 'table' => 'post'], 1),
        ]);

        $this->assertSame(['title' => ['old' => 'A', 'new' => 'B']], $comparison->fields);
        $this->assertSame(['' => ['added' => [self::AUTHOR_2], 'removed' => []]], $comparison->associations);
        $this->assertTrue($comparison->removed);
        $this->assertSame([], $comparison->unreadable);
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function update(int $id, array $changes): Entry
    {
        return $this->entry($id, 'update', ['source' => self::AUTHOR_1, 'changes' => $changes]);
    }

    /**
     * @param array<string, mixed> $target
     */
    private function link(int $id, string $type, string $field, array $target): Entry
    {
        return $this->entry($id, $type, ['source' => ['field' => $field] + self::AUTHOR_1, 'target' => $target, 'is_owning_side' => true]);
    }

    /**
     * @param array<string, mixed> $diffs
     */
    private function entry(int $id, string $type, array $diffs, int $schemaVersion = 2): Entry
    {
        return Entry::fromArray([
            'id' => $id,
            'type' => $type,
            'object_id' => '1',
            'diffs' => json_encode($diffs, JSON_THROW_ON_ERROR),
            'schema_version' => $schemaVersion,
        ]);
    }
}
