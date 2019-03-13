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
     * Creates an audit table
     *
     * @param Schema $schema
     * @param Table $table
     */
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

    /**
     * Ensures an audit table's structure is valid.
     *
     * @param AbstractSchemaManager $schemaManager
     * @param Table $table
     * @param EntityManager $em
     *
     * @throws UpdateException
     * @throws \Doctrine\DBAL\Schema\SchemaException
     */
    public function updateAuditTable(AbstractSchemaManager $schemaManager, Table $table, EntityManager $em): void
    {
        $fromSchema = $schemaManager->createSchema();
        $toSchema = clone $fromSchema;

        $table = $toSchema->getTable($table->getName());
        $columns = $schemaManager->listTableColumns($table->getName());
        $expected = $this->manager->getHelper()->getAuditTableColumns();
        $processed = [];

        foreach ($columns as $column) {
            if (array_key_exists($column->getName(), $expected)) {
                // column is part of expected columns
                $table->dropColumn($column->getName());
                $table->addColumn($column->getName(), $expected[$column->getName()]['type'], $expected[$column->getName()]['options']);
            } else {
                // column is not part of expected columns so it has to be removed
                $table->dropColumn($column->getName());
            }

            $processed[] = $column->getName();
        }

        foreach ($expected as $column => $options) {
            if (!\in_array($column, $processed, true)) {
                // expected column in not part of concrete ones so it's a new column, we need to add it
                $table->addColumn($column, $options['type'], $options['options']);
            }
        }

        $sql = $fromSchema->getMigrateToSql($toSchema, $schemaManager->getDatabasePlatform());
        foreach ($sql as $query) {
            try {
                $statement = $em->getConnection()->prepare($query);
                $statement->execute();
            } catch (\Exception $e) {
                throw new UpdateException(sprintf('Failed to update/fix "%s" audit table.', $table->getName()));
            }
        }
    }
}
