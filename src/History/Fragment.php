<?php

declare(strict_types=1);

namespace DH\AuditorBundle\History;

use DH\Auditor\History\Change;

/**
 * One part of a phrase, such as "changed Status: Draft → Published", and the changes it tells.
 */
final readonly class Fragment implements \Stringable
{
    /**
     * The fragment as HTML: the text, escaped, with its values in <strong>.
     */
    public string $html;

    /**
     * @param string       $text    plain text
     * @param list<Change> $changes changes told by the fragment
     * @param null|string  $icon    name of the icon of the fragment: created, snapshot, deleted, edited, status,
     *                              toggle, text, added, removed, json (or any name a describer gives)
     * @param null|string  $html    HTML version of the text, escaped by its author; the escaped text by default
     */
    public function __construct(
        public string $text,
        public array $changes = [],
        public ?string $icon = null,
        ?string $html = null,
    ) {
        $this->html = $html ?? htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public function __toString(): string
    {
        return $this->text;
    }
}
