<?php

namespace DH\DoctrineAuditBundle\Command;

use DateTime;
use DH\DoctrineAuditBundle\Reader\AuditReader;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
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

class CleanAuditLogsCommand extends Command implements ContainerAwareInterface
{
    use LockableTrait;

    protected static $defaultName = 'audit:clean';

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
            ->setDescription('Cleans audit tables')
            ->setName(self::$defaultName)
            ->addOption('no-confirm', null, InputOption::VALUE_NONE, 'No interaction mode')
            ->addArgument('keep', InputArgument::OPTIONAL, 'Keep last N months of audit.', '12')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$this->lock()) {
            $output->writeln('The command is already running in another process.');

            return 0;
        }

        $io = new SymfonyStyle($input, $output);

        $keep = (int) $input->getArgument('keep');
        if ($keep <= 0) {
            $io->error("'keep' argument must be a positive number.");
            $this->release();

            return 0;
        }

        $until = new DateTime();
        $until->modify('-'.$keep.' month');

        /**
         * @var AuditReader
         */
        $reader = $this->container->get('dh_doctrine_audit.reader');

        /**
         * @var Connection
         */
        $connection = $reader->getConfiguration()->getEntityManager()->getConnection();

        $entities = $reader->getEntities();

        $message = sprintf(
            "You are about to clean audits older than %d months (up to <comment>%s</comment>): %d entities involved.\n Do you want to proceed?",
            $keep,
            $until->format('Y-m-d'),
            \count($entities)
        );

        $confirm = $input->getOption('no-confirm') ? true : $io->confirm($message, false);

        if ($confirm) {
            $progressBar = new ProgressBar($output, \count($entities));
            $progressBar->setBarWidth(70);
            $progressBar->setFormat("%message%\n".$progressBar->getFormatDefinition('debug'));

            $progressBar->setMessage('Starting...');
            $progressBar->start();

            foreach ($entities as $entity => $tablename) {
                $auditTable = implode('', [
                    $reader->getConfiguration()->getTablePrefix(),
                    $tablename,
                    $reader->getConfiguration()->getTableSuffix(),
                ]);

                /**
                 * @var QueryBuilder
                 */
                $queryBuilder = $connection->createQueryBuilder();
                $queryBuilder
                    ->delete($auditTable)
                    ->where('created_at < :until')
                    ->setParameter(':until', $until->format('Y-m-d'))
                    ->execute()
                ;

                $progressBar->setMessage("Cleaning audit tables... (<info>{$auditTable}</info>)");
                $progressBar->advance();
            }

            $progressBar->setMessage('Cleaning audit tables... (<info>done</info>)');
            $progressBar->display();

            $io->newLine(2);

            $io->success('Success.');
        } else {
            $io->success('Cancelled.');
        }

        // if not released explicitly, Symfony releases the lock
        // automatically when the execution of the command ends
        $this->release();

        return 0;
    }
}
