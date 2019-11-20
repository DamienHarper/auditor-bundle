<?php

namespace DH\DoctrineAuditBundle\Event;

use DH\DoctrineAuditBundle\Helper\UpdateHelper;
use DH\DoctrineAuditBundle\Manager\AuditManager;
use DH\DoctrineAuditBundle\Reader\AuditReader;
use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Mapping\ClassMetadataInfo;
use Doctrine\ORM\Tools\Event\GenerateSchemaTableEventArgs;
use Doctrine\ORM\Tools\ToolEvents;
use Exception;

class CreateSchemaListener implements EventSubscriber
{
    /**
     * @var AuditManager
     */
    protected $manager;

    /**
     * @var AuditReader
     */
    protected $reader;

    public function __construct(AuditManager $manager, AuditReader $reader)
    {
        $this->manager = $manager;
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
        if ($this->reader->getEntityManager() !== $this->manager->getConfiguration()->getEntityManager()) {
            return;
        }

        // check if entity or its children are audited
        if (!$this->manager->getConfiguration()->isAuditable($metadata->name)) {
            $audited = false;
            if (
                $metadata->rootEntityName === $metadata->name &&
                ClassMetadataInfo::INHERITANCE_TYPE_SINGLE_TABLE === $metadata->inheritanceType
            ) {
                foreach ($metadata->subClasses as $subClass) {
                    if ($this->manager->getConfiguration()->isAuditable($subClass)) {
                        $audited = true;
                    }
                }
            }
            if (!$audited) {
                return;
            }
        }

        $updater = new UpdateHelper($this->manager, $this->reader);
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
