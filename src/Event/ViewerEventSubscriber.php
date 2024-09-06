<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Event;

use DH\Auditor\Auditor;
use DH\Auditor\Provider\Doctrine\Configuration as DoctrineProviderConfiguration;
use DH\Auditor\Provider\Doctrine\DoctrineProvider;
use DH\AuditorBundle\Controller\ViewerController;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\KernelEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

class ViewerEventSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly Auditor $auditor) {}

    public function onKernelController(KernelEvent $event): void
    {
        // Symfony 3.4+ compatibility (no ControllerEvent typehint)
        if (!method_exists($event, 'getController')) {
            throw new NotFoundHttpException();
        }

        $controller = $event->getController();

        // when a controller class defines multiple action methods, the controller
        // is returned as [$controllerInstance, 'methodName']
        if (\is_array($controller)) {
            $controller = $controller[0];
        }

        $auditorConfiguration = $this->auditor->getConfiguration();

        /** @var DoctrineProviderConfiguration $providerConfiguration */
        $providerConfiguration = $this->auditor->getProvider(DoctrineProvider::class)->getConfiguration();

        $isAuditorEnabled = $auditorConfiguration->isEnabled();
        $isViewerEnabled = $providerConfiguration->isViewerEnabled();

        if ($controller instanceof ViewerController && (!$isAuditorEnabled || !$isViewerEnabled)) {
            throw new NotFoundHttpException();
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::CONTROLLER => 'onKernelController',
        ];
    }
}
