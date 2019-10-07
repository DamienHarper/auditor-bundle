<?php

namespace DH\DoctrineAuditBundle\User;

interface UserInterface
{
    public function getId();

    public function getUsername(): ?string;
}
