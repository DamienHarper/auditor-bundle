<?php

namespace DH\DoctrineAuditBundle\Helper;

use DH\DoctrineAuditBundle\AuditConfiguration;
use DH\DoctrineAuditBundle\AuditManager;
use DH\DoctrineAuditBundle\Exception\UpdateException;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;

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

    /**
     * Creates an audit table.
     *
     * @param Table $table
     *
     * @throws \Doctrine\DBAL\DBALException
     *
     * @return Schema
     */
    public function createAuditTable(Table $table): Schema
    {
        $entityManager = $this->getConfiguration()->getEntityManager();
        $schemaManager = $entityManager->getConnection()->getSchemaManager();
        $schema = $schemaManager->createSchema();

        $auditTablename = preg_replace(
            sprintf('#^([^\.]+\.)?(%s)$#', preg_quote($table->getName(), '#')),
            sprintf(
                '$1%s$2%s',
                preg_quote($this->getConfiguration()->getTablePrefix(), '#'),
                preg_quote($this->getConfiguration()->getTableSuffix(), '#')
            ),
            $table->getName()
        );

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

            $sql = $schema->toSql($entityManager->getConnection()->getDatabasePlatform());
            foreach ($sql as $query) {
                try {
                    $statement = $entityManager->getConnection()->prepare($query);
                    $statement->execute();
                } catch (\Exception $e) {
                }
            }
        }

        return $schema;
    }

    /**
     * Ensures an audit table's structure is valid.
     *
     * @param Table $table
     *
     * @throws UpdateException
     * @throws \Doctrine\DBAL\Schema\SchemaException
     *
     * @return Schema
     */
    public function updateAuditTable(Table $table): Schema
    {
        $entityManager = $this->getConfiguration()->getEntityManager();
        $schemaManager = $entityManager->getConnection()->getSchemaManager();
        $fromSchema = $schemaManager->createSchema();

        $toSchema = clone $fromSchema;

        $columns = $schemaManager->listTableColumns($table->getName());
        $expectedColumns = $this->manager->getHelper()->getAuditTableColumns();
        $expectedIndices = $this->manager->getHelper()->getAuditTableIndices($table->getName());
        $processed = [];

        // process columns
        foreach ($columns as $column) {
            if (\array_key_exists($column->getName(), $expectedColumns)) {
                // column is part of expected columns
                $table->dropColumn($column->getName());
                $table->addColumn($column->getName(), $expectedColumns[$column->getName()]['type'], $expectedColumns[$column->getName()]['options']);
            } else {
                // column is not part of expected columns so it has to be removed
                $table->dropColumn($column->getName());
            }

            $processed[] = $column->getName();
        }

        foreach ($expectedColumns as $column => $options) {
            if (!\in_array($column, $processed, true)) {
                // expected column in not part of concrete ones so it's a new column, we need to add it
                $table->addColumn($column, $options['type'], $options['options']);
            }
        }

        // process indices
        foreach ($expectedIndices as $column => $options) {
            if ('primary' === $options['type']) {
                $table->dropPrimaryKey();
                $table->setPrimaryKey([$column]);
            } else {
                if ($table->hasIndex($options['name'])) {
                    $table->dropIndex($options['name']);
                }
                $table->addIndex([$column], $options['name']);
            }
        }

        // apply changes
        $sql = $fromSchema->getMigrateToSql($toSchema, $schemaManager->getDatabasePlatform());
        foreach ($sql as $query) {
            try {
                $statement = $entityManager->getConnection()->prepare($query);
                $statement->execute();
            } catch (\Exception $e) {
                throw new UpdateException(sprintf('Failed to update/fix "%s" audit table.', $table->getName()));
            }
        }

        return $toSchema;
    }
}
