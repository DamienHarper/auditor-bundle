<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Tests\Messenger;

use DH\Auditor\Blame\BlameContext;
use DH\Auditor\Blame\BlameContextStack;
use DH\Auditor\Configuration;
use DH\Auditor\User\User;
use DH\AuditorBundle\Messenger\BlameContextMiddleware;
use DH\AuditorBundle\Messenger\BlameStamp;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Middleware\StackMiddleware;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

/**
 * @internal
 */
#[Small]
final class BlameContextMiddlewareTest extends TestCase
{
    private Configuration $configuration;

    /**
     * Blame context current when the next middleware ran.
     */
    private ?BlameContext $handledContext = null;

    private bool $handled = false;

    protected function setUp(): void
    {
        $this->configuration = new Configuration([]);
        $this->configuration->setUserProvider(static fn (): User => new User('1', 'alice'));
    }

    public function testDispatchStampsTheCurrentUser(): void
    {
        $envelope = $this->handle(new Envelope(new \stdClass()));

        $stamp = $envelope->last(BlameStamp::class);
        $this->assertInstanceOf(BlameStamp::class, $stamp);
        $this->assertSame('1', $stamp->userId);
        $this->assertSame('alice', $stamp->username);
        $this->assertTrue($this->handled, 'The next middleware is called.');
        $this->assertNull($this->handledContext, 'No blame context is pushed on dispatch.');
    }

    public function testDispatchStampsTheClassAndTheImpersonator(): void
    {
        $this->configuration->setUserProvider(static fn (): User => new User('1', 'alice', 'App\Entity\Customer', new User('9', 'admin', 'App\Entity\Admin')));

        $stamp = $this->handle(new Envelope(new \stdClass()))->last(BlameStamp::class);

        $this->assertInstanceOf(BlameStamp::class, $stamp);
        $this->assertSame('App\Entity\Customer', $stamp->fqdn);
        $this->assertSame('9', $stamp->impersonatorId);
        $this->assertSame('admin', $stamp->impersonatorUsername);
        $this->assertSame('App\Entity\Admin', $stamp->impersonatorFqdn);
    }

    public function testHandlingBlamesTheClassAndTheImpersonator(): void
    {
        $this->handle(new Envelope(new \stdClass(), [new BlameStamp('3', 'carol', 'App\Entity\Customer', '9', 'admin', 'App\Entity\Admin'), new ReceivedStamp('async')]));

        $user = $this->handledContext?->user;
        $this->assertSame('App\Entity\Customer', $user?->fqdn);
        $this->assertSame('9', $user?->impersonator?->identifier);
        $this->assertSame('admin', $user?->impersonator?->username);
        $this->assertSame('App\Entity\Admin', $user?->impersonator?->fqdn);
    }

    public function testHandlingWithoutImpersonator(): void
    {
        $this->handle(new Envelope(new \stdClass(), [new BlameStamp('3', 'carol'), new ReceivedStamp('async')]));

        $this->assertNull($this->handledContext?->user?->fqdn);
        $this->assertNull($this->handledContext?->user?->impersonator, 'No impersonator without impersonator values in the stamp.');
    }

    public function testDispatchStampsTheUserOfTheCurrentBlameContext(): void
    {
        $envelope = $this->configuration->getBlameContextStack()->run(
            new BlameContext('messenger', 'App\Message\Parent', user: new User('2', 'bob')),
            fn (): Envelope => $this->handle(new Envelope(new \stdClass())),
        );

        $stamp = $envelope->last(BlameStamp::class);
        $this->assertInstanceOf(BlameStamp::class, $stamp);
        $this->assertSame('bob', $stamp->username, 'A message dispatched by a handler is blamed on the same user.');
    }

    public function testDispatchKeepsAnExistingStamp(): void
    {
        $envelope = $this->handle(new Envelope(new \stdClass(), [new BlameStamp('3', 'carol')]));

        $this->assertCount(1, $envelope->all(BlameStamp::class));
        $this->assertSame('carol', $envelope->last(BlameStamp::class)?->username);
    }

    public function testDispatchWithoutUserAddsNoStamp(): void
    {
        $this->configuration->setUserProvider(static fn (): null => null);

        $envelope = $this->handle(new Envelope(new \stdClass()));

        $this->assertNull($envelope->last(BlameStamp::class));
    }

    public function testHandlingRunsInAMessengerBlameContext(): void
    {
        $this->handle(new Envelope(new \stdClass(), [new BlameStamp('3', 'carol'), new ReceivedStamp('async')]));

        $this->assertTrue($this->handled);
        $this->assertInstanceOf(BlameContext::class, $this->handledContext);
        $this->assertSame(['type' => 'messenger', 'label' => \stdClass::class, 'data' => ['transport' => 'async']], $this->handledContext->toArray());
        $this->assertSame('3', $this->handledContext->user?->identifier);
        $this->assertSame('carol', $this->handledContext->user?->username);
        $this->assertTrue($this->configuration->getBlameContextStack()->isEmpty(), 'The context is popped once the message is handled.');
    }

    public function testHandlingWithoutStampKeepsTheUserProvider(): void
    {
        $this->handle(new Envelope(new \stdClass(), [new ReceivedStamp('async')]));

        $this->assertInstanceOf(BlameContext::class, $this->handledContext);
        $this->assertNull($this->handledContext->user, 'Without stamp, the user provider is blamed.');
    }

    public function testTheContextIsPoppedWhenAHandlerFails(): void
    {
        $failing = new class implements MiddlewareInterface {
            public function handle(Envelope $envelope, StackInterface $stack): Envelope
            {
                throw new \RuntimeException('Handler failed');
            }
        };

        try {
            new BlameContextMiddleware($this->configuration)->handle(
                new Envelope(new \stdClass(), [new ReceivedStamp('async')]),
                new StackMiddleware($failing),
            );
            $this->fail('The exception is rethrown.');
        } catch (\RuntimeException $runtimeException) {
            $this->assertSame('Handler failed', $runtimeException->getMessage());
        }

        $this->assertTrue($this->configuration->getBlameContextStack()->isEmpty());
    }

    /**
     * @internal called by the probe middleware
     */
    public function probe(?BlameContext $context): void
    {
        $this->handled = true;
        $this->handledContext = $context;
    }

    private function handle(Envelope $envelope): Envelope
    {
        $stack = $this->configuration->getBlameContextStack();
        $probe = new readonly class($this, $stack) implements MiddlewareInterface {
            public function __construct(
                private BlameContextMiddlewareTest $test,
                private BlameContextStack $stack,
            ) {}

            public function handle(Envelope $envelope, StackInterface $stack): Envelope
            {
                $this->test->probe($this->stack->current());

                return $envelope;
            }
        };

        return new BlameContextMiddleware($this->configuration)->handle($envelope, new StackMiddleware($probe));
    }
}
