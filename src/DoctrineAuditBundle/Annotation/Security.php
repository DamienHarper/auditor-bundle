<?php

namespace DH\DoctrineAuditBundle\Annotation;

use Doctrine\Common\Annotations\Annotation;
use Doctrine\Common\Annotations\Annotation\Required;

/**
 * @Annotation
 *
 * @Target("CLASS")
 */
final class Security extends Annotation
{
    /**
     * @Required
     *
     * @var array<string>
     */
    public $roles;
}
