# FAQ:

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
