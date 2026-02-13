<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Command;

use DH\AuditorBundle\Viewer\ActivityGraphProvider;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'audit:cache:clear',
    description: 'Clear the activity graph cache',
)]
final class ClearActivityCacheCommand extends Command
{
    public function __construct(
        private readonly ?ActivityGraphProvider $activityGraphProvider = null,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addOption('entity', null, InputOption::VALUE_REQUIRED, 'Clear cache for a specific entity (FQCN)')
            ->setHelp(<<<'HELP'
                The <info>%command.name%</info> command clears the activity graph cache.

                Clear all activity graph cache:
                    <info>php %command.full_name%</info>

                Clear cache for a specific entity:
                    <info>php %command.full_name% --entity="App\Entity\User"</info>

                Note: Clearing all cache requires a cache pool that supports tags (TagAwareAdapterInterface).
                If your cache pool doesn't support tags, you can only clear cache for specific entities.
                HELP)
        ;
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->activityGraphProvider instanceof ActivityGraphProvider) {
            $io->warning('Activity graph is not configured or disabled.');

            return Command::SUCCESS;
        }

        if (!$this->activityGraphProvider->isCacheAvailable()) {
            $io->warning([
                'Activity graph cache is not available.',
                'Make sure symfony/cache is installed and cache is enabled in configuration.',
            ]);

            return Command::SUCCESS;
        }

        /** @var null|string $entity */
        $entity = $input->getOption('entity');

        if ($this->activityGraphProvider->clearCache($entity)) {
            $message = null !== $entity
                ? \sprintf('Cache cleared for entity: %s', $entity)
                : 'All activity graph cache cleared.';
            $io->success($message);
        } elseif (null === $entity) {
            $io->warning([
                'Could not clear all cache.',
                'Your cache pool may not support tags (TagAwareCacheInterface).',
                'Try clearing cache for a specific entity with --entity option.',
            ]);
        } else {
            $io->warning(\sprintf('Could not clear cache for entity: %s', $entity));
        }

        return Command::SUCCESS;
    }
}
