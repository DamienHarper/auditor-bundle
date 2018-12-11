<?php

namespace DH\DoctrineAuditBundle\User;

interface UserInterface
{
    public function getId(): string;
    public function getUsername(): string;
}
