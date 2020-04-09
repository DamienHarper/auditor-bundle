<?php

namespace DH\DoctrineAuditBundle\Model;

use DH\DoctrineAuditBundle\Configuration;
use DH\DoctrineAuditBundle\Helper\AuditHelper;

class Transaction
{
    public const INSERT = 'inserted';
    public const UPDATE = 'updated';
    public const REMOVE = 'removed';
    public const ASSOCIATE = 'associated';
    public const DISSOCIATE = 'dissociated';

    /**
     * @var Configuration
     */
    private $configuration;

    /**
     * @var AuditHelper
     */
    private $helper;

    /**
     * @var null|string
     */
    private $transaction_hash;

    /**
     * @var array
     */
    private $inserted = [];     // [$source, $changeset]

    /**
     * @var array
     */
    private $updated = [];      // [$source, $changeset]

    /**
     * @var array
     */
    private $removed = [];      // [$source, $id]

    /**
     * @var array
     */
    private $associated = [];   // [$source, $target, $mapping]

    /**
     * @var array
     */
    private $dissociated = [];  // [$source, $target, $id, $mapping]

    public function __construct(AuditHelper $helper)
    {
        $this->helper = $helper;
        $this->configuration = $helper->getConfiguration();
    }

    /**
     * Returns transaction hash.
     *
     * @return string
     */
    public function getTransactionHash(): string
    {
        if (null === $this->transaction_hash) {
            $this->transaction_hash = sha1(uniqid('tid', true));
        }

        return $this->transaction_hash;
    }

    public function getInserted(): array
    {
        return $this->inserted;
    }

    public function getUpdated(): array
    {
        return $this->updated;
    }

    public function getRemoved(): array
    {
        return $this->removed;
    }

    public function getAssociated(): array
    {
        return $this->associated;
    }

    public function getDissociated(): array
    {
        return $this->dissociated;
    }

    public function trackAuditEvent(string $type, array $data): void
    {
        $this->{$type}[] = $data;
    }
}
