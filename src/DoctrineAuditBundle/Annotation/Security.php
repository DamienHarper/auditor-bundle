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
     * @Annotation\Required()
     *
     * @var array<string>
     */
    public $roles;
}
