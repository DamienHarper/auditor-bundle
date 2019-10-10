# General Configuration

Depending on the Symfony version your application relies, configuration is located in the following file:
- In a Symfony >= 4.0 application: `config/packages/dh_doctrine_audit.yaml`
- In a Symfony <= 3.4 application: `app/config/config.yml`


## Audit tables naming format
Audit table names are composed of a prefix, the audited table name and a suffix. 
By default, the prefix is empty and the suffix is `_audit`. Though, they can be customized.

```yaml
dh_doctrine_audit:
    table_prefix: ''
    table_suffix: '_audit'
```


## Timezone
You can configure the timezone the audit `created_at` is generated in. This by default is 'UTC'.

```yaml
dh_doctrine_audit:
    timezone: 'Europe/London'
```


## Ignored properties (globally)
By default, DoctrineAuditBundle audits every property of entities declared auditable (cf. [Audit Configuration](21-audit-configuration.md) section)
But, you can define some properties to be always ignored by the audit process.

```yaml
dh_doctrine_audit:
    ignored_columns:    # properties ignored by the audit process in any audited entity
        - createdAt
        - updatedAt
```


## Enabling/Disabling audits (globally)
By default, DoctrineAuditBundle audits every entity declared auditable (cf. [Audit Configuration](21-audit-configuration.md) section).

It is however possible to disable audits by default. In this case, **nothing is audited** 
until auditing is enabled [at runtime](25-enabling-disabling-audits#at-runtime-enabledisable).

```yaml
dh_doctrine_audit:
    enabled: false
```


## Storage Configuration
By default, DoctrineAuditBundle stores audits in the same database than the tables mapped to entities.

However, DoctrineAuditBundle lets you store audits into a secondary database (dual database setup).

**WARNING:** Using a secondary database for storing audits **breaks atomicity** provided by the bundle by default. 
Audits persistence operations are performed into different transactions than entity persistence operations.
This means that:
- if one of the current audited entity operation **fails**, audit data is **still persisted** 
to the secondary database which is very bad (reference to entity data which doesn't exist 
in the main database or reference to entity data in main database which doesn't reflect changes 
logged in audit data)

- if one of the current audited entity operation **succeed**, audit data persistence in the 
secondary database **still can fail** which is bad but can be acceptable in some use cases 
(depending on how critical audit data is for your application/business, missing audit data 
could be acceptable)

```yaml
dh_doctrine_audit:
    storage_entity_manager: doctrine.orm.your_custom_entity_manager
 ```


