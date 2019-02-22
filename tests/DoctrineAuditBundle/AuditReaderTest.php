<?php

namespace DH\DoctrineAuditBundle\Tests;

use DH\DoctrineAuditBundle\AuditConfiguration;
use DH\DoctrineAuditBundle\AuditReader;
use DH\DoctrineAuditBundle\User\TokenStorageUserProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Security;

/**
 * @covers \DH\DoctrineAuditBundle\AuditReader
 * @covers \DH\DoctrineAuditBundle\AuditConfiguration
 * @covers \DH\DoctrineAuditBundle\User\TokenStorageUserProvider
 */
class AuditReaderTest extends BaseTestCase
{
    protected $fixturesPath = __DIR__ . '/Fixtures';

    public function testGetAuditConfiguration(): void
    {
        $reader = $this->getReader();

        $this->assertInstanceOf(AuditConfiguration::class, $reader->getConfiguration(), 'configuration instanceof AuditConfiguration::class');
    }

    public function testFilterIsNullByDefault(): void
    {
        $reader = $this->getReader();

        $this->assertNull($reader->getFilter(), 'filter is null by default.');
    }

    public function testFilterCanOnlyBePartOfAllowedValues(): void
    {
        $reader = $this->getReader();

        $reader->filterBy('UNKNOWN');
        $this->assertNull($reader->getFilter(), 'filter is null when AuditReader::filterBy() parameter is not an allowed value.');

        $reader->filterBy(AuditReader::ASSOCIATE);
        $this->assertSame(AuditReader::ASSOCIATE, $reader->getFilter(), 'filter is not null when AuditReader::filterBy() parameter is an allowed value.');

        $reader->filterBy(AuditReader::DISSOCIATE);
        $this->assertSame(AuditReader::DISSOCIATE, $reader->getFilter(), 'filter is not null when AuditReader::filterBy() parameter is an allowed value.');

        $reader->filterBy(AuditReader::INSERT);
        $this->assertSame(AuditReader::INSERT, $reader->getFilter(), 'filter is not null when AuditReader::filterBy() parameter is an allowed value.');

        $reader->filterBy(AuditReader::REMOVE);
        $this->assertSame(AuditReader::REMOVE, $reader->getFilter(), 'filter is not null when AuditReader::filterBy() parameter is an allowed value.');

        $reader->filterBy(AuditReader::UPDATE);
        $this->assertSame(AuditReader::UPDATE, $reader->getFilter(), 'filter is not null when AuditReader::filterBy() parameter is an allowed value.');
    }

    public function testGetEntityTableName(): void
    {
        $entities = [
            'DH\DoctrineAuditBundle\Tests\Fixtures\Core\Post' => null,
            'DH\DoctrineAuditBundle\Tests\Fixtures\Core\Comment' => null,
        ];

        $configuration = $this->getConfiguration([
            'entities' => $entities,
        ]);

        $reader = $this->getReader($configuration);

        $this->assertSame('post', $reader->getEntityTableName('DH\DoctrineAuditBundle\Tests\Fixtures\Core\Post'), 'tablename is ok.');
        $this->assertSame('comment', $reader->getEntityTableName('DH\DoctrineAuditBundle\Tests\Fixtures\Core\Comment'), 'tablename is ok.');
    }

    /**
     * @depends testGetEntityTableName
     */
    public function testGetEntities(): void
    {
        $entities = [
            'DH\DoctrineAuditBundle\Tests\Fixtures\Core\Post' => null,
            'DH\DoctrineAuditBundle\Tests\Fixtures\Core\Comment' => null,
        ];

        $expected = array_combine(
            array_keys($entities),
            ['post', 'comment']
        );
        ksort($expected);

        $configuration = $this->getConfiguration([
            'entities' => $entities,
        ]);

        $reader = $this->getReader($configuration);

        $this->assertSame($expected, $reader->getEntities(), 'entities are sorted.');
    }



    protected function getConfiguration(array $options = []): AuditConfiguration
    {
        $container = new ContainerBuilder();
        $security = new Security($container);
        $requestStack = new RequestStack();
        $userProvider = new TokenStorageUserProvider($security);

        return new AuditConfiguration(
            array_merge([
                'table_prefix' => '',
                'table_suffix' => '_audit',
                'ignored_columns' => [],
                'entities' => [],
            ], $options),
            $userProvider,
            $requestStack
        );
    }

    protected function getReader(AuditConfiguration $configuration = null): AuditReader
    {
        return new AuditReader($configuration ?? $this->getConfiguration(), $this->getEntityManager());
    }
}