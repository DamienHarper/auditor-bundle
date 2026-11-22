# Audit Viewer

> **Web interface for browsing audit logs**

The bundle includes a web interface for browsing audit logs.

## ✨ Enabling the Viewer

```yaml
# config/packages/dh_auditor.yaml
dh_auditor:
    providers:
        doctrine:
            viewer: true
```

Or with options:

```yaml
dh_auditor:
    providers:
        doctrine:
            viewer:
                enabled: true
                page_size: 50
```

## 🛤️ Routes

The viewer registers these routes:

| Route                                | URL                                | Description                         |
|--------------------------------------|------------------------------------|-------------------------------------|
| `dh_auditor_export`                  | `/audit/export`                    | Export of audit entries (streamed)  |
| `dh_auditor_list_audits`             | `/audit`                           | List all audited entities           |
| `dh_auditor_show_entity_stream`      | `/audit/{entity}/{id?}`            | Entity audit stream                 |
| `dh_auditor_show_transaction_stream` | `/audit/transaction/{hash}`        | Transaction details                 |
| `dh_auditor_show_user_stream`        | `/audit/user/{id}`                 | Activity timeline of one user       |
| `dh_auditor_select_revisions`        | `/audit/{entity}/{id}/revisions`   | Pick two revisions of an object     |
| `dh_auditor_compare_revisions`       | `/audit/{entity}/{id}/compare`     | Compare two revisions of an object  |

### Route Configuration

With Symfony Flex, routes are auto-configured. Otherwise, create `config/routes/dh_auditor.yaml`:

```yaml
dh_auditor:
    resource: "@DHAuditorBundle/Controller/"
    type: auditor
```

## 📄 Pages

### Entity List (`/audit`)

Displays all audited entities with:
- 📋 Entity class name
- 🗄️ Audit table name
- 🔢 Number of audit entries
- 🔗 Link to view history

The list can be sorted by name, number of entries or latest activity (`?sort=name_asc`, `name_desc`, `count_desc`,
`count_asc`, `activity_desc`, `activity_asc`).

### Entity Stream (`/audit/{entity}`)

Shows audit entries for an entity:
- 📅 Chronological list (newest first)
- 🏷️ Operation type (insert/update/remove/associate/dissociate)
- 👤 User and timestamp
- 📝 Changed properties (diff)

Filter by specific entity ID:
```
/audit/App-Entity-User/42
```

#### 🔍 Filters

The entity stream page includes filters to narrow down audit entries:

**Action Type Filter**
Filter by operation type:
- `insert` - Record created
- `update` - Record updated
- `remove` - Record deleted
- `associate` - Relation added
- `dissociate` - Relation removed

**User Filter**
Filter by the user who performed the action:
- All registered users who have made changes
- Anonymous (for actions without user attribution)
- CLI commands (each command is tracked by its name, e.g., `app:import-users`)

**Changed Field Filter**
Filter by the field touched by the change:
- Lists the audited fields and associations of the entity, from its Doctrine mapping
- Inserts and updates that changed the field, and removes recorded with a full snapshot
- For a collection, the items added to (`associate`) or removed from (`dissociate`) it

> [!NOTE]
> The changed field filter requires native JSON support (MySQL 5.7+, MariaDB 10.2.3+, PostgreSQL 9.4+, SQLite 3.38+) and an audit table migrated to the schema v2 structure (`audit:schema:migrate`). It is hidden otherwise. On large audit tables, combine it with a single entity (`/audit/{entity}/{id}`) or a period: the database has to read the diffs of every entry left by the other filters.

**Date Range Filter**
Keep the entries created between two days (inclusive), in the `dh_auditor.timezone` time zone.

> [!TIP]
> Filters can be combined and are preserved during pagination.

**URL parameters:**
```
/audit/App-Entity-User?type=update&user=42
/audit/App-Entity-User?type=insert&user=__anonymous__
/audit/App-Entity-User?field=email
/audit/App-Entity-User?date_from=2026-01-01&date_to=2026-03-31
```

### Compare Revisions (`/audit/{entity}/{id}/compare`)

From the stream of one object (`/audit/{entity}/{id}`), pick two of its revisions to see the state of the object
before and after the changes made between them, field by field. The page links to the previous and next revisions,
so that the comparison can be moved along the history.

### Transaction View (`/audit/transaction/{hash}`)

Groups all changes from a single database transaction across all entities.

### User Timeline (`/audit/user/{id}`)

