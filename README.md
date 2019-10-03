DoctrineAuditBundle [![Tweet](https://img.shields.io/twitter/url/http/shields.io.svg?style=social)](https://twitter.com/intent/tweet?text=Create%20audit%20logs%20for%20all%20Doctrine%20ORM%20database%20related%20changes%20with%20DoctrineAuditBundle.&url=https://github.com/DamienHarper/DoctrineAuditBundle&hashtags=doctrine-audit-log-bundle)
===================

[![Latest Stable Version](https://poser.pugx.org/damienharper/doctrine-audit-bundle/v/stable)](https://packagist.org/packages/damienharper/doctrine-audit-bundle)
[![Latest Unstable Version](https://poser.pugx.org/damienharper/doctrine-audit-bundle/v/unstable)](https://packagist.org/packages/damienharper/doctrine-audit-bundle)
[![Build Status](https://travis-ci.com/DamienHarper/DoctrineAuditBundle.svg?branch=master)](https://travis-ci.com/DamienHarper/DoctrineAuditBundle)
[![License](https://poser.pugx.org/damienharper/doctrine-audit-bundle/license)](https://packagist.org/packages/damienharper/doctrine-audit-bundle)
[![Maintainability](https://api.codeclimate.com/v1/badges/2b8ef891de14763f043b/maintainability)](https://codeclimate.com/github/DamienHarper/DoctrineAuditBundle/maintainability)
[![Test Coverage](https://api.codeclimate.com/v1/badges/2b8ef891de14763f043b/test_coverage)](https://codeclimate.com/github/DamienHarper/DoctrineAuditBundle/test_coverage)
[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/DamienHarper/DoctrineAuditBundle/badges/quality-score.png?b=master)](https://scrutinizer-ci.com/g/DamienHarper/DoctrineAuditBundle/?branch=master)<br/>
[![Total Downloads](https://poser.pugx.org/damienharper/doctrine-audit-bundle/downloads)](https://packagist.org/packages/damienharper/doctrine-audit-bundle)
[![Monthly Downloads](https://poser.pugx.org/damienharper/doctrine-audit-bundle/d/monthly)](https://packagist.org/packages/damienharper/doctrine-audit-bundle)
[![Daily Downloads](https://poser.pugx.org/damienharper/doctrine-audit-bundle/d/daily)](https://packagist.org/packages/damienharper/doctrine-audit-bundle)

This bundle creates audit logs for all Doctrine ORM database related changes:

- inserts and updates including their diffs and relation field diffs.
- many to many relation changes, association and dissociation actions.
- if there is an user in token storage, it is used to identify the user who made the changes.
- the audit entries are inserted within the same transaction during **flush**, 
if something fails the state remains clean.

Basically you can track any change from these log entries if they were
managed through standard **ORM** operations.

**NOTE:** audit cannot track DQL or direct SQL updates or delete statement executions.

You can try this bundle by cloning its companion demo app. 
Follow instructions at [doctrine-audit-bundle-demo](https://github.com/DamienHarper/doctrine-audit-bundle-demo).

This bundle is inspired by [data-dog/audit-bundle](https://github.com/DATA-DOG/DataDogAuditBundle.git) and 
[simplethings/entity-audit-bundle](https://github.com/simplethings/EntityAuditBundle.git)

A changelog is available [here](CHANGELOG.md)


Installation
============

Applications that use Symfony Flex
----------------------------------

Open a command console, enter your project directory and execute:

```bash
composer require damienharper/doctrine-audit-bundle
```

Applications that don't use Symfony Flex
----------------------------------------

### Step 1: Download the Bundle

Open a command console, enter your project directory and execute the
following command to download the latest stable version of this bundle:

```bash
composer require damienharper/doctrine-audit-bundle
```

This command requires you to have Composer installed globally, as explained
in the [installation chapter](https://getcomposer.org/doc/00-intro.md)
of the Composer documentation.

### Step 2: Enable the Bundle

Then, enable the bundle by adding it to the list of registered bundles
in the `app/AppKernel.php` file of your project:

```php
<?php
// app/AppKernel.php

// ...
class AppKernel extends Kernel
{
    public function registerBundles()
    {
        $bundles = array(
            // ...
            new DH\DoctrineAuditBundle\DHDoctrineAuditBundle(),
            new WhiteOctober\PagerfantaBundle\WhiteOctoberPagerfantaBundle(), // only required if you plan to use included viewer/templates
        );

        // ...
    }

    // ...
}
```


Configuration
=============

### Audited entities and properties

By default, DoctrineAuditBundle won't audit any entity, you have to configure which entities 
have to be audited.

```yaml
// app/config/config.yml (symfony < 3.4)
// config/packages/dh_doctrine_audit.yaml (symfony >= 3.4)
dh_doctrine_audit:
    entities:
        MyBundle\Entity\MyAuditedEntity1: ~
        MyBundle\Entity\MyAuditedEntity2: ~
```

All `MyAuditedEntity1` and `MyAuditedEntity2` properties will be audited. 
Though it is possible to exclude some of them from the audit process.

```yaml
// app/config/config.yml (symfony < 3.4)
// config/packages/dh_doctrine_audit.yaml (symfony >= 3.4)
dh_doctrine_audit:
    entities:
        MyBundle\Entity\MyAuditedEntity1: ~   # all MyAuditedEntity1 properties are audited
        MyBundle\Entity\MyAuditedEntity2:
            ignored_columns:                  # properties ignored by the audit process
                - createdAt
                - updatedAt
```

It is also possible to specify properties that are globally ignored by the audit process.

```yaml
// app/config/config.yml (symfony < 3.4)
// config/packages/dh_doctrine_audit.yaml (symfony >= 3.4)
dh_doctrine_audit:
    ignored_columns:    # properties ignored by the audit process in any audited entity
        - createdAt
        - updatedAt
```

### Audit tables naming format

Audit table names are composed of a prefix, the audited table name and a suffix. 
By default, the prefix is empty and the suffix is `_audit`. Though, they can be customized.

```yaml
// app/config/config.yml (symfony < 3.4)
// config/packages/dh_doctrine_audit.yaml (symfony >= 3.4)
dh_doctrine_audit:
    table_prefix: ''
    table_suffix: '_audit'
```

### Timezone

You can configure the timezone the audit `created_at` is generated in. This by default is 'UTC'.

```yaml
// app/config/config.yml (symfony < 3.4)
// config/packages/dh_doctrine_audit.yaml (symfony >= 3.4)
dh_doctrine_audit:
    timezone: 'Europe/London'
```

### Single Table Inheritance

This bundle supports Doctrine `SINGLE_TABLE` inheritance.
Configuring the root table to be audited does not suffice to get all child tables audited.
You have to configure every child table that needs to be audited as well.

### Creating audit tables

The process of audit table creation differs depending on your current setup:
- **single** database setup: audit tables are stored in the **same** database than audited ones (most common use case)
- **dual** database setup: audit tables are stored in a **secondary** database

#### Single database setup (most common use case)

Open a command console, enter your project directory and execute the
following command to review the new audit tables in the update schema queue.

```bash
# symfony < 3.4
app/console doctrine:schema:update --dump-sql 
```

```bash
# symfony >= 3.4
bin/console doctrine:schema:update --dump-sql 
```

##### Using [DoctrineMigrationsBundle](http://symfony.com/doc/current/bundles/DoctrineMigrationsBundle/index.html)

```bash
# symfony < 3.4
app/console doctrine:migrations:diff
app/console doctrine:migrations:migrate
```

```bash
# symfony >= 3.4
bin/console doctrine:migrations:diff
bin/console doctrine:migrations:migrate
```

##### Using Doctrine Schema
    
```bash
# symfony < 3.4
app/console doctrine:schema:update --force
```

```bash
# symfony >= 3.4
bin/console doctrine:schema:update --force
```

#### Dual database setup

Doctrine `Schema-Tool` and `DoctrineMigrationsBundle` are not able to work with more than one
database at once. To workaround that limitation, this bundle offers a migration command that 
focuses on audit schema manipulation.

Open a command console, enter your project directory and execute the following command to 
review the new audit tables in the update schema queue.

```bash
# symfony < 3.4
app/console audit:schema:update --dump-sql 
```

```bash
# symfony >= 3.4
bin/console audit:schema:update --dump-sql 
```

Once you're done, execute the following command to apply.
    
```bash
# symfony < 3.4
app/console audit:schema:update --force
```

```bash
# symfony >= 3.4
bin/console audit:schema:update --force
```

### Custom database for storage audit

**Warning:** Using custom database for storing audit **breaks atomicity**. 
Audited entity operation is performed into different transactions. It means that:
* if the current audited entity operation **fails**, audit data is **still persisted** to the separate database 
which is very bad (reference to entity data which doesn't exist in the main database or 
reference to entity data in main database which doesn't reflect changes logged in audit data)

* if the current audited entity operation **succeed**, audit data persistence in the separate database 
**still can fail** which is bad but can be acceptable in some use cases (depending on how critical 
audit data is for your application/business, missing audit data could be acceptable)

It is possible to save audits in a different database than the one where audited entities live. 
To do that you have change the last argument of `dh_doctrine_audit.configuration` service to set the entity manager
binded to that second database. 

```yaml
// config/services.yaml (symfony >= 3.4)
dh_doctrine_audit.configuration:
    class: DH\DoctrineAuditBundle\AuditConfiguration
    arguments:
        - "%dh_doctrine_audit.configuration%"
        - "@dh_doctrine_audit.user_provider"
        - "@request_stack"
        - "@security.firewall.map"
        - "@doctrine.orm.your_custom_entity_manager"
 ```

### Audit viewer

Add the following routes to the routing configuration to enable the included audits viewer.

```yaml
// app/config/routing.yml (symfony < 3.4)
// config/routes.yaml (symfony >= 3.4)
dh_doctrine_audit:
    resource: "@DHDoctrineAuditBundle/Controller/"
    type: annotation
``` 

It is possible to filter results by event type by calling `AuditReader::filterBy` method 
before getting the results.

```php
/**
 * @Route("/audit/details/{entity}/{id}", name="dh_doctrine_audit_show_audit_entry", methods={"GET"})
 */
public function showAuditEntryAction(string $entity, int $id)
{
    $reader = $this->container->get('dh_doctrine_audit.reader');
    
    $data = $reader
         ->filterBy(AuditReader::UPDATE)   // add this to only get `update` entries.
         ->getAudit($entity, $id)
     ;

    return $this->render('@DHDoctrineAudit/Audit/entity_audit_details.html.twig', [
        'entity' => $entity,
        'entry' => $data[0],
    ]);
}
```

Available constants are:
```php
AuditReader::UPDATE
AuditReader::ASSOCIATE
AuditReader::DISSOCIATE
AuditReader::INSERT
AuditReader::REMOVE
```

### Custom user provider

If you don't use Symfony's `TokenStorage` to save your current user, you can configure 
a custom user provider. You just need to implement the `UserProviderInterface` and 
configure it as a service named `dh_doctrine_audit.user_provider`.

```php
use DH\DoctrineAuditBundle\User\User;
use DH\DoctrineAuditBundle\User\UserInterface;
use DH\DoctrineAuditBundle\User\UserProviderInterface;

class CustomUserProvider implements UserProviderInterface
{
    public function getUser(): ?UserInterface
    {
        // Your logic goes here...
        return new User($yourUserId, $yourUsername);
    }
}
```

Then add this to your `services.yaml` file:

```yaml
services:
    dh_doctrine_audit.user_provider:
        class: App\CustomUserProvider
```

### Enabling and disabling the auditing of entities

You can enable or disable the auditing of entities [globally](#globally-enabledisable), 
[per entity](#per-entity-enabledisable) and at [runtime](#at-runtime-enabledisable).
By default, it is enabled globally.

#### Globally enable/disable

Global enabling/disabling is done in the configuration file.
- When enabled globally, all entities configured under the `entities` section of the 
configuration file are audited unless explicitly disabled in their audit configuration 
(cf. [Per entity enabling/disabling](#per-entity-enabledisable)).
- When disabled globally, **nothing is audited**.

```yaml
// app/config/config.yml (symfony < 3.4)
// config/packages/dh_doctrine_audit.yaml (symfony >= 3.4)
dh_doctrine_audit:
    enabled: true
```

#### Per entity enable/disable

Per entity enabling/disabling is done in the configuration file.

This lets you disable audit logging for an entity by default and only enable auditing 
when needed for example. To do so, add this to your configuration file:

```yaml
// app/config/config.yml (symfony < 3.4)
// config/packages/dh_doctrine_audit.yaml (symfony >= 3.4)
dh_doctrine_audit:
    enabled: true                               # auditing is globally enabled
    entities:
        MyBundle\Entity\MyAuditedEntity1:
            enabled: false                      # auditing of this entity is disabled
        MyBundle\Entity\MyAuditedEntity2: ~     # auditing of this entity is enabled
```

In the above example, an audit table will be created for `MyAuditedEntity1`, 
but audit entries will only be saved when auditing is explicitly enabled [at runtime](#at-runtime-enabledisable).

#### At runtime enable/disable

**Warning:** disabling audit logging for an entity will make its audit logs **incomplete/partial** 
(no change applied to specified entity is logged in the relevant audit table while audit logging 
is disabled for that entity).

You can disable audit logging at runtime by calling `AuditConfiguration::disableAuditFor(string $entity)`
This will prevent the system from logging changes applied to `$entity` objects.

You can then re-enable audit logging at runtime by calling `AuditConfiguration::enableAuditFor(string $entity)`

To disable auditing for an entity, you first have to inject the `dh_doctrine_audit.configuration` 
service in your class, then use:

```php
$auditConfiguration->disableAuditFor(MyAuditedEntity1::class);
```

To enable auditing afterwards, use:

```php
$auditConfiguration->enableAuditFor(MyAuditedEntity1::class);
```


Usage
=====

**audit** entities will be mapped automatically if you run schema update or similar.
And all the database changes will be reflected in the audit logs afterwards.


Audits cleanup
==============

**Notice**: symfony/lock is required, to install it use `composer require symfony/lock`

DoctrineAuditBundle provides a convenient command that helps you cleaning audit tables.
Open a command console, enter your project directory and execute:

```bash
# symfony < 3.4
app/console audit:clean
```

```bash
# symfony >= 3.4
bin/console audit:clean
```

By default it cleans audit entries older than 12 months. You can override this by providing 
the number of months you want to keep in the audit tables. For example, to keep 18 months:

```bash
# symfony < 3.4
app/console audit:clean 18
```

```bash
# symfony >= 3.4
bin/console audit:clean 18
```

It is also possible to bypass the confirmation and make the command non-interactive 
if you plan to schedule it (ie. cron)

```bash
# symfony < 3.4
app/console audit:clean --no-confirm
```

```bash
# symfony >= 3.4
bin/console audit:clean --no-confirm
```

FAQ:
====

#### I've added an new entity in the config file but it's not audited.

> First check its namespace, then clear your cache and re-run `doctrine:schema:update` or `doctrine:migrations:migrate`.

#### I don't use Symfony's `TokenStorage` to manage my users, how do I proceed?

> Check the [Custom user provider](#custom-user-provider) section.

#### I want to store audit data in a dedicated database, how to do it?

> Check the [Custom database for storage audit](#custom-database-for-storage-audit) section.

#### I use Doctrine SINGLE_TABLE inheritance and I've configured an entity to be audited but its child are not audited, how to proceed?

> Check the [Single Table Inheritance](#single-table-inheritance) section.

#### I want to disable or enable auditing dynamically, is this possible?

> Check the [Enabling and disabling the auditing of entities](#enabling-and-disabling-the-auditing-of-entities) section.


Contributing
============

DoctrineAuditBundle is an open source project. Contributions made by the community are welcome. 
Send us your ideas, code reviews, pull requests and feature requests to help us improve this project.

Do not forget to provide unit tests when contributing to this project. 
To do so, follow instructions in [this dedicated README](tests/README.md)


Supported DB
============

* MySQL
* MariaDB
* PostgreSQL
* SQLite

*This bundle should work with **any other** database supported by Doctrine. 
Though, we can only really support the ones we can test with Travis-CI.*


License
=======

DoctrineAuditBundle is free to use and is licensed under the [MIT license](http://www.opensource.org/licenses/mit-license.php)
