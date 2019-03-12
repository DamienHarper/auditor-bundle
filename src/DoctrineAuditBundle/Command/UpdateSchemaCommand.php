<?php

namespace DH\DoctrineAuditBundle\Command;

use DH\DoctrineAuditBundle\AuditManager;
use DH\DoctrineAuditBundle\Helper\UpdateHelper;
use DH\DoctrineAuditBundle\Reader\AuditReader;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bridge\Doctrine\RegistryInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

class UpdateSchemaCommand extends Command implements ContainerAwareInterface
{
    use LockableTrait;

    private $container;

    protected static $defaultName = 'audit:schema:update';

    protected function configure()
    {
        $this
            ->setDescription('Update audit tables structure')
            ->setName(self::$defaultName)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$this->lock()) {
            $output->writeln('The command is already running in another process.');

            return 0;
        }

        $io = new SymfonyStyle($input, $output);

        /**
         * @var AuditManager $manager
         */
        $manager = $this->container->get('dh_doctrine_audit.manager');

        /**
         * @var UpdateHelper $updater
         */
        $updater = new UpdateHelper($manager);

        /**
         * @var RegistryInterface $registry
         */
        $registry = $this->container->get('doctrine');

        /**
         * @var Connection $connection
         */
        $connection = $registry->getManager()->getConnection();

        /**
         * @var AbstractSchemaManager $schemaManager
         */
        $schemaManager = $connection->getSchemaManager();
        $tables = $schemaManager->listTables();
        $audits = [];

        $regex = sprintf(
            '#^%s(.*)%s$#',
            preg_quote($updater->getConfiguration()->getTablePrefix(), '#'),
            preg_quote($updater->getConfiguration()->getTableSuffix(), '#')
        );

        foreach ($tables as $table) {
            if (preg_match($regex, $table->getName())) {
                $audits[] = $table;
            }
        }

        $progressBar = new ProgressBar($output, \count($audits));
        $progressBar->setBarWidth(70);
        $progressBar->setFormat("%message%\n".$progressBar->getFormatDefinition('debug'));

        $progressBar->setMessage('Starting...');
        $progressBar->start();

        foreach ($audits as $table) {
            $progressBar->setMessage("Processing audit tables... (<info>{$table->getName()}</info>)");
            $progressBar->display();

            $operations = $updater->checkAuditTable($schemaManager, $table);
            if (isset($operations['add']) || isset($operations['update']) || isset($operations['remove'])) {
                $updater->updateAuditTable($schemaManager, $table, $operations, $registry->getManager());
            }

            $progressBar->advance();
        }

        $progressBar->setMessage("Processing audit tables... (<info>done</info>)");
        $progressBar->display();

        $io->newLine(2);

        $io->success('Success.');

        // if not released explicitly, Symfony releases the lock
        // automatically when the execution of the command ends
        $this->release();

        return 0;
    }

    public function setContainer(ContainerInterface $container = null): void
    {
        $this->container = $container;
    }

    public function unlock()
    {
        return $this->release();
    }
}
