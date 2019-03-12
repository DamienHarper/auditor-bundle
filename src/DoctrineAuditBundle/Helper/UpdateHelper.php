<?php

namespace DH\DoctrineAuditBundle\Helper;

use DH\DoctrineAuditBundle\AuditConfiguration;
use DH\DoctrineAuditBundle\AuditManager;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;

class UpdateHelper
{
    /**
     * @var AuditManager
     */
    private $manager;

    /**
     * @param AuditManager $manager
     */
    public function __construct(AuditManager $manager)
    {
        $this->manager = $manager;
    }

    /**
     * @return \DH\DoctrineAuditBundle\AuditConfiguration
     */
    public function getConfiguration(): AuditConfiguration
    {
        return $this->manager->getConfiguration();
    }

    public function createAuditTable(Schema $schema, Table $table): void
    {
        $entityTablename = $table->getName();
        $regex = sprintf('#^(%s\.)(.*)$#', preg_quote($schema->getName(), '#'));
        if (preg_match($regex, $entityTablename)) {
            // entityTablename already prefixed with schema name
            $auditTablename = preg_replace(
                $regex,
                sprintf(
                    '$1%s$2%s',
                    preg_quote($this->getConfiguration()->getTablePrefix(), '#'),
                    preg_quote($this->getConfiguration()->getTableSuffix(), '#')
                ),
                $entityTablename
            );
        } else {
            $auditTablename = $this->getConfiguration()->getTablePrefix().$table->getName().$this->getConfiguration()->getTableSuffix();
        }

        if (null !== $auditTablename && !$schema->hasTable($auditTablename)) {
            $auditTable = $schema->createTable($auditTablename);

            // Add columns to audit table
            foreach ($this->manager->getHelper()->getAuditTableColumns() as $name => $struct) {
                $auditTable->addColumn($name, $struct['type'], $struct['options']);
            }

            // Add indices to audit table
            foreach ($this->manager->getHelper()->getAuditTableIndices($auditTablename) as $column => $struct) {
                if ('primary' === $struct['type']) {
                    $auditTable->setPrimaryKey([$column]);
                } else {
                    $auditTable->addIndex([$column], $struct['name']);
                }
            }
        }
    }

    public function checkAuditTable(AbstractSchemaManager $schemaManager, Table $table): array
    {
        $columns = $schemaManager->listTableColumns($table->getName());
        $expected = $this->manager->getHelper()->getAuditTableColumns();

        $add = [];
        $update = [];
        $remove = [];
        $processed = [];

        foreach ($columns as $column) {
            if (array_key_exists($column->getName(), $expected)) {
                // column is part of expected columns, check its properties
                if ($column->getType()->getName() !== $expected[$column->getName()]['type']) {
                    // column type is different
                    $update[] = [
                        'column' => $column,
                        'metadata' => $expected[$column->getName()],
                    ];
                } else {
                    foreach ($expected[$column->getName()]['options'] as $key => $value) {
                        $method = 'get'.ucfirst($key);
                        if (method_exists($column, $method)) {
                            if ($value !== $column->{$method}()) {
                                $update[] = [
                                    'column' => $column,
                                    'metadata' => $expected[$column->getName()],
                                ];
                            }
                        }
                    }
                }
            } else {
                // column is not part of expected columns so it has to be removed
                $remove[] = [
                    'column' => $column,
                ];
            }

            $processed[] = $column->getName();
        }

        foreach ($expected as $column => $struct) {
            if (!\in_array($column, $processed, true)) {
                $add[] = [
                    'column' => $column,
                    'metadata' => $struct,
                ];
            }
        }

        $operations = [];

        if (!empty($add)) {
            $operations['add'] = $add;
        }
        if (!empty($update)) {
            $operations['update'] = $update;
        }
        if (!empty($remove)) {
            $operations['remove'] = $remove;
        }

        return $operations;
    }

    public function updateAuditTable(AbstractSchemaManager $schemaManager, Table $table, array $operations, EntityManager $em): void
    {
        $fromSchema = $schemaManager->createSchema();
        $toSchema = clone $fromSchema;

        $table = $toSchema->getTable($table->getName());

        if (isset($operations['add'])) {
            foreach ($operations['add'] as $operation) {
                $table->addColumn($operation['column'], $operation['metadata']['type'], $operation['metadata']['options']);
            }
        }

        if (isset($operations['update'])) {
            foreach ($operations['update'] as $operation) {
                $table->changeColumn($operation['column']->getName(), $operation['metadata']['options']);
            }
        }

        if (isset($operations['remove'])) {
            foreach ($operations['remove'] as $operation) {
                $table->dropColumn($operation['column']->getName());
            }
        }

        $sql = $fromSchema->getMigrateToSql($toSchema, $schemaManager->getDatabasePlatform());
        foreach ($sql as $query) {
            $statement = $em->getConnection()->prepare($query);
            $statement->execute();
        }
    }
}
