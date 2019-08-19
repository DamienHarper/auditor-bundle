<?php

namespace DH\DoctrineAuditBundle\Tests\EventSubscriber;

use DH\DoctrineAuditBundle\Reader\AuditEntry;
use DH\DoctrineAuditBundle\Reader\AuditReader;
use DH\DoctrineAuditBundle\Tests\CoreTest;
use DH\DoctrineAuditBundle\Tests\Fixtures\Core\Author;
use DH\DoctrineAuditBundle\Tests\Fixtures\Core\Bike;
use DH\DoctrineAuditBundle\Tests\Fixtures\Core\Car;
use DH\DoctrineAuditBundle\Tests\Fixtures\Core\DummyEntity;
use DH\DoctrineAuditBundle\Tests\Fixtures\Core\Post;
use DH\DoctrineAuditBundle\Tests\Fixtures\Core\Tag;
use DH\DoctrineAuditBundle\Tests\Fixtures\Core\Vehicle;

/**
 * @covers \DH\DoctrineAuditBundle\AuditConfiguration
 * @covers \DH\DoctrineAuditBundle\AuditManager
 * @covers \DH\DoctrineAuditBundle\DBAL\AuditLogger
 * @covers \DH\DoctrineAuditBundle\EventSubscriber\CreateSchemaListener
 * @covers \DH\DoctrineAuditBundle\EventSubscriber\AuditSubscriber
 * @covers \DH\DoctrineAuditBundle\Helper\AuditHelper
 * @covers \DH\DoctrineAuditBundle\Helper\DoctrineHelper
 * @covers \DH\DoctrineAuditBundle\Helper\UpdateHelper
 * @covers \DH\DoctrineAuditBundle\Reader\AuditEntry
 * @covers \DH\DoctrineAuditBundle\Reader\AuditReader
 * @covers \DH\DoctrineAuditBundle\User\User
 * @covers \DH\DoctrineAuditBundle\User\TokenStorageUserProvider
 */
class AuditSubscriberTest extends CoreTest
{
    public function testInsertWithoutRelation(): void
    {
        $em = $this->getEntityManager();

        $author = new Author();
        $author
            ->setFullname('John Doe')
            ->setEmail('john.doe@gmail.com')
        ;
        $em->persist($author);
        $em->flush();

        $reader = $this->getReader($this->getAuditConfiguration());
        $audits = $reader->getAudits(Author::class);
        $this->assertCount(1, $audits, 'persisting a new entity (no relation set) creates 1 entry in the audit table.');

        /** @var AuditEntry $entry */
        $entry = $audits[0];
        $this->assertSame(1, $entry->getId(), 'audit entry ID is ok.');
        $this->assertSame(AuditReader::INSERT, $entry->getType(), 'audit entry type is ok.');
        $this->assertEquals(1, $entry->getUserId(), 'audit entry blame_id is ok.');
        $this->assertSame('dark.vador', $entry->getUsername(), 'audit entry blame_user is ok.');
        $this->assertSame('1.2.3.4', $entry->getIp(), 'audit entry IP is ok.');
        $this->assertEquals([
            'fullname' => [
                'old' => null,
                'new' => 'John Doe',
            ],
            'email' => [
                'old' => null,
                'new' => 'john.doe@gmail.com',
            ],
        ], $entry->getDiffs(), 'audit entry diffs is ok.');
    }

