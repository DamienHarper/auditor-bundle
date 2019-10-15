# Audit events

DoctrineAuditBundle fires an AuditEvent for every audit log entry.
This opens the doors to:

- log entries in non SQL datastore such as Elasticsearch for example.
- send an email/notification if a specific entity has been changed.

As a reference, you can have a look at the bundled [AuditSubscriber](../src/DoctrineAuditBundle/Event/AuditSubscriber.php)

## Listening to audit events

First you have to create an event subscriber that listens to `AuditEvent` events.

```php
<?php

namespace App\Event;

use DH\DoctrineAuditBundle\Event\LifecycleEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class MySubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            LifecycleEvent::class => 'onAuditEvent',
        ];
    }

    public function onAuditEvent(LifecycleEvent $event): LifecycleEvent
    {
        // do your stuff here...

        return $event;
    }
}
```

Then, any time an `AuditEvent` is fired, the `MySubscriber::onAuditEvent()` method 
will be run with the event as an argument.