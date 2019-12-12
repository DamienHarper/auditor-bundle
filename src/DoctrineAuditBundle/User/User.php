<?php

namespace DH\DoctrineAuditBundle\User;

class User implements UserInterface
{
    /**
     * @var null|int|string
     */
    protected $id;

    /**
     * @var null|string
     */
    protected $username;

    /**
     * User constructor.
     *
     * @param null|int|string $id
     * @param null|string     $username
     */
    public function __construct($id = null, ?string $username = null)
    {
        $this->id = $id;
        $this->username = $username;
    }

    /**
     * @return null|int|string
     */
    public function getId()
    {
        return $this->id;
    }

    public function getUsername(): ?string
    {
        return $this->username;
    }
}
