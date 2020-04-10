<?php

namespace DH\DoctrineAuditBundle\Tests\Command;

use DH\DoctrineAuditBundle\Command\UpdateSchemaCommand;
use DH\DoctrineAuditBundle\Reader\Reader;
use DH\DoctrineAuditBundle\Tests\CoreTest;
use DH\DoctrineAuditBundle\Transaction\TransactionManager;
use Doctrine\Bundle\DoctrineBundle\Registry;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * @internal
 */
final class UpdateSchemaCommandTest extends CoreTest
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
        self::assertStringContainsString('[CAUTION] This operation should not be executed in a production environment!', $output);
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
        self::assertStringContainsString('The command is already running in another process.', $output);
    }

    protected function createCommand(): UpdateSchemaCommand
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

        $manager = new TransactionManager($this->getAuditConfiguration());
        $container->set('dh_doctrine_audit.manager', $manager);

        $reader = new Reader($this->getAuditConfiguration(), $em);
        $container->set('dh_doctrine_audit.reader', $reader);

        $command = new UpdateSchemaCommand();
        $command->setContainer($container);
        $command->unlock();

        $this->tearDownAuditSchema();

        return $command;
    }
}
