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

 Route                              URL                              Description                
-----------------------------------------------------------------------------------------------
 `dh_auditor_list_audits`           `/audit`                         List all audited entities  
 `dh_auditor_show_entity_history`   `/audit/{entity}/{id?}`          Entity audit history       
 `dh_auditor_show_transaction`      `/audit/transaction/{hash}`      Transaction details        

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

### Entity History (`/audit/{entity}`)

Shows audit entries for an entity:
- Chronological list (newest first)
- Operation type (insert/update/remove)
- User and timestamp
- Changed properties (diff)

Filter by specific entity ID:
```
/audit/App-Entity-User/42
```

### Transaction View (`/audit/transaction/{hash}`)

Groups all changes from a single database transaction across all entities.

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

// Entity history
$url = $urlGenerator->generate('dh_auditor_show_entity_history', [
    'entity' => 'App-Entity-User',
]);

// Specific entity
$url = $urlGenerator->generate('dh_auditor_show_entity_history', [
    'entity' => 'App-Entity-User',
    'id' => 42,
]);

// Transaction
$url = $urlGenerator->generate('dh_auditor_show_transaction', [
    'hash' => $transactionHash,
]);
```

## Customization

### Override Templates

Create files in `templates/bundles/DHAuditorBundle/`:

```
templates/bundles/DHAuditorBundle/
├── Audit/
│   ├── audits.html.twig           # Entity list
│   ├── entity_history.html.twig   # Audit history
│   ├── entry.html.twig            # Single entry
│   ├── entry_diff.html.twig       # Property diff
│   └── transaction.html.twig      # Transaction view
└── layout.html.twig               # Base layout
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

 Block                   Description              
--------------------------------------------------
 `title`                 Page title               
 `stylesheets`           CSS includes             
 `navbar`                Navigation bar           
 `breadcrumbs`           Breadcrumb navigation    
 `dh_auditor_header`     Page header content      
 `dh_auditor_content`    Main content area        
 `dh_auditor_pager`      Pagination               
 `javascripts`           JavaScript includes      

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
