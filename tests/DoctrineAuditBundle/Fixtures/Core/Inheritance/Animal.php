<?php

namespace DH\DoctrineAuditBundle\Tests\Fixtures\Core\Inheritance;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity
 * @ORM\Table(name="animal")
 * @ORM\InheritanceType("JOINED")
 * @ORM\DiscriminatorColumn(name="type", type="string")
 * @ORM\DiscriminatorMap({"cat": "Cat", "dog": "Dog"})
 */
abstract class Animal
{
    /**
     * @ORM\Column(type="string", length=50)
     */
    protected $label;
    /**
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="IDENTITY")
     * @ORM\Column(type="integer")
     */
    private $id;

    final public function getId()
    {
        return $this->id;
    }

    final public function getLabel()
    {
        return $this->label;
    }

    final public function setLabel($label)
    {
        $this->label = $label;

        return $this;
    }
}
