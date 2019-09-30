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
            foreach ($this->manager->getHelper()->getAuditTableColumns() as $columnName => $struct) {
                $auditTable->addColumn($columnName, $struct['type'], $struct['options']);
            }

            // Add indices to audit table
            foreach ($this->manager->getHelper()->getAuditTableIndices($auditTablename) as $columnName => $struct) {
                if ('primary' === $struct['type']) {
                    $auditTable->setPrimaryKey([$columnName]);
                } else {
                    $auditTable->addIndex([$columnName], $struct['name']);
                }
            }

            $sql = $schema->toSql($entityManager->getConnection()->getDatabasePlatform());
            foreach ($sql as $query) {
                try {
                    $statement = $entityManager->getConnection()->prepare($query);
                    $statement->execute();
                } catch (\Exception $e) {
                    // This should never happen
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
        $toSchema = $schemaManager->createSchema();
        $fromSchema = clone $toSchema;

        $table = $toSchema->getTable($table->getName());

        $columns = $schemaManager->listTableColumns($table->getName());
        $expectedColumns = $this->manager->getHelper()->getAuditTableColumns();
        $expectedIndices = $this->manager->getHelper()->getAuditTableIndices($table->getName());

        // process columns
        $this->processColumns($table, $columns, $expectedColumns);

        // process indices
        $this->processIndices($table, $expectedIndices);

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

    /**
     * @param Table $table
     * @param array $columns
     * @param array $expectedColumns
     */
    private function processColumns(Table $table, array $columns, array $expectedColumns): void
    {
        $processed = [];

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

        foreach ($expectedColumns as $columnName => $options) {
            if (!\in_array($columnName, $processed, true)) {
                // expected column in not part of concrete ones so it's a new column, we need to add it
                $table->addColumn($columnName, $options['type'], $options['options']);
            }
        }
    }

    /**
     * @param Table $table
     * @param array $expectedIndices
     *
     * @throws \Doctrine\DBAL\Schema\SchemaException
     */
    private function processIndices(Table $table, array $expectedIndices): void
    {
        foreach ($expectedIndices as $columnName => $options) {
            if ('primary' === $options['type']) {
                $table->dropPrimaryKey();
                $table->setPrimaryKey([$columnName]);
            } else {
                if ($table->hasIndex($options['name'])) {
                    $table->dropIndex($options['name']);
                }
                $table->addIndex([$columnName], $options['name']);
            }
        }
    }
}
