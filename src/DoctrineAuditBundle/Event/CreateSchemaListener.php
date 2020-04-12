<?php

namespace DH\DoctrineAuditBundle\Event;

use DH\DoctrineAuditBundle\Reader\Reader;
use DH\DoctrineAuditBundle\Transaction\TransactionManager;
use DH\DoctrineAuditBundle\Updater\UpdateManager;
use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Mapping\ClassMetadataInfo;
use Doctrine\ORM\Tools\Event\GenerateSchemaTableEventArgs;
use Doctrine\ORM\Tools\ToolEvents;
use Exception;

class CreateSchemaListener implements EventSubscriber
{
    /**
     * @var TransactionManager
     */
    protected $transactionManager;

    /**
     * @var Reader
     */
    protected $reader;

    public function __construct(TransactionManager $transactionManager, Reader $reader)
    {
        $this->transactionManager = $transactionManager;
        $this->reader = $reader;
    }

    public function postGenerateSchemaTable(GenerateSchemaTableEventArgs $eventArgs): void
    {
        $metadata = $eventArgs->getClassMetadata();

        // check inheritance type and returns if unsupported
        if (!\in_array($metadata->inheritanceType, [
            ClassMetadataInfo::INHERITANCE_TYPE_NONE,
            ClassMetadataInfo::INHERITANCE_TYPE_JOINED,
            ClassMetadataInfo::INHERITANCE_TYPE_SINGLE_TABLE,
        ], true)) {
            throw new Exception(sprintf('Inheritance type "%s" is not yet supported', $metadata->inheritanceType));
        }

        // check reader and manager entity managers and returns if different
        if ($this->reader->getEntityManager() !== $this->transactionManager->getConfiguration()->getEntityManager()) {
            return;
        }

        // check if entity or its children are audited
        if (!$this->transactionManager->getConfiguration()->isAuditable($metadata->name)) {
            $audited = false;
            if (
                $metadata->rootEntityName === $metadata->name &&
                ClassMetadataInfo::INHERITANCE_TYPE_SINGLE_TABLE === $metadata->inheritanceType
            ) {
                foreach ($metadata->subClasses as $subClass) {
                    if ($this->transactionManager->getConfiguration()->isAuditable($subClass)) {
                        $audited = true;
                    }
                }
            }
            if (!$audited) {
                return;
            }
        }

        $updater = new UpdateManager($this->transactionManager, $this->reader);
        $updater->createAuditTable($eventArgs->getClassTable(), $eventArgs->getSchema());
    }

    /**
     * {@inheritdoc}
     */
    public function getSubscribedEvents()
    {
        return [
            ToolEvents::postGenerateSchemaTable,
        ];
    }
}
