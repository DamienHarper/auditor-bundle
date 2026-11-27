<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Tests\Event;

use DH\AuditorBundle\Event\ViewerAccessListener;
use PHPUnit\Framework\Attributes\Small;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * @internal
 */
#[Small]
final class ViewerAccessListenerWiringTest extends KernelTestCase
{
    public function testTheListenerIsRegisteredWithALogger(): void
    {
        self::bootKernel();

        $dispatcher = self::getContainer()->get('event_dispatcher');
        $this->assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        $listeners = array_filter(
            $dispatcher->getListeners(KernelEvents::CONTROLLER),
            static fn (mixed $listener): bool => $listener instanceof ViewerAccessListener || (\is_array($listener) && $listener[0] instanceof ViewerAccessListener),
        );
        $this->assertCount(1, $listeners);

        $listener = array_values($listeners)[0];
        $listener = \is_array($listener) ? $listener[0] : $listener;
        $this->assertNotNull(new \ReflectionProperty(ViewerAccessListener::class, 'logger')->getValue($listener), 'The application logger is injected.');
    }
}
