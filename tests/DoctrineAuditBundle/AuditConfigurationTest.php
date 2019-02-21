<?php

namespace DH\DoctrineAuditBundle\Tests;

use DH\DoctrineAuditBundle\AuditConfiguration;
use DH\DoctrineAuditBundle\User\TokenStorageUserProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Security;

/**
 * @coversNothing
 */
class AuditConfigurationTest extends TestCase
{
    public function testDefaultTablePrefix(): void
    {
        $configuration = $this->getAuditConfiguration();

        $this->assertSame('', $configuration->getTablePrefix(), 'table_prefix is empty by default.');
    }

    public function testDefaultTableSuffix(): void
    {
        $configuration = $this->getAuditConfiguration();

        $this->assertSame('_audit', $configuration->getTableSuffix(), 'table_suffix is "_audit" by default.');
    }

    public function testCustomTablePrefix(): void
    {
        $configuration = $this->getAuditConfiguration([
            'table_prefix' => 'audit_',
        ]);

        $this->assertSame('audit_', $configuration->getTablePrefix(), 'custom table_prefix is "audit_".');
    }

    public function testCustomTableSuffix(): void
    {
        $configuration = $this->getAuditConfiguration([
            'table_suffix' => '_audit_log',
        ]);

        $this->assertSame('_audit_log', $configuration->getTableSuffix(), 'custom table_suffix is "_audit_log".');
    }

    public function testGloballyIgnoredColumns(): void
    {
        $ignored = [
            'created_at',
            'updated_at',
        ];

        $configuration = $this->getAuditConfiguration([
            'ignored_columns' => $ignored,
        ]);

        $this->assertSame($ignored, $configuration->getIgnoredColumns(), 'ignored columns are honored.');
    }

    public function testGetEntities(): void
    {
        $entities = [
            'Fixtures\Core\Post' => null,
            'Fixtures\Core\Comment' => null,
        ];

        $configuration = $this->getAuditConfiguration([
            'entities' => $entities,
        ]);

        $this->assertSame($entities, $configuration->getEntities(), 'AuditConfiguration::getEntities() returns configured entities list.');
    }

    public function testIsAudited(): void
    {
        $entities = [
            'Fixtures\Core\Post' => null,
        ];

        $configuration = $this->getAuditConfiguration([
            'entities' => $entities,
        ]);

        $this->assertTrue($configuration->isAudited('Fixtures\Core\Post'), 'entity "Fixtures\Core\Post" is audited.');
        $this->assertFalse($configuration->isAudited('Fixtures\Core\Comment'), 'entity "Fixtures\Core\Comment" is not audited.');
    }

    /**
     * @depends testIsAudited
     */
    public function testIsAuditedHonorsEnabledFlag(): void
    {
        $entities = [
            'Fixtures\Core\Post' => [
                'enabled' => true,
            ],
        ];

        $configuration = $this->getAuditConfiguration([
            'entities' => $entities,
        ]);

        $this->assertTrue($configuration->isAudited('Fixtures\Core\Post'), 'entity "Fixtures\Core\Post" is audited.');

        $entities = [
            'Fixtures\Core\Post' => [
                'enabled' => false,
            ],
        ];

        $configuration = $this->getAuditConfiguration([
            'entities' => $entities,
        ]);

        $this->assertFalse($configuration->isAudited('Fixtures\Core\Post'), 'entity "Fixtures\Core\Post" is not audited.');
    }

    /**
     * @depends testIsAudited
     */
    public function testIsAuditedFieldAuditsAnyFieldByDefault(): void
    {
        $entities = [
            'Fixtures\Core\Post' => null,
        ];

        $configuration = $this->getAuditConfiguration([
            'entities' => $entities,
        ]);

        $this->assertTrue($configuration->isAuditedField('Fixtures\Core\Post', 'id'), 'any field is audited.');
        $this->assertTrue($configuration->isAuditedField('Fixtures\Core\Post', 'title'), 'any field is audited.');
        $this->assertTrue($configuration->isAuditedField('Fixtures\Core\Post', 'created_at'), 'any field is audited.');
        $this->assertTrue($configuration->isAuditedField('Fixtures\Core\Post', 'updated_at'), 'any field is audited.');
    }

