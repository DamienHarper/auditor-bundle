<?php

namespace DH\DoctrineAuditBundle\Tests\Fixtures\Issues;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity
 * @ORM\HasLifecycleCallbacks()
 * @ORM\Table(name="case_diesel")
 */
class DieselCase
{
    /**
     * @ORM\Id
     * @ORM\ManyToOne(targetEntity="CoreCase", cascade={"persist"})
     * @ORM\JoinColumn(name="core_case", referencedColumnName="id")
     */
    public $coreCase;

    /**
     * @ORM\Column(type="string", length=50)
     */
    protected $name;

    /**
     * Get the value of name.
     *
     * @return mixed
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * Set the value of name.
     *
     * @param mixed $name
     */
    public function setName($name): void
    {
        $this->name = $name;
    }
}
