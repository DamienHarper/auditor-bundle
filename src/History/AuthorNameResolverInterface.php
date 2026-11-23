<?php

declare(strict_types=1);

namespace DH\AuditorBundle\History;

use DH\Auditor\History\HistoryItem;

/**
 * Gives the current name of the author of an action, instead of the name recorded at the time of the change.
 *
 * Implement it as a service and alias this interface to it:
 *
 *     DH\AuditorBundle\History\AuthorNameResolverInterface: '@App\Audit\AuthorNameResolver'
 *
 * Called once per action: cache the names the implementation looks up.
 */
interface AuthorNameResolverInterface
{
    /**
     * @return null|string the current name of the user, or null when the user no longer exists
     */
    public function resolve(int|string $userId, HistoryItem $item): ?string;
}