    /**
     * @depends testIsAudited
     */
    public function testIsAuditedFieldHonorsLocallyIgnoredColumns(): void
    {
        $entities = [
            'Fixtures\Core\Post' => [
                'ignored_columns' => [
                    'created_at',
                    'updated_at',
                ],
            ],
        ];

        $configuration = $this->getAuditConfiguration([
            'entities' => $entities,
        ]);

        $this->assertTrue($configuration->isAuditedField('Fixtures\Core\Post', 'id'), 'field "Fixtures\Core\Post::$id" is audited.');
        $this->assertTrue($configuration->isAuditedField('Fixtures\Core\Post', 'title'), 'field "Fixtures\Core\Post::$title" is audited.');
        $this->assertFalse($configuration->isAuditedField('Fixtures\Core\Post', 'created_at'), 'field "Fixtures\Core\Post::$created_at" is not audited.');
        $this->assertFalse($configuration->isAuditedField('Fixtures\Core\Post', 'updated_at'), 'field "Fixtures\Core\Post::$updated_at" is not audited.');
    }

    /**
     * @depends testIsAudited
     */
    public function testIsAuditedFieldHonorsGloballyIgnoredColumns(): void
    {
        $entities = [
            'Fixtures\Core\Post' => null,
        ];

        $configuration = $this->getAuditConfiguration([
            'ignored_columns' => [
                'created_at',
                'updated_at',
            ],
            'entities' => $entities,
        ]);

        $this->assertTrue($configuration->isAuditedField('Fixtures\Core\Post', 'id'), 'field "Fixtures\Core\Post::$id" is audited.');
        $this->assertTrue($configuration->isAuditedField('Fixtures\Core\Post', 'title'), 'field "Fixtures\Core\Post::$title" is audited.');
        $this->assertFalse($configuration->isAuditedField('Fixtures\Core\Post', 'created_at'), 'field "Fixtures\Core\Post::$created_at" is not audited.');
        $this->assertFalse($configuration->isAuditedField('Fixtures\Core\Post', 'updated_at'), 'field "Fixtures\Core\Post::$updated_at" is not audited.');
    }

    /**
     * @depends testIsAuditedHonorsEnabledFlag
     */
    public function testEnableAuditFor(): void
    {
        $entities = [
            'Fixtures\Core\Post' => [
                'enabled' => false,
            ],
        ];

        $configuration = $this->getAuditConfiguration([
            'entities' => $entities,
        ]);

        $this->assertFalse($configuration->isAudited('Fixtures\Core\Post'), 'entity "Fixtures\Core\Post" is not audited.');

        $configuration->enableAuditFor('Fixtures\Core\Post');

        $this->assertTrue($configuration->isAudited('Fixtures\Core\Post'), 'entity "Fixtures\Core\Post" is audited.');
    }

    /**
     * @depends testIsAuditedHonorsEnabledFlag
     */
    public function testDisableAuditFor(): void
    {
        $entities = [
            'Fixtures\Core\Post' => [
                'enabled' => true,
            ],
        ];

        $configuration = $this->getAuditConfiguration([
            'entities' => $entities,
        ]);

        $this->assertTrue($configuration->isAudited('Fixtures\Core\Post'), 'entity "Fixtures\Core\Post" is audited.');

        $configuration->disableAuditFor('Fixtures\Core\Post');

        $this->assertFalse($configuration->isAudited('Fixtures\Core\Post'), 'entity "Fixtures\Core\Post" is not audited.');
    }

    /** Utility methods */
    protected function getContainer(): ContainerBuilder
    {
        return new ContainerBuilder();
    }

    protected function getSecurity(): Security
    {
        return new Security($this->getContainer());
    }

    protected function getRequestStack(): RequestStack
    {
        return new RequestStack();
    }

    protected function getUserProvider(): TokenStorageUserProvider
    {
        return new TokenStorageUserProvider($this->getSecurity());
    }

    protected function getAuditConfiguration(array $options = []): AuditConfiguration
    {
        return new AuditConfiguration(
            array_merge([
                'table_prefix' => '',
                'table_suffix' => '_audit',
                'ignored_columns' => [],
                'entities' => [],
            ], $options),
            $this->getUserProvider(),
            $this->getRequestStack()
        );
    }
}
