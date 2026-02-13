The bundle includes a web interface for browsing audit logs.

## Enabling the Viewer

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

## Routes

The viewer registers three routes:

| Route                              | URL                         | Description               |
|------------------------------------|-----------------------------|---------------------------|
| `dh_auditor_list_audits`           | `/audit`                    | List all audited entities |
| `dh_auditor_show_entity_stream`    | `/audit/{entity}/{id?}`     | Entity audit stream       |
| `dh_auditor_show_transaction_stream` | `/audit/transaction/{hash}` | Transaction details     |        

### Route Configuration

With Symfony Flex, routes are auto-configured. Otherwise, create `config/routes/dh_auditor.yaml`:

```yaml
dh_auditor:
    resource: "@DHAuditorBundle/Controller/"
    type: auditor
```

## Pages

### Entity List (`/audit`)

Displays all audited entities with:
- Entity class name
- Audit table name
- Number of audit entries
- Link to view history

### Entity Stream (`/audit/{entity}`)

Shows audit entries for an entity:
- Chronological list (newest first)
- Operation type (insert/update/remove/associate/dissociate)
- User and timestamp
- Changed properties (diff)

Filter by specific entity ID:
```
/audit/App-Entity-User/42
```

#### Filters

The entity stream page includes filters to narrow down audit entries:

**Action Type Filter**
Filter by operation type:
- Insert (record created)
- Update (record updated)
- Remove (record deleted)
- Associate (relation added)
- Dissociate (relation removed)

**User Filter**
Filter by the user who performed the action:
- All registered users who have made changes
- Anonymous (for actions without user attribution)
- CLI commands (each command is tracked by its name, e.g., `app:import-users`)

Filters can be combined and are preserved during pagination.

URL parameters:
```
/audit/App-Entity-User?type=update&user=42
/audit/App-Entity-User?type=insert&user=__anonymous__
```

### Transaction View (`/audit/transaction/{hash}`)

Groups all changes from a single database transaction across all entities.

## Activity Graph

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

| Layout | Description |
|--------|-------------|
| `bottom` | Sparkline displayed below the entity info, full width (default) |
| `inline` | Compact sparkline displayed in the header row, next to event count |

### Caching

The activity graph uses caching to improve performance on large audit tables. Caching requires `symfony/cache`:

```bash
composer require symfony/cache
```

If `symfony/cache` is not installed, the graph will still work but without caching.

#### Cache with Tags

If your cache pool supports tags (`TagAwareCacheInterface`), the bundle uses the tag `dh_auditor.activity` for efficient cache invalidation.

### Clear Cache Command

Clear the activity graph cache manually:

```bash
# Clear all activity graph cache
php bin/console audit:cache:clear

# Clear cache for a specific entity
php bin/console audit:cache:clear --entity="App\Entity\User"
```

> **Note:** Clearing all cache requires a cache pool that supports tags. If your cache pool doesn't support tags, you can only clear cache for specific entities.

### Display Behavior

| State | Behavior |
|-------|----------|
| Graph disabled (`enabled: false`) | Activity graph section is completely hidden |
| Graph enabled, no data | Placeholder with "No recent activity" message |
| Graph enabled, with data | Bars are normalized (tallest bar = 100%) |

## Dark Mode

The viewer includes a dark/light mode toggle button in the header.

### Behavior

- **Default**: Follows system preference (`prefers-color-scheme`)
- **Manual toggle**: Click the sun/moon icon in the header
- **Persistence**: User preference is saved in `localStorage`

The toggle works without page reload and persists across sessions.

## Access Control

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

## URL Format

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

## Generating URLs

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
    'hash' => $transactionHash,
]);
```

## Customization

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

## Assets

Install assets:

```bash
bin/console assets:install
```

Assets are installed to `public/bundles/dhauditor/`.

## Translations

Available translations:

- English (en)
- French (fr)
- German (de)
- Spanish (es)
- Italian (it)
- Dutch (nl)
- Russian (ru)
- Ukrainian (uk)
- Estonian (et)

The viewer uses your application's locale automatically.

## Disabling the Viewer

```yaml
dh_auditor:
    providers:
        doctrine:
            viewer: false
```

Routes are not registered, `/audit` returns 404.

## Next Steps

- [Role Checker](../customization/role-checker.md) - Access control
- [Querying](../querying.md) - Programmatic access
