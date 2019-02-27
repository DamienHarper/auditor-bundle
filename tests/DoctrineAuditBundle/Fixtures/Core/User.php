<?php

namespace DH\DoctrineAuditBundle\Tests\Fixtures\Core;

use Symfony\Component\Security\Core\User\UserInterface;
use DH\DoctrineAuditBundle\User\User as BaseUser;

class User extends BaseUser implements UserInterface
{
    protected $roles = [];

    public function setRoles(array $roles): self
    {
        $this->roles = $roles;

        return $this;
    }

    public function getRoles(): array
    {
        return $this->roles;
    }

    public function getPassword(): string
    {
        return '';
    }

    public function getSalt(): string
    {
        return '';
    }

    public function eraseCredentials(): void
    {
    }

}