    /**
     * @depends testInsertWithoutRelation
     */
    public function testUpdateWithoutRelation(): void
    {
        $em = $this->getEntityManager();

        $author = new Author();
        $author
            ->setFullname('John')
            ->setEmail('john.doe@gmail.com')
        ;
        $em->persist($author);
        $em->flush();

        $author->setFullname('John Doe');
        $em->flush();

        $reader = $this->getReader($this->getAuditConfiguration());
        $audits = $reader->getAudits(Author::class);
        $this->assertCount(2, $audits, 'persisting an updated entity (no relation set) creates 2 entries in the audit table.');

        /** @var AuditEntry $entry */
        $entry = $audits[1];
        $this->assertSame(1, $entry->getId(), 'audit entry ID is ok.');
        $this->assertSame(AuditReader::INSERT, $entry->getType(), 'audit entry type is ok.');
        $this->assertEquals(1, $entry->getUserId(), 'audit entry blame_id is ok.');
        $this->assertSame('dark.vador', $entry->getUsername(), 'audit entry blame_user is ok.');
        $this->assertSame('1.2.3.4', $entry->getIp(), 'audit entry IP is ok.');
        $this->assertEquals([
            'fullname' => [
                'old' => null,
                'new' => 'John',
            ],
            'email' => [
                'old' => null,
                'new' => 'john.doe@gmail.com',
            ],
        ], $entry->getDiffs(), 'audit entry diffs is ok.');

        $entry = $audits[0];
        $this->assertSame(2, $entry->getId(), 'audit entry ID is ok.');
        $this->assertSame(AuditReader::UPDATE, $entry->getType(), 'audit entry type is ok.');
        $this->assertEquals(1, $entry->getUserId(), 'audit entry blame_id is ok.');
        $this->assertSame('dark.vador', $entry->getUsername(), 'audit entry blame_user is ok.');
        $this->assertSame('1.2.3.4', $entry->getIp(), 'audit entry IP is ok.');
        $this->assertEquals([
            'fullname' => [
                'old' => 'John',
                'new' => 'John Doe',
            ],
        ], $entry->getDiffs(), 'audit entry diffs is ok.');
    }

    /**
     * @depends testInsertWithoutRelation
     */
    public function testRemoveWithoutRelation(): void
    {
        $em = $this->getEntityManager();

        $author = new Author();
        $author
            ->setFullname('John Doe')
            ->setEmail('john.doe@gmail.com')
        ;
        $em->persist($author);
        $em->flush();

        $reader = $this->getReader($this->getAuditConfiguration());
        $beforeCount = \count($reader->getAudits(Author::class));

        $em->remove($author);
        $em->flush();

        $reader = $this->getReader($this->getAuditConfiguration());
        $audits = $reader->getAudits(Author::class);
        $afterCount = \count($audits);

        $this->assertEquals($beforeCount + 1, $afterCount, 'removing an entity (no relation set) adds 1 entry in the audit table.');

        /** @var AuditEntry $entry */
        $entry = $audits[0];
        $this->assertSame(2, $entry->getId(), 'audit entry ID is ok.');
        $this->assertSame(AuditReader::REMOVE, $entry->getType(), 'audit entry type is ok.');
        $this->assertEquals(1, $entry->getUserId(), 'audit entry blame_id is ok.');
        $this->assertSame('dark.vador', $entry->getUsername(), 'audit entry blame_user is ok.');
        $this->assertSame('1.2.3.4', $entry->getIp(), 'audit entry IP is ok.');
        $this->assertEquals([
            'label' => Author::class.'#1',
            'class' => Author::class,
            'table' => $em->getClassMetadata(Author::class)->getTableName(),
            'id' => 1,
        ], $entry->getDiffs(), 'audit entry diffs is ok.');
    }