Lists everything a user did, newest first, in a single timeline across all audited entities.
The user is identified by the `blame_id` recorded with each entry (up to 255 characters); in
every stream, the author of an entry links to this page.

- Only entities the current viewer is allowed to see (see [Access Control](#-access-control))
  are included, both in the list and in the events count.
- Anonymous entries (no `blame_id`) have no timeline. Neither do entries recorded with an empty
  `blame_id`, which is what happens when the Symfony user class has no `getId()` method.
- Each audited entity is queried separately, for at most `page × page_size` rows: browsing deep
  pages of a very active user costs more than browsing the first ones.

## 📤 Export

`/audit/export` streams audit entries, so that large exports do not load everything in memory:

| Parameter   | Description                                                                  |
|-------------|------------------------------------------------------------------------------|
| `format`    | `ndjson` (default, recommended for large exports), `json` or `csv`           |
| `entity`    | Entity to export (`App-Entity-User`); all the entities the user can view when omitted |
| `id`        | Object ID                                                                    |
| `from`, `to`| Creation date range (ISO 8601, e.g. `2026-01-01`)                            |
| `blame_id`  | User ID                                                                      |
| `anonymize` | `1` to remove the user (ID, name, class, firewall) and the IP address        |

```
/audit/export?entity=App-Entity-User&format=csv&from=2026-01-01
```

The export follows the same access rules as the viewer (see [Access Control](#-access-control)): an entity the user
cannot view is refused, or left out when no entity is given. From the command line, the provider's `audit:export`
command offers the same filters.

## 📊 Activity Graph

Each entity card displays a visual sparkline graph showing audit activity over time.

### Configuration

```yaml
dh_auditor:
    providers:
        doctrine:
            viewer:
                enabled: true
                activity_graph:
                    enabled: true       # Show/hide the graph (default: true)
                    days: 30            # Number of days to display (default: 30, max: 30)
                    layout: 'bottom'    # Graph layout: 'bottom' or 'inline' (default: 'bottom')
                    cache:
                        enabled: true   # Enable caching (default: true)
                        pool: 'cache.app'  # Cache pool service ID
                        ttl: 300        # Cache TTL in seconds (default: 300)
```

### Layout Options

| Layout   | Description                                                       |
|----------|-------------------------------------------------------------------|
| `bottom` | Sparkline displayed below the entity info, full width (default)  |
| `inline` | Compact sparkline displayed in the header row, next to event count |

### Caching

The activity graph uses caching to improve performance on large audit tables.

> [!NOTE]
> Caching requires `symfony/cache`:
> ```bash
> composer require symfony/cache
> ```
> If `symfony/cache` is not installed, the graph will still work but without caching.

#### Cache with Tags

If your cache pool supports tags (`TagAwareCacheInterface`), the bundle uses the tag `dh_auditor.activity` for efficient cache invalidation.

### 🗑️ Clear Cache Command

Clear the activity graph cache manually:

```bash
# Clear all activity graph cache
php bin/console audit:cache:clear

# Clear cache for a specific entity
php bin/console audit:cache:clear --entity="App\Entity\User"
```

> [!IMPORTANT]
> Clearing all cache requires a cache pool that supports tags. If your cache pool doesn't support tags, you can only clear cache for specific entities.

### Display Behavior

| State                            | Behavior                                         |
|----------------------------------|--------------------------------------------------|
| Graph disabled (`enabled: false`)| Activity graph section is completely hidden      |
| Graph enabled, no data           | Placeholder with "No recent activity" message    |
| Graph enabled, with data         | Bars are normalized (tallest bar = 100%)         |

## 🌙 Dark Mode

The viewer includes a dark/light mode toggle button in the header.

### Behavior

- **Default**: Follows system preference (`prefers-color-scheme`)
- **Manual toggle**: Click the sun/moon icon in the header
- **Persistence**: User preference is saved in `localStorage`

The toggle works without page reload and persists across sessions.

## 🔒 Access Control

### Via Configuration

```yaml
dh_auditor:
    providers:
        doctrine:
            entities:
                App\Entity\User:
                    roles:
                        view:
                            - ROLE_ADMIN
```

### Via Attributes

```php
#[Audit\Auditable]
#[Audit\Security(view: ['ROLE_ADMIN'])]
class User {}
```

### Via Symfony Security

```yaml
# config/packages/security.yaml
security:
    access_control:
        - { path: ^/audit, roles: ROLE_ADMIN }
```

> [!NOTE]
> In association diffs (`associate` / `dissociate`), the related entity links to its audit stream
> only when the current user can open it: the entity must be audited and its `view` roles granted.
> Otherwise it is displayed as plain text. Templates can apply the same rule with the
> `dh_auditor_is_viewable(entityClass)` Twig function.

## 🔗 URL Format

Entity namespaces use dashes instead of backslashes:

```
App\Entity\User → App-Entity-User
```

Helper methods:

```php
use DH\AuditorBundle\Helper\UrlHelper;

// Namespace to URL parameter
$param = UrlHelper::namespaceToParam('App\Entity\User');
// Returns: 'App-Entity-User'

// URL parameter to namespace
$namespace = UrlHelper::paramToNamespace('App-Entity-User');
// Returns: 'App\Entity\User'
```

## 🔧 Generating URLs

```php
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

// Entity list
$url = $urlGenerator->generate('dh_auditor_list_audits');

// Entity stream
$url = $urlGenerator->generate('dh_auditor_show_entity_stream', [
    'entity' => 'App-Entity-User',
]);

// Specific entity
$url = $urlGenerator->generate('dh_auditor_show_entity_stream', [
    'entity' => 'App-Entity-User',
    'id' => 42,
]);

// With filters
$url = $urlGenerator->generate('dh_auditor_show_entity_stream', [
    'entity' => 'App-Entity-User',
    'type' => 'update',
    'user' => '42',
]);

// Transaction
$url = $urlGenerator->generate('dh_auditor_show_transaction_stream', [
    'hash' => $entry->transactionId,
]);

// User timeline
$url = $urlGenerator->generate('dh_auditor_show_user_stream', [
    'id' => $entry->userId,
]);
```

## 🎨 Customization

### Override Templates

Create files in `templates/bundles/DHAuditorBundle/`:

```
templates/bundles/DHAuditorBundle/
├── Audit/
│   ├── audits.html.twig              # Entity list
│   ├── entity_stream.html.twig       # Entity audit stream
│   ├── transaction_stream.html.twig  # Transaction view
│   ├── entry.html.twig               # Single entry
│   └── helpers/
│       ├── helper.html.twig          # Helper macros
│       └── pager.html.twig           # Pagination
└── layout.html.twig                  # Base layout
```

> [!NOTE]
> The changeset of a JSON column is a deep diff: a tree whose leaves are `{new}`, `{old}` or `{new, old}`.
> A JSON object whose own keys are `new`/`old` therefore looks like a field diff, so a custom
> `entry.html.twig` must not tell them apart by their keys. Use `entry.isDeepDiff(field)` for the root of
> a changeset, and the `dh_auditor_is_diff_leaf(node)` Twig function for the nodes inside a deep diff.

### Custom Layout

```twig
{# templates/bundles/DHAuditorBundle/layout.html.twig #}
{% extends 'base.html.twig' %}

{% block stylesheets %}
    {{ parent() }}
    <link rel="stylesheet" href="{{ asset('bundles/dhauditor/app.css') }}">
{% endblock %}

{% block body %}
    <div class="container">
        {% block dh_auditor_content %}{% endblock %}
    </div>
{% endblock %}
```

### Template Blocks

| Block                | Description                          | Available in                     |
|----------------------|--------------------------------------|----------------------------------|
| `title`              | Page title                           | All pages                        |
| `stylesheets`        | CSS includes                         | All pages                        |
| `dh_auditor_content` | Main content area                    | All pages                        |
| `dh_auditor_header`  | Sub-header with back link & filters  | Entity stream, Transaction view  |
| `dh_auditor_pager`   | Pagination                           | Entity stream                    |
| `javascripts`        | JavaScript includes                  | All pages                        |      

## 📦 Assets

Install assets:

```bash
bin/console assets:install
```

Assets are installed to `public/bundles/dhauditor/`.

## 🌍 Translations

Available translations:

| Language   | Code |
|------------|------|
| English    | `en` |
| French     | `fr` |
| German     | `de` |
| Spanish    | `es` |
| Italian    | `it` |
| Dutch      | `nl` |
| Russian    | `ru` |
| Ukrainian  | `uk` |
| Estonian   | `et` |

The viewer uses your application's locale automatically.

## 🚫 Disabling the Viewer

```yaml
dh_auditor:
    providers:
        doctrine:
            viewer: false
```

Routes are not registered, `/audit` returns 404.

---

## 🚀 Next Steps

- 🛡️ [Role Checker](../customization/role-checker.md) - Access control
- ⚙️ [Configuration](../configuration/index.md) - All options
