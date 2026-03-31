# Upgrade Guide

> **Navigate between major versions of auditor-bundle**

This section contains upgrade guides for major versions.

## 📚 Upgrade Guides

- ⬆️ [Upgrading to 8.0](v8.md) - From 7.x to 8.0
- ⬆️ [Upgrading to 7.0](v7.md) - From 6.x to 7.0

## 📋 Version Support

| Version | Status                 | Support Until |
|:--------|:-----------------------|:--------------|
| 8.x     | Active development 🚀  | Current       |
| 7.x     | Active support         | TBD           |
| 6.x     | End of Life            | -             |

## ✅ General Upgrade Process

> [!IMPORTANT]
> Always follow these steps when upgrading:

1. 📖 **Read the upgrade guide** for your target version
2. 📦 **Update dependencies** in `composer.json`
3. 🔄 **Run Composer update**
4. 🗑️ **Clear cache**
5. ⚙️ **Update configuration** if needed
6. ✅ **Run tests**

```bash
# Update dependencies
composer update damienharper/auditor damienharper/auditor-bundle

# Clear cache
bin/console cache:clear

# Run tests
bin/phpunit
```

## 🗄️ Schema Updates

After upgrading, always check if audit tables need updating.

**For fresh installations or existing v2 tables:**

```bash
# Preview changes
bin/console audit:schema:update --dump-sql

# Apply changes
bin/console audit:schema:update --force
```

**When upgrading from auditor-bundle 7.x or earlier (existing audit data):**

> [!CAUTION]
> `audit:schema:update` refuses to run if any audit table still has the legacy v1 schema. Run `audit:schema:migrate` first to preserve your data, then run `audit:schema:update`.

```bash
# Migrate legacy audit tables to v2 (preserves all data)
bin/console audit:schema:migrate --force --convert-all

# Then apply any remaining schema changes
bin/console audit:schema:update --force
```