    /**
     * @depends testInsertWithoutRelation
     */
    public function testAuditingIntValues(): void
    {
        $em = $this->getEntityManager();
        $reader = $this->getReader($this->getAuditConfiguration());

        $dummy = new DummyEntity();
        $dummy->setLabel('int: null->17');
        $dummy->setIntValue(17);
        $em->persist($dummy);
        $em->flush();

        $audits = $reader->getAudits(DummyEntity::class);
        $this->assertSame(AuditReader::INSERT, $audits[0]->getType(), 'AuditReader::INSERT operation.');
        $this->assertEquals([
            'label' => [
                'old' => null,
                'new' => 'int: null->17',
            ],
            'int_value' => [
                'old' => null,
                'new' => 17,
            ],
        ], $audits[0]->getDiffs(), 'int: null->17');

        $dummy->setLabel('int: 17->null');
        $dummy->setIntValue(null);
        $em->persist($dummy);
        $em->flush();

        $audits = $reader->getAudits(DummyEntity::class);
        $this->assertSame(AuditReader::UPDATE, $audits[0]->getType(), 'AuditReader::UPDATE operation.');
        $this->assertEquals([
            'label' => [
                'old' => 'int: null->17',
                'new' => 'int: 17->null',
            ],
            'int_value' => [
                'old' => 17,
                'new' => null,
            ],
        ], $audits[0]->getDiffs(), 'int: 17->null');

        $dummy->setLabel('int: null->24');
        $dummy->setIntValue(24);
        $em->persist($dummy);
        $em->flush();

        $audits = $reader->getAudits(DummyEntity::class);
        $this->assertSame(AuditReader::UPDATE, $audits[0]->getType(), 'AuditReader::UPDATE operation.');
        $this->assertEquals([
            'label' => [
                'old' => 'int: 17->null',
                'new' => 'int: null->24',
            ],
            'int_value' => [
                'old' => null,
                'new' => 24,
            ],
        ], $audits[0]->getDiffs(), 'int: null->24');

        $dummy->setLabel('int: 24->"24"');
        $dummy->setIntValue('24');
        $em->persist($dummy);
        $em->flush();

        $audits = $reader->getAudits(DummyEntity::class);
        $this->assertSame(AuditReader::UPDATE, $audits[0]->getType(), 'AuditReader::UPDATE operation.');
        $this->assertEquals([
            'label' => [
                'old' => 'int: null->24',
                'new' => 'int: 24->"24"',
            ],
        ], $audits[0]->getDiffs(), 'int: 24->"24"');

        $dummy->setLabel('int: "24"->24');
        $dummy->setIntValue(24);
        $em->persist($dummy);
        $em->flush();

        $audits = $reader->getAudits(DummyEntity::class);
        $this->assertSame(AuditReader::UPDATE, $audits[0]->getType(), 'AuditReader::UPDATE operation.');
        $this->assertEquals([
            'label' => [
                'old' => 'int: 24->"24"',
                'new' => 'int: "24"->24',
            ],
        ], $audits[0]->getDiffs(), 'int: "24"->24');

        $dummy->setLabel('int: 24->24.0');
        $dummy->setIntValue(24.0);
        $em->persist($dummy);
        $em->flush();

        $audits = $reader->getAudits(DummyEntity::class);
        $this->assertSame(AuditReader::UPDATE, $audits[0]->getType(), 'AuditReader::UPDATE operation.');
        $this->assertEquals([
            'label' => [
                'old' => 'int: "24"->24',
                'new' => 'int: 24->24.0',
            ],
        ], $audits[0]->getDiffs(), 'int: 24->24.0');

        $dummy->setLabel('int: 24->null');
        $dummy->setIntValue(null);
        $em->persist($dummy);
        $em->flush();

        $audits = $reader->getAudits(DummyEntity::class);
        $this->assertSame(AuditReader::UPDATE, $audits[0]->getType(), 'AuditReader::UPDATE operation.');
        $this->assertEquals([
            'label' => [
                'old' => 'int: 24->24.0',
                'new' => 'int: 24->null',
            ],
            'int_value' => [
                'old' => 24,
                'new' => null,
            ],
        ], $audits[0]->getDiffs(), 'int: 24->null');
    }

