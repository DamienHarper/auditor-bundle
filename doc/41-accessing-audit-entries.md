# Accessing Audit Entries

The audit entries can be programmatically accessed through the AuditReader class. 

Invoke the AuditReader through your container as follows:

```php
/**
 * @var AuditReader $reader
 */
$reader = $this->container->get('dh_doctrine_audit.reader');
```

You can then access the various accessor methods in the AuditReader. 

## Accessing audit entries by pagination

```php
$entityId = $entity->getId(); // Of type EntityObject, refer to your entity configured previously
$pageNumber = 1; // Always starts at 1
$pageSize = 10; // How many results per page

$audits = $reader->getAudits(EntityObject::class, $entityId, $pageNumber, $pageSize);
```

## Accessing audit entries by date 

Note: Dates are inclusive. 

```php
$entityId = $entity->getId(); // Of type EntityObject, refer to your entity configured previously
$startDate = new \DateTime('-1 week'); // Expected in configured timezone
$endDate = new \DateTime('now'); // Optional

$audits = $reader->getAuditsByDate(EntityObject::class, $entityId, $startDate, $endDate);
```

You can then iterate through the `$audits` array to access the internal details. 