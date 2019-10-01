<?php

namespace DH\DoctrineAuditBundle\Command;

use DH\DoctrineAuditBundle\Exception\UpdateException;
use DH\DoctrineAuditBundle\Helper\UpdateHelper;
use DH\DoctrineAuditBundle\Manager\AuditManager;
use DH\DoctrineAuditBundle\Reader\AuditReader;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
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
            ->addOption('dump-sql', null, InputOption::VALUE_NONE, 'Dumps the generated SQL statements to the screen (does not execute them).')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Causes the generated SQL statements to be physically executed against your database.')
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

        $dumpSql = true === $input->getOption('dump-sql');
        $force = true === $input->getOption('force');

        /**
         * @var AuditManager
         */
        $manager = $this->container->get('dh_doctrine_audit.manager');

        /**
         * @var AuditReader
         */
        $reader = $this->container->get('dh_doctrine_audit.reader');

        /**
         * @var UpdateHelper
         */
        $updater = new UpdateHelper($manager);

        $readerEntityManager = $reader->getEntityManager();
        $readerSchemaManager = $readerEntityManager->getConnection()->getSchemaManager();

        $auditEntityManager = $manager->getConfiguration()->getEntityManager();
        $auditSchemaManager = $auditEntityManager->getConnection()->getSchemaManager();

        $auditSchema = $auditSchemaManager->createSchema();
        $fromSchema = clone $auditSchema;
        $readerSchema = $readerSchemaManager->createSchema();
        $tables = $readerSchema->getTables();

        $entities = $reader->getEntities();
        foreach ($tables as $table) {
            if (\in_array($table->getName(), array_values($entities), true)) {
                try {
                    $auditTablename = preg_replace(
                        sprintf('#^([^\.]+\.)?(%s)$#', preg_quote($table->getName(), '#')),
                        sprintf(
                            '$1%s$2%s',
                            preg_quote($manager->getConfiguration()->getTablePrefix(), '#'),
                            preg_quote($manager->getConfiguration()->getTableSuffix(), '#')
                        ),
                        $table->getName()
                    );

                    if ($auditSchema->hasTable($auditTablename)) {
                        $updater->updateAuditTable($auditSchema->getTable($auditTablename), $auditSchema);
                    } else {
                        $updater->createAuditTable($table, $auditSchema);
                    }
                } catch (UpdateException $e) {
                    $io->error($e->getMessage());
                }
            }
        }

        $sqls = $fromSchema->getMigrateToSql($auditSchema, $auditSchemaManager->getDatabasePlatform());

        if (empty($sqls)) {
            $io->success('Nothing to update - your database is already in sync with the current entity metadata.');

            $this->release();

            return 0;
        }

        if ($dumpSql) {
            $io->text('The following SQL statements will be executed:');
            $io->newLine();

            foreach ($sqls as $sql) {
                $io->text(sprintf('    %s;', $sql));
            }
        }

        if ($force) {
            if ($dumpSql) {
                $io->newLine();
            }
            $io->text('Updating database schema...');
            $io->newLine();

            foreach ($sqls as $sql) {
                try {
                    $statement = $auditEntityManager->getConnection()->prepare($sql);
                    $statement->execute();
                } catch (\Exception $e) {
                    // something bad happened here :/
                    throw new UpdateException(sprintf('Failed to create/update "%s" audit table.', $table->getName()));
                }
            }

            $pluralization = (1 === \count($sqls)) ? 'query was' : 'queries were';

            $io->text(sprintf('    <info>%s</info> %s executed', \count($sqls), $pluralization));
            $io->success('Database schema updated successfully!');
        }

        if ($dumpSql || $force) {
            $this->release();

            return 0;
        }

        $io->caution('This operation should not be executed in a production environment!');

        $io->text(
            [
                sprintf('The Schema-Tool would execute <info>"%s"</info> queries to update the database.', \count($sqls)),
                '',
                'Please run the operation by passing one - or both - of the following options:',
                '',
                sprintf('    <info>%s --force</info> to execute the command', $this->getName()),
                sprintf('    <info>%s --dump-sql</info> to dump the SQL statements to the screen', $this->getName()),
            ]
        );

        $this->release();

        return 1;
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
