<?php

namespace DH\DoctrineAuditBundle\Tests\Fixtures\Core\Standard;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity
 * @ORM\Table(name="dummy_entity")
 */
class DummyEntity
{
    /**
     * @ORM\Column(type="string", length=50)
     */
    protected $label;

    /**
     * @ORM\Column(type="integer", nullable=true)
     */
    protected $int_value;

    /**
     * @ORM\Column(type="decimal", precision=8, scale=2, nullable=true)
     */
    protected $decimal_value;

    /**
     * @ORM\Column(type="boolean", nullable=true, options={"default": "0"})
     */
    protected $bool_value;

    /**
     * @ORM\Column(type="array")
     */
    protected $php_array;

    /**
     * @ORM\Column(type="json_array", nullable=true)
     */
    protected $json_array;

    /**
     * @ORM\Column(type="simple_array", nullable=true)
     */
    protected $simple_array;
    /**
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="IDENTITY")
     * @ORM\Column(type="integer")
     */
    private $id;

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
     *
     * @return DummyEntity
     */
    public function setLabel($label)
    {
        $this->label = $label;

        return $this;
    }

    public function getPhpArray()
    {
        return $this->php_array;
    }

    public function setPhpArray(array $php_array)
    {
        $this->php_array = $php_array;

        return $this;
    }

    public function getJsonArray()
    {
        return $this->json_array;
    }

    public function setJsonArray($json_array)
    {
        $this->json_array = $json_array;

        return $this;
    }

    public function getSimpleArray()
    {
        return $this->simple_array;
    }

    public function setSimpleArray($simple_array)
    {
        $this->simple_array = $simple_array;

        return $this;
    }

    public function getIntValue()
    {
        return $this->int_value;
    }

    public function setIntValue($int_value)
    {
        $this->int_value = $int_value;

        return $this;
    }

    public function getDecimalValue()
    {
        return $this->decimal_value;
    }

    public function setDecimalValue($decimal_value)
    {
        $this->decimal_value = $decimal_value;

        return $this;
    }

    public function getBoolValue()
    {
        return $this->bool_value;
    }

    public function setBoolValue($bool_value)
    {
        $this->bool_value = $bool_value;

        return $this;
    }
}
