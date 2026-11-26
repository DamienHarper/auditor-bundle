# UPGRADE FROM 7.x to 8.0

This document summarizes the backward incompatible changes introduced in auditor-bundle 8.0.

For the complete upgrade guide, with every step in order, see [docs/upgrade/v8.md](docs/upgrade/v8.md).

## Requirements Changes

| Requirement                              | 7.x    | 8.0    |
|------------------------------------------|--------|--------|
| PHP                                      | >= 8.4 | >= 8.4 |
| Symfony                                  | >= 8.1 | >= 8.1 |
| Doctrine DBAL                            | >= 4.3 | >= 4.6 (via the provider) |
| Doctrine ORM                             | >= 3.2 | >= 3.7 (via the provider) |
| `damienharper/auditor`                   | 4.x    | 5.x    |
| `damienharper/auditor-doctrine-provider` | 1.x    | 2.x    |

See [auditor UPGRADE-5.0.md](https://github.com/DamienHarper/auditor/blob/master/UPGRADE-5.0.md) for the core
library changes.

## Audit tables: schema v2

auditor-doctrine-provider 2.0 writes a new audit format (ULID `transaction_id`, `blame` JSON column, unified
`diffs` envelope). **Existing audit tables must be migrated** before `audit:schema:update` runs, and the migration
cannot be undone:

1. back up the audit tables, and rehearse on a copy of your data to measure the duration;
2. stop writing audits (maintenance mode, Messenger workers, scheduled tasks);
3. update the packages, without letting `doctrine:schema:update` or a generated Doctrine migration touch the audit
   tables (audit tables created by Doctrine migrations need a replayable migration and a deployment order, see
   [Audit tables managed by Doctrine Migrations](https://github.com/DamienHarper/auditor-doctrine-provider/blob/main/docs/upgrade/v2.md#audit-tables-managed-by-doctrine-migrations));
4. migrate, then apply the rest of the schema:

```bash
bin/console audit:schema:migrate --force --convert-all
bin/console audit:schema:update --force
```

Raw SQL written against the audit tables (`transaction_hash`, `blame_user`, `ip`… columns) must be rewritten. See
the [auditor-doctrine-provider 2.0 upgrade guide](https://github.com/DamienHarper/auditor-doctrine-provider/blob/main/docs/upgrade/v2.md).

## Breaking Changes

### `Entry`

- `$entry->transactionHash` is removed: use `$entry->transactionId`.
- `getDiffs()` returns the `changes` of a schema v2 entry. `$entry->username`, `userFqdn`, `userFirewall` and `ip`
  keep working.

### User provider and impersonation

- The built-in `UserProvider` no longer appends `[impersonator <name>]` to the username: the impersonated user is
  blamed, and the user who impersonates them is written in the `impersonator` key of the `blame` JSON
  (`$entry->impersonator`, `ImpersonatorFilter`, an "impersonated by" badge in the viewer). Exports get the
  `blame_impersonator_id`, `blame_impersonator` and `blame_impersonator_fqdn` fields.
- `user_fqdn` is the class of the user of the security token (it was `DH\Auditor\User\User`). Custom user
  providers pass it as the third argument of `User`: `new User($id, $name, DoctrineHelper::getRealClassName($user))`
  (`DH\Auditor\Provider\Doctrine\Persistence\Helper\DoctrineHelper` resolves Doctrine proxies).
- Existing entries are not rewritten. To find the ones written with the former suffix:

```sql
-- MySQL, MariaDB, SQLite
SELECT * FROM post_audit WHERE JSON_EXTRACT(blame, '$.username') LIKE '%[impersonator %';
-- PostgreSQL
SELECT * FROM post_audit WHERE CAST(blame AS jsonb) ->> 'username' LIKE '%[impersonator %';
```

### Viewer templates

The viewer was rewritten around actions. Templates overridden from 7.x under `templates/bundles/DHAuditorBundle/`
must be rewritten from the new ones:

- `Audit/audits.html.twig` is the overview and counts actions;
- `Audit/entity_stream.html.twig` only renders the stream of a whole entity: the history of an object is
  `Audit/object_history.html.twig`;
- `Audit/transaction_stream.html.twig` renders one action, no longer paginated;
- `Audit/user_stream.html.twig` receives actions (`rows`) instead of `entries`, and their number (`actionCount`)
  instead of `entryCount`;
- `Audit/entity_stream.html.twig` and `Audit/object_history.html.twig` count actions: `paginator.numResults` and the
  new `actionCount` are numbers of actions (`entryCount` is still given to the history of an object);
- `Audit/entry.html.twig` is removed: the comparison page renders its changes like the action details, with the
  `text_change()` and `related()` macros of `History/_macros.html.twig`.

The templates are styled with design tokens instead of the Tailwind palette (`bg-dh-card` instead of
`bg-white dark:bg-slate-900`, `text-dh-muted-foreground` instead of `text-slate-500 dark:text-slate-400`…). The
precompiled `app.css` only holds the classes of the bundle templates: an overridden template that keeps 7.x classes
(`slate-*`, `indigo-*`, `emerald-*`…) loses their styles. Rewrite it with the `dh-*` tokens (see
[Theme](docs/viewer/index.md#-theme)). An application that compiles the `<twig:DhAuditor:History>` component with its
own Tailwind imports `src/Resources/assets/css/theme.css` (see
[Styling](docs/customization/histories.md#styling)).

Route names are unchanged, but the history of an object reads other query parameters: `type` → `what`,
`user` → `who`, `date_from` / `date_to` → `when` (last 7, 30 or 90 days), `page` → the `after` cursor.

A custom layout that does not extend the bundle's one must load `bundles/dhauditor/viewer.js` (inline scripts were
removed for a strict Content Security Policy).

### Configuration

- The viewer `page_size` counts actions (transactions), no longer entries: every page of a list of actions but the
  last holds `page_size` actions, each one whole.
- The entity `ignored_columns` set in YAML are now applied to `#[Auditable]` entities too, on top of `#[Ignore]`.
- An audit entry that cannot be written now fails the flush and rolls the transaction back (it used to be lost
  silently).
- `extra_data_provider` is called once per flush instead of once per audit entry.

## Quick Migration

```bash
# 1. Update dependencies (pulls auditor ^5.0 and auditor-doctrine-provider ^2.0)
composer require damienharper/auditor-bundle:^8.0

# 2. Clear cache
bin/console cache:clear

# 3. Migrate the audit tables (after a backup, with audits stopped)
bin/console audit:schema:migrate --force --convert-all
bin/console audit:schema:update --force
```

## Need Help?

- [Upgrade guide](docs/upgrade/v8.md)
- [GitHub Issues](https://github.com/DamienHarper/auditor-bundle/issues)
