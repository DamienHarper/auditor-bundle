<?php

namespace DH\DoctrineAuditBundle\EventSubscriber;

use DH\DoctrineAuditBundle\AuditManager;
use DH\DoctrineAuditBundle\Helper\UpdateHelper;
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

    public function __construct(AuditManager $manager)
    {
        $this->manager = $manager;
    }

    public function postGenerateSchemaTable(GenerateSchemaTableEventArgs $eventArgs)
    {
        $cm = $eventArgs->getClassMetadata();

        if (!$this->manager->getConfiguration()->isAudited($cm->name)) {
            $audited = false;
            if ($cm->rootEntityName === $cm->name && ($cm->isInheritanceTypeJoined() || $cm->isInheritanceTypeSingleTable())) {
                foreach ($cm->subClasses as $subClass) {
                    if ($this->manager->getConfiguration()->isAudited($subClass)) {
                        $audited = true;
                    }
                }
            }
            if (!$audited) {
                return;
            }
        }

        if (!\in_array($cm->inheritanceType, [
            ClassMetadataInfo::INHERITANCE_TYPE_NONE,
            ClassMetadataInfo::INHERITANCE_TYPE_JOINED,
            ClassMetadataInfo::INHERITANCE_TYPE_SINGLE_TABLE,
        ], true)) {
            throw new \Exception(sprintf('Inheritance type "%s" is not yet supported', $cm->inheritanceType));
        }

        $updater = new UpdateHelper($this->manager);
        $updater->createAuditTable($eventArgs->getSchema(), $eventArgs->getClassTable());
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
