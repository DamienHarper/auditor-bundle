<?php

namespace DH\DoctrineAuditBundle\Tests\Fixtures\Core;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity()
 * @ORM\Table(name="vehicle")
 * @ORM\InheritanceType("SINGLE_TABLE")
 * @ORM\DiscriminatorColumn(name="type", type="string")
 * @ORM\DiscriminatorMap({"car" = "Car", "bike" = "Bike", "vehicle" = "Vehicle"})
 */
class Vehicle
{
    /**
     * @ORM\Id()
     * @ORM\GeneratedValue()
     * @ORM\Column(type="integer")
     */
    private $id;

    /**
     * @ORM\Column(type="string", length=50)
     */
    protected $label;

    /**
     * @ORM\Column(type="integer")
     */
    private $wheels;


    public function getWheels(): int
    {
        return $this->wheels;
    }

    public function setWheels(int $wheels): self
    {
        $this->wheels = $wheels;

        return $this;
    }

    public function getId()
    {
        return $this->id;
    }

    /**
     * Get the value of name.
     *
     * @return mixed
     */
    public function getLabel()
    {
        return $this->label;
    }

    /**
     * Set the value of name.
     *
     * @param mixed $label
     * @return DummyEntity
     */
    public function setLabel($label)
    {
        $this->label = $label;
        return $this;
    }
}
