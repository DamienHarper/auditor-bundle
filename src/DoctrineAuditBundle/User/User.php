<?php

namespace DH\DoctrineAuditBundle\User;

class User implements UserInterface
{
    /**
     * @var string|null
     */
    protected $id;

    /**
     * @var string|null
     */
    protected $username;

    public function __construct(?string $id, ?string $username)
    {
        $this->id = $id;
        $this->username = $username;
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getUsername(): ?string
    {
        return this->username;
    }
}
