<?php

namespace DH\DoctrineAuditBundle\Tests\Fixtures\Core\Inheritance;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity
 * @ORM\Table(name="dog")
 */
class Dog extends Animal
{
}
