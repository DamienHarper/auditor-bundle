<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Attribute;

use DH\AuditorBundle\History\ChangeDescriberInterface;

/**
 * Registers a ChangeDescriberInterface service for the changes of an entity, or of one of its fields.
 *
 * @see ChangeDescriberInterface
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final readonly class AsChangeDescriber
{
    /**
     * @param class-string $entity FQCN of the audited entity
     * @param null|string  $field  field described; null for every change of the entity no field describer takes
     */
    public function __construct(
        public string $entity,
        public ?string $field = null,
    ) {}
}
