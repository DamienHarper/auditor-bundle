<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Event;

use DH\Auditor\Blame\BlameContext;
use DH\Auditor\Configuration;
use DH\Auditor\User\UserProviderInterface;
use DH\AuditorBundle\User\ConsoleUserProviderInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener(event: ConsoleEvents::COMMAND, method: 'registerConsoleUserProvider')]
#[AsEventListener(event: ConsoleEvents::TERMINATE, method: 'restoreDefaultUserProvider')]
final class ConsoleEventSubscriber
{
    public const string CONTEXT_TYPE = 'console';

    /**
     * The running commands, innermost last: the user provider in place before each of them and its blame context.
     *
     * @var list<array{command: ?Command, previousProvider: ?callable, context: BlameContext}>
     */
    private array $commands = [];

    public function __construct(
        private readonly ConsoleUserProviderInterface $consoleUserProvider,
        private readonly Configuration $configuration,
        private readonly UserProviderInterface $provider,
    ) {}

    public function registerConsoleUserProvider(ConsoleCommandEvent $commandEvent): void
    {
        $command = $commandEvent->getCommand();

        // the audit entries of the command get "via": {"type": "console", "label": "<command name>"}
        $context = new BlameContext(self::CONTEXT_TYPE, $command?->getName());

        // restored when the command ends: the provider set before it, which may be a custom one (not the default)
        $this->commands[] = ['command' => $command, 'previousProvider' => $this->configuration->getUserProvider(), 'context' => $context];
        $this->consoleUserProvider->setCurrentCommand($command);
        $this->configuration->setUserProvider($this->consoleUserProvider);
        $this->configuration->getBlameContextStack()->push($context);
    }

    public function restoreDefaultUserProvider(?ConsoleTerminateEvent $terminateEvent = null): void
    {
        $key = array_key_last($this->commands);
        // a command this listener did not see start (another COMMAND listener stopped the event): nothing to restore
        if (null === $key || ($terminateEvent instanceof ConsoleTerminateEvent && $this->commands[$key]['command'] !== $terminateEvent->getCommand())) {
            return;
        }

        $ended = array_pop($this->commands);
        // a command run from another one: the outer command is blamed again
        $outer = [] === $this->commands ? null : $this->commands[array_key_last($this->commands)];
        $this->consoleUserProvider->setCurrentCommand($outer['command'] ?? null);
        $this->configuration->setUserProvider($ended['previousProvider'] ?? $this->provider);

        // the stack may have been reset meanwhile (kernel.reset in a worker): only pop the context of this command
        $stack = $this->configuration->getBlameContextStack();
        if ($stack->current() === $ended['context']) {
            $stack->pop();
        }
    }
}
