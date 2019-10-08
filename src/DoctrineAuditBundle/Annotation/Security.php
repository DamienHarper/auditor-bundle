<?php

namespace DH\DoctrineAuditBundle\Annotation;

use Doctrine\Common\Annotations\Annotation;
use Doctrine\Common\Annotations\Annotation\Required;

/**
 * @Annotation
 * @Target("CLASS")
 * @Attributes({
 *     @Attribute("roles", required=true, type="array<string>"),
 * })
 */
final class Security extends Annotation
{
    /**
     * @Required
     */
    public $roles;
}
