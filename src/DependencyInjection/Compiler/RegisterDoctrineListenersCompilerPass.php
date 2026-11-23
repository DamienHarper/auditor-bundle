<?php

declare(strict_types=1);

namespace DH\AuditorBundle\DependencyInjection\Compiler;

use DH\Auditor\Provider\Doctrine\Auditing\Event\DoctrineSubscriber;
use DH\Auditor\Provider\Doctrine\DoctrineProvider;
use Doctrine\ORM\Events;
use Gedmo\SoftDeleteable\SoftDeleteableListener;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Declares the subscriber of the DoctrineProvider as a lazy Doctrine listener of the connections of the auditing
 * entity managers, instead of letting the provider attach it when it is built.
 *
 * The event manager of a connection then builds the provider on the first flush: the flushes run before anything else
 * needs the provider (a login, a kernel.request listener, with a warm metadata cache) are audited too. A listener
 * attached while the event manager initializes its lazy listeners would be dropped, hence a declared listener rather
 * than one that would only build the provider.
 *
 * It must run before the pass of DoctrineBundle that registers the tagged listeners.
 */
final class RegisterDoctrineListenersCompilerPass implements CompilerPassInterface
{
    public const string SUBSCRIBER_SERVICE_ID = 'dh_auditor.provider.doctrine.subscriber';

    public function process(ContainerBuilder $container): void
    {
        $configurationKey = 'dh_auditor.provider.doctrine.configuration';
        if (!$container->hasDefinition(DoctrineProvider::class) || !$container->hasParameter($configurationKey) || !$container->hasParameter('doctrine.connections')) {
            return;
        }

        $config = $container->getParameter($configurationKey);
        \assert(\is_array($config) && \array_key_exists('auditing_services', $config));

        $connectionServices = $container->getParameter('doctrine.connections');
        \assert(\is_array($connectionServices));

        /** @var list<string> $auditingServices */
        $auditingServices = $config['auditing_services'];
        $connections = [];
        foreach (array_unique($auditingServices) as $entityManagerName) {
            $connection = $this->connectionName($container, str_replace('@', '', $entityManagerName), $connectionServices);
            if (null === $connection) {
                // an entity manager the pass cannot resolve: the provider keeps attaching the listeners itself
                return;
            }

            $connections[$connection] = $connection;
        }

        if ([] === $connections) {
            return;
        }

        $events = [Events::onFlush, Events::postFlush];
        if (class_exists(SoftDeleteableListener::class)) {
            $events[] = SoftDeleteableListener::POST_SOFT_DELETE;
        }

        $subscriber = $container->register(self::SUBSCRIBER_SERVICE_ID, DoctrineSubscriber::class)
            ->setFactory([new Reference(DoctrineProvider::class), 'getDoctrineSubscriber'])
        ;
        foreach ($connections as $connection) {
            foreach ($events as $event) {
                $subscriber->addTag('doctrine.event_listener', ['event' => $event, 'connection' => $connection]);
            }
        }

        // before the registration of the auditing services, which would attach the listeners
        $provider = $container->getDefinition(DoctrineProvider::class);
        $provider->setMethodCalls([['disableListenerAttachment', []], ...$provider->getMethodCalls()]);
    }

    /**
     * @param array<mixed> $connectionServices connection name => connection service ID
     */
    private function connectionName(ContainerBuilder $container, string $entityManagerName, array $connectionServices): ?string
    {
        if (!$container->has($entityManagerName)) {
            return null;
        }

        $arguments = $container->findDefinition($entityManagerName)->getArguments();
        $argument = $arguments[0] ?? null;
        if (!$argument instanceof Reference) {
            return null;
        }

        $connection = array_search((string) $argument, $connectionServices, true);

        return \is_string($connection) ? $connection : null;
    }
}
