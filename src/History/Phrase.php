<?php

declare(strict_types=1);

namespace DH\AuditorBundle\History;

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

    public function __toString(): string
    {
        return $this->text();
    }

    public static function of(Fragment|string ...$fragments): self
    {
        $fragments = array_map(static fn (Fragment|string $fragment): Fragment => $fragment instanceof Fragment ? $fragment : new Fragment($fragment), array_values($fragments));

        return new self($fragments, $fragments[0]->icon ?? null);
    }

    public function isEmpty(): bool
    {
        return [] === $this->fragments;
    }

    public function text(): string
    {
        return $this->join(array_map(static fn (Fragment $fragment): string => $fragment->text, $this->fragments), $this->separator, $this->lastSeparator);
    }

    /**
     * The phrase as HTML: escaped, with the values in <strong>.
     */
    public function html(): string
    {
        $escape = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return $this->join(array_map(static fn (Fragment $fragment): string => $fragment->html, $this->fragments), $escape($this->separator), $escape($this->lastSeparator));
    }

    /**
     * @param list<string> $parts
     */
    private function join(array $parts, string $separator, string $lastSeparator): string
    {
        $last = array_pop($parts);
        if (null === $last) {
            return '';
        }

        return [] === $parts ? $last : implode($separator, $parts).$lastSeparator.$last;
    }
}
