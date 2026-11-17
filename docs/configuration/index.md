# Configuration Reference

> **All configuration options available in auditor-bundle**

This section covers all configuration options for auditor-bundle.

## 📁 Configuration File

All configuration is done in `config/packages/dh_auditor.yaml`.

## 🔍 Quick Reference

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
            utf8_convert: false             # Re-enable legacy UTF-8 conversion (opt-in)
            compression: false              # Compress diffs/extra_data columns (MariaDB only)
            viewer: false
            retention:
                schedule: ~                 # Cron expression for scheduled pruning (opt-in)
                default_max_age: ~          # Max age for entities without a policy (scheduled pruning only)
```

## ⚙️ Global Options

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

> [!TIP]
> See [User Provider Customization](../customization/user-provider.md) for creating custom providers.

### security_provider

| Type     | Default                          | Description                      |
|----------|----------------------------------|----------------------------------|
| `string` | `'dh_auditor.security_provider'` | Service ID for security provider |

```yaml
dh_auditor:
    security_provider: 'App\Audit\CustomSecurityProvider'
```

> [!TIP]
> See [Security Provider Customization](../customization/security-provider.md) for creating custom providers.

### role_checker

| Type     | Default                     | Description                 |
|----------|-----------------------------|-----------------------------|
| `string` | `'dh_auditor.role_checker'` | Service ID for role checker |

```yaml
dh_auditor:
    role_checker: 'App\Audit\CustomRoleChecker'
```

> [!TIP]
> See [Role Checker Customization](../customization/role-checker.md) for creating custom checkers.

## 🗄️ Doctrine Provider Options

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

                # With a retention policy
                App\Entity\Payment:
                    retention:
                        max_age: 'P7Y'      # ISO 8601 duration
                        max_entries: ~      # keep the N most recent entries
```

**Entity options:**

| Option                  | Type      | Default | Description                                            |
|-------------------------|-----------|---------|--------------------------------------------------------|
| `enabled`               | `bool`    | `true`  | Enable/disable auditing for entity                     |
| `ignored_columns`       | `array`   | `[]`    | Properties to ignore for this entity                   |
| `roles.view`            | `array`   | `[]`    | Roles required to view audits                          |
| `retention.max_age`     | `?string` | `~`     | Entries older than this ISO 8601 duration are pruned   |
| `retention.max_entries` | `?int`    | `~`     | Only the N most recent entries are kept                |

**Retention policies** are applied by `audit:clean` (and by scheduled pruning when enabled):

- `max_age` replaces the global `audit:clean` cutoff for this entity; `max_entries` applies on top of the age rule.
- Values are validated when the container is built: `max_age` must be an ISO 8601 duration greater than zero
  (`P30D`, `P1Y`…), `max_entries` a positive integer, and only these two keys are allowed.
- They override the `#[Auditable(maxAge: ..., maxEntries: ...)]` values key by key: setting only `max_age` keeps the
  attribute `maxEntries`, and `max_age: ~` explicitly disables the attribute `maxAge` (e.g. per environment).
- Without any `retention` option nor attribute value, nothing changes: the entity follows the global `audit:clean`
  cutoff, as before.

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

> [!NOTE]
> See [Multi-Database Setup](storage.md) for details on using multiple storage services.

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

> [!NOTE]
> See [Multi-Database Setup](storage.md) for details.

### utf8_convert

| Type   | Default | Description                                   |
|--------|---------|-----------------------------------------------|
| `bool` | `false` | Re-enable the legacy UTF-8 conversion pass    |

```yaml
dh_auditor:
    providers:
        doctrine:
            utf8_convert: false
```

In auditor 3.x, every audit entry value was passed through `mb_convert_encoding()` automatically. In auditor 4.0+ this implicit conversion is **disabled by default** because DBAL 4 enforces UTF-8 connections on PHP 8.4+, making the conversion a no-op for virtually all modern applications.

If your application reads data from legacy non-UTF-8 sources, you can re-enable the conversion explicitly:

```yaml
dh_auditor:
    providers:
        doctrine:
            utf8_convert: true
```

> [!NOTE]
> Enabling this option has a small performance cost as every stored value is passed through `mb_convert_encoding()`. Leave it disabled unless you know your data sources may produce non-UTF-8 strings.

### compression

| Type   | Default | Description                                                         |
|--------|---------|---------------------------------------------------------------------|
| `bool` | `false` | Store the `diffs` and `extra_data` columns compressed (MariaDB only) |

```yaml
dh_auditor:
    providers:
        doctrine:
            compression: true
```

