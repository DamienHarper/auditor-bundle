<?php

namespace DH\DoctrineAuditBundle\EventSubscriber;

use DH\DoctrineAuditBundle\Helper\UpdateHelper;
use DH\DoctrineAuditBundle\Manager\AuditManager;
use DH\DoctrineAuditBundle\Reader\AuditReader;
use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Mapping\ClassMetadataInfo;
use Doctrine\ORM\Tools\Event\GenerateSchemaTableEventArgs;
use Doctrine\ORM\Tools\ToolEvents;

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

        if (!$this->manager->getConfiguration()->isAudited($metadata->name)) {
            $audited = false;
            if ($metadata->rootEntityName === $metadata->name && ($metadata->isInheritanceTypeJoined() || $metadata->isInheritanceTypeSingleTable())) {
                foreach ($metadata->subClasses as $subClass) {
                    if ($this->manager->getConfiguration()->isAudited($subClass)) {
                        $audited = true;
                    }
                }
            }
            if (!$audited) {
                return;
            }
        }

        if (!\in_array($metadata->inheritanceType, [
            ClassMetadataInfo::INHERITANCE_TYPE_NONE,
            ClassMetadataInfo::INHERITANCE_TYPE_JOINED,
            ClassMetadataInfo::INHERITANCE_TYPE_SINGLE_TABLE,
        ], true)) {
            throw new \Exception(sprintf('Inheritance type "%s" is not yet supported', $metadata->inheritanceType));
        }

        if ($this->reader->getEntityManager() === $this->manager->getConfiguration()->getEntityManager()) {
            // default_entity_manager
            $updater = new UpdateHelper($this->manager, $this->reader);
            $updater->createAuditTable($eventArgs->getClassTable(), $eventArgs->getSchema());
        }
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
