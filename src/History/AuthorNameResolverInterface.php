<?php

declare(strict_types=1);

namespace DH\AuditorBundle\History;

use DH\Auditor\History\HistoryItem;

/**
 * Gives the current name of the author of an action, instead of the name recorded at the time of the change.
 *
 * Implement it as a service: the bundle uses it as soon as it exists.
 */
interface AuthorNameResolverInterface
{
    /**
     * @return null|string the current name of the user, or null when the user no longer exists
     */
    public function resolve(int|string $userId, HistoryItem $item): ?string;
}