    /**
     * @depends testInsertWithoutRelation
     */
    public function testAuditingDecimalValues(): void
    {
        $em = $this->getEntityManager();
        $reader = $this->getReader($this->getAuditConfiguration());

        $dummy = new DummyEntity();
        $dummy->setLabel('decimal: null->10.2');
        $dummy->setDecimalValue(10.2);
        $em->persist($dummy);
        $em->flush();

        $audits = $reader->getAudits(DummyEntity::class);
        $this->assertSame(AuditReader::INSERT, $audits[0]->getType(), 'AuditReader::INSERT operation.');
        $this->assertEquals([
            'label' => [
                'old' => null,
                'new' => 'decimal: null->10.2',
            ],
            'decimal_value' => [
                'old' => null,
                'new' => '10.2',
            ],
        ], $audits[0]->getDiffs(), 'decimal: null->10.2');

        $dummy->setLabel('decimal: 10.2->"10.2"');
        $dummy->setDecimalValue('10.2');
        $em->persist($dummy);
        $em->flush();

        $audits = $reader->getAudits(DummyEntity::class);
        $this->assertSame(AuditReader::UPDATE, $audits[0]->getType(), 'AuditReader::UPDATE operation.');
        $this->assertEquals([
            'label' => [
                'old' => 'decimal: null->10.2',
                'new' => 'decimal: 10.2->"10.2"',
            ],
        ], $audits[0]->getDiffs(), 'decimal: 10.2->"10.2"');
    }

    /**
     * @depends testInsertWithoutRelation
     */
    public function testAuditingBooleanValues(): void
    {
        $em = $this->getEntityManager();
        $reader = $this->getReader($this->getAuditConfiguration());

        $dummy = new DummyEntity();
        $dummy->setLabel('bool: null');
        $em->persist($dummy);
        $em->flush();

        $audits = $reader->getAudits(DummyEntity::class);
        $this->assertSame(AuditReader::INSERT, $audits[0]->getType(), 'AuditReader::INSERT operation.');
        $this->assertEquals([
            'label' => [
                'old' => null,
                'new' => 'bool: null',
            ],
        ], $audits[0]->getDiffs(), 'bool: null');

        $dummy->setLabel('bool: null->true');
        $dummy->setBoolValue(true);
        $em->persist($dummy);
        $em->flush();

        $audits = $reader->getAudits(DummyEntity::class);
        $this->assertSame(AuditReader::UPDATE, $audits[0]->getType(), 'AuditReader::UPDATE operation.');
        $this->assertEquals([
            'label' => [
                'old' => 'bool: null',
                'new' => 'bool: null->true',
            ],
            'bool_value' => [
                'old' => null,
                'new' => true,
            ],
        ], $audits[0]->getDiffs(), 'bool: null->true');

        $dummy->setLabel('bool: true->null');
        $dummy->setBoolValue(null);
        $em->persist($dummy);
        $em->flush();

        $audits = $reader->getAudits(DummyEntity::class);
        $this->assertSame(AuditReader::UPDATE, $audits[0]->getType(), 'AuditReader::UPDATE operation.');
        $this->assertEquals([
            'label' => [
                'old' => 'bool: null->true',
                'new' => 'bool: true->null',
            ],
            'bool_value' => [
                'old' => true,
                'new' => null,
            ],
        ], $audits[0]->getDiffs(), 'bool: true->null');

        $dummy->setLabel('bool: null->false');
        $dummy->setBoolValue(false);
        $em->persist($dummy);
        $em->flush();

        $audits = $reader->getAudits(DummyEntity::class);
        $this->assertSame(AuditReader::UPDATE, $audits[0]->getType(), 'AuditReader::UPDATE operation.');
        $this->assertEquals([
            'label' => [
                'old' => 'bool: true->null',
                'new' => 'bool: null->false',
            ],
            'bool_value' => [
                'old' => null,
                'new' => false,
            ],
        ], $audits[0]->getDiffs(), 'bool: null->false');

        $dummy->setLabel('bool: false->null');
        $dummy->setBoolValue(null);
        $em->persist($dummy);
        $em->flush();

        $audits = $reader->getAudits(DummyEntity::class);
        $this->assertSame(AuditReader::UPDATE, $audits[0]->getType(), 'AuditReader::UPDATE operation.');
        $this->assertEquals([
            'label' => [
                'old' => 'bool: null->false',
                'new' => 'bool: false->null',
            ],
            'bool_value' => [
                'old' => false,
                'new' => null,
            ],
        ], $audits[0]->getDiffs(), 'bool: false->null');
    }

