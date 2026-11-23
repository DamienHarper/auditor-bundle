<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Tests\Fixtures\History;

use DH\Auditor\History\HistoryItem;
use DH\AuditorBundle\History\AuthorNameResolverInterface;

final class AuthorNameResolver implements AuthorNameResolverInterface
{
    public function resolve(int|string $userId, HistoryItem $item): ?string
    {
        return match ((string) $userId) {
            '2' => 'Bob Martin',
            '3' => null, // a deleted user
            default => $item->username,
        };
    }
}
