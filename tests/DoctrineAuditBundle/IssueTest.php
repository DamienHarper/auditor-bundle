<?php

namespace DH\DoctrineAuditBundle\Tests;

use DH\DoctrineAuditBundle\AuditConfiguration;
use DH\DoctrineAuditBundle\AuditReader;
use DH\DoctrineAuditBundle\Tests\Fixtures\Issues\CoreCase;
use DH\DoctrineAuditBundle\Tests\Fixtures\Issues\DieselCase;

/**
 * @covers \DH\DoctrineAuditBundle\AuditConfiguration
 * @covers \DH\DoctrineAuditBundle\AuditEntry
 * @covers \DH\DoctrineAuditBundle\AuditReader
 * @covers \DH\DoctrineAuditBundle\EventSubscriber\AuditSubscriber
 * @covers \DH\DoctrineAuditBundle\EventSubscriber\CreateSchemaListener
 * @covers \DH\DoctrineAuditBundle\User\TokenStorageUserProvider
 * @covers \DH\DoctrineAuditBundle\DBAL\AuditLogger
 */
class IssueTest extends BaseTest
{
    /**
     * @var string
     */
    protected $fixturesPath = __DIR__.'/Fixtures';


    /**
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\ORM\ORMException
     * @throws \Doctrine\ORM\Tools\ToolsException
     */
    public function setUp(): void
    {
        $this->getEntityManager();
        $this->getSchemaTool();

        $configuration = $this->getAuditConfiguration();
        $configuration->setEntities([
            DieselCase::class => ['enabled' => true],
            CoreCase::class => ['enabled' => true],
        ]);

        $this->setUpEntitySchema();
        $this->setupEntities();
    }

    public function testIssue40(): void
    {
        $reader = $this->getReader($this->getAuditConfiguration());

        $audits = $reader->getAudits(CoreCase::class);
        $this->assertCount(1, $audits, 'results count ok.');
        $this->assertSame(AuditReader::INSERT, $audits[0]->getType(), 'AuditReader::INSERT operation.');

        $audits = $reader->getAudits(DieselCase::class);
        $this->assertCount(1, $audits, 'results count ok.');
        $this->assertSame(AuditReader::INSERT, $audits[0]->getType(), 'AuditReader::INSERT operation.');

    }

    protected function setupEntities(): void
    {
        $em = $this->getEntityManager();

        $coreCase = new CoreCase();
        $coreCase->type = 'type1';
        $coreCase->status = 'status1';
        $em->persist($coreCase);
        $em->flush();

        $dieselCase = new DieselCase();
        $dieselCase->coreCase = $coreCase;
        $dieselCase->setName('yo');
        $em->persist($dieselCase);
        $em->flush();
    }



    protected function getReader(AuditConfiguration $configuration = null): AuditReader
    {
        return new AuditReader($configuration ?? $this->createAuditConfiguration(), $this->getEntityManager());
    }
}