    /**
     * @depends testInsertWithoutRelation
     */
    public function testAuditingPhpArrayValues(): void
    {
        $em = $this->getEntityManager();
        $reader = $this->getReader($this->getAuditConfiguration());

        $dummy = new DummyEntity();
        $dummy->setLabel('php_array: null->[R1, R2]');
        $dummy->setPhpArray(['R1', 'R2']);
        $em->persist($dummy);
        $em->flush();

        $audits = $reader->getAudits(DummyEntity::class);
        $this->assertSame(AuditReader::INSERT, $audits[0]->getType(), 'AuditReader::INSERT operation.');
        $this->assertEquals([
            'label' => [
                'old' => null,
                'new' => 'php_array: null->[R1, R2]',
            ],
            'php_array' => [
                'old' => null,
                'new' => 'a:2:{i:0;s:2:"R1";i:1;s:2:"R2";}',
            ],
        ], $audits[0]->getDiffs(), 'php_array: null->[R1, R2]');
    }

    /**
     * @depends testInsertWithoutRelation
     */
    public function testAuditingJsonArrayValues(): void
    {
        $em = $this->getEntityManager();
        $reader = $this->getReader($this->getAuditConfiguration());

        $dummy = new DummyEntity();
        $dummy->setLabel('json_array: null->[R1, R2]');
        $dummy->setJsonArray(['R1', 'R2']);
        $em->persist($dummy);
        $em->flush();

        $audits = $reader->getAudits(DummyEntity::class);
        $this->assertSame(AuditReader::INSERT, $audits[0]->getType(), 'AuditReader::INSERT operation.');
        $this->assertEquals([
            'label' => [
                'old' => null,
                'new' => 'json_array: null->[R1, R2]',
            ],
            'json_array' => [
                'old' => null,
                'new' => '["R1","R2"]',
            ],
        ], $audits[0]->getDiffs(), 'json_array: null->[R1, R2]');
    }

    /**
     * @depends testInsertWithoutRelation
     */
    public function testAuditingSimpleArrayValues(): void
    {
        $em = $this->getEntityManager();
        $reader = $this->getReader($this->getAuditConfiguration());

        $dummy = new DummyEntity();
        $dummy->setLabel('simple_array: null->[R1, R2]');
        $dummy->setSimpleArray(['R1', 'R2']);
        $em->persist($dummy);
        $em->flush();

        $audits = $reader->getAudits(DummyEntity::class);
        $this->assertSame(AuditReader::INSERT, $audits[0]->getType(), 'AuditReader::INSERT operation.');
        $this->assertEquals([
            'label' => [
                'old' => null,
                'new' => 'simple_array: null->[R1, R2]',
            ],
            'simple_array' => [
                'old' => null,
                'new' => 'R1,R2',
            ],
        ], $audits[0]->getDiffs(), 'simple_array: null->[R1, R2]');
    }

