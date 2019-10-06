<?php

namespace DH\DoctrineAuditBundle\Annotation;

use Doctrine\Common\Annotations\Annotation;

/**
 * @Annotation
 *
 * @Target("CLASS")
 */
final class Security extends Annotation
{
    /**
     * @var array<string>
     */
    public $roles;
}
