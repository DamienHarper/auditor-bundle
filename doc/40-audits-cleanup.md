# Audits cleanup

**Notice**: symfony/lock is required, to install it use `composer require symfony/lock`

DoctrineAuditBundle provides a convenient command that helps you cleaning audit tables.
Open a command console, enter your project directory and execute:

```bash
# symfony < 3.4
app/console audit:clean
```

```bash
# symfony >= 3.4
bin/console audit:clean
```

By default it cleans audit entries older than 12 months. You can override this by providing 
the number of months you want to keep in the audit tables. For example, to keep 18 months:

```bash
# symfony < 3.4
app/console audit:clean 18
```

```bash
# symfony >= 3.4
bin/console audit:clean 18
```

It is also possible to bypass the confirmation and make the command non-interactive 
if you plan to schedule it (ie. cron)

```bash
# symfony < 3.4
app/console audit:clean --no-confirm
```

```bash
# symfony >= 3.4
bin/console audit:clean --no-confirm
```
