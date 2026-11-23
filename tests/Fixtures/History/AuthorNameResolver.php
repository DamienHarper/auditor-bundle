<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Tests\Fixtures\History;

use DH\Auditor\History\HistoryItem;
use DH\AuditorBundle\History\AuthorNameResolverInterface;

final class AuthorNameResolver implements AuthorNameResolverInterface
{
    public function resolve(int|string $userId, HistoryItem $item): ?string
    {
        return match ((int) $userId) {
            1 => 'alice',
            2 => 'Bob Martin',
            default => null,
        };
    }
}
