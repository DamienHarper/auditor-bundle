<?php

namespace DH\DoctrineAuditBundle\User;

class User implements UserInterface
{
    /**
     * @var int|null
     */
    protected $id;

    /**
     * @var string|null
     */
    protected $username;

    public function __construct(?int $id = null, ?string $username = null)
    {
        $this->id = $id;
        $this->username = $username;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUsername(): ?string
    {
        return $this->username;
    }
}
