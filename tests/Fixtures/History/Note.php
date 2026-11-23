<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Tests\Fixtures\History;

use DH\AuditorBundle\History\HistoryElementInterface;

/**
 * An element of the application (not audited) mixed with the actions of a history.
 */
final readonly class Note implements \Stringable, HistoryElementInterface
{
    public function __construct(
        public string $name,
        private string $sortKey,
    ) {}

    public function __toString(): string
    {
        return 'note:'.$this->name;
    }

    public function getSortKey(): string
    {
        return $this->sortKey;
    }
}
