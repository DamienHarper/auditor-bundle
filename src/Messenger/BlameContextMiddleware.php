<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Messenger;

use DH\Auditor\Blame\BlameContext;
use DH\Auditor\Configuration;
use DH\Auditor\User\User;
use DH\Auditor\User\UserInterface;
use DH\AuditorBundle\Tests\Messenger\BlameContextMiddlewareTest;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

/**
 * Blames the changes made by a worker on the user who dispatched the message.
 *
 * - On dispatch, stamps the message with the current user (the one of the current blame context, or else the one
 *   returned by the user provider).
 * - When a worker handles the message, runs the handlers in a "messenger" blame context labelled with the message
 *   class: the audit entries get `"via": {"type": "messenger", "label": "App\\Message\\…"}` and blame the stamped user.
 *
 * Opt-in: add the "dh_auditor.messenger.blame_context_middleware" middleware to your buses.
 *
 * @see BlameContextMiddlewareTest
 */
final readonly class BlameContextMiddleware implements MiddlewareInterface
{
    public const string CONTEXT_TYPE = 'messenger';

    public function __construct(private Configuration $configuration) {}

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $receivedStamp = $envelope->last(ReceivedStamp::class);

        if (!$receivedStamp instanceof ReceivedStamp) {
            if (!$envelope->last(BlameStamp::class) instanceof BlameStamp) {
                $blameStamp = $this->createBlameStamp();
                if ($blameStamp instanceof BlameStamp) {
                    $envelope = $envelope->with($blameStamp);
                }
            }

            return $stack->next()->handle($envelope, $stack);
        }

        $blameStamp = $envelope->last(BlameStamp::class);
        $context = new BlameContext(
            self::CONTEXT_TYPE,
            $envelope->getMessage()::class,
            ['transport' => $receivedStamp->getTransportName()],
            $blameStamp instanceof BlameStamp ? new User($blameStamp->userId, $blameStamp->username) : null,
        );

        return $this->configuration->getBlameContextStack()->run(
            $context,
            static fn (): Envelope => $stack->next()->handle($envelope, $stack),
        );
    }

    private function createBlameStamp(): ?BlameStamp
    {
        $user = $this->configuration->getBlameContextStack()->current()?->user;

        if (!$user instanceof UserInterface) {
            $userProvider = $this->configuration->getUserProvider();
            $user = null === $userProvider ? null : $userProvider();
        }

        if (!$user instanceof UserInterface || (null === $user->identifier && null === $user->username)) {
            return null;
        }

        return new BlameStamp($user->identifier, $user->username);
    }
}
