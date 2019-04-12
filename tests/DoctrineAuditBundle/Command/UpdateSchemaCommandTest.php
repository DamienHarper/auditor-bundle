<?php

namespace DH\DoctrineAuditBundle\Tests\Command;

use DH\DoctrineAuditBundle\AuditManager;
use DH\DoctrineAuditBundle\Command\UpdateSchemaCommand;
use DH\DoctrineAuditBundle\Helper\AuditHelper;
use DH\DoctrineAuditBundle\Reader\AuditReader;
use DH\DoctrineAuditBundle\Tests\CoreTest;
use Doctrine\Bundle\DoctrineBundle\Registry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * @covers \DH\DoctrineAuditBundle\AuditConfiguration
 * @covers \DH\DoctrineAuditBundle\AuditManager
 * @covers \DH\DoctrineAuditBundle\Command\UpdateSchemaCommand
 * @covers \DH\DoctrineAuditBundle\DBAL\AuditLogger
 * @covers \DH\DoctrineAuditBundle\EventSubscriber\AuditSubscriber
 * @covers \DH\DoctrineAuditBundle\EventSubscriber\CreateSchemaListener
 * @covers \DH\DoctrineAuditBundle\Helper\AuditHelper
 * @covers \DH\DoctrineAuditBundle\Helper\DoctrineHelper
 * @covers \DH\DoctrineAuditBundle\Helper\UpdateHelper
 * @covers \DH\DoctrineAuditBundle\Reader\AuditReader
 * @covers \DH\DoctrineAuditBundle\User\TokenStorageUserProvider
 * @covers \DH\DoctrineAuditBundle\User\User
 */
class UpdateSchemaCommandTest extends CoreTest
{
    use LockableTrait;

    public function testExecute(): void
    {
        $command = $this->createCommand();
        $commandTester = new CommandTester($command);
        $commandTester->execute([]);
        $command->unlock();

        // the output of the command in the console
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('[OK] Success', $output);
    }

    /**
     * @depends testExecute
     */
    public function testExecuteFailsWhileLocked(): void
    {
        $this->lock('audit:schema:update');

        $command = $this->createCommand();
        $commandTester = new CommandTester($command);
        $commandTester->execute([]);
        $command->unlock();

        // the output of the command in the console
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('The command is already running in another process.', $output);
    }

    protected function createCommand(): Command
    {
        $this->fixturesPath = __DIR__.'/../Fixtures';

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

        $helper = new AuditHelper($this->getAuditConfiguration());
        $manager = new AuditManager($this->getAuditConfiguration(), $helper);
        $container->set('dh_doctrine_audit.manager', $manager);

        $reader = new AuditReader($this->getAuditConfiguration(), $em);
        $container->set('dh_doctrine_audit.reader', $reader);

        $command = new UpdateSchemaCommand();
        $command->setContainer($container);
        $command->unlock();

        return $command;
    }
}
