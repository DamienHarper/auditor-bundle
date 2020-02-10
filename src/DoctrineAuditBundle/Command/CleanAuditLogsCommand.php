<?php

namespace DH\DoctrineAuditBundle\Command;

use DateTime;
use DH\DoctrineAuditBundle\Reader\AuditReader;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Exception\RuntimeException;
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

    /**
     * @var null|ContainerInterface
     */
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
            ->addArgument('keep', InputArgument::OPTIONAL, 'Audits retention period (must be expressed as an ISO 8601 date interval, e.g. P12M to keep the last 12 months or P7D to keep the last 7 days).', 'P12M')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (null === $this->container) {
            throw new RuntimeException('No container.');
        }

        if (!$this->lock()) {
            $output->writeln('The command is already running in another process.');

            return 0;
        }

        $io = new SymfonyStyle($input, $output);

        if (is_numeric($input->getArgument('keep'))) {
            $deprecationMessage = "Providing an integer value for the 'keep' argument is deprecated. Please use the ISO 8601 duration format (e.g. P12M).";
            @\trigger_error($deprecationMessage, E_USER_DEPRECATED);
            $io->writeln($deprecationMessage);

            $keep = (int) $input->getArgument('keep');

            if ($keep <= 0) {
                $io->error("'keep' argument must be a positive number.");
                $this->release();
    
                return 0;
            }

            $until = new DateTime();
            $until->modify('-'.$keep.' month');
        } else {
            $keep = strval($input->getArgument('keep'));

            try {
                $dateInterval = new \DateInterval($keep);
            } catch (\Exception $e) {
                $io->error(sprintf("'keep' argument must be a valid ISO 8601 date interval. '%s' given.", $keep));
                $this->release();
                
                return 0;
            }
            
            $until = new DateTime();
            $until->sub($dateInterval);
        }

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
            "You are about to clean audits created before <comment>%s</comment>: %d entities involved.\n Do you want to proceed?",
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
