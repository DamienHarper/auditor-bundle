<?php

namespace DH\DoctrineAuditBundle\Event;

use Symfony\Component\EventDispatcher\Event as ComponentEvent;
use Symfony\Contracts\EventDispatcher\Event as ContractsEvent;

if (class_exists(ComponentEvent::class)) {
    abstract class AuditEvent extends ComponentEvent
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
        final public function getPayload(): array
        {
            return $this->payload;
        }
    }
} else {
    abstract class AuditEvent extends ContractsEvent
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
        final public function getPayload(): array
        {
            return $this->payload;
        }
    }
}
