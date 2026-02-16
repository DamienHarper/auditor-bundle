# Upgrade Guide

> **Navigate between major versions of auditor-bundle**

This section contains upgrade guides for major versions.

## 📚 Upgrade Guides

- ⬆️ [Upgrading to 7.0](v7.md) - From 6.x to 7.0

## 📋 Version Support

| Version | Status                 | Support Until |
|:--------|:-----------------------|:--------------|
| 7.x     | Active development 🚀  | Current       |
| 6.x     | Active support         | TBD           |
| 5.x     | End of Life            | -             |             

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

After upgrading, check if audit tables need updates:

```bash
# Preview changes
bin/console audit:schema:update --dump-sql

# Apply changes
bin/console audit:schema:update --force
```
