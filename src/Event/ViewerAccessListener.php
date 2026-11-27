<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Event;

use DH\AuditorBundle\Controller\ExportController;
use DH\AuditorBundle\Controller\ViewerController;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Logs each request of the viewer and of the export (route, its parameters, query string, user, IP), so that who read
 * or exported the audit can be told.
 *
 * Runs after ViewerEventSubscriber: a request to a disabled viewer is not logged. A request denied by the role checks
 * of the controller is.
 */
#[AsEventListener(event: KernelEvents::CONTROLLER, priority: -8)]
final readonly class ViewerAccessListener
{
    public function __construct(
        private ?LoggerInterface $logger = null,
        private ?TokenStorageInterface $tokenStorage = null,
    ) {}

    public function __invoke(ControllerEvent $event): void
    {
        $controller = $event->getController();
        if (\is_array($controller)) {
            $controller = $controller[0];
        }

        if (!$this->logger instanceof LoggerInterface || (!$controller instanceof ViewerController && !$controller instanceof ExportController)) {
            return;
        }

        $request = $event->getRequest();
        $this->logger->info('Audit viewer request: {route}', [
            'route' => $request->attributes->get('_route'),
            'route_params' => $request->attributes->get('_route_params', []),
            'query' => $request->query->all(),
            'user' => $this->tokenStorage?->getToken()?->getUserIdentifier(),
            'ip' => $request->getClientIp(),
        ]);
    }
}
