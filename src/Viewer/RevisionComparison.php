<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Viewer;

/**
 * Result of comparing two revisions of an audited object.
 *
 * @see RevisionComparator
 */
final readonly class RevisionComparison
{
    /**
     * @param array<string, array{old: mixed, new: mixed}>                                                 $fields       field (or JSON path) => values before and after
     * @param array<string, array{added: list<array<string, mixed>>, removed: list<array<string, mixed>>}> $associations collection field => entities linked and unlinked
     * @param list<string>                                                                                 $inconsistent fields whose successive changes do not follow each other (an entry is missing in between)
     * @param list<string>                                                                                 $unreadable   fields changed in a way that cannot be shown as a before/after pair
     * @param bool                                                                                         $removed      whether the object was deleted between the two revisions
     * @param bool                                                                                         $recreated    whether an object with the same identifier was inserted again after its deletion
     */
    public function __construct(
        public array $fields,
        public array $associations,
        public array $inconsistent,
        public array $unreadable,
        public bool $removed,
        public bool $recreated,
    ) {}
}
