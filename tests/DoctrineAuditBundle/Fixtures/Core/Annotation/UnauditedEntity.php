<?php

namespace DH\DoctrineAuditBundle\Tests\Fixtures\Core\Annotation;

use DH\DoctrineAuditBundle\Annotation as Audit;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity
 * @ORM\Table(name="unaudited_entity")
 *
 * @Audit\Auditable(enabled=false)
 * @Audit\Security(roles={"ROLE1", "ROLE2"})
 */
class UnauditedEntity
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="IDENTITY")
     * @ORM\Column(type="integer")
     */
    private $id;

    /**
     * @var string
     */
    public $auditedField;

    /**
     * @var string
     *
     * @Audit\Ignore
     */
    public $ignoredField;
}
