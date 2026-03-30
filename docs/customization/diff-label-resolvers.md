# Diff Label Resolvers

> **Display human-readable labels instead of raw IDs in audit diffs**

By default, audit diffs store raw field values. For integer foreign keys this means an entry like:

```json
{"categoryId": {"old": 1, "new": 2}}
```

With `#[DiffLabel]` resolvers, the same diff becomes:

```json
{
  "categoryId": {
    "old": {"value": 1, "label": "Books"},
    "new": {"value": 2, "label": "Electronics"}
  }
}
```

Labels are resolved at **write-time** (during flush) and stored in the JSON — they remain accurate even if the referenced record is later renamed or deleted.

---

## 📋 Overview

| Component | Description |
|-----------|-------------|
| `#[DiffLabel(resolver: MyResolver::class)]` | Property attribute: declares which resolver handles this field |
| `DiffLabelResolverInterface` | Interface to implement: `__invoke(mixed $value): ?string` |
| Auto-tagging | Implementing classes are auto-tagged — no `services.yaml` needed |

---

## 🚀 Quick Start

### Step 1 — Implement a resolver

```php
<?php

namespace App\Audit\Resolver;

use DH\Auditor\Contract\DiffLabelResolverInterface;

final class CategoryResolver implements DiffLabelResolverInterface
{
    public function __construct(
        private readonly CategoryRepository $repository,
    ) {}

    public function __invoke(mixed $value): ?string
    {
        $category = $this->repository->find($value);

        return $category?->getName();
    }
}
```

Symfony's **autoconfiguration** automatically detects classes that implement `DiffLabelResolverInterface` and tags them with `dh_auditor.diff_label_resolver`. No `services.yaml` entry is required beyond standard autowiring.

### Step 2 — Annotate the entity property

```php
<?php

namespace App\Entity;

use App\Audit\Resolver\CategoryResolver;
use DH\Auditor\Attribute\Auditable;
use DH\Auditor\Attribute\DiffLabel;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[Auditable]
class Product
{
    #[ORM\Column(type: Types::INTEGER)]
    #[DiffLabel(resolver: CategoryResolver::class)]
    private int $categoryId;

    // ...
}
```

### Step 3 — Done

After the next flush, any change to `categoryId` will include the resolved label in the stored diff.

---

## 🔄 How It Works

Labels are resolved during the **post-commit callback** of the DBAL middleware (after the Doctrine transaction commits but before the audit rows are written). The label is stored as a `{"value": x, "label": "y"}` pair in the JSON diff.

```
flush() → DB commits → post-commit callback:
    diff() detects #[DiffLabel] on 'categoryId'
    → calls CategoryResolver(1) → "Books"
    → stores {"old": {"value": 1, "label": "Books"}, "new": {"value": 2, "label": "Electronics"}}
    → audit row inserted
```

**The raw value is always preserved.** The label is stored alongside `value`, never replacing it. Your application code that reads `$entry->getDiffs()` and accesses `$diffs['categoryId']['new']` will receive `['label' => 'Electronics', 'value' => 2]` instead of `2`.

---

## 📋 Resolver Interface

```php
namespace DH\Auditor\Contract;

interface DiffLabelResolverInterface
{
    public function __invoke(mixed $value): ?string;
}
```

| Return value | Effect |
|---|---|
| `"Some Label"` | Stored as `{"value": x, "label": "Some Label"}` |
| `null` | Raw scalar stored as-is (no label wrapper) |

Returning `null` is the correct way to signal "I cannot resolve a label for this value" — for example, when the referenced record does not exist. The audit diff will store the plain integer without a label.

---

## ⚠️ Resolver Constraints

Resolvers run **inside the DBAL post-commit callback**, which means:

- ✅ **Safe**: Pure in-memory lookups (arrays, cache)
- ✅ **Safe**: Read-only queries on a separate connection or read-only EM
- ✅ **Safe**: External API calls (but beware latency — this blocks the request)
- ❌ **Unsafe**: Flushing or persisting on the **same EntityManager** that is being flushed
- ❌ **Unsafe**: Operations that start a new Doctrine transaction on the same connection

The safest pattern for DB lookups is to use a dedicated read-only EntityManager or a raw DBAL connection:

```php
final class CategoryResolver implements DiffLabelResolverInterface
{
    public function __construct(
        private readonly Connection $connection,  // raw DBAL connection
    ) {}

    public function __invoke(mixed $value): ?string
    {
        $name = $this->connection->fetchOne(
            'SELECT name FROM category WHERE id = ?',
            [$value],
        );

        return $name !== false ? $name : null;
    }
}
```

---

## 🖥️ Viewer Integration

The audit viewer automatically displays labels when they are present in the diff. No template changes are needed — the `helper.dump()` macro already detects the `{"value": x, "label": "y"}` shape and renders the label instead of the raw value.

---

## 📌 Manual Tag (Optional)

Autoconfiguration handles tagging automatically. If you need to disable autoconfiguration for a specific service, add the tag manually:

```yaml
# config/services.yaml
App\Audit\Resolver\CategoryResolver:
    tags:
        - { name: dh_auditor.diff_label_resolver }
```

> [!IMPORTANT]
> The service ID **must equal the resolver's fully-qualified class name**. This is always the case with autowiring and is what the `::class` constant in `#[DiffLabel(resolver: CategoryResolver::class)]` references.

---

## 📝 Complete Example

```php
<?php
// src/Audit/Resolver/ProductCategoryResolver.php

namespace App\Audit\Resolver;

use App\Repository\CategoryRepository;
use DH\Auditor\Contract\DiffLabelResolverInterface;

final class ProductCategoryResolver implements DiffLabelResolverInterface
{
    public function __construct(
        private readonly CategoryRepository $repository,
    ) {}

    public function __invoke(mixed $value): ?string
    {
        return $this->repository->find($value)?->getName();
    }
}
```

```php
<?php
// src/Entity/Product.php

namespace App\Entity;

use App\Audit\Resolver\ProductCategoryResolver;
use DH\Auditor\Attribute\Auditable;
use DH\Auditor\Attribute\DiffLabel;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[Auditable]
class Product
{
    #[ORM\Column(type: Types::INTEGER)]
    #[DiffLabel(resolver: ProductCategoryResolver::class)]
    private int $categoryId;

    #[ORM\Column(type: Types::INTEGER)]
    #[DiffLabel(resolver: SupplierResolver::class)]
    private int $supplierId;

    // ...
}
```

The resulting audit diff for an update to both fields:

```json
{
  "categoryId": {
    "old": {"value": 1, "label": "Books"},
    "new": {"value": 3, "label": "Software"}
  },
  "supplierId": {
    "old": {"value": 42, "label": "Acme Corp"},
    "new": {"value": 67, "label": "Globex Inc"}
  }
}
```

---

## 🚀 Next Steps

- 🏷️ [Entity Attributes](../configuration/attributes.md) — Full attribute reference
- 📦 [Extra Data](extra-data.md) — Attach request-level context to audit entries
