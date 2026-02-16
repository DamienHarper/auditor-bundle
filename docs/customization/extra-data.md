# Extra Data

> **Store additional contextual information in audit entries**

The `extra_data` feature allows you to attach arbitrary supplementary data to each audit entry. This is useful for capturing contextual information that isn't part of the entity's fields, such as department, role, request metadata, or any business-specific information.

## 📋 Overview

Each audit entry has a nullable JSON `extra_data` column. By default, it is `NULL` (zero overhead when not used). To populate it, you create an event listener on `LifecycleEvent` that sets the `extra_data` key in the payload before the entry is persisted.

> [!NOTE]
> This feature is provided by the **auditor** library. This page shows Symfony-specific integration examples. For complete documentation, see the [auditor extra-data documentation](https://github.com/DamienHarper/auditor).

## 🔧 Setting Up a Listener

Create an event listener using Symfony's `#[AsEventListener]` attribute:

```php
<?php

namespace App\EventListener;

use App\Entity\User;
use DH\Auditor\Event\LifecycleEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener(event: LifecycleEvent::class, priority: 10)]
final class AuditExtraDataListener
{
    public function __invoke(LifecycleEvent $event): void
    {
        $payload = $event->getPayload();

        // Filter by entity class
        if ($payload['entity'] !== User::class || null === $event->entity) {
            return;
        }

        // Attach extra data as a JSON string
        $payload['extra_data'] = json_encode([
            'department' => $event->entity->getDepartment(),
            'role' => $event->entity->getRole(),
        ], JSON_THROW_ON_ERROR);

        $event->setPayload($payload);
    }
}
```

### With Service Injection

Since the listener is a standard Symfony service, you can inject any dependency:

```php
<?php

namespace App\EventListener;

use App\Entity\Order;
use DH\Auditor\Event\LifecycleEvent;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RequestStack;

#[AsEventListener(event: LifecycleEvent::class, priority: 10)]
final class OrderAuditExtraDataListener
{
    public function __construct(
        private readonly Security $security,
        private readonly RequestStack $requestStack,
    ) {}

    public function __invoke(LifecycleEvent $event): void
    {
        $payload = $event->getPayload();

        if ($payload['entity'] !== Order::class) {
            return;
        }

        $request = $this->requestStack->getCurrentRequest();

        $payload['extra_data'] = json_encode([
            'admin_user' => $this->security->getUser()?->getUserIdentifier(),
            'route' => $request?->attributes->get('_route'),
            'reason' => $request?->headers->get('X-Audit-Reason'),
        ], JSON_THROW_ON_ERROR);

        $event->setPayload($payload);
    }
}
```

## 📖 Reading Extra Data

The `Entry` model provides access via the `extraData` property or the `getExtraData()` method:

```php
$reader = new Reader($provider);
$entries = $reader->createQuery(User::class)->execute();

foreach ($entries as $entry) {
    $extraData = $entry->extraData; // ?array (decoded JSON)

    if (null !== $extraData) {
        echo sprintf(
            "Department: %s, Role: %s\n",
            $extraData['department'] ?? 'N/A',
            $extraData['role'] ?? 'N/A',
        );
    }
}
```

## 🔍 Filtering by Extra Data (JsonFilter)

You can filter audit entries by `extra_data` content using the `JsonFilter` class:

```php
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Filter\JsonFilter;

$reader = new Reader($provider);
$query = $reader->createQuery(User::class, ['page_size' => null]);

// Filter by exact value
$query->addFilter(new JsonFilter('extra_data', 'department', 'IT'));

// Filter with LIKE pattern
$query->addFilter(new JsonFilter('extra_data', 'department', 'IT%', 'LIKE'));

// Filter by multiple values (IN)
$query->addFilter(new JsonFilter('extra_data', 'status', ['active', 'pending'], 'IN'));

// Nested JSON path
$query->addFilter(new JsonFilter('extra_data', 'user.role', 'admin'));

$entries = $query->execute();
```

### Supported Operators

| Operator | Description | Example |
|----------|-------------|---------|
| `=` | Exact match (default) | `new JsonFilter('extra_data', 'dept', 'IT')` |
| `!=` | Not equal | `new JsonFilter('extra_data', 'dept', 'IT', '!=')` |
| `LIKE` | Pattern matching | `new JsonFilter('extra_data', 'dept', 'IT%', 'LIKE')` |
| `NOT LIKE` | Negative pattern | `new JsonFilter('extra_data', 'dept', '%temp%', 'NOT LIKE')` |
| `IN` | Multiple values | `new JsonFilter('extra_data', 'dept', ['IT', 'HR'], 'IN')` |
| `NOT IN` | Exclude values | `new JsonFilter('extra_data', 'dept', ['IT'], 'NOT IN')` |
| `IS NULL` | Value is null | `new JsonFilter('extra_data', 'dept', null, 'IS NULL')` |
| `IS NOT NULL` | Value exists | `new JsonFilter('extra_data', 'dept', null, 'IS NOT NULL')` |

## 🗄️ Schema Update

The `extra_data` column is added automatically when you run the schema update command:

```bash
# Preview the SQL that will be executed
php bin/console audit:schema:update --dump-sql

# Apply the change
php bin/console audit:schema:update --force
```

## ⚠️ Important Caveats

### JSON Encoding

> [!WARNING]
> The `extra_data` value in the payload must be either `null` or a **JSON-encoded string** (not an array). Always use `json_encode()` when setting it:
>
> ```php
> // ✅ Correct
> $payload['extra_data'] = json_encode(['key' => 'value'], JSON_THROW_ON_ERROR);
>
> // ❌ Incorrect - will not be stored properly
> $payload['extra_data'] = ['key' => 'value'];
> ```

### Entity State in `remove()` Operations

> [!WARNING]
> During a `remove` operation, the entity object is still in memory but has been **detached from the Unit of Work**.
>
> - Direct property access works (e.g., `$entity->getName()`)
> - **Lazy-loaded associations may not be accessible**
>
> If you need association data during deletions, ensure those associations are eagerly loaded or fetch the data before the flush.

### Do Not Write to the Audited EntityManager

> [!CAUTION]
> The `LifecycleEvent` is dispatched **during** a flush. The listener executes synchronously within the same database transaction.
>
> - **SELECTs are safe** (reading from another entity manager or connection)
> - **INSERT/UPDATE/DELETE on the audited EntityManager will interfere** with the ongoing flush
>
> If you need to perform write operations based on audit data, defer them (e.g., using Symfony Messenger).

## 📚 Full Documentation

For complete documentation including:
- Database-specific indexing strategies
- Performance considerations
- Advanced filtering options

See the [auditor extra-data documentation](https://github.com/DamienHarper/auditor).
