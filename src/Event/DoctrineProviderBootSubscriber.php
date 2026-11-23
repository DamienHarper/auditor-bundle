<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Event;

use DH\Auditor\Provider\Doctrine\DoctrineProvider;
use Psr\Container\ContainerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Builds the DoctrineProvider at the start of each web request, before the firewall and the listeners that may flush.
 *
 * The provider attaches its Doctrine listeners when it is built. With a warm metadata cache, nothing else builds it
 * before kernel.controller, and the flushes made earlier (a form_login / json_login listener, a kernel.request
 * listener) would not be audited.
 */
final class DoctrineProviderBootSubscriber implements EventSubscriberInterface
{
    /**
     * Above the listeners of Symfony and, in practice, of the applications.
     */
    public const PRIORITY = 4_096;

    /**
     * @param ContainerInterface $locator a locator of the DoctrineProvider, which is built on the first web request
     */
    public function __construct(private readonly ContainerInterface $locator) {}

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', self::PRIORITY]];
    }

    public function onKernelRequest(): void
    {
        // building the provider attaches its Doctrine listeners
        $this->locator->get(DoctrineProvider::class);
    }
}
