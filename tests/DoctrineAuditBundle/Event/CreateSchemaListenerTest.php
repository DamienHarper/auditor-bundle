<?php

namespace DH\DoctrineAuditBundle\Tests\Event;

use DH\DoctrineAuditBundle\Tests\BaseTest;
use DH\DoctrineAuditBundle\Tests\Fixtures\Core\Inheritance\Animal;
use DH\DoctrineAuditBundle\Tests\Fixtures\Core\Inheritance\Bike;
use DH\DoctrineAuditBundle\Tests\Fixtures\Core\Inheritance\Car;
use DH\DoctrineAuditBundle\Tests\Fixtures\Core\Inheritance\Cat;
use DH\DoctrineAuditBundle\Tests\Fixtures\Core\Inheritance\Dog;
use DH\DoctrineAuditBundle\Tests\Fixtures\Core\Inheritance\Vehicle;
use DH\DoctrineAuditBundle\Tests\Fixtures\Core\Standard\Author;
use DH\DoctrineAuditBundle\Tests\Fixtures\Core\Standard\DummyEntity;

/**
 * @internal
 */
final class CreateSchemaListenerTest extends BaseTest
{
    /**
     * @throws \Doctrine\Common\Persistence\Mapping\MappingException
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\ORM\ORMException
     * @throws \Doctrine\ORM\Tools\ToolsException
     * @throws \ReflectionException
     */
    protected function setUp(): void
    {
        $this->getEntityManager();
        $this->getSchemaTool();

        $configuration = $this->getAuditConfiguration();
        $configuration->setEntities([
            Car::class => ['enabled' => true],
            Bike::class => ['enabled' => true],

            Cat::class => ['enabled' => true],
            Dog::class => ['enabled' => true],

            Author::class => ['enabled' => true],
        ]);

        $classes = [
            Vehicle::class,
            Car::class,
            Bike::class,

            Cat::class,
            Dog::class,
            Animal::class,

            Author::class,
            DummyEntity::class,
        ];

        $metaClasses = [];

        foreach ($classes as $class) {
            $metaClasses[] = $this->getEntityManager()->getMetadataFactory()->getMetadataFor($class);
        }

        $this->getSchemaTool()->createSchema($metaClasses);

        $this->setUpAuditSchema();
    }

    private function getTables()
    {
        $configuration = $this->getAuditConfiguration();
        $em = $configuration->getEntityManager();
        $schemaManager = $em->getConnection()->getSchemaManager();

        $tableNames = [];

        foreach ($schemaManager->listTables() as $table) {
            $tableNames[] = $table->getName();
        }

        return $tableNames;
    }

    public function testCorrectSchemaForJoinedTableInheritance()
    {
        $tableNames = $this->getTables();

        static::assertContains('animal', $tableNames);
        static::assertContains('dog', $tableNames);
        static::assertContains('cat', $tableNames);

        static::assertNotContains('animal_audit', $tableNames);
    }

    public function testCorrectSchemaForSingleTableInheritance()
    {
        $tableNames = $this->getTables();

        static::assertNotContains('bike_audit', $tableNames);
        static::assertNotContains('car_audit', $tableNames);
        static::assertContains('vehicle', $tableNames);
    }

    public function testCorrectSchemaStandard()
    {
        $tableNames = $this->getTables();

        static::assertContains('author', $tableNames);
        static::assertContains('author_audit', $tableNames);

        static::assertContains('dummy_entity', $tableNames);
        static::assertNotContains('dummy_entity_audit', $tableNames);
    }
}
