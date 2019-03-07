<?php

namespace DH\DoctrineAuditBundle\Tests\Fixtures\Issues;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity
 * @ORM\HasLifecycleCallbacks
 * @ORM\Table(name="case_core")
 */
class CoreCase
{
    /**
     * @ORM\Column(type="integer")
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    private $id;

    /**
     * @ORM\Column(type="string", name="type", length=50)
     */
    public $type;

    /**
     * @ORM\Column(type="string", name="status", length=50)
     */
    public $status;
}
