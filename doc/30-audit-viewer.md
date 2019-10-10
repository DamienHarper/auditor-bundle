# Audit viewer

Add the following routes to the routing configuration to enable the included audits viewer.

```yaml
dh_doctrine_audit:
    resource: "@DHDoctrineAuditBundle/Controller/"
    type: annotation
``` 

It is possible to filter results by event type by calling `AuditReader::filterBy` method 
before getting the results.

```php
<?php

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
