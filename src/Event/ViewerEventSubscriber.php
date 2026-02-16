<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Event;

use DH\Auditor\Auditor;
use DH\Auditor\Configuration as AuditorConfiguration;
use DH\Auditor\Provider\Doctrine\Configuration as DoctrineProviderConfiguration;
use DH\Auditor\Provider\Doctrine\DoctrineProvider;
use DH\AuditorBundle\Controller\ViewerController;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

#[AsEventListener(event: KernelEvents::CONTROLLER)]
final readonly class ViewerEventSubscriber
{
    public function __construct(private Auditor $auditor) {}

    public function __invoke(ControllerEvent $event): void
    {
        $controller = $event->getController();

        // when a controller class defines multiple action methods, the controller
        // is returned as [$controllerInstance, 'methodName']
        if (\is_array($controller)) {
            $controller = $controller[0];
        }

        if (!$controller instanceof ViewerController) {
            return;
        }

        /** @var AuditorConfiguration $auditorConfiguration */
        $auditorConfiguration = $this->auditor->getConfiguration();

        /** @var DoctrineProviderConfiguration $providerConfiguration */
        $providerConfiguration = $this->auditor->getProvider(DoctrineProvider::class)->getConfiguration();

        $isAuditorEnabled = $auditorConfiguration->enabled;
        $isViewerEnabled = $providerConfiguration->isViewerEnabled();

        if (!$isAuditorEnabled || !$isViewerEnabled) {
            throw new NotFoundHttpException();
        }
    }
}
