<?php

namespace DH\DoctrineAuditBundle\Annotation;

use Doctrine\Common\Annotations\Annotation;

/**
 * @Annotation
 *
 * @Target("CLASS")
 */
final class Auditable extends Annotation
{
    /**
     * @var bool
     */
    public $enabled = true;
}