    /**
     * @depends testInsertWithoutRelation
     */
    public function testOneToManyAssociate(): void
    {
        $em = $this->getEntityManager();
        $reader = $this->getReader($this->getAuditConfiguration());

        $author = new Author();
        $author
            ->setFullname('John Doe')
            ->setEmail('john.doe@gmail.com')
        ;
        $em->persist($author);
        $em->flush();

        $post1 = new Post();
        $post1
            ->setTitle('First post')
            ->setBody('Here is the body')
            ->setCreatedAt(new \DateTime())
        ;
        $em->persist($post1);

        $post2 = new Post();
        $post2
            ->setTitle('Second post')
            ->setBody('Here is another body')
            ->setCreatedAt(new \DateTime())
        ;
        $em->persist($post2);
        $em->flush();

        $author->addPost($post1);
        $author->addPost($post2);
        $em->flush();

        /** @var AuditEntry[] $audits */
        $audits = $reader->getAudits(Author::class, null, 1, 50);

        $i = 0;
        $this->assertCount(3, $audits, 'result count is ok.');
        $this->assertSame(AuditReader::ASSOCIATE, $audits[$i++]->getType(), 'entry'.$i.' is an AuditReader::ASSOCIATE operation.');
        $this->assertSame(AuditReader::ASSOCIATE, $audits[$i++]->getType(), 'entry'.$i.' is an AuditReader::ASSOCIATE operation.');
        $this->assertSame(AuditReader::INSERT, $audits[$i++]->getType(), 'entry'.$i.' is an AuditReader::INSERT operation.');

        $this->assertEquals([
            'source' => [
                'label' => Author::class.'#1',
                'class' => Author::class,
                'table' => $em->getClassMetadata(Author::class)->getTableName(),
                'id' => 1,
            ],
            'target' => [
                'label' => (string) $post2,
                'class' => Post::class,
                'table' => $em->getClassMetadata(Post::class)->getTableName(),
                'id' => 2,
            ],
        ], $audits[0]->getDiffs(), 'relation ok.');

        $this->assertEquals([
            'source' => [
                'label' => Author::class.'#1',
                'class' => Author::class,
                'table' => $em->getClassMetadata(Author::class)->getTableName(),
                'id' => 1,
            ],
            'target' => [
                'label' => (string) $post1,
                'class' => Post::class,
                'table' => $em->getClassMetadata(Post::class)->getTableName(),
                'id' => 1,
            ],
        ], $audits[1]->getDiffs(), 'relation ok.');
    }

    /**
     * @depends testOneToManyAssociate
     */
    public function testOneToManyDissociate(): void
    {
        $em = $this->getEntityManager();
        $reader = $this->getReader($this->getAuditConfiguration());

        $author = new Author();
        $author
            ->setFullname('John Doe')
            ->setEmail('john.doe@gmail.com')
        ;
        $em->persist($author);
        $em->flush();

        $post1 = new Post();
        $post1
            ->setTitle('First post')
            ->setBody('Here is the body')
            ->setCreatedAt(new \DateTime())
        ;
        $em->persist($post1);

        $post2 = new Post();
        $post2
            ->setTitle('Second post')
            ->setBody('Here is another body')
            ->setCreatedAt(new \DateTime())
        ;
        $em->persist($post2);
        $em->flush();

        $author->addPost($post1);
        $author->addPost($post2);
        $em->flush();

        $author->removePost($post1);
        $author->removePost($post2);
        $em->flush();

        /** @var AuditEntry[] $audits */
        $audits = $reader->getAudits(Author::class, null, 1, 50);

        $i = 0;
        $this->assertCount(5, $audits, 'result count is ok.');
        $this->assertSame(AuditReader::DISSOCIATE, $audits[$i++]->getType(), 'entry'.$i.' is an AuditReader::DISSOCIATE operation.');
        $this->assertSame(AuditReader::DISSOCIATE, $audits[$i++]->getType(), 'entry'.$i.' is an AuditReader::DISSOCIATE operation.');
        $this->assertSame(AuditReader::ASSOCIATE, $audits[$i++]->getType(), 'entry'.$i.' is an AuditReader::ASSOCIATE operation.');
        $this->assertSame(AuditReader::ASSOCIATE, $audits[$i++]->getType(), 'entry'.$i.' is an AuditReader::ASSOCIATE operation.');
        $this->assertSame(AuditReader::INSERT, $audits[$i++]->getType(), 'entry'.$i.' is an AuditReader::INSERT operation.');

        $this->assertEquals([
            'source' => [
                'label' => Author::class.'#1',
                'class' => Author::class,
                'table' => $em->getClassMetadata(Author::class)->getTableName(),
                'id' => 1,
            ],
            'target' => [
                'label' => (string) $post2,
                'class' => Post::class,
                'table' => $em->getClassMetadata(Post::class)->getTableName(),
                'id' => 2,
            ],
        ], $audits[0]->getDiffs(), 'relation ok.');

        $this->assertEquals([
            'source' => [
                'label' => Author::class.'#1',
                'class' => Author::class,
                'table' => $em->getClassMetadata(Author::class)->getTableName(),
                'id' => 1,
            ],
            'target' => [
                'label' => (string) $post1,
                'class' => Post::class,
                'table' => $em->getClassMetadata(Post::class)->getTableName(),
                'id' => 1,
            ],
        ], $audits[1]->getDiffs(), 'relation ok.');
    }

