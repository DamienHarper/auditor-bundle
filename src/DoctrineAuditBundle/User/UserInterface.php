<?php

namespace DH\DoctrineAuditBundle\User;

interface UserInterface
{
    /**
     * @return null|int|string
     */
    public function getId();

    public function getUsername(): ?string;
}
