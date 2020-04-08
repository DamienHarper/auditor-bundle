<?php

namespace DH\DoctrineAuditBundle\Tests\Event;

use DateTime;
use DH\DoctrineAuditBundle\Model\Entry;
use DH\DoctrineAuditBundle\Reader\Reader;
use DH\DoctrineAuditBundle\Tests\CoreTest;
use DH\DoctrineAuditBundle\Tests\Fixtures\Core\Standard\Author;
use DH\DoctrineAuditBundle\Tests\Fixtures\Core\Standard\DummyEntity;
use DH\DoctrineAuditBundle\Tests\Fixtures\Core\Standard\Post;
use DH\DoctrineAuditBundle\Tests\Fixtures\Core\Standard\Tag;

/**
 * @internal
 */
final class DoctrineSubscriberTest extends CoreTest
{
    public function testCustomStorageEntityManager(): void
    {
        $configuration = $this->createAuditConfiguration([], $this->getSecondaryEntityManager());
        $defaultEM = $this->getEntityManager();

        self::assertNotNull($configuration->getEntityManager(), 'custom storage entity manager is not null');
        self::assertNotSame($defaultEM, $configuration->getEntityManager(), 'custom storage entity manager is not default one');
    }

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
        self::assertCount(1, $audits, 'persisting a new entity (no relation set) creates 1 entry in the audit table.');

