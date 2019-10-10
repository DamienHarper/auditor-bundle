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
