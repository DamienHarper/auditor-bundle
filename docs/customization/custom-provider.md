# Custom Audit Provider

> **Register a custom provider with the bundle using a single service tag**

`auditor-bundle` ships with first-class support for custom providers. Any service tagged with
`dh_auditor.provider` is automatically registered with the `Auditor` service at compile time —
no manual wiring, no `services.yaml` boilerplate beyond the tag.

---

## 🔌 How it works

A **compiler pass** (`RegisterProvidersCompilerPass`) reads all services tagged with
`dh_auditor.provider` and adds them as providers to the `Auditor` service. This happens at
container compile time, before the application boots.

```
Container compilation
  └─ RegisterProvidersCompilerPass
       └─ finds all services tagged with dh_auditor.provider
            └─ calls Auditor::registerProvider($provider) for each one
```

This means you can ship a standalone provider package and integrate it with the bundle by
adding a single tag — without forking the bundle or patching `services.yaml`.

---

## 🚀 Quick start

### 1. Implement your provider

Extend `AbstractProvider` from the core library and implement the three required methods:

```php
namespace App\Audit;

use DH\Auditor\Event\LifecycleEvent;
use DH\Auditor\Provider\AbstractProvider;
use Symfony\Contracts\Service\ResetInterface;

final class ElasticsearchProvider extends AbstractProvider implements ResetInterface
{
    public function __construct(private readonly ElasticClient $client)
    {
        $this->configuration = new ElasticsearchConfiguration($client);
        $this->registerStorageService(new ElasticsearchStorageService('default', $client));
    }

    public function supportsStorage(): bool
    {
        return true;
    }

    public function supportsAuditing(): bool
    {
        // This provider only writes audit entries; DoctrineProvider handles change detection.
        return false;
    }

    public function persist(LifecycleEvent $event): void
    {
        $payload = $event->getPayload();

        $this->client->index([
            'index' => 'audit',
            'body'  => [
                'type'       => $payload['type'],
                'entity'     => $payload['entity'],   // FQCN — always present when DoctrineProvider is the auditing source
                'object_id'  => $payload['object_id'],
                'diffs'      => json_decode($payload['diffs'], true),
                'blame_user' => $payload['blame_user'],
                'created_at' => $payload['created_at']->format(\DateTimeInterface::ATOM),
            ],
        ]);
    }

    public function reset(): void
    {
        // Clear any request-scoped state for long-running processes (Messenger workers, etc.)
        $this->client->reset();
    }
}
```

> [!TIP]
> Implementing `Symfony\Contracts\Service\ResetInterface` is recommended for providers used
> in long-running processes (Symfony Messenger workers). The bundle registers `DoctrineProvider`
> with `kernel.reset` for this reason. Do the same for your provider if it holds state between
> requests.

### 2. Register the service with the tag

```yaml
# config/services.yaml
App\Audit\ElasticsearchProvider:
    tags: [dh_auditor.provider]
```

That's it. The bundle's compiler pass handles the rest.

> [!NOTE]
> If your provider is distributed as a Symfony bundle, you can register the tag
> programmatically in your bundle's extension or compiler pass — users do not need
> to touch their `services.yaml`.

---

## 🔀 Mixing providers (auditing + custom storage)

A common pattern is to keep **DoctrineProvider for change detection** (it listens to Doctrine
events) and add a **custom provider for storage** (e.g. writing to Elasticsearch or a remote
API):

```yaml
# config/packages/dh_auditor.yaml
dh_auditor:
    providers:
        doctrine:
            storage_services:
                - '@doctrine.orm.default_entity_manager'
            auditing_services:
                - '@doctrine.orm.default_entity_manager'
            entities:
                App\Entity\Order: ~
                App\Entity\Payment: ~

# config/services.yaml
App\Audit\ElasticsearchProvider:
    tags: [dh_auditor.provider]
```

`auditor` will dispatch every `LifecycleEvent` to **all registered providers** that support
storage. Both `DoctrineProvider` and `ElasticsearchProvider` will receive the event and persist
the audit entry to their respective backends.

---

## 📦 Providing a bundle (distributable package)

If you ship a standalone package, wire the tag automatically in your extension:

```php
namespace Acme\AuditElasticBundle\DependencyInjection;

use Acme\AuditElasticBundle\Provider\ElasticsearchProvider;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;

final class AcmeAuditElasticExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $container
            ->register(ElasticsearchProvider::class)
            ->addArgument(new Reference('elastic_client'))
            ->addTag('dh_auditor.provider')
        ;
    }
}
```

Users of your bundle only need to install the package and enable the bundle — no additional
configuration required in their `services.yaml`.

---

## ⚙️ Container parameters exposed by the bundle

| Parameter | Type | Description |
|-----------|------|-------------|
| `dh_auditor.viewer_enabled` | `bool` | `true` when the Doctrine provider's built-in viewer is enabled |

> [!NOTE]
> `dh_auditor.viewer_enabled` is set to `true` only when the `doctrine` provider is configured
> with `viewer: true` (or an array with `enabled: true`). Custom providers do not affect this
> parameter — the viewer is currently a Doctrine-specific feature.

---

## 📚 Further reading

- [Building a custom provider (core library)](https://github.com/DamienHarper/auditor/blob/master/docs/providers/custom-provider.md) — full provider API, `LifecycleEvent` payload reference, packaging guide
- [Configuration reference](../configuration/) — `dh_auditor` YAML options
