<?php

declare(strict_types=1);

namespace DH\AuditorBundle\History\Part;

/**
 * A value told by a phrase, such as "Published" or "Nov 22, 2026", already formatted.
 */
final readonly class ValuePart implements PhrasePart
{
    public string $kind;

    public function __construct(public string $value)
    {
        $this->kind = 'value';
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
