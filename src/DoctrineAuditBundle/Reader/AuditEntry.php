<?php

namespace DH\DoctrineAuditBundle\Reader;

class AuditEntry
{
    /**
     * @var int
     */
    protected $id;

    /**
     * @var string
     */
    protected $type;

    /**
     * @var string
     */
    protected $object_id;

    /**
     * @var null|string
     */
    protected $transaction_hash;

    /**
     * @var string
     */
    protected $diffs;

    /**
     * @var null|int|string
     */
    protected $blame_id;

    /**
     * @var string
     */
    protected $blame_user;

    /**
     * @var string
     */
    protected $blame_user_fqdn;

    /**
     * @var string
     */
    protected $blame_user_firewall;

    /**
     * @var string
     */
    protected $ip;

    /**
     * @var string
     */
    protected $created_at;

    public function __set($name, $value): void
    {
        $this->{$name} = $value;
    }

    public function __get($name)
    {
        return $this->{$name};
    }

    public function __isset($name)
    {
        return property_exists($this, $name);
    }

    /**
     * Get the value of id.
     *
     * @return int
     */
    public function getId(): int
    {
        return $this->id;
    }

    /**
     * Get the value of type.
     *
     * @return string
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * Get the value of object_id.
     *
     * @return string
     */
    public function getObjectId(): string
    {
        return $this->object_id;
    }

    /**
     * Get the value of transaction_hash.
     *
     * @return null|string
     */
    public function getTransactionHash(): ?string
    {
        return $this->transaction_hash;
    }

    /**
     * Get the value of blame_id.
     *
     * @return null|int|string
     */
    public function getUserId()
    {
        return $this->blame_id;
    }

    /**
     * Get the value of blame_user.
     *
     * @return null|string
     */
    public function getUsername(): ?string
    {
        return $this->blame_user;
    }

    /**
     * @return null|string
     */
    public function getUserFqdn(): ?string
    {
        return $this->blame_user_fqdn;
    }

    /**
     * @return null|string
     */
    public function getUserFirewall(): ?string
    {
        return $this->blame_user_firewall;
    }

    /**
     * Get the value of ip.
     *
     * @return string
     */
    public function getIp(): ?string
    {
        return $this->ip;
    }

    /**
     * Get the value of created_at.
     *
     * @return string
     */
    public function getCreatedAt(): string
    {
        return $this->created_at;
    }

    /**
     * Get the value of created_at.
     *
     * @return array
     */
    public function getDiffs(): ?array
    {
        return json_decode($this->diffs, true);
    }
}
