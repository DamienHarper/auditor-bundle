<?php

namespace DH\DoctrineAuditBundle\Tests;

use DH\DoctrineAuditBundle\AuditConfiguration;
use DH\DoctrineAuditBundle\User\TokenStorageUserProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Security;

class AuditConfigurationTest extends TestCase
{
    public function testDefaultTablePrefixAndSuffix(): void
    {
        $configuration = $this->getAuditConfiguration();

        $this->assertEquals('', $configuration->getTablePrefix(), 'table_prefix is empty by default.');
        $this->assertEquals('_audit', $configuration->getTableSuffix(), 'table_suffix is "_audit" by default.');
    }

    public function testCustomTablePrefixAndSuffix(): void
    {
        $configuration = $this->getAuditConfiguration([
            'table_prefix' => 'audit_',
            'table_suffix' => '_trail',
        ]);

        $this->assertEquals('audit_', $configuration->getTablePrefix(), 'custom table_prefix is "audit_".');
        $this->assertEquals('_trail', $configuration->getTableSuffix(), 'custom table_suffix is "_trail".');
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

        $this->assertEquals($ignored, $configuration->getIgnoredColumns(), 'ignored columns are honored.');
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

    public function testIsAuditedField(): void
    {
        $entities = [
            'Fixtures\Core\Post' => [
                'ignored_columns' => [
                    'created_at',
                    'updated_at',
                ]
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
