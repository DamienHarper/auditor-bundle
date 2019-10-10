# Creating audit tables

The process of audit table creation differs depending on your current setup:
- **single** database setup: audit tables are stored in the **same** database than audited ones (most common use case)
- **dual** database setup: audit tables are stored in a **secondary** database


## Single database setup (most common use case)
Open a command console, enter your project directory and execute the
following command to review the new audit tables in the update schema queue.

```bash
# symfony < 3.4
app/console doctrine:schema:update --dump-sql 
```

```bash
# symfony >= 3.4
bin/console doctrine:schema:update --dump-sql 
```

### Using [DoctrineMigrationsBundle](http://symfony.com/doc/current/bundles/DoctrineMigrationsBundle/index.html)
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

### Using Doctrine Schema
```bash
# symfony < 3.4
app/console doctrine:schema:update --force
```

```bash
# symfony >= 3.4
bin/console doctrine:schema:update --force
```


## Dual database setup
Doctrine `Schema-Tool` and `DoctrineMigrationsBundle` are not able to work with more than one
database at once. To workaround that limitation, this bundle offers a migration command that 
focuses on audit schema manipulation.

Open a command console, enter your project directory and execute the following command to 
review the new audit tables in the update schema queue.

```bash
# symfony < 3.4
app/console audit:schema:update --dump-sql 
```

```bash
# symfony >= 3.4
bin/console audit:schema:update --dump-sql 
```

Once you're done, execute the following command to apply.
    
```bash
# symfony < 3.4
app/console audit:schema:update --force
```

```bash
# symfony >= 3.4
bin/console audit:schema:update --force
```