    /**
     * @depends testOneToManyAssociate
     */
    public function testManyToManyAssociate(): void
    {
        $em = $this->getEntityManager();
        $reader = $this->getReader($this->getAuditConfiguration());

        $author = new Author();
        $author
            ->setFullname('John Doe')
            ->setEmail('john.doe@gmail.com')
        ;
        $em->persist($author);

        $post = new Post();
        $post
            ->setAuthor($author)
            ->setTitle('First post')
            ->setBody('Here is the body')
            ->setCreatedAt(new \DateTime())
        ;
        $em->persist($post);

        $tag1 = new Tag();
        $tag1->setTitle('techno');
        $em->persist($tag1);

        $tag2 = new Tag();
        $tag2->setTitle('house');
        $em->persist($tag2);

        $tag3 = new Tag();
        $tag3->setTitle('hardcore');
        $em->persist($tag3);

        $em->flush();

        $post->addTag($tag1);
        $post->addTag($tag2);
        $post->addTag($tag3);
        $em->flush();

        /** @var AuditEntry[] $audits */
        $audits = $reader->getAudits(Post::class, null, 1, 50);

        $i = 0;
        $this->assertCount(4, $audits, 'result count is ok.');
        $this->assertSame(AuditReader::ASSOCIATE, $audits[$i++]->getType(), 'entry'.$i.' is an AuditReader::ASSOCIATE operation.');
        $this->assertSame(AuditReader::ASSOCIATE, $audits[$i++]->getType(), 'entry'.$i.' is an AuditReader::ASSOCIATE operation.');
        $this->assertSame(AuditReader::ASSOCIATE, $audits[$i++]->getType(), 'entry'.$i.' is an AuditReader::ASSOCIATE operation.');
        $this->assertSame(AuditReader::INSERT, $audits[$i++]->getType(), 'entry'.$i.' is an AuditReader::INSERT operation.');

        $this->assertEquals([
            'source' => [
                'label' => (string) $post,
                'class' => Post::class,
                'table' => $em->getClassMetadata(Post::class)->getTableName(),
                'id' => 1,
            ],
            'target' => [
                'label' => Tag::class.'#3',
                'class' => Tag::class,
                'table' => $em->getClassMetadata(Tag::class)->getTableName(),
                'id' => 3,
            ],
            'table' => 'post__tag',
        ], $audits[0]->getDiffs(), 'relation ok.');

        $this->assertEquals([
            'source' => [
                'label' => (string) $post,
                'class' => Post::class,
                'table' => $em->getClassMetadata(Post::class)->getTableName(),
                'id' => 1,
            ],
            'target' => [
                'label' => Tag::class.'#2',
                'class' => Tag::class,
                'table' => $em->getClassMetadata(Tag::class)->getTableName(),
                'id' => 2,
            ],
            'table' => 'post__tag',
        ], $audits[1]->getDiffs(), 'relation ok.');

        $this->assertEquals([
            'source' => [
                'label' => (string) $post,
                'class' => Post::class,
                'table' => $em->getClassMetadata(Post::class)->getTableName(),
                'id' => 1,
            ],
            'target' => [
                'label' => Tag::class.'#1',
                'class' => Tag::class,
                'table' => $em->getClassMetadata(Tag::class)->getTableName(),
                'id' => 1,
            ],
            'table' => 'post__tag',
        ], $audits[2]->getDiffs(), 'relation ok.');
    }

