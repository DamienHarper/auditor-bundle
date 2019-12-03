# Audit Configuration

Audit configuration can be achieved using the YAML configuration file described in the previous section 
and/or using annotations (recommended).


## Using annotations
Several annotations are available and let you configure which entities are auditable, 
which properties should ignored and even the security requirements (roles) to view audits.

### @Auditable
This annotation tells DoctrineAuditBundle to audit an entity. It has to be included in the entity class docblock.
```php
<?php

/**
 * @ORM\Entity
 *
 * @Audit\Auditable
 */
class MyEntity
{
    //...
}
```
The above example makes `MyEntity` auditable, auditing is **enabled** by default.

You can pass an `enabled` (boolean) property to this annotation to instruct the bundle if auditing is
enabled or not by default for this entity. 
```php
<?php

/**
 * @ORM\Entity
 *
 * @Audit\Auditable(enabled=false)
 */
class MyEntity
{
    //...
}
```
The above example makes `MyEntity` auditable, auditing is **disabled** by default.


### @Ignore
This annotation tells DoctrineAuditBundle to ignore a property (its changes won't be audited).
It has to be included in the property docblock.
```php
<?php

/**
 * @ORM\Entity
 *
 * @Audit\Auditable
 */
class MyEntity
{
    /**
     * @var string
     */
    public $property1;

    /**
     * @var string
     *
     * @Audit\Ignore
     */
    public $property2;

    //...
}
```
The above example makes `MyEntity` auditable, auditing is **enabled** by default and `property2` 
**won't be** audited.

**Notice:** [globally ignored properties](20-general-configuration.md#ignored-properties-globally) 
do not have to have an `@Ignore` annotation.


## @Security
This annotation rules the audit viewer and lets you specify which role(s) is(are) required to allow
the viewer to display audits of an entity. It has to be included in the entity class docblock.
```php
<?php

/**
 * @ORM\Entity
 *
 * @Audit\Auditable
 * @Audit\Security(view={"ROLE1", "ROLE2"})
 */
class MyEntity
{
    /**
     * @var string
     */
    public $property1;

    //...
}
```
The above example makes the audit viewer allow access (viewing) to `MyEntity` audits if 
the logged in user is granted either `ROLE1` or `ROLE2`.


### Example using annotations
```php
<?php

namespace App\Entity;

use DH\DoctrineAuditBundle\Annotation as Audit;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity
 *
 * @Audit\Auditable
 * @Audit\Security(view={"ROLE1", "ROLE2"})
 */
class AuditedEntity
{
    /**
     * @var string
     */
    public $property1;

    /**
     * @var string
     *
     * @Audit\Ignore
     */
    public $property2;

    //...
}
```


## Using configuration file

### Audited entities and properties
By default, DoctrineAuditBundle won't audit any entity, you have to configure which entities 
have to be audited.

```yaml
dh_doctrine_audit:
    entities:
        App\Entity\MyEntity1: ~
        App\Entity\MyEntity2: ~
```

All `MyEntity1` and `MyEntity2` properties will be audited. 
Though it is possible to exclude some of them from the audit process.

```yaml
dh_doctrine_audit:
    entities:
        App\Entity\MyEntity1: ~   # all MyAuditedEntity1 properties are audited
        App\Entity\MyEntity2:
            ignored_columns:      # properties ignored by the audit process
                - createdAt
                - updatedAt
```


### Example configuration
```yaml
dh_doctrine_audit:
    table_prefix: ''
    table_suffix: '_audit'
    timezone: 'Europe/London'
    ignored_columns:
        - createdAt
        - updatedAt
    entities:
        MyBundle\Entity\MyAuditedEntity1: ~
        MyBundle\Entity\MyAuditedEntity2:
           ignored_columns:
                - deletedAt
```


## Inheritance (Doctrine)

This bundle supports all of Doctrine inheritance types:
 - [mapped superclass inheritance](https://www.doctrine-project.org/projects/doctrine-orm/en/2.6/reference/inheritance-mapping.html#mapped-superclasses)
 - [single table inheritance](https://www.doctrine-project.org/projects/doctrine-orm/en/2.6/reference/inheritance-mapping.html#single-table-inheritance)
 - [class table inheritance](https://www.doctrine-project.org/projects/doctrine-orm/en/2.6/reference/inheritance-mapping.html#class-table-inheritance)


**Note**: configuring the root table to be audited does not suffice to get all child tables audited in a 
**single table inheritance** context. You have to configure every child table that needs to be audited as well.
