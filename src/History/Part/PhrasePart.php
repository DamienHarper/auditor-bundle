<?php

declare(strict_types=1);

namespace DH\AuditorBundle\History\Part;

/**
 * A piece of a fragment of a phrase: plain text, a value, or a reference to an object. A template renders each kind (its "kind"
 * property) with its own markup, escaped; the text of the part is the phrase without markup.
 */
interface PhrasePart extends \Stringable
{
    /**
     * "text", "value" or "object".
     */
    public string $kind { get; }
}