    /**
     * @depends testManyToManyAssociate
     */
    public function testManyToManyDissociate(): void
    {
        $em = $this->getEntityManager();
        $reader = $this->getReader($this->getAuditConfiguration());

        $author = new Author();
        $author
            ->setFullname('John Doe')
            ->setEmail('john.doe@gmail.com')
        ;
        $em->persist($author);

        $post = new Post();
        $post
            ->setAuthor($author)
            ->setTitle('First post')
            ->setBody('Here is the body')
            ->setCreatedAt(new \DateTime())
        ;
        $em->persist($post);

        $tag1 = new Tag();
        $tag1->setTitle('techno');
        $em->persist($tag1);

        $tag2 = new Tag();
        $tag2->setTitle('house');
        $em->persist($tag2);

        $tag3 = new Tag();
        $tag3->setTitle('hardcore');
        $em->persist($tag3);

        $em->flush();

        $post->addTag($tag1);
        $post->addTag($tag2);
        $post->addTag($tag3);
        $em->flush();

        $post->removeTag($tag2);
        $em->flush();

        /** @var AuditEntry[] $audits */
        $audits = $reader->getAudits(Post::class, null, 1, 50);

        $i = 0;
        $this->assertCount(5, $audits, 'result count is ok.');
        $this->assertSame(AuditReader::DISSOCIATE, $audits[$i++]->getType(), 'entry'.$i.' is an AuditReader::DISSOCIATE operation.');
        $this->assertSame(AuditReader::ASSOCIATE, $audits[$i++]->getType(), 'entry'.$i.' is an AuditReader::ASSOCIATE operation.');
        $this->assertSame(AuditReader::ASSOCIATE, $audits[$i++]->getType(), 'entry'.$i.' is an AuditReader::ASSOCIATE operation.');
        $this->assertSame(AuditReader::ASSOCIATE, $audits[$i++]->getType(), 'entry'.$i.' is an AuditReader::ASSOCIATE operation.');
        $this->assertSame(AuditReader::INSERT, $audits[$i++]->getType(), 'entry'.$i.' is an AuditReader::INSERT operation.');

        $this->assertEquals([
            'source' => [
                'label' => (string) $post,
                'class' => Post::class,
                'table' => $em->getClassMetadata(Post::class)->getTableName(),
                'id' => 1,
            ],
            'target' => [
                'label' => Tag::class.'#2',
                'class' => Tag::class,
                'table' => $em->getClassMetadata(Tag::class)->getTableName(),
                'id' => 2,
            ],
            'table' => 'post__tag',
        ], $audits[0]->getDiffs(), 'relation ok.');
    }

    public function testSoftRemove(): void
    {
        $em = $this->getEntityManager();
        $em->getFilters()->enable('soft-deleteable');

        $author = new Author();
        $author
            ->setFullname('John Doe')
            ->setEmail('john.doe@gmail.com')
        ;
        $em->persist($author);

        $post = new Post();
        $post
            ->setAuthor($author)
            ->setTitle('First post')
            ->setBody('Here is the body')
            ->setCreatedAt(new \DateTime())
        ;
        $em->persist($post);
        $em->flush();

        $em->remove($post);
        $em->flush();

        $this->assertTrue(true);
    }

    /**
     * @depends testInsertWithoutRelation
     */
    public function testAuditingSubclass(): void
    {
        $em = $this->getEntityManager();

        $vehicle = new Vehicle();
        $vehicle->setLabel('Oh my truck');
        $vehicle->setWheels(6);
        $em->persist($vehicle);
        $em->flush();

        $car = new Car();
        $car->setLabel('La Ferrari');
        $car->setWheels(4);
        $em->persist($car);
        $em->flush();

        $bike = new Bike();
        $bike->setLabel('ZX10R');
        $bike->setWheels(2);
        $em->flush();

        $this->assertCount(0, [], 'no audits.');
    }

    protected function setupEntities(): void
    {
    }
}
