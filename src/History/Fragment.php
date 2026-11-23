<?php

declare(strict_types=1);

namespace DH\AuditorBundle\History;

use DH\Auditor\History\Change;
use DH\AuditorBundle\History\Part\ObjectReference;
use DH\AuditorBundle\History\Part\PhrasePart;
use DH\AuditorBundle\History\Part\TextPart;
use DH\AuditorBundle\History\Part\ValuePart;

/**
 * One part of a phrase, such as "changed Status: Draft → Published", and the changes it tells.
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

    /**
     * The values and object references of the fragment.
     *
     * @return list<ObjectReference|ValuePart>
     */
    public function getValues(): array
    {
        return array_values(array_filter($this->parts, static fn (PhrasePart $part): bool => $part instanceof ValuePart || $part instanceof ObjectReference));
    }
}
