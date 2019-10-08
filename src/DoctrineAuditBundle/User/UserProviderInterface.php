<?php

namespace DH\DoctrineAuditBundle\User;

use Symfony\Component\Security\Core\Security;

interface UserProviderInterface
{
    public function getUser(): ?UserInterface;
    public function getSecurity(): ?Security;
}
