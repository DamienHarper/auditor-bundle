# DoctrineAuditBundle 

This bundle creates audit logs for all doctrine ORM database related changes:

- inserts and updates including their diffs and relation field diffs.
- many to many relation changes, association and dissociation actions.
- if there is an user in token storage, it is used to identify the user who made the changes.
- the audit entries are inserted within the same transaction during **flush**, if something fails the state remains clean.

Basically you can track any change from these log entries if they were
managed through standard **ORM** operations.

**NOTE:** audit cannot track DQL or direct SQL updates or delete statement executions.

This bundle is inspired by [data-dog/audit-bundle](https://github.com/DATA-DOG/DataDogAuditBundle.git) and 
[simplethings/entity-audit-bundle](https://github.com/simplethings/EntityAuditBundle.git)

## Install

First, install it with composer:

    composer require damienharper/doctrine-audit-bundle

Then, add it in your **AppKernel** bundles (symfony < 3.4).
```php
    // app/AppKernel.php
    public function registerBundles()
    {
        $bundles = array(
            ...
            new DH\DoctrineAuditBundle\DHDoctrineAuditBundle(),
            ...
        );
        ...
    }
```

### Configure

Then configure which entities are audited

```yaml
    // app/config/config.yml (symfony < 3.4)
    // config/dh_doctrine_audit.yaml (symfony >= 3.4)
    dh_doctrine_audit:
        audited_entities:
            - MyBundle\Entity\MyAuditedEntity1
            - MyBundle\Entity\MyAuditedEntity2
```

or which are not

```yaml
    // app/config/config.yml (symfony < 3.4)
    // config/dh_doctrine_audit.yaml (symfony >= 3.4)
    dh_doctrine_audit:
        unaudited_entities:
            - MyBundle\Entity\MyNotAuditedEntity
```

You can specify either audited or unaudited entities. If both are specified, only audited entities would be taken into account.


### Creating new tables

Call the command below to see the new tables in the update schema queue.

```bash
    # symfony < 3.4
    app/console doctrine:schema:update --dump-sql 
```

```bash
    # symfony >= 3.4
    bin/console doctrine:schema:update --dump-sql 
```

**Notice**: DoctrineAuditBundle currently **only** works with a DBAL Connection and EntityManager named **"default"**.


Finally, create the database tables used by the bundle:

Using [Doctrine Migrations Bundle](http://symfony.com/doc/current/bundles/DoctrineMigrationsBundle/index.html):
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

Using Doctrine Schema:
    
```bash
    # symfony < 3.4
    app/console doctrine:schema:update --force
```

```bash
    # symfony >= 3.4
    bin/console doctrine:schema:update --force
```

## Usage

**audit** entities will be mapped automatically if you run schema update or similar.
And all the database changes will be reflected in the audit logs afterwards.

## License

DoctrineAuditBundle is free to use and is licensed under the [MIT license](http://www.opensource.org/licenses/mit-license.php)

