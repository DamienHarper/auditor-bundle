# UPGRADE FROM 6.x to 7.0

This document summarizes the backward incompatible changes introduced in auditor-bundle 7.0.

For a complete upgrade guide with step-by-step instructions, see the [full documentation](docs/upgrade/v7.md).

## Requirements Changes

| Requirement      | 6.x     | 7.0    |
|------------------|---------|--------|
| PHP              | >= 8.2  | >= 8.4 |
| Symfony          | >= 5.4  | >= 8.0 |
| Doctrine DBAL    | >= 3.2  | >= 4.0 |
| Doctrine ORM     | >= 2.13 | >= 3.2 |
| Doctrine Bundle  | >= 2.0  | >= 3.0 |
| PHPUnit          | >= 11.0 | >= 12.0 |
| damienharper/auditor | >= 3.2 | >= 4.0 |

See [auditor UPGRADE-4.0.md](https://github.com/DamienHarper/auditor/blob/master/UPGRADE-4.0.md) for auditor library changes.

## Breaking Changes

### Route Names

| Before (6.x)                       | After (7.0)                          |
|------------------------------------|--------------------------------------|
| `dh_auditor_show_entity_history`   | `dh_auditor_show_entity_stream`      |
| `dh_auditor_show_transaction`      | `dh_auditor_show_transaction_stream` |

### ConsoleUserProvider

CLI commands now use the command name as the user identifier:

| Before (6.x)                     | After (7.0)                        |
|----------------------------------|------------------------------------|
| `blame_id: "command"`            | `blame_id: "app:import-users"`     |
| `blame_user: "app:import-users"` | `blame_user: "app:import-users"`   |

**Note:** Existing audit entries with `blame_id = "command"` will not be automatically migrated.

### Removed Classes

| Removed Class | Replacement |
|---------------|-------------|
| `DH\AuditorBundle\DependencyInjection\Configuration` | `DHAuditorBundle::configure()` |
| `DH\AuditorBundle\DependencyInjection\DHAuditorExtension` | `DHAuditorBundle::loadExtension()` |
| `DH\AuditorBundle\DependencyInjection\Compiler\AddProviderCompilerPass` | Autowiring |
| `DH\AuditorBundle\DependencyInjection\Compiler\CustomConfigurationCompilerPass` | `DHAuditorBundle::loadExtension()` |
| `DH\AuditorBundle\DependencyInjection\Compiler\DoctrineProviderConfigurationCompilerPass` | `DoctrineMiddlewareCompilerPass` |

### UserProvider

The `AnonymousToken` handling was removed (deprecated in Symfony 6.0, removed in Symfony 8.0).

### Template Blocks

The following Twig blocks have been removed from the base layout:

| Removed Block        | Reason                           |
|----------------------|----------------------------------|
| `navbar`             | Replaced by built-in header      |
| `breadcrumbs`        | No longer used                   |

The `dh_auditor_header` and `dh_auditor_pager` blocks are still available in stream templates.

If you override `layout.html.twig`, update your template to use the available blocks: `title`, `stylesheets`, `dh_auditor_content`, `javascripts`.

### Composer Scripts

The `setup5`, `setup6`, `setup7`, `setup8` scripts have been replaced by a unified `setup` script.

## Quick Migration

```bash
# 1. Update dependencies
composer require php:^8.4 symfony/framework-bundle:^8.0 \
    doctrine/dbal:^4.0 doctrine/orm:^3.2 doctrine/doctrine-bundle:^3.0 \
    damienharper/auditor:^4.0 damienharper/auditor-bundle:^7.0

# 2. Clear cache
bin/console cache:clear

# 3. Check schema
bin/console audit:schema:update --dump-sql
```

## Need Help?

- [Full upgrade documentation](docs/upgrade/v7.md)
- [GitHub Issues](https://github.com/DamienHarper/auditor-bundle/issues)
