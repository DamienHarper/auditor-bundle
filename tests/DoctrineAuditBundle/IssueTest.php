<?php

namespace DH\DoctrineAuditBundle\Tests;

use DH\DoctrineAuditBundle\Reader\AuditReader;
use DH\DoctrineAuditBundle\Tests\Fixtures\Issue40\CoreCase;
use DH\DoctrineAuditBundle\Tests\Fixtures\Issue40\DieselCase;
use DH\DoctrineAuditBundle\Tests\Fixtures\Issue37\Locale;
use DH\DoctrineAuditBundle\Tests\Fixtures\Issue37\User;

/**
 * @covers \DH\DoctrineAuditBundle\AuditConfiguration
 * @covers \DH\DoctrineAuditBundle\AuditManager
 * @covers \DH\DoctrineAuditBundle\DBAL\AuditLogger
 * @covers \DH\DoctrineAuditBundle\EventSubscriber\AuditSubscriber
 * @covers \DH\DoctrineAuditBundle\EventSubscriber\CreateSchemaListener
 * @covers \DH\DoctrineAuditBundle\Helper\AuditHelper
 * @covers \DH\DoctrineAuditBundle\Helper\DoctrineHelper
 * @covers \DH\DoctrineAuditBundle\Helper\UpdateHelper
 * @covers \DH\DoctrineAuditBundle\Reader\AuditEntry
 * @covers \DH\DoctrineAuditBundle\Reader\AuditReader
 * @covers \DH\DoctrineAuditBundle\User\TokenStorageUserProvider
 */
class IssueTest extends BaseTest
{
    /**
     * @var string
     */
    protected $fixturesPath = [
        __DIR__ . '/Fixtures/Issue37',
        __DIR__ . '/Fixtures/Issue40',
    ];

    public function testIssue40(): void
    {
        $em = $this->getEntityManager();
        $reader = $this->getReader($this->getAuditConfiguration());

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

        $audits = $reader->getAudits(CoreCase::class);
        $this->assertCount(1, $audits, 'results count ok.');
        $this->assertSame(AuditReader::INSERT, $audits[0]->getType(), 'AuditReader::INSERT operation.');

        $audits = $reader->getAudits(DieselCase::class);
        $this->assertCount(1, $audits, 'results count ok.');
        $this->assertSame(AuditReader::INSERT, $audits[0]->getType(), 'AuditReader::INSERT operation.');
    }

    public function testIssue37(): void
    {
        $em = $this->getEntityManager();
        $reader = $this->getReader($this->getAuditConfiguration());

        $localeFR = new Locale();
        $localeFR
            ->setId('fr_FR')
            ->setName('Français')
        ;
        $em->persist($localeFR);
        $em->flush();

        $localeEN = new Locale();
        $localeEN
            ->setId('en_US')
            ->setName('Français')
        ;
        $em->persist($localeEN);
        $em->flush();

        $audits = $reader->getAudits(Locale::class);
        $this->assertCount(2, $audits, 'results count ok.');
        $this->assertSame('en_US', $audits[0]->getObjectId(), 'AuditEntry::object_id is a string.');
        $this->assertSame('fr_FR', $audits[1]->getObjectId(), 'AuditEntry::object_id is a string.');

        $user1 = new User();
        $user1
            ->setUsername('john.doe')
            ->setLocale($localeFR)
        ;
        $em->persist($user1);
        $em->flush();

        $user2 = new User();
        $user2
            ->setUsername('dark.vador')
            ->setLocale($localeEN)
        ;
        $em->persist($user2);
        $em->flush();

        $audits = $reader->getAudits(User::class);
        $this->assertCount(2, $audits, 'results count ok.');
    }

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
            Locale::class => ['enabled' => true],
            User::class => ['enabled' => true],
        ]);

        $this->setUpEntitySchema();
    }
}
