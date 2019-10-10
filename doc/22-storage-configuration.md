# Storage Configuration

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
---

To store audits in a secondary database, you have change the last argument of `dh_doctrine_audit.configuration` service 
to set the entity manager binded to that secondary database. 

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
        - "@dh_doctrine_audit.annotation_loader"
 ```
