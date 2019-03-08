<?php

namespace DH\DoctrineAuditBundle\Tests\Command;

use DH\DoctrineAuditBundle\AuditReader;
use DH\DoctrineAuditBundle\Command\CleanAuditLogsCommand;
use DH\DoctrineAuditBundle\Tests\CoreTest;
use Doctrine\Bundle\DoctrineBundle\Registry;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * @covers \DH\DoctrineAuditBundle\AuditConfiguration
 * @covers \DH\DoctrineAuditBundle\AuditReader
 * @covers \DH\DoctrineAuditBundle\Helper\DoctrineHelper
 * @covers \DH\DoctrineAuditBundle\DBAL\AuditLogger
 * @covers \DH\DoctrineAuditBundle\Command\CleanAuditLogsCommand
 * @covers \DH\DoctrineAuditBundle\EventSubscriber\AuditSubscriber
 * @covers \DH\DoctrineAuditBundle\EventSubscriber\CreateSchemaListener
 * @covers \DH\DoctrineAuditBundle\User\TokenStorageUserProvider
 */
class CleanAuditLogsCommandTest extends CoreTest
{
    public function testExecute(): void
    {
        $this->fixturesPath = __DIR__.'/../Fixtures';

        $application = new Application();
        $application->add(new CleanAuditLogsCommand());

        $container = new ContainerBuilder();
        $em = $this->getEntityManager();

        $container->set('entity_manager', $em);
        $container->setAlias('doctrine.orm.default_entity_manager', 'entity_manager');

        $registry = new Registry(
            $container,
            [],
            ['default' => 'entity_manager'],
            'default',
            'default'
        );

        $container->set('doctrine', $registry);

        $reader = new AuditReader($this->getAuditConfiguration(), $em);
        $container->set('dh_doctrine_audit.reader', $reader);


        $command = $application->find('audit:clean');
        $command->setContainer($container);
        $commandTester = new CommandTester($command);
        $commandTester->execute([
            '--no-confirm' => true,
        ]);

        // the output of the command in the console
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('[OK] Success', $output);
    }

    public function testExecuteWithKeepNegative(): void
    {
        $this->fixturesPath = __DIR__.'/../Fixtures';

        $application = new Application();
        $application->add(new CleanAuditLogsCommand());

        $container = new ContainerBuilder();
        $em = $this->getEntityManager();

        $container->set('entity_manager', $em);
        $container->setAlias('doctrine.orm.default_entity_manager', 'entity_manager');

        $registry = new Registry(
            $container,
            [],
            ['default' => 'entity_manager'],
            'default',
            'default'
        );

        $container->set('doctrine', $registry);

        $reader = new AuditReader($this->getAuditConfiguration(), $em);
        $container->set('dh_doctrine_audit.reader', $reader);


        $command = $application->find('audit:clean');
        $command->setContainer($container);
        $commandTester = new CommandTester($command);

        $commandTester->execute([
            '--no-confirm' => true,
            'keep' => -1,
        ]);

        // the output of the command in the console
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString("'keep' argument must be a positive number.", $output);
    }
}