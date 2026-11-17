<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Viewer;

use DH\Auditor\Model\Entry;
use DH\AuditorBundle\Tests\Viewer\RevisionComparatorTest;

/**
 * Computes what changed on an audited object between two of its revisions.
 *
 * Only the entries recorded after the older revision, up to and including the newer one, are
 * composed: for each field, the value before is the "old" value of the first change in that
 * range and the value after is the "new" value of the last one. The history is never replayed
 * from the object's creation, so the result stays exact when older entries have been purged
 * (audit:clean, retention policies).
 *
 * @see RevisionComparatorTest
 */
final class RevisionComparator
{
    /**
     * @param list<Entry> $entries entries recorded after the older revision, up to and including the newer one, oldest first
     */
    public function compare(array $entries): RevisionComparison
    {
        /** @var array<string, array{old: mixed, new: mixed}> $fields */
        $fields = [];

        /** @var array<string, true> $inconsistent */
        $inconsistent = [];

        /** @var array<string, true> $unreadable */
        $unreadable = [];

        /** @var array<string, array<string, array{target: array<string, mixed>, balance: int}>> $links */
        $links = [];

        $removed = false;
        $recreated = false;

        foreach ($entries as $entry) {
            if ('associate' === $entry->type || 'dissociate' === $entry->type) {
                $this->collectLink($entry, $links);

                continue;
            }

            if ('remove' === $entry->type) {
                $removed = true;

                // Legacy (schema v1) removals only describe the deleted object, not its fields.
                if ($entry->schemaVersion < 2) {
                    continue;
                }
            } elseif ('insert' === $entry->type && $removed) {
                $recreated = true;
            }

            foreach ($entry->getDiffs() as $field => $changeset) {
                $field = (string) $field;

                // Legacy (schema v1) entries may carry scalar metadata next to the changesets (e.g. "label").
                if (!\is_array($changeset)) {
                    continue;
                }

                $leaves = $this->flatten($field, $changeset);
                if ([] === $leaves) {
                    // A changeset without any old/new pair cannot be composed (e.g. a JSON value set to null).
                    $unreadable[$field] = true;

                    continue;
                }

                foreach ($leaves as $path => $leaf) {
                    if (!\array_key_exists($path, $fields)) {
                        $fields[$path] = $leaf;

                        continue;
                    }

                    // Each change must start from the value the previous one ended with; otherwise
                    // an entry in between is missing and the result cannot be trusted for this field.
                    // An insertion starts from nothing by definition (the object was deleted before).
                    if ('insert' !== $entry->type && $this->identity($fields[$path]['new']) !== $this->identity($leaf['old'])) {
                        $inconsistent[$path] = true;
                    }

                    $fields[$path]['new'] = $leaf['new'];
                }
            }
        }

        $fields = array_filter(
            $fields,
            fn (array $change): bool => $this->identity($change['old']) !== $this->identity($change['new'])
        );
        ksort($fields);

        $associations = [];
        foreach ($links as $field => $targets) {
            $added = [];
            $dissociated = [];
            foreach ($targets as $target) {
                if ($target['balance'] > 0) {
                    $added[] = $target['target'];
                } elseif ($target['balance'] < 0) {
                    $dissociated[] = $target['target'];
                }
            }

            if ([] !== $added || [] !== $dissociated) {
                $associations[$field] = ['added' => $added, 'removed' => $dissociated];
            }
        }

        ksort($associations);
        ksort($inconsistent);
        ksort($unreadable);

        return new RevisionComparison(
            $fields,
            $associations,
            array_keys($inconsistent),
            array_keys($unreadable),
            $removed,
            $recreated,
        );
    }

    /**
     * Flattens a changeset into old/new pairs indexed by path ("field" or "field.key.subkey" for JSON fields).
     *
     * @param array<array-key, mixed> $changeset
     *
     * @return array<string, array{old: mixed, new: mixed}>
     */
    private function flatten(string $path, array $changeset): array
    {
        if ($this->isLeaf($changeset)) {
            return [$path => ['old' => $changeset['old'] ?? null, 'new' => $changeset['new'] ?? null]];
        }

        $leaves = [];
        foreach ($changeset as $key => $value) {
            if (\is_array($value)) {
                $leaves += $this->flatten($path.'.'.$key, $value);
            }
        }

        return $leaves;
    }

    /**
     * @param array<array-key, mixed> $changeset
     */
    private function isLeaf(array $changeset): bool
    {
        return [] !== $changeset && [] === array_diff(array_keys($changeset), ['old', 'new']);
    }

    /**
     * Returns what identifies a value, ignoring display-only data: the label added by a
     * #[DiffLabel] resolver, or the label of an associated entity's summary.
     */
    private function identity(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }

        if (['label', 'value'] === $this->sortedKeys($value)) {
            return $value['value'];
        }

        if (isset($value['class']) && \is_string($value['class'])) {
            return [$value['class'], $this->summaryId($value)];
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $value
     *
     * @return list<array-key>
     */
    private function sortedKeys(array $value): array
    {
        $keys = array_keys($value);
        sort($keys);

        return $keys;
    }

    /**
     * The provider stores an entity summary's identifier under its primary key name, and adds "pkName" when it is not "id".
     *
     * @param array<array-key, mixed> $summary
     */
    private function summaryId(array $summary): mixed
    {
        $pkName = isset($summary['pkName']) && \is_string($summary['pkName']) ? $summary['pkName'] : 'id';

        return $summary[$pkName] ?? null;
    }

    /**
     * @param array<string, array<string, array{target: array<string, mixed>, balance: int}>> $links
     */
    private function collectLink(Entry $entry, array &$links): void
    {
        $diffs = $entry->getDiffs();
        $source = $diffs['source'] ?? null;
        $target = $diffs['target'] ?? null;

        if (!\is_array($source) || !\is_array($target)) {
            return;
        }

        /** @var array<string, mixed> $target */
        $field = isset($source['field']) && \is_string($source['field']) ? $source['field'] : '';
        $key = json_encode($this->identity($target), JSON_THROW_ON_ERROR);

        $links[$field][$key] ??= ['target' => $target, 'balance' => 0];
        $links[$field][$key]['target'] = $target;
        $links[$field][$key]['balance'] += 'associate' === $entry->type ? 1 : -1;
    }
}
