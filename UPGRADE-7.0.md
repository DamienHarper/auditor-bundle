# UPGRADE FROM 6.x to 7.0

This document describes the backward incompatible changes introduced in auditor-bundle 7.0 and how to adapt your code accordingly.

## Requirements Changes

### PHP Version
- **Minimum PHP version is now 8.4** (was 8.2 in 6.x, required by Symfony 8.0)

### Symfony Version
- **Minimum Symfony version is now 8.0** (was 5.4 in 6.x)
- Support for Symfony 5.4, 6.4, and 7.x has been dropped

### Doctrine Versions
- **Doctrine DBAL**: minimum version is now **4.0** (was 3.2 in 6.x)
- **Doctrine ORM**: minimum version is now **3.2** (was 2.13 in 6.x)
- **Doctrine Bundle**: minimum version is now **3.0** (was 2.0 in 6.x)

### PHPUnit Version
- **PHPUnit**: minimum version is now **12.0** (was 11.0 in 6.x)

### Auditor Library
- **damienharper/auditor**: minimum version is now **4.0** (was 3.2 in 6.x)
- See [auditor UPGRADE-4.0.md](https://github.com/DamienHarper/auditor/blob/master/UPGRADE-4.0.md) for details about auditor library changes

## Bundle Architecture Changes

### AbstractBundle Migration

The bundle now extends `Symfony\Component\HttpKernel\Bundle\AbstractBundle` instead of `Symfony\Component\HttpKernel\Bundle\Bundle`.

This is an internal change and should not affect your application configuration.

### Removed Classes

The following classes have been removed:

| Removed Class | Reason |
|---------------|--------|
| `DH\AuditorBundle\DependencyInjection\Configuration` | Merged into `DHAuditorBundle::configure()` |
| `DH\AuditorBundle\DependencyInjection\DHAuditorExtension` | Merged into `DHAuditorBundle::loadExtension()` |
| `DH\AuditorBundle\DependencyInjection\Compiler\AddProviderCompilerPass` | No longer needed with autowiring |
| `DH\AuditorBundle\DependencyInjection\Compiler\CustomConfigurationCompilerPass` | Merged into `DHAuditorBundle::loadExtension()` |
| `DH\AuditorBundle\DependencyInjection\Compiler\DoctrineProviderConfigurationCompilerPass` | Replaced by `DoctrineMiddlewareCompilerPass` |

### Removed Files

- `src/Resources/config/services.yaml` - Services are now defined programmatically in `DHAuditorBundle::loadExtension()`

## Code Changes

### Route Names

The viewer route names have been updated for consistency:

| Before (6.x)                       | After (7.0)                          |
|------------------------------------|--------------------------------------|
| `dh_auditor_show_entity_history`   | `dh_auditor_show_entity_stream`      |
| `dh_auditor_show_transaction`      | `dh_auditor_show_transaction_stream` |

Update any route references in your code:

```php
// Before
$url = $urlGenerator->generate('dh_auditor_show_entity_history', ['entity' => 'App-Entity-User']);

// After
$url = $urlGenerator->generate('dh_auditor_show_entity_stream', ['entity' => 'App-Entity-User']);
```

### ConsoleUserProvider

CLI commands now use the command name as the user identifier instead of a generic `"command"` string:

| Before (6.x)                    | After (7.0)                        |
|---------------------------------|------------------------------------|
| `blame_id: "command"`           | `blame_id: "app:import-users"`     |
| `blame_user: "app:import-users"` | `blame_user: "app:import-users"` |

This allows:
- Filtering audit entries by specific CLI command in the viewer
- Better traceability of automated processes

**Note:** Existing audit entries with `blame_id = "command"` will not be automatically migrated.

### UserProvider

The `UserProvider` class no longer handles `AnonymousToken` (removed in Symfony 6.0).

**Before (6.x):**
```php
if (!$token instanceof TokenInterface || $token instanceof AnonymousToken) {
    return null;
}
```

**After (7.0):**
```php
if (!$token instanceof TokenInterface) {
    return null;
}
```

The `getId()` method check remains unchanged - if your user entity doesn't have a `getId()` method, consider implementing one or creating a custom `UserProvider`.

## Composer Scripts

The following composer scripts have been removed:
- `setup5` (Symfony 5.4)
- `setup6` (Symfony 6.4)
- `setup7` (Symfony 7.x)
- `setup8` (Symfony 8.0)

Use the new unified `setup` script instead:
```bash
composer setup
```

## Migration Steps

1. **Update your PHP version** to 8.4 or higher
2. **Update your Symfony dependencies** to 8.0 or higher
3. **Update Doctrine dependencies**:
   - `doctrine/dbal` to ^4.0
   - `doctrine/orm` to ^3.2
   - `doctrine/doctrine-bundle` to ^3.0
4. **Update auditor library**:
   - `damienharper/auditor` to ^4.0
5. **Update auditor-bundle**:
   - `damienharper/auditor-bundle` to ^7.0
6. **Clear your cache**:
   ```bash
   php bin/console cache:clear
   ```
7. **Run your test suite** to ensure everything works correctly

## Configuration

The bundle configuration format remains unchanged. No modifications to your `config/packages/dh_auditor.yaml` are required.

## Need Help?

If you encounter any issues during the upgrade, please:
1. Check the [official documentation](https://damienharper.github.io/auditor-docs/)
2. Open an issue on [GitHub](https://github.com/DamienHarper/auditor-bundle/issues)
