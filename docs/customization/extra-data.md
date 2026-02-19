# Extra Data

> **Store additional contextual information in audit entries**

The `extra_data` feature allows you to attach arbitrary supplementary data to each audit entry. This is useful for capturing contextual information that isn't part of the entity's fields, such as route name, request ID, department, role, or any business-specific information.

## 📋 Overview

Each audit entry has a nullable JSON `extra_data` column. By default, it is `NULL` (zero overhead when not used).

There are two complementary approaches to populate it:

| Approach | Scope | Use for |
|----------|-------|---------|
| [`extra_data_provider`](#approach-1-extra_data_provider-config-option) | Global — all entries | Request-level context: route, request ID, tenant |
| [`LifecycleEvent` listener](#approach-2-lifecycleevent-listener) | Per-entity — fine-grained | Entity-specific data: department, role, entity properties |

Both can be used simultaneously. When they are, the provider runs first and the listener can enrich or override the result.

> [!NOTE]
> This feature is provided by the **auditor** library. This page shows Symfony-specific integration. For complete documentation, see the [auditor extra-data documentation](https://github.com/DamienHarper/auditor).

---

## Approach 1: `extra_data_provider` Config Option

The bundle ships a ready-to-use `ExtraDataProvider` service that captures the **current route name** and stores it in every audit entry.

### Enable the Built-in Provider

```yaml
# config/packages/dh_auditor.yaml
dh_auditor:
    extra_data_provider: dh_auditor.extra_data_provider
```

This stores `{"route": "app_order_edit"}` in `extra_data` for every audit entry produced during an HTTP request. Outside of an HTTP request (e.g. console commands), `extra_data` remains `null`.

### Use a Custom Provider

Create your own service that returns `?array`:

```php
<?php

namespace App\Audit;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

final readonly class MyExtraDataProvider
{
    public function __construct(
        private RequestStack $requestStack,
        private TokenStorageInterface $tokenStorage,
    ) {}

    public function __invoke(): ?array
    {
        $request = $this->requestStack->getCurrentRequest();
        if (null === $request) {
            return null;
        }

        return [
            'route'      => $request->attributes->get('_route'),
            'request_id' => $request->headers->get('X-Request-ID'),
            'user_agent' => $request->headers->get('User-Agent'),
        ];
    }
}
```

Then reference it in config:

```yaml
dh_auditor:
    extra_data_provider: App\Audit\MyExtraDataProvider
```

Or register it programmatically (e.g. in a test or a non-Symfony context):

```php
$auditor->getConfiguration()->setExtraDataProvider(
    static fn (): ?array => ['route' => 'app_order_edit']
);
```

### When to Use This Approach

- Capture the same contextual information for every audit entry (route, tenant, environment…)
- Simple, zero-boilerplate setup
- Does **not** have access to the individual entity being audited

---

## Approach 2: `LifecycleEvent` Listener

Create an event listener using Symfony's `#[AsEventListener]` attribute. The listener receives the full payload **and** the audited entity object, giving you full control.

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
            'role'       => $event->entity->getRole(),
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
            'route'      => $request?->attributes->get('_route'),
            'reason'     => $request?->headers->get('X-Audit-Reason'),
        ], JSON_THROW_ON_ERROR);

        $event->setPayload($payload);
    }
}
```

### Merging Provider and Listener Data

When a global provider is also configured, you can merge its result with entity-specific data in the listener:

```php
public function __invoke(LifecycleEvent $event): void
{
    $payload = $event->getPayload();

    if ($payload['entity'] !== User::class) {
        return;
    }

    // Decode what the provider already set (if anything)
    $existing = null !== $payload['extra_data']
        ? json_decode($payload['extra_data'], true, 512, JSON_THROW_ON_ERROR)
        : [];

    // Merge entity-specific data on top
    $merged = array_merge($existing, [
        'department' => $event->entity->getDepartment(),
    ]);

    $payload['extra_data'] = json_encode($merged, JSON_THROW_ON_ERROR);
    $event->setPayload($payload);
}
```

### When to Use This Approach

- Attach entity-specific data (e.g. `$entity->getDepartment()`)
- Apply `extra_data` only to certain entity classes
- Conditionally skip `extra_data` based on entity state

---

## 📖 Reading Extra Data

The `Entry` model provides access via the `extraData` property or the `getExtraData()` method:

```php
$reader = new Reader($provider);
$entries = $reader->createQuery(User::class)->execute();

foreach ($entries as $entry) {
    $extraData = $entry->extraData; // ?array (decoded JSON)

    if (null !== $extraData) {
        echo sprintf(
            "Route: %s, Department: %s\n",
            $extraData['route'] ?? 'N/A',
            $extraData['department'] ?? 'N/A',
        );
    }
}
```

---

## 🔍 Filtering by Extra Data (JsonFilter)

You can filter audit entries by `extra_data` content using the `JsonFilter` class:

```php
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Filter\JsonFilter;

$reader = new Reader($provider);
$query = $reader->createQuery(User::class, ['page_size' => null]);

// Filter by exact value
$query->addFilter(new JsonFilter('extra_data', 'route', 'app_user_edit'));

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

---

## 🗄️ Schema Update

The `extra_data` column is added automatically when you run the schema update command:

```bash
# Preview the SQL that will be executed
php bin/console audit:schema:update --dump-sql

# Apply the change
php bin/console audit:schema:update --force
```

---

## ⚠️ Important Caveats

### JSON Encoding (Listener Only)

> [!WARNING]
> When setting `extra_data` in a **listener**, the value must be either `null` or a **JSON-encoded string** (not an array). Always use `json_encode()`:
>
> ```php
> // ✅ Correct
> $payload['extra_data'] = json_encode(['key' => 'value'], JSON_THROW_ON_ERROR);
>
> // ❌ Incorrect - will not be stored properly
> $payload['extra_data'] = ['key' => 'value'];
> ```
>
> The `extra_data_provider` callable is exempt from this rule — return a plain `?array` and encoding is handled automatically.

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

---

## 📚 Full Documentation

For complete documentation including database-specific indexing strategies, performance considerations, and advanced filtering options, see the [auditor extra-data documentation](https://github.com/DamienHarper/auditor).
