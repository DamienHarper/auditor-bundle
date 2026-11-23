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
 * The event manager of a connection registers the listener when it is created and builds the provider on the first
 * flush: the flushes run before anything else needs the provider (a login, a kernel.request listener, with a warm
 * metadata cache) are audited too. A declared listener is used rather than one that would only build the provider:
 * doctrine-bridge before 6.4.44, 7.4.17 and 8.1.5 drops a listener attached while it initializes the lazy listeners of
 * the same event.
 *
 * The listeners are declared only when every auditing entity manager is one of DoctrineBundle, using the event
 * manager of its connection; otherwise the provider keeps attaching them when it is built, and the container
 * compilation log says why.
 *
 * It must run before the pass of DoctrineBundle that registers the tagged listeners.
 */
final class RegisterDoctrineListenersCompilerPass implements CompilerPassInterface
{
    public const string SUBSCRIBER_SERVICE_ID = 'dh_auditor.provider.doctrine.subscriber';

    /**
     * The subscriber runs after the other listeners of an event, as when the provider attached it at runtime: it
     * audits the changes they make in onFlush.
     */
    public const int PRIORITY = -1_024;

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
            $entityManagerName = str_replace('@', '', $entityManagerName);
            $connection = $this->connectionName($container, $entityManagerName, $connectionServices);
            if (null === $connection) {
                $container->log($this, \sprintf('The auditing entity manager "%s" does not use the event manager of a DoctrineBundle connection: the DoctrineProvider attaches its listeners when it is built, and the flushes run before that are not audited.', $entityManagerName));

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
                $subscriber->addTag('doctrine.event_listener', ['event' => $event, 'connection' => $connection, 'priority' => self::PRIORITY]);
            }
        }

        // before the registration of the auditing services, which would attach the listeners
        $provider = $container->getDefinition(DoctrineProvider::class);
        $provider->setMethodCalls([['disableListenerAttachment', []], ...$provider->getMethodCalls()]);
    }

    /**
     * The DoctrineBundle connection of an entity manager that dispatches its events on the event manager of that
     * connection, null otherwise.
     *
     * @param array<mixed> $connectionServices connection name => connection service ID
     */
    private function connectionName(ContainerBuilder $container, string $entityManagerName, array $connectionServices): ?string
    {
        if (!$container->has($entityManagerName)) {
            return null;
        }

        $arguments = $container->findDefinition($entityManagerName)->getArguments();
        $connectionArgument = $arguments[0] ?? null;
        $eventManagerArgument = $arguments[2] ?? null;
        if (!$connectionArgument instanceof Reference || !$eventManagerArgument instanceof Reference) {
            return null;
        }

        $connection = array_search($this->serviceId($container, (string) $connectionArgument), $connectionServices, true);
        if (!\is_string($connection)) {
            return null;
        }

        $eventManager = \sprintf('doctrine.dbal.%s_connection.event_manager', $connection);

        return $eventManager === $this->serviceId($container, (string) $eventManagerArgument) ? $connection : null;
    }

    /**
     * The service an ID points to, through its aliases.
     */
    private function serviceId(ContainerBuilder $container, string $id): string
    {
        while ($container->hasAlias($id)) {
            $id = (string) $container->getAlias($id);
        }

        return $id;
    }
}