        /** @var Entry $entry */
        $entry = $audits[0];
        self::assertSame(1, $entry->getId(), 'audit entry ID is ok.');
        self::assertSame(Reader::INSERT, $entry->getType(), 'audit entry type is ok.');
        self::assertSame('1', $entry->getUserId(), 'audit entry blame_id is ok.');
        self::assertSame('dark.vador', $entry->getUsername(), 'audit entry blame_user is ok.');
        self::assertSame('1.2.3.4', $entry->getIp(), 'audit entry IP is ok.');
        self::assertEquals([
            'email' => [
                'old' => null,
                'new' => 'john.doe@gmail.com',
            ],
            'fullname' => [
                'old' => null,
                'new' => 'John Doe',
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
        self::assertCount(2, $audits, 'persisting an updated entity (no relation set) creates 2 entries in the audit table.');

        /** @var Entry $entry */
        $entry = $audits[1];
        self::assertSame(1, $entry->getId(), 'audit entry ID is ok.');
        self::assertSame(Reader::INSERT, $entry->getType(), 'audit entry type is ok.');
        self::assertSame('1', $entry->getUserId(), 'audit entry blame_id is ok.');
        self::assertSame('dark.vador', $entry->getUsername(), 'audit entry blame_user is ok.');
        self::assertSame('1.2.3.4', $entry->getIp(), 'audit entry IP is ok.');
        self::assertEquals([
            'email' => [
                'old' => null,
                'new' => 'john.doe@gmail.com',
            ],
            'fullname' => [
                'old' => null,
                'new' => 'John',
            ],
        ], $entry->getDiffs(), 'audit entry diffs is ok.');

        $entry = $audits[0];
        self::assertSame(2, $entry->getId(), 'audit entry ID is ok.');
        self::assertSame(Reader::UPDATE, $entry->getType(), 'audit entry type is ok.');
        self::assertSame('1', $entry->getUserId(), 'audit entry blame_id is ok.');
        self::assertSame('dark.vador', $entry->getUsername(), 'audit entry blame_user is ok.');
        self::assertSame('1.2.3.4', $entry->getIp(), 'audit entry IP is ok.');
        self::assertEquals([
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

        self::assertSame($beforeCount + 1, $afterCount, 'removing an entity (no relation set) adds 1 entry in the audit table.');

        /** @var Entry $entry */
        $entry = $audits[0];
        self::assertSame(2, $entry->getId(), 'audit entry ID is ok.');
        self::assertSame(Reader::REMOVE, $entry->getType(), 'audit entry type is ok.');
        self::assertSame('1', $entry->getUserId(), 'audit entry blame_id is ok.');
        self::assertSame('dark.vador', $entry->getUsername(), 'audit entry blame_user is ok.');
        self::assertSame('1.2.3.4', $entry->getIp(), 'audit entry IP is ok.');
        self::assertEquals([
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
        self::assertSame(Reader::INSERT, $audits[0]->getType(), 'AuditReader::INSERT operation.');
        self::assertEquals([
            'int_value' => [
                'old' => null,
                'new' => 17,
            ],
            'label' => [
                'old' => null,
                'new' => 'int: null->17',
            ],
        ], $audits[0]->getDiffs(), 'int: null->17');

        $dummy->setLabel('int: 17->null');
        $dummy->setIntValue(null);
        $em->persist($dummy);
        $em->flush();

        $audits = $reader->getAudits(DummyEntity::class);
        self::assertSame(Reader::UPDATE, $audits[0]->getType(), 'AuditReader::UPDATE operation.');
        self::assertEquals([
            'int_value' => [
                'old' => 17,
                'new' => null,
            ],
            'label' => [
                'old' => 'int: null->17',
                'new' => 'int: 17->null',
            ],
        ], $audits[0]->getDiffs(), 'int: 17->null');

        $dummy->setLabel('int: null->24');
        $dummy->setIntValue(24);
        $em->persist($dummy);
        $em->flush();

        $audits = $reader->getAudits(DummyEntity::class);
        self::assertSame(Reader::UPDATE, $audits[0]->getType(), 'AuditReader::UPDATE operation.');
        self::assertEquals([
            'int_value' => [
                'old' => null,
                'new' => 24,
            ],
            'label' => [
                'old' => 'int: 17->null',
                'new' => 'int: null->24',
            ],
        ], $audits[0]->getDiffs(), 'int: null->24');

        $dummy->setLabel('int: 24->"24"');
        $dummy->setIntValue('24');
        $em->persist($dummy);
        $em->flush();

        $audits = $reader->getAudits(DummyEntity::class);
        self::assertSame(Reader::UPDATE, $audits[0]->getType(), 'AuditReader::UPDATE operation.');
        self::assertEquals([
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
        self::assertSame(Reader::UPDATE, $audits[0]->getType(), 'AuditReader::UPDATE operation.');
        self::assertEquals([
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
        self::assertSame(Reader::UPDATE, $audits[0]->getType(), 'AuditReader::UPDATE operation.');
        self::assertEquals([
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
        self::assertSame(Reader::UPDATE, $audits[0]->getType(), 'AuditReader::UPDATE operation.');
        self::assertEquals([
            'int_value' => [
                'old' => 24,
                'new' => null,
            ],
            'label' => [
                'old' => 'int: 24->24.0',
                'new' => 'int: 24->null',
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
        self::assertSame(Reader::INSERT, $audits[0]->getType(), 'AuditReader::INSERT operation.');
        self::assertEquals([
            'decimal_value' => [
                'old' => null,
                'new' => 10.2,
            ],
            'label' => [
                'old' => null,
                'new' => 'decimal: null->10.2',
            ],
        ], $audits[0]->getDiffs(), 'decimal: null->10.2');

        $dummy->setLabel('decimal: 10.2->"10.2"');
        $dummy->setDecimalValue('10.2');
        $em->persist($dummy);
        $em->flush();

        $audits = $reader->getAudits(DummyEntity::class);
        self::assertSame(Reader::UPDATE, $audits[0]->getType(), 'AuditReader::UPDATE operation.');
        self::assertEquals([
            'decimal_value' => [
                'old' => 10.2,
                'new' => '10.2',
            ],
            'label' => [
                'old' => 'decimal: null->10.2',
                'new' => 'decimal: 10.2->"10.2"',
            ],
        ], $audits[0]->getDiffs(), 'decimal: 10.2->"10.2"');

        $dummy->setLabel('decimal: "10.2"->5.0');
        $dummy->setDecimalValue(5.0);
        $em->persist($dummy);
        $em->flush();

        $audits = $reader->getAudits(DummyEntity::class);
        self::assertSame(Reader::UPDATE, $audits[0]->getType(), 'AuditReader::UPDATE operation.');
        self::assertEquals([
            'decimal_value' => [
                'old' => '10.2',
                'new' => 5.0,
            ],
            'label' => [
                'old' => 'decimal: 10.2->"10.2"',
                'new' => 'decimal: "10.2"->5.0',
            ],
        ], $audits[0]->getDiffs(), 'decimal: "10.2"->5.0');

        $dummy->setLabel('decimal: 5.0->"5.0"');
        $dummy->setDecimalValue('5.0');
        $em->persist($dummy);
        $em->flush();

        $audits = $reader->getAudits(DummyEntity::class);
        self::assertSame(Reader::UPDATE, $audits[0]->getType(), 'AuditReader::UPDATE operation.');
        self::assertEquals([
            'decimal_value' => [
                'old' => 5.0,
                'new' => '5.0',
            ],
            'label' => [
                'old' => 'decimal: "10.2"->5.0',
                'new' => 'decimal: 5.0->"5.0"',
            ],
        ], $audits[0]->getDiffs(), 'decimal: 5.0->"5.0"');
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
        self::assertSame(Reader::INSERT, $audits[0]->getType(), 'AuditReader::INSERT operation.');
        self::assertEquals([
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
        self::assertSame(Reader::UPDATE, $audits[0]->getType(), 'AuditReader::UPDATE operation.');
        self::assertEquals([
            'bool_value' => [
                'old' => null,
                'new' => true,
            ],
            'label' => [
                'old' => 'bool: null',
                'new' => 'bool: null->true',
            ],
        ], $audits[0]->getDiffs(), 'bool: null->true');

        $dummy->setLabel('bool: true->null');
        $dummy->setBoolValue(null);
        $em->persist($dummy);
        $em->flush();

        $audits = $reader->getAudits(DummyEntity::class);
        self::assertSame(Reader::UPDATE, $audits[0]->getType(), 'AuditReader::UPDATE operation.');
        self::assertEquals([
            'bool_value' => [
                'old' => true,
                'new' => null,
            ],
            'label' => [
                'old' => 'bool: null->true',
                'new' => 'bool: true->null',
            ],
        ], $audits[0]->getDiffs(), 'bool: true->null');

        $dummy->setLabel('bool: null->false');
        $dummy->setBoolValue(false);
        $em->persist($dummy);
        $em->flush();

        $audits = $reader->getAudits(DummyEntity::class);
        self::assertSame(Reader::UPDATE, $audits[0]->getType(), 'AuditReader::UPDATE operation.');
        self::assertEquals([
            'bool_value' => [
                'old' => null,
                'new' => false,
            ],
            'label' => [
                'old' => 'bool: true->null',
                'new' => 'bool: null->false',
            ],
        ], $audits[0]->getDiffs(), 'bool: null->false');

        $dummy->setLabel('bool: false->null');
        $dummy->setBoolValue(null);
        $em->persist($dummy);
        $em->flush();

        $audits = $reader->getAudits(DummyEntity::class);
        self::assertSame(Reader::UPDATE, $audits[0]->getType(), 'AuditReader::UPDATE operation.');
        self::assertEquals([
            'bool_value' => [
                'old' => false,
                'new' => null,
            ],
            'label' => [
                'old' => 'bool: null->false',
                'new' => 'bool: false->null',
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
        self::assertSame(Reader::INSERT, $audits[0]->getType(), 'AuditReader::INSERT operation.');
        self::assertEquals([
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
        self::assertSame(Reader::INSERT, $audits[0]->getType(), 'AuditReader::INSERT operation.');
        self::assertEquals([
            'json_array' => [
                'old' => null,
                'new' => '["R1","R2"]',
            ],
            'label' => [
                'old' => null,
                'new' => 'json_array: null->[R1, R2]',
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
        self::assertSame(Reader::INSERT, $audits[0]->getType(), 'AuditReader::INSERT operation.');
        self::assertEquals([
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
            ->setCreatedAt(new DateTime())
        ;
        $em->persist($post1);

        $post2 = new Post();
        $post2
            ->setTitle('Second post')
            ->setBody('Here is another body')
            ->setCreatedAt(new DateTime())
        ;
        $em->persist($post2);
        $em->flush();

        $author->addPost($post1);
        $author->addPost($post2);
        $em->flush();

        /** @var Entry[] $audits */
        $audits = $reader->getAudits(Author::class, null, 1, 50);

        $i = 0;
        self::assertCount(3, $audits, 'result count is ok.');
        self::assertSame(Reader::ASSOCIATE, $audits[$i++]->getType(), 'entry'.$i.' is an AuditReader::ASSOCIATE operation.');
        self::assertSame(Reader::ASSOCIATE, $audits[$i++]->getType(), 'entry'.$i.' is an AuditReader::ASSOCIATE operation.');
        self::assertSame(Reader::INSERT, $audits[$i++]->getType(), 'entry'.$i.' is an AuditReader::INSERT operation.');

        self::assertEquals([
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

        self::assertEquals([
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
            ->setCreatedAt(new DateTime())
        ;
        $em->persist($post1);

        $post2 = new Post();
        $post2
            ->setTitle('Second post')
            ->setBody('Here is another body')
            ->setCreatedAt(new DateTime())
        ;
        $em->persist($post2);
        $em->flush();

        $author->addPost($post1);
        $author->addPost($post2);
        $em->flush();

        $author->removePost($post1);
        $author->removePost($post2);
        $em->flush();

        /** @var Entry[] $audits */
        $audits = $reader->getAudits(Author::class, null, 1, 50);

        $i = 0;
        self::assertCount(5, $audits, 'result count is ok.');
        self::assertSame(Reader::DISSOCIATE, $audits[$i++]->getType(), 'entry'.$i.' is an AuditReader::DISSOCIATE operation.');
        self::assertSame(Reader::DISSOCIATE, $audits[$i++]->getType(), 'entry'.$i.' is an AuditReader::DISSOCIATE operation.');
        self::assertSame(Reader::ASSOCIATE, $audits[$i++]->getType(), 'entry'.$i.' is an AuditReader::ASSOCIATE operation.');
        self::assertSame(Reader::ASSOCIATE, $audits[$i++]->getType(), 'entry'.$i.' is an AuditReader::ASSOCIATE operation.');
        self::assertSame(Reader::INSERT, $audits[$i++]->getType(), 'entry'.$i.' is an AuditReader::INSERT operation.');

        self::assertEquals([
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

        self::assertEquals([
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
            ->setCreatedAt(new DateTime())
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

        /** @var Entry[] $audits */
        $audits = $reader->getAudits(Post::class, null, 1, 50);

        $i = 0;
        self::assertCount(4, $audits, 'result count is ok.');
        self::assertSame(Reader::ASSOCIATE, $audits[$i++]->getType(), 'entry'.$i.' is an AuditReader::ASSOCIATE operation.');
        self::assertSame(Reader::ASSOCIATE, $audits[$i++]->getType(), 'entry'.$i.' is an AuditReader::ASSOCIATE operation.');
        self::assertSame(Reader::ASSOCIATE, $audits[$i++]->getType(), 'entry'.$i.' is an AuditReader::ASSOCIATE operation.');
        self::assertSame(Reader::INSERT, $audits[$i++]->getType(), 'entry'.$i.' is an AuditReader::INSERT operation.');

        self::assertEquals([
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

        self::assertEquals([
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

        self::assertEquals([
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
            ->setCreatedAt(new DateTime())
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

        /** @var Entry[] $audits */
        $audits = $reader->getAudits(Post::class, null, 1, 50);

        $i = 0;
        self::assertCount(5, $audits, 'result count is ok.');
        self::assertSame(Reader::DISSOCIATE, $audits[$i++]->getType(), 'entry'.$i.' is an AuditReader::DISSOCIATE operation.');
        self::assertSame(Reader::ASSOCIATE, $audits[$i++]->getType(), 'entry'.$i.' is an AuditReader::ASSOCIATE operation.');
        self::assertSame(Reader::ASSOCIATE, $audits[$i++]->getType(), 'entry'.$i.' is an AuditReader::ASSOCIATE operation.');
        self::assertSame(Reader::ASSOCIATE, $audits[$i++]->getType(), 'entry'.$i.' is an AuditReader::ASSOCIATE operation.');
        self::assertSame(Reader::INSERT, $audits[$i++]->getType(), 'entry'.$i.' is an AuditReader::INSERT operation.');

        self::assertEquals([
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
        $reader = $this->getReader($this->getAuditConfiguration());

        $post = new Post();
        $post
            ->setTitle('First post')
            ->setBody('Here is the body')
            ->setCreatedAt(new DateTime())
        ;
        $em->persist($post);
        $em->flush();

        $audits = $reader->getAudits(Post::class);
        $beforeCount = \count($audits);

        $em->remove($post);
        $em->flush();

        $reader = $this->getReader($this->getAuditConfiguration());
        $audits = $reader->getAudits(Post::class);
        $afterCount = \count($audits);

        self::assertSame($beforeCount + 1, $afterCount, 'removing an entity (no relation set) adds 1 entry in the audit table.');

        $i = 0;
        self::assertCount(2, $audits, 'result count is ok.');
        self::assertSame(Reader::REMOVE, $audits[$i++]->getType(), 'entry'.$i.' is an AuditReader::REMOVE operation.');
        self::assertSame(Reader::INSERT, $audits[$i++]->getType(), 'entry'.$i.' is an AuditReader::INSERT operation.');
    }

    public function testTransactionHash(): void
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
            ->setCreatedAt(new DateTime())
        ;
        $em->persist($post);
        $em->flush();

        /** @var \DH\DoctrineAuditBundle\Model\Entry[] $audits */
        $audits = $reader->getAudits(Author::class);

        self::assertCount(1, $audits, 'result count is ok.');
        $author_transaction_hash = $audits[0]->getTransactionHash();

        /** @var Entry[] $audits */
        $audits = $reader->getAudits(Post::class);

        self::assertCount(1, $audits, 'result count is ok.');
        $post_transaction_hash = $audits[0]->getTransactionHash();

        self::assertSame($author_transaction_hash, $post_transaction_hash, 'transaction hash is the same for both audit entries.');

        $em->remove($post);
        $em->flush();

        /** @var Entry[] $audits */
        $audits = $reader->getAudits(Post::class);

        self::assertCount(2, $audits, 'result count is ok.');
        $removed_post_transaction_hash = $audits[0]->getTransactionHash();

        self::assertNotSame($removed_post_transaction_hash, $post_transaction_hash, 'transaction hash is NOT the same.');
    }

    protected function setupEntities(): void
    {
    }
}
