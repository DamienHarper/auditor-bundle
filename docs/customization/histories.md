# Histories

> **Show users the history of a business object and who changed each field**

auditor 5.0 and its Doctrine provider can gather the audit entries of an object and of the entities attached to it
(a property listing with its features, publications and visits), group them into actions, and tell who changed a
field last. This page covers what the bundle wires; the concepts are in the
[auditor](https://damienharper.github.io/auditor-docs/auditor/history) and
[provider](https://damienharper.github.io/auditor-docs/auditor-doctrine-provider/querying/history) documentation.

## 🌳 Declaring roots

With `#[AuditRoot('path')]` on the entity, or with the `roots` entity option:

```yaml
dh_auditor:
    providers:
        doctrine:
            entities:
                App\Entity\Property: ~
                App\Entity\PropertyFeature:
                    roots: [property]
                App\Entity\VisitReport:
                    roots: [visit.property]
```

The syntax of the paths is checked when the container is built; a path that does not follow single-valued
associations fails at the first flush. After declaring a root on an entity that already has audit entries, update the schema (it adds the `audit_root` table) and index the existing entries:

```bash
php bin/console audit:schema:update --force
php bin/console audit:roots:rebuild
```

For roots that a path cannot express, `root_resolver` takes the ID of an invokable service returning extra roots
keyed by a name of your choice:

```php
final class AttachmentRootResolver
{
    /** @return array<string, ?object> */
    public function __invoke(object $entity): array
    {
        return $entity instanceof Attachment ? ['subject' => $entity->getSubject()] : [];
    }
}
```

```yaml
dh_auditor:
    providers:
        doctrine:
            root_resolver: App\Audit\AttachmentRootResolver
```

## 📜 Reading the history of a root

The `Reader` service is autowirable:

```php
use DH\Auditor\History\ChangeExtractor;
use DH\Auditor\History\HistoryGrouper;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Reader;

public function history(Reader $reader, Property $property, ?string $after): Response
{
    $page = $reader->createRootQuery(Property::class, $property->getId())->fetch(20, $after);
    $grouper = new HistoryGrouper(extractor: new ChangeExtractor([Property::class => ['status', 'price']]));

    return $this->render('property/history.html.twig', [
        'items' => $page->group($grouper),   // list<HistoryItem>: one action per transaction
        'next' => $page->hasMore ? $page->lastCursor : null,
    ]);
}
```

Only the `view` role of the root is checked. Use `read_scope` (an invokable service receiving each query and the
entity it reads) to restrict what can be read, for instance to the current tenant.

## 🏷️ Labels

Field labels come from the `dh_auditor_fields` translation domain, keyed by `FQCN.field`; a field without
translation is humanized (`surfaceArea` gives "Surface area"):

```yaml
# translations/dh_auditor_fields.fr.yaml
App\Entity\Property.price: Prix
App\Entity\Property.surfaceArea: Surface habitable
```

The resolver is the `DH\Auditor\Contract\FieldLabelResolverInterface` service: alias it to your own implementation
to label fields another way.

`entity_label_resolver` takes the ID of a service implementing `EntityLabelResolverInterface`: it builds the label
of an entity written in the diffs ("12 rue des Lilas" rather than `App\Entity\Property#42`), at write time.

## 🔎 In templates

```twig
{% set change = dh_auditor_last_change(property, 'price') %}
{% if change %}
    {{ dh_auditor_field_label(property, 'price') }}: {{ change.value('price') }}, changed
    by {{ change.entry.username ?? 'system' }} {{ change.entry.createdAt|time_ago }}
{% endif %}

{% for field, change in dh_auditor_last_changes(property, ['price', 'status']) %}
    {{ dh_auditor_field_label(property, field) }}: {{ change ? change.entry.username : '-' }}
{% endfor %}
```

| Function | Returns |
|---|---|
| `dh_auditor_last_change(entity, field)` | the last `Change` of the field (its creation when never changed), or null |
| `dh_auditor_last_changes(entity, fields)` | `field => ?Change` |
| `dh_auditor_field_label(entity_or_class, field)` | the label of the field |

The `last_change` functions return null when the entity is not audited, has no identifier yet, or when the current
user cannot view its audits. For a field never changed, the change is the creation of the entity, which holds every
field: read the value with `change.value(field)` (`Change::getValue()`) and its label with `change.label(field)`.
