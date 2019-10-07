<?php

namespace DH\DoctrineAuditBundle\User;

interface UserProviderInterface
{
    public function getUser(): ?UserInterface;
}
