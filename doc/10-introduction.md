# Introduction
This bundle creates audit logs for all Doctrine ORM database related changes:

- inserts and updates including their diffs and relation field diffs.
- many to many relation changes, association and dissociation actions.
- if there is an user in token storage, it is used to identify the user who made the changes.
- audit entries are inserted within the same transaction during **flush** event 
so that even if something fails the global state remains clean.

Basically you can track any change of any entity from audit logs.

**NOTE:** this bundle cannot track DQL or direct SQL update or delete statement executions.
