<?php

namespace DH\DoctrineAuditBundle\Helper;

use DH\DoctrineAuditBundle\AuditConfiguration;
use DH\DoctrineAuditBundle\AuditManager;
use DH\DoctrineAuditBundle\Exception\UpdateException;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
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

    /**
     * Creates an audit table.
     *
     * @param Schema $schema
     * @param Table  $table
     */
    public function createAuditTable(Schema $schema, Table $table): void
    {
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
        }
    }

    /**
     * Ensures an audit table's structure is valid.
     *
     * @param AbstractSchemaManager $schemaManager
     * @param Schema                $schema
     * @param Table                 $table
     * @param EntityManager         $em
     *
     * @throws UpdateException
     * @throws \Doctrine\DBAL\Schema\SchemaException
     *
     * @return Schema
     */
    public function updateAuditTable(AbstractSchemaManager $schemaManager, Schema $schema, Table $table, EntityManager $em): Schema
    {
        $fromSchema = $schema;
        $toSchema = clone $schema;

        $table = $toSchema->getTable($table->getName());
        $columns = $schemaManager->listTableColumns($table->getName());
        $expectedColumns = $this->manager->getHelper()->getAuditTableColumns();
        $expectedIndices = $this->manager->getHelper()->getAuditTableIndices($table->getName());
        $processed = [];

        // process columns
        foreach ($columns as $column) {
            if (array_key_exists($column->getName(), $expectedColumns)) {
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
                $table->dropIndex($options['name']);
                $table->addIndex([$column], $options['name']);
            }
        }

        // apply changes
        $sql = $fromSchema->getMigrateToSql($toSchema, $schemaManager->getDatabasePlatform());
        foreach ($sql as $query) {
            try {
                $statement = $em->getConnection()->prepare($query);
                $statement->execute();
            } catch (\Exception $e) {
                throw new UpdateException(sprintf('Failed to update/fix "%s" audit table.', $table->getName()));
            }
        }

        return $toSchema;
    }
}
