<?php

declare(strict_types=1);

namespace DH\AuditorBundle\History\Part;

/**
 * Words of a phrase, such as "changed" or "Status: ".
 */
final readonly class TextPart implements PhrasePart
{
    public string $kind;

    public function __construct(public string $text)
    {
        $this->kind = 'text';
    }

    public function __toString(): string
    {
        return $this->text;
    }
}
