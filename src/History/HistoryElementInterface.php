<?php

declare(strict_types=1);

namespace DH\AuditorBundle\History;

/**
 * An element of the application (a comment, a note…) shown among the actions of a history by the
 * <twig:DhAuditor:History> component, at the place its sort key gives it.
 *
 * The sort key compares with Entry::getSortKey(): build it with SortKey::of().
 */
interface HistoryElementInterface
{
    public function getSortKey(): string;
}
