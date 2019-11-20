<?php

namespace DH\DoctrineAuditBundle\Command;

use DH\DoctrineAuditBundle\Helper\UpdateHelper;
use DH\DoctrineAuditBundle\Manager\AuditManager;
use DH\DoctrineAuditBundle\Reader\AuditReader;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

class UpdateSchemaCommand extends Command implements ContainerAwareInterface
{
    use LockableTrait;

    protected static $defaultName = 'audit:schema:update';

    private $container;

    public function setContainer(?ContainerInterface $container = null): void
    {
        $this->container = $container;
    }

    public function unlock(): void
    {
        $this->release();
    }

    protected function configure(): void
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
        $updater = new UpdateHelper($manager, $reader);

        $sqls = $updater->getUpdateAuditSchemaSql();

        if (empty($sqls)) {
            $io->success('Nothing to update.');

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

            $progressBar = new ProgressBar($output, \count($sqls));
            $progressBar->start();

            $updater->updateAuditSchema($sqls, static function (array $progress) use ($progressBar): void {
                $progressBar->advance();
            });

            $progressBar->finish();

            $io->newLine(2);

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
}
