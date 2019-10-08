<?php

namespace DH\DoctrineAuditBundle\Tests\Fixtures\Core\Inheritance;

use DH\DoctrineAuditBundle\Tests\Fixtures\Core\Inheritance\Vehicle;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity
 */
class Bike extends Vehicle
{
}
