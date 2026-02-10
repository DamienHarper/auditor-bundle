This section covers all configuration options for auditor-bundle.

## Configuration File

All configuration is done in `config/packages/dh_auditor.yaml`.

## Quick Reference

```yaml
dh_auditor:
    enabled: true                           # Enable/disable auditing globally
    timezone: 'UTC'                         # Timezone for audit timestamps
    user_provider: 'dh_auditor.user_provider'
    security_provider: 'dh_auditor.security_provider'
    role_checker: 'dh_auditor.role_checker'
    
    providers:
        doctrine:
            table_prefix: ''
            table_suffix: '_audit'
            ignored_columns: []
            entities: []
            storage_services: ['@doctrine.orm.default_entity_manager']
            auditing_services: ['@doctrine.orm.default_entity_manager']
            storage_mapper: ~
            viewer: false
```

## Global Options

### enabled

| Type   | Default | Description                   |
|--------|---------|-------------------------------|
| `bool` | `true`  | Enable or disable all auditing |

```yaml
dh_auditor:
    enabled: true
```

When `false`, no changes are audited until re-enabled at runtime.

### timezone

| Type     | Default | Description                   |
|----------|---------|-------------------------------|
| `string` | `'UTC'` | Timezone for audit timestamps |

```yaml
dh_auditor:
    timezone: 'Europe/Paris'
```

### user_provider

| Type     | Default                      | Description                  |
|----------|------------------------------|------------------------------|
| `string` | `'dh_auditor.user_provider'` | Service ID for user provider |

```yaml
dh_auditor:
    user_provider: 'App\Audit\CustomUserProvider'
```

See [User Provider Customization](../customization/user-provider.md).

### security_provider

| Type     | Default                          | Description                      |
|----------|----------------------------------|----------------------------------|
| `string` | `'dh_auditor.security_provider'` | Service ID for security provider |

```yaml
dh_auditor:
    security_provider: 'App\Audit\CustomSecurityProvider'
```

See [Security Provider Customization](../customization/security-provider.md).

### role_checker

| Type     | Default                     | Description                 |
|----------|-----------------------------|-----------------------------|
| `string` | `'dh_auditor.role_checker'` | Service ID for role checker |

```yaml
dh_auditor:
    role_checker: 'App\Audit\CustomRoleChecker'
```

See [Role Checker Customization](../customization/role-checker.md).

## Doctrine Provider Options

All options under `providers.doctrine`:

### table_prefix / table_suffix

| Option         | Type     | Default    | Description                  |
|----------------|----------|------------|------------------------------|
| `table_prefix` | `string` | `''`       | Prefix for audit table names |
| `table_suffix` | `string` | `'_audit'` | Suffix for audit table names |

```yaml
dh_auditor:
    providers:
        doctrine:
            table_prefix: 'audit_'
            table_suffix: ''
```

Example: Entity table `users` → Audit table `audit_users`

### ignored_columns

| Type    | Default | Description                                    |
|---------|---------|------------------------------------------------|
| `array` | `[]`    | Properties to ignore globally across all entities |

```yaml
dh_auditor:
    providers:
        doctrine:
            ignored_columns:
                - createdAt
                - updatedAt
                - password
```

### entities

| Type    | Default | Description                   |
|---------|---------|-------------------------------|
| `array` | `[]`    | Entities to audit and options |

```yaml
dh_auditor:
    providers:
        doctrine:
            entities:
                # Simple: all defaults
                App\Entity\User: ~
                
                # With options
                App\Entity\Post:
                    enabled: true
                    ignored_columns:
                        - viewCount
                    roles:
                        view:
                            - ROLE_ADMIN
```

Entity options:

| Option            | Type    | Default | Description                          |
|-------------------|---------|---------|--------------------------------------|
| `enabled`         | `bool`  | `true`  | Enable/disable auditing for entity   |
| `ignored_columns` | `array` | `[]`    | Properties to ignore for this entity |
| `roles.view`      | `array` | `[]`    | Roles required to view audits        |

### storage_services

| Type    | Default                                   | Description                  |
|---------|-------------------------------------------|------------------------------|
| `array` | `['@doctrine.orm.default_entity_manager']` | Entity managers for storage |

```yaml
dh_auditor:
    providers:
        doctrine:
            storage_services:
                - '@doctrine.orm.default_entity_manager'
                - '@doctrine.orm.audit_entity_manager'
```

See [Multi-Database Setup](storage.md) for details.

### auditing_services

| Type    | Default                                   | Description                 |
|---------|-------------------------------------------|-----------------------------|
| `array` | `['@doctrine.orm.default_entity_manager']` | Entity managers to monitor |

```yaml
dh_auditor:
    providers:
        doctrine:
            auditing_services:
                - '@doctrine.orm.default_entity_manager'
                - '@doctrine.orm.secondary_entity_manager'
```

### storage_mapper

| Type            | Default | Description                              |
|-----------------|---------|------------------------------------------|
| `string\|null`  | `null`  | Service ID for routing audits to storage |

Required when using multiple storage services.

```yaml
dh_auditor:
    providers:
        doctrine:
            storage_mapper: 'App\Audit\StorageMapper'
```

See [Multi-Database Setup](storage.md) for details.

### viewer

| Type           | Default | Description                   |
|----------------|---------|-------------------------------|
| `bool\|array`  | `false` | Enable/configure audit viewer |

```yaml
# Simple enable
dh_auditor:
    providers:
        doctrine:
            viewer: true

# With options
dh_auditor:
    providers:
        doctrine:
            viewer:
                enabled: true
                page_size: 50
```

Viewer options:

| Option      | Type   | Default | Description         |
|-------------|--------|---------|---------------------|
| `enabled`   | `bool` | `false` | Enable the viewer   |
| `page_size` | `int`  | `50`    | Results per page    |

## Complete Example

```yaml
# config/packages/dh_auditor.yaml
dh_auditor:
    enabled: true
    timezone: 'Europe/Paris'
    
    providers:
        doctrine:
            table_suffix: '_audit'
            
            ignored_columns:
                - createdAt
                - updatedAt
            
            entities:
                App\Entity\User:
                    roles:
                        view: [ROLE_ADMIN]
                
                App\Entity\Post:
                    ignored_columns:
                        - viewCount
                
                App\Entity\Comment: ~
            
            viewer:
                enabled: true
                page_size: 100
```

## Environment-Specific Configuration

```yaml
# config/packages/dev/dh_auditor.yaml
dh_auditor:
    providers:
        doctrine:
            viewer: true

# config/packages/prod/dh_auditor.yaml
dh_auditor:
    providers:
        doctrine:
            viewer:
                enabled: true
                page_size: 50
```

## Using Environment Variables

```yaml
dh_auditor:
    enabled: '%env(bool:AUDITOR_ENABLED)%'
    timezone: '%env(AUDITOR_TIMEZONE)%'
```

## Next Steps

- [Entity Attributes](attributes.md) - Configure entities with PHP attributes
- [Storage Configuration](storage.md) - Multi-database setup
- [Customization](../customization/index.md) - Custom providers
