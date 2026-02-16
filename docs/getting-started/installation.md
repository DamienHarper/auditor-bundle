# Installation

> **Install auditor-bundle in your Symfony application**

This guide covers the installation of auditor-bundle in a Symfony application.

## 📋 Requirements

### Version 7.x (Current)

| Requirement   | Version |
|---------------|---------|
| PHP           | >= 8.4  |
| Symfony       | >= 8.0  |
| Doctrine DBAL | >= 4.0  |
| Doctrine ORM  | >= 3.2  |
| auditor       | >= 4.0  |

### Previous Versions

| Version | PHP    | Symfony | auditor |
|---------|--------|---------|---------|
| 6.x     | >= 8.2 | >= 5.4  | >= 3.0  |
| 5.x     | >= 7.4 | >= 4.4  | >= 2.0  |

## 📦 Install via Composer

```bash
composer require damienharper/auditor-bundle
```

This installs both the bundle and the auditor library.

## 🔌 Bundle Registration

### Symfony Flex (Automatic)

With Symfony Flex, the bundle is automatically registered in `config/bundles.php`.

### Manual Registration

Add to `config/bundles.php`:

```php
<?php

return [
    // ...
    DH\AuditorBundle\DHAuditorBundle::class => ['all' => true],
];
```

## ⚙️ Basic Configuration

Create `config/packages/dh_auditor.yaml`:

```yaml
dh_auditor:
    providers:
        doctrine:
            entities:
                App\Entity\User: ~
                App\Entity\Post: ~
```

This minimal configuration:
- ✅ Enables auditing for `User` and `Post` entities
- ✅ Uses default settings for everything else

## 🛤️ Route Configuration

### Symfony Flex (Automatic)

Routes are automatically added to `config/routes/dh_auditor.yaml`.

### Manual Configuration

Create `config/routes/dh_auditor.yaml`:

```yaml
dh_auditor:
    resource: "@DHAuditorBundle/Controller/"
    type: auditor
```

## 🗄️ Database Schema

Create audit tables by updating your database schema:

### Using Doctrine Migrations (Recommended)

```bash
bin/console doctrine:migrations:diff
bin/console doctrine:migrations:migrate
```

### Using Schema Tool

```bash
bin/console doctrine:schema:update --force
```

## 🎨 Asset Installation

Install assets for the audit viewer:

```bash
bin/console assets:install
```

## ✅ Verification

1. **Enable the viewer** in your configuration:
   ```yaml
   dh_auditor:
       providers:
           doctrine:
               viewer: true
               entities:
                   App\Entity\User: ~
   ```

2. **Clear cache**:
   ```bash
   bin/console cache:clear
   ```

3. **Access the viewer** at `/audit`

4. **Make a change** to a User entity and verify it appears in the viewer

> [!TIP]
> If you don't see the viewer at `/audit`, make sure routes are properly configured and the cache has been cleared.

## 📦 What Gets Installed

The bundle registers these services automatically:

| Service                                                  | Purpose                          |
|----------------------------------------------------------|----------------------------------|
| `DH\Auditor\Auditor`                                     | Main auditor service             |
| `DH\Auditor\Provider\Doctrine\DoctrineProvider`          | Doctrine provider                |
| `DH\Auditor\Provider\Doctrine\Persistence\Reader\Reader` | Audit reader                     |
| `dh_auditor.user_provider`                               | User info for audit entries      |
| `dh_auditor.security_provider`                           | Security info for audit entries  |
| `dh_auditor.role_checker`                                | Access control for viewer        |

---

## 🚀 Next Steps

- ⚙️ [Configuration Reference](../configuration/index.md) - All configuration options
- 👁️ [Audit Viewer](../viewer/index.md) - Using the web interface
- 🔧 [Customization](../customization/index.md) - Custom providers
