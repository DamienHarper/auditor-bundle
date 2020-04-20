<?php

namespace DH\DoctrineAuditBundle\Tests;

use DH\DoctrineAuditBundle\Reader\Reader;
use DH\DoctrineAuditBundle\Tests\Fixtures\Core\Inheritance\Bike;
use DH\DoctrineAuditBundle\Tests\Fixtures\Core\Inheritance\Car;
use DH\DoctrineAuditBundle\Tests\Fixtures\Core\Inheritance\Cat;
use DH\DoctrineAuditBundle\Tests\Fixtures\Core\Inheritance\Dog;
use DH\DoctrineAuditBundle\Tests\Fixtures\Core\Inheritance\Vehicle;
use DH\DoctrineAuditBundle\Tests\Fixtures\Issue37\Locale;
use DH\DoctrineAuditBundle\Tests\Fixtures\Issue37\User;
use DH\DoctrineAuditBundle\Tests\Fixtures\Issue40\CoreCase;
use DH\DoctrineAuditBundle\Tests\Fixtures\Issue40\DieselCase;
use DH\DoctrineAuditBundle\Tests\Fixtures\IssueX\Comment;
use DH\DoctrineAuditBundle\Tests\Fixtures\IssueX\DataFixtures;
use DH\DoctrineAuditBundle\Tests\Fixtures\IssueX\DependentDataFixture;
use DH\DoctrineAuditBundle\Tests\Fixtures\IssueX\Post;
use Doctrine\Common\DataFixtures\Event\Listener\ORMReferenceListener;
use Doctrine\Common\DataFixtures\ProxyReferenceRepository;
use Doctrine\Common\DataFixtures\ReferenceRepository;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;

/**
 * @internal
 */
final class IssueTest extends BaseTest
{
    /**
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\ORM\ORMException
     * @throws \Doctrine\ORM\Tools\ToolsException
     */
    protected function setUp(): void
    {
        $this->getEntityManager();
        $this->getSchemaTool();

        $configuration = $this->getAuditConfiguration();
        $configuration->setEntities([
            DieselCase::class => ['enabled' => true],
            CoreCase::class => ['enabled' => true],
            Locale::class => ['enabled' => true],
            User::class => ['enabled' => true],
            Vehicle::class => ['enabled' => true],
            Car::class => ['enabled' => true],
            Bike::class => ['enabled' => true],
            Cat::class => ['enabled' => true],
            Dog::class => ['enabled' => true],
            Post::class => ['enabled' => true],
            Comment::class => ['enabled' => true],
        ]);

        $this->setUpEntitySchema();
        $this->setUpAuditSchema();
    }

    public function testAuditingSubclass(): void
    {
        $em = $this->getEntityManager();
        $reader = $this->getReader($this->getAuditConfiguration());

        $car = new Car();
        $car->setLabel('La Ferrari');
        $car->setWheels(4);
        $em->persist($car);
        $em->flush();

        $bike = new Bike();
        $bike->setLabel('ZX10R');
        $bike->setWheels(2);
        $em->persist($bike);
        $em->flush();

        $tryke = new Vehicle();
        $tryke->setLabel('Can-am Spyder');
        $tryke->setWheels(3);
        $em->persist($tryke);
        $em->flush();

        $cat = new Cat();
        $cat->setLabel('cat');
        $em->persist($cat);
        $em->flush();

        $dog = new Dog();
        $dog->setLabel('dog');
        $em->persist($dog);
        $em->flush();

        $audits = $reader->getAudits(Vehicle::class);
        self::assertCount(1, $audits, 'results count ok.');

        $audits = $reader->getAudits(Vehicle::class, null, null, null, null, false);
        self::assertCount(3, $audits, 'results count ok.');

        $audits = $reader->getAudits(Car::class);
        self::assertCount(1, $audits, 'results count ok.');

        $audits = $reader->getAudits(Bike::class);
        self::assertCount(1, $audits, 'results count ok.');

        $audits = $reader->getAudits(Cat::class);
        self::assertCount(1, $audits, 'results count ok.');

        $audits = $reader->getAudits(Dog::class);
        self::assertCount(1, $audits, 'results count ok.');

        $car->setLabel('Taycan');
        $em->persist($car);
        $em->flush();

        $cat->setLabel('cat2');
        $em->persist($cat);
        $em->flush();

        $audits = $reader->getAudits(Vehicle::class);
        self::assertCount(1, $audits, 'results count ok.');

        $audits = $reader->getAudits(Car::class);
        self::assertCount(2, $audits, 'results count ok.');

        $audits = $reader->getAudits(Dog::class);
        self::assertCount(1, $audits, 'results count ok.');

        $audits = $reader->getAudits(Cat::class);
        self::assertCount(2, $audits, 'results count ok.');
    }

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
        self::assertCount(1, $audits, 'results count ok.');
        self::assertSame(Reader::INSERT, $audits[0]->getType(), 'AuditReader::INSERT operation.');

        $audits = $reader->getAudits(DieselCase::class);
        self::assertCount(1, $audits, 'results count ok.');
        self::assertSame(Reader::INSERT, $audits[0]->getType(), 'AuditReader::INSERT operation.');
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
        self::assertCount(2, $audits, 'results count ok.');
        self::assertSame('en_US', $audits[0]->getObjectId(), 'AuditEntry::object_id is a string.');
        self::assertSame('fr_FR', $audits[1]->getObjectId(), 'AuditEntry::object_id is a string.');

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
        self::assertCount(2, $audits, 'results count ok.');
    }

    public function testIssueNext(): void
    {
        $em = $this->getEntityManager();

        $cacheDriver = $em->getMetadataFactory()->getCacheDriver();

        if ($cacheDriver) {
            $cacheDriver->deleteAll();
        }

        $dataFixture = new DataFixtures();
        $dependentDataFixture = new DependentDataFixture();

        $referenceRepository = new ProxyReferenceRepository($em);

        $listener = new ORMReferenceListener($referenceRepository);

        $em->getEventManager()->removeEventListener(
            $listener->getSubscribedEvents(),
            $listener
        );

        $em->getEventManager()->addEventSubscriber($listener);

        $dependentDataFixture->setReferenceRepository($referenceRepository);
        $dependentDataFixture->load($em);
        $em->clear();

        $dataFixture->setReferenceRepository($referenceRepository);
        $dataFixture->load($em);
        $em->clear();

        $post = $referenceRepository->getReference('post_1');

        $this->assertEquals(1, $post->getId());

        $comment1 = $referenceRepository->getReference('comment_1');
        $this->assertEquals(1, $comment1->getId());
        $this->assertEquals('Comment One', $comment1->getBody());

        $comment2 = $referenceRepository->getReference('comment_2');
        $this->assertEquals(2, $comment2->getId());
        $this->assertEquals('Comment Two', $comment2->getBody());
    }
}
