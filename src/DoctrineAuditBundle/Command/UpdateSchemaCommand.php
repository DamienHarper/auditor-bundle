<?php

namespace DH\DoctrineAuditBundle\Command;

use DH\DoctrineAuditBundle\AuditManager;
use DH\DoctrineAuditBundle\Exception\UpdateException;
use DH\DoctrineAuditBundle\Helper\UpdateHelper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
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
         * @var AuditManager
         */
        $manager = $this->container->get('dh_doctrine_audit.manager');

        /**
         * @var UpdateHelper
         */
        $updater = new UpdateHelper($manager);

        $io->writeln('Introspecting schema...');

        $entityManager = $manager->getConfiguration()->getEntityManager();
        $schemaManager = $entityManager->getConnection()->getSchemaManager();

        $schema = $schemaManager->createSchema();
        $tables = $schema->getTables();
        $audits = [];
        $errors = [];

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

        if (0 === \count($audits)) {
            $io->warning('There are no audit table to upgrade.');

            return 0;
        }

        $progressBar = new ProgressBar($output, \count($audits));
        $progressBar->setBarWidth(70);
        $progressBar->setFormat("%message%\n".$progressBar->getFormatDefinition('debug'));

        $progressBar->setMessage('Starting...');
        $progressBar->start();

        foreach ($audits as $table) {
            $progressBar->setMessage("Processing audit tables... (<info>{$table->getName()}</info>)");
            $progressBar->display();

            try {
                $updater->updateAuditTable($table);
            } catch (UpdateException $e) {
                $errors[] = $e->getMessage();
                $io->error($e->getMessage());
            }

            $progressBar->advance();
        }

        $progressBar->setMessage('Processing audit tables... (<info>done</info>)');
        $progressBar->display();

        $io->newLine(2);

        if (empty($errors)) {
            $io->success('Success.');
        } else {
            foreach ($errors as $error) {
                $io->error($error);
            }
        }

        // if not released explicitly, Symfony releases the lock
        // automatically when the execution of the command ends
        $this->release();

        return (int) empty($errors);
    }

    public function setContainer(ContainerInterface $container = null): void
    {
        $this->container = $container;
    }

    public function unlock()
    {
        $this->release();
    }
}
