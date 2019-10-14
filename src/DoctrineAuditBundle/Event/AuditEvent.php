<?php

namespace DH\DoctrineAuditBundle\Event;

use Symfony\Contracts\EventDispatcher\Event;

abstract class AuditEvent extends Event
{
    /**
     * @var array
     */
    private $payload;

    public function __construct(array $payload)
    {
        $this->payload = $payload;
    }

    /**
     * @return array
     */
    public function getPayload(): array
    {
        return $this->payload;
    }
}