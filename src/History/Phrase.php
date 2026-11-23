<?php

declare(strict_types=1);

namespace DH\AuditorBundle\History;

use DH\AuditorBundle\History\Part\PhrasePart;
use DH\AuditorBundle\History\Part\TextPart;

/**
 * What an action did, told as a list of fragments joined as "a, b and c".
 */
final readonly class Phrase implements \Stringable
{
    /**
     * @param list<Fragment> $fragments
     * @param null|string    $icon          icon of the main change of the action
     * @param string         $separator     between fragments
     * @param string         $lastSeparator before the last fragment
     */
    public function __construct(
        public array $fragments,
        public ?string $icon = null,
        private string $separator = ', ',
        private string $lastSeparator = ' and ',
    ) {}

    /**
     * The phrase without markup.
     */
    public function __toString(): string
    {
        return implode('', array_map(strval(...), $this->getParts()));
    }

    /**
     * Builds a phrase from fragments, or from plain text.
     */
    public static function of(Fragment|string ...$fragments): self
    {
        $fragments = array_map(static fn (Fragment|string $fragment): Fragment => $fragment instanceof Fragment ? $fragment : Fragment::of([$fragment]), array_values($fragments));

        return new self($fragments, $fragments[0]->icon ?? null);
    }

    public function isEmpty(): bool
    {
        return [] === $this->fragments;
    }

    /**
     * The parts of all fragments, joined by the separators: what a template renders.
     *
     * @return list<PhrasePart>
     */
    public function getParts(): array
    {
        $parts = [];
        $last = \count($this->fragments) - 1;
        foreach ($this->fragments as $index => $fragment) {
            if ($index > 0) {
                $parts[] = new TextPart($index === $last ? $this->lastSeparator : $this->separator);
            }

            array_push($parts, ...$fragment->parts);
        }

        return self::mergeText($parts);
    }

    /**
     * Joins adjacent text parts and drops empty ones.
     *
     * @param list<PhrasePart> $parts
     *
     * @return list<PhrasePart>
     */
    public static function mergeText(array $parts): array
    {
        $merged = [];
        foreach ($parts as $part) {
            if ($part instanceof TextPart) {
                if ('' === $part->text) {
                    continue;
                }

                $last = array_key_last($merged);
                if (null !== $last && $merged[$last] instanceof TextPart) {
                    $merged[$last] = new TextPart($merged[$last]->text.$part->text);

                    continue;
                }
            }

            $merged[] = $part;
        }

        return $merged;
    }
}
