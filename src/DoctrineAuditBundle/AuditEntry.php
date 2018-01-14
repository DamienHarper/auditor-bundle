<?php

namespace DH\DoctrineAuditBundle;

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
     * @var int
     */
    protected $object_id;
    /**
     * @var string
     */
    protected $diffs;
    /**
     * @var int
     */
    protected $blame_id;
    /**
     * @var string
     */
    protected $blame_user;
    /**
     * @var string
     */
    protected $ip;
    /**
     * @var string
     */
    protected $created_at;


    public function __set($name, $value)
    {
        $this->$name = $value;
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
     * @return int
     */
    public function getObjectId(): ?int
    {
        return $this->object_id;
    }

    /**
     * Get the value of blame_id.
     *
     * @return int|string
     */
    public function getUserId()
    {
        return $this->blame_id ?? 'Unknown';
    }

    /**
     * Get the value of blame_user.
     *
     * @return string
     */
    public function getUsername(): string
    {
        return $this->blame_user ?? 'Unknown';
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