On MySQL and MariaDB, audit tables can become the largest tables of a database, almost entirely because of the
`diffs` column. On **MariaDB**, this option declares `diffs` and `extra_data` as compressed columns
(`JSON COMPRESSED`): MariaDB compresses and decompresses them transparently, so the viewer, the `Reader`, `JsonFilter`
and JSON functions in SQL keep working unchanged.

The option has no effect on other platforms:

- **PostgreSQL** already compresses large `JSONB` values automatically (TOAST);
- **MySQL** has no column compression. If disk space matters more than speed, InnoDB page compression can be enabled
  manually on audit tables (`ALTER TABLE ... COMPRESSION='zlib'` followed by `OPTIMIZE TABLE`);
- **SQLite** has no compression.

New audit tables are created compressed. **Existing audit tables must be converted** by running:

```bash
# Preview, then apply
bin/console audit:schema:update --dump-sql
bin/console audit:schema:update --force
```

If you manage your schema with Doctrine Migrations, copy the SQL shown by `audit:schema:update --dump-sql` into a
migration: `doctrine:migrations:diff` does not detect the compression of existing columns (and never reports a
difference for compressed columns either). Converting rewrites the audit tables and compresses existing entries, which
can take a while on large tables. Setting the option back to `false` and running `audit:schema:update` again restores
uncompressed columns without losing data.

> [!NOTE]
> See the [DoctrineProvider configuration](https://github.com/DamienHarper/auditor-doctrine-provider/blob/main/docs/providers/doctrine/configuration.md)
> for details.

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

**Viewer options:**

| Option      | Type   | Default | Description         |
|-------------|--------|---------|---------------------|
| `enabled`   | `bool` | `false` | Enable the viewer   |
| `page_size` | `int`  | `50`    | Results per page    |

### retention

Scheduled pruning of audit entries with [Symfony Scheduler](https://symfony.com/doc/current/scheduler.html).
**Disabled by default**: nothing is ever deleted automatically unless `schedule` is set.

| Option            | Type      | Default | Description                                                             |
|-------------------|-----------|---------|-------------------------------------------------------------------------|
| `schedule`        | `?string` | `~`     | Cron expression (e.g. `'0 3 * * *'`, `'@daily'`) enabling scheduled pruning |
| `default_max_age` | `?string` | `~`     | ISO 8601 duration applied to entities without a `max_age` policy        |

```yaml
dh_auditor:
    providers:
        doctrine:
            entities:
                App\Entity\Payment:
                    retention:
                        max_age: 'P7Y'
                App\Entity\Session:
                    retention:
                        max_entries: 10000
            retention:
                schedule: '0 3 * * *'   # every day at 3 AM
```

A scheduled run applies the same rules as `audit:clean`, with one difference: **there is no implicit global
retention period**. Only explicit policies are applied (entity `retention` options or `#[Auditable]` values); an entity
without a policy is left untouched, unless `default_max_age` is set.

Requirements:

```bash
composer require symfony/scheduler symfony/messenger dragonmantank/cron-expression
```

Then run the schedule's worker (e.g. with a process manager):

```bash
php bin/console messenger:consume scheduler_dh_auditor_retention
```

Notes:

- Every policy is validated before anything is deleted; an invalid one makes the run fail without deleting anything.
- A scheduled run shares the `audit:clean` lock: if the command is running on the same host, the run is skipped.
- The configuration is validated when the container is built (cron expression, durations, allowed keys).

### Logging

No option is needed: the bundle injects the application's `logger` service into the Doctrine provider. The provider
logs, at the `error` level, the failures it cannot throw without hiding the original error, e.g. audit entries stored
on another connection that could not be deleted after their transaction was rolled back (the log context gives the
`transaction_id` and the audit table, to clean them up by hand).

With [MonologBundle](https://symfony.com/doc/current/logging.html), these messages go to the dedicated **`auditor`
channel**, as do the `info` messages of the [scheduled pruning](#retention) (one per pruned entity, or when a run is
skipped), so they can be routed to their own handler:

```yaml
# config/packages/monolog.yaml
monolog:
    handlers:
        auditor:
            type: stream
            path: '%kernel.logs_dir%/auditor.log'
            level: error
            channels: ['auditor']
```

Without a `logger` service, nothing is logged.

## 📝 Complete Example

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

## 🌍 Environment-Specific Configuration

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

## 🔐 Using Environment Variables

```yaml
dh_auditor:
    enabled: '%env(bool:AUDITOR_ENABLED)%'
    timezone: '%env(AUDITOR_TIMEZONE)%'
```

---

## 🚀 Next Steps

- 🏷️ [Entity Attributes](attributes.md) - Configure entities with PHP attributes
- 🗄️ [Storage Configuration](storage.md) - Multi-database setup
- 🔧 [Customization](../customization/index.md) - Custom providers
