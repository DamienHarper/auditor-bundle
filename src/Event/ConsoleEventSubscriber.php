<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Event;

use DH\Auditor\Blame\BlameContext;
use DH\Auditor\Configuration;
use DH\Auditor\User\UserProviderInterface;
use DH\AuditorBundle\User\ConsoleUserProviderInterface;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener(event: ConsoleEvents::COMMAND, method: 'registerConsoleUserProvider')]
#[AsEventListener(event: ConsoleEvents::TERMINATE, method: 'restoreDefaultUserProvider')]
final class ConsoleEventSubscriber
{
    public const string CONTEXT_TYPE = 'console';

    /**
     * Blame contexts pushed for the running commands, innermost last.
     *
     * @var list<BlameContext>
     */
    private array $contexts = [];

    public function __construct(
        private readonly ConsoleUserProviderInterface $consoleUserProvider,
        private readonly Configuration $configuration,
        private readonly UserProviderInterface $provider,
    ) {}

    public function registerConsoleUserProvider(ConsoleCommandEvent $commandEvent): void
    {
        $command = $commandEvent->getCommand();
        $this->consoleUserProvider->setCurrentCommand($command);
        $this->configuration->setUserProvider($this->consoleUserProvider);

        // the audit entries of the command get "via": {"type": "console", "label": "<command name>"}
        $context = new BlameContext(self::CONTEXT_TYPE, $command?->getName());
        $this->contexts[] = $context;
        $this->configuration->getBlameContextStack()->push($context);
    }

    public function restoreDefaultUserProvider(): void
    {
        $this->consoleUserProvider->setCurrentCommand(null);
        $this->configuration->setUserProvider($this->provider);

        // the stack may have been reset meanwhile (kernel.reset in a worker): only pop the context of this command
        $context = array_pop($this->contexts);
        $stack = $this->configuration->getBlameContextStack();
        if (null !== $context && $stack->current() === $context) {
            $stack->pop();
        }
    }
}
