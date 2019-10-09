# Enabling and disabling audits

You can enable or disable the auditing of entities [globally](#globally-enabledisable), 
[per entity](#per-entity-enabledisable) and at [runtime](#at-runtime-enabledisable).
By default, it is enabled globally.


## Globally enable/disable
Global enabling/disabling is done in the configuration file.
- When enabled globally, all entities configured under the `entities` section of the 
configuration file are audited unless explicitly disabled in their audit configuration 
(cf. [Per entity enabling/disabling](#per-entity-enabledisable)).
- When disabled globally, **nothing is audited**.

```yaml
dh_doctrine_audit:
    enabled: true
```

## Per entity enable/disable
Per entity enabling/disabling is done in the configuration file.

This lets you disable audit logging for an entity by default and only enable auditing 
when needed for example. To do so, add this to your configuration file:

```yaml
dh_doctrine_audit:
    enabled: true                   # auditing is globally enabled
    entities:
        App\Entity\MyEntity1:
            enabled: false          # auditing of this entity is disabled
        App\Entity\MyEntity2: ~     # auditing of this entity is enabled
```
In the above example, an audit table will be created for `MyAuditedEntity1`, 
but audit entries will only be saved when auditing is explicitly enabled [at runtime](#at-runtime-enabledisable).


## At runtime enable/disable
**WARNING:** disabling audit logging for an entity will make its audit logs **incomplete/partial** 
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
