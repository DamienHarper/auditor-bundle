<?php

namespace DH\DoctrineAuditBundle\Event;

use DH\DoctrineAuditBundle\Manager\AuditManager;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class AuditSubscriber implements EventSubscriberInterface
{
    /**
     * @var AuditManager
     */
    private $manager;

    public function __construct(AuditManager $manager)
    {
        $this->manager = $manager;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            LifecycleEvent::class => 'onAuditEvent',
        ];
    }

    /**
     * @param LifecycleEvent $event
     *
     * @throws \Doctrine\DBAL\DBALException
     *
     * @return LifecycleEvent
     */
    public function onAuditEvent(LifecycleEvent $event): LifecycleEvent
    {
        $payload = $event->getPayload();
        $auditTable = $payload['table'];
        unset($payload['table']);
        unset($payload['entity']);

        $fields = [
            'type' => ':type',
            'object_id' => ':object_id',
            'discriminator' => ':discriminator',
            'transaction_hash' => ':transaction_hash',
            'diffs' => ':diffs',
            'blame_id' => ':blame_id',
            'blame_user' => ':blame_user',
            'blame_user_fqdn' => ':blame_user_fqdn',
            'blame_user_firewall' => ':blame_user_firewall',
            'ip' => ':ip',
            'created_at' => ':created_at',
        ];

        $query = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $auditTable,
            implode(', ', array_keys($fields)),
            implode(', ', array_values($fields))
        );

        $storage = $this->manager->selectStorageSpace($this->manager->getConfiguration()->getEntityManager());
        $statement = $storage->getConnection()->prepare($query);

        foreach ($payload as $key => $value) {
            $statement->bindValue($key, $value);
        }

        $statement->execute();

        return $event;
    }
}
