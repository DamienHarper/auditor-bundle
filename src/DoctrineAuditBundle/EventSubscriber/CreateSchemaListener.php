<?php

namespace DH\DoctrineAuditBundle\EventSubscriber;

use DH\DoctrineAuditBundle\AuditConfiguration;
use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Mapping\ClassMetadataInfo;
use Doctrine\ORM\Tools\Event\GenerateSchemaTableEventArgs;
use Doctrine\ORM\Tools\ToolEvents;

class CreateSchemaListener implements EventSubscriber
{
    protected $configuration;

    public function __construct(AuditConfiguration $configuration)
    {
        $this->configuration = $configuration;
    }

    public function postGenerateSchemaTable(GenerateSchemaTableEventArgs $eventArgs)
    {
        $cm = $eventArgs->getClassMetadata();

        if (!$this->configuration->isAudited($cm->name)) {
            $audited = false;
            if ($cm->rootEntityName === $cm->name && ($cm->isInheritanceTypeJoined() || $cm->isInheritanceTypeSingleTable())) {
                foreach ($cm->subClasses as $subClass) {
                    if ($this->configuration->isAudited($subClass)) {
                        $audited = true;
                    }
                }
            }
            if (!$audited) {
                return;
            }
        }

        $schema = $eventArgs->getSchema();
        $entityTable = $eventArgs->getClassTable();

        $entityTablename = $entityTable->getName();
        $regex = sprintf('#^(%s\.)(.*)$#', preg_quote($schema->getName(), '#'));
        if (preg_match($regex, $entityTablename)) {
            // entityTablename already prefixed with schema name
            $auditTablename = preg_replace(
                $regex,
                sprintf(
                    '$1%s$2%s',
                    preg_quote($this->configuration->getTablePrefix(), '#'),
                    preg_quote($this->configuration->getTableSuffix(), '#')
                ),
                $entityTablename
            );
        } else {
            $auditTablename = $this->configuration->getTablePrefix().$entityTable->getName().$this->configuration->getTableSuffix();
        }

        $auditTable = $schema->createTable($auditTablename);
        $auditTable->addColumn('id', 'integer', [
            'autoincrement' => true,
            'unsigned' => true,
        ]);
        $auditTable->addColumn('type', 'string', [
            'notnull' => true,
            'length' => 10,
        ]);
        // TODO: automate the object_id as integer or string based on original entity
        $auditTable->addColumn('object_id', 'integer', [
            'notnull' => true,
            'unsigned' => true,
        ]);
        $auditTable->addColumn('diffs', 'json_array', [
            'default' => null,
            'notnull' => false,
        ]);
        $auditTable->addColumn('blame_id', 'integer', [
            'default' => null,
            'notnull' => false,
            'unsigned' => true,
        ]);
        $auditTable->addColumn('blame_user', 'string', [
            'default' => null,
            'notnull' => false,
            'length' => 100,
        ]);
        $auditTable->addColumn('ip', 'string', [
            'default' => null,
            'notnull' => false,
            'length' => 45,
        ]);
        $auditTable->addColumn('created_at', 'datetime', [
            'notnull' => true,
        ]);
        $auditTable->setPrimaryKey(['id']);
        $auditTable->addIndex(['type'], 'type_'.md5($auditTable->getName()).'_idx');
        $auditTable->addIndex(['object_id'], 'object_id_'.md5($auditTable->getName()).'_idx');
        $auditTable->addIndex(['blame_id'], 'blame_id_'.md5($auditTable->getName()).'_idx');
        $auditTable->addIndex(['created_at'], 'created_at_'.md5($auditTable->getName()).'_idx');

        if (!\in_array($cm->inheritanceType, [
            ClassMetadataInfo::INHERITANCE_TYPE_NONE,
            ClassMetadataInfo::INHERITANCE_TYPE_JOINED,
            ClassMetadataInfo::INHERITANCE_TYPE_SINGLE_TABLE,
        ], true)) {
            throw new \Exception(sprintf('Inheritance type "%s" is not yet supported', $cm->inheritanceType));
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
