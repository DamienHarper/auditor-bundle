<?php

declare(strict_types=1);

namespace DH\AuditorBundle\History;

use DH\Auditor\History\Change;
use DH\AuditorBundle\History\Part\PhrasePart;
use DH\AuditorBundle\History\Part\TextPart;

/**
 * What a phrase tells about one change, or about a group of changes (merged, summed up or told by a describer), such
 * as "changed Status: Draft → Published"; it is made of parts (text, values, object references).
 */
final readonly class Fragment implements \Stringable
{
    /**
     * @param list<PhrasePart> $parts   text, values and object references, in reading order
     * @param list<Change>     $changes changes told by the fragment
     * @param null|string      $icon    name of the icon of the fragment; the bundle uses created, snapshot, deleted,
     *                                  edited, status, toggle, text, added, removed and json, and a describer may give
     *                                  another name its templates know
     */
    public function __construct(
        public array $parts,
        public array $changes = [],
        public ?string $icon = null,
    ) {}

    public function __toString(): string
    {
        return implode('', array_map(strval(...), $this->parts));
    }

    /**
     * Builds a fragment from strings (text), values and object references:
     *
     *     Fragment::of(['removed the ticket from ', new ObjectReference(Initiative::class, '3', 'Q4 roadmap')], [$change], 'removed')
     *
     * @param list<PhrasePart|string> $parts
     * @param list<Change>            $changes
     */
    public static function of(array $parts, array $changes = [], ?string $icon = null): self
    {
        return new self(array_map(static fn (PhrasePart|string $part): PhrasePart => \is_string($part) ? new TextPart($part) : $part, $parts), $changes, $icon);
    }
}
