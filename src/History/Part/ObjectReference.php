<?php

declare(strict_types=1);

namespace DH\AuditorBundle\History\Part;

use DH\AuditorBundle\History\ObjectUrlResolverInterface;

/**
 * An object named by a phrase, such as the post that was created or the tag that was added. Templates link it to the
 * URL an ObjectUrlResolverInterface service gives, when the reader may see it.
 *
 * @see ObjectUrlResolverInterface
 */
final readonly class ObjectReference implements PhrasePart
{
    public string $kind;

    /**
     * @param string $class FQCN of the object
     * @param string $id    identifier of the object, as the audit tables store it
     * @param string $label label of the object
     */
    public function __construct(
        public string $class,
        public string $id,
        public string $label,
    ) {
        $this->kind = 'object';
    }

    public function __toString(): string
    {
        return $this->label;
    }
}
