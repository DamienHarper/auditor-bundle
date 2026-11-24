---
name: auditor-bundle
description: Use when adding audit logs or an entity change history to a Symfony application with damienharper/auditor-bundle 8.x (formerly DoctrineAuditBundle), on top of auditor 5.x and auditor-doctrine-provider 2.x. Covers install, the dh_auditor YAML configuration, entity attributes, the audit viewer and its security (roles), custom user, security and role providers, extra data, blame contexts, console commands and audit table schema migrations.
---

# auditor-bundle (Symfony audit logs, 8.x)

`damienharper/auditor-bundle` (namespace `DH\AuditorBundle`, formerly *DoctrineAuditBundle*) records every insert,
update, removal and many-to-many (dis)association of audited Doctrine ORM entities into audit tables
(`<table>_audit` by default), and shows them in a web viewer.

The work is split over three packages; the bundle requires the other two:

| Package | Role |
|---|---|
| `damienharper/auditor` (5.x) | Core: `Auditor`, `Configuration`, attributes (`DH\Auditor\Attribute\*`), `Entry` model, blame, events |
| `damienharper/auditor-doctrine-provider` (2.x) | `DoctrineProvider`: change detection, audit tables, `Reader`, `audit:*` commands |
| `damienharper/auditor-bundle` (8.x) | Symfony wiring (`dh_auditor` config), user/security/role providers from Symfony Security, viewer at `/audit`, export, console and Messenger blame, scheduled retention, Twig helpers |

Full documentation: https://damienharper.github.io/auditor-docs/auditor-bundle/

## 1. Check the versions first

Run `composer show damienharper/auditor-bundle` (or read `composer.lock`). This skill describes **8.x**.

| auditor-bundle | auditor | auditor-doctrine-provider | PHP | Symfony | Doctrine |
|---|---|---|---|---|---|
| 8.x | 5.x | 2.x | >= 8.4, `ext-intl` | >= 8.1 | DBAL >= 4.6, ORM >= 3.7, DoctrineBundle 3 |
| 7.x | 4.x (>= 4.4) | 1.x (>= 1.4) | >= 8.4 | >= 8.1 | DBAL 4, ORM >= 3.2 |

On 7.x, do not apply 8.x-only features (phrases, histories, `audit:snapshot`, `/audit/export`, schema v2 `blame` JSON)
without upgrading; see "Upgrading" below.

## 2. Install

```bash
composer require damienharper/auditor-bundle
```

- Bundle class: `DH\AuditorBundle\DHAuditorBundle`. Flex adds it to `config/bundles.php`; otherwise add
  `DH\AuditorBundle\DHAuditorBundle::class => ['all' => true]`.
- Viewer routes: make sure `config/routes/dh_auditor.yaml` exists, else create it:

```yaml
dh_auditor:
    resource: "@DHAuditorBundle/Controller/"
    type: auditor
```

- Install the viewer assets (`public/bundles/dhauditor/`): `bin/console assets:install`.
- Create the audit tables, see section 7.

## 3. Configure `config/packages/dh_auditor.yaml`

Real option tree (defaults shown). Only list what you change.

```yaml
dh_auditor:
    enabled: true                                   # global switch
    timezone: 'UTC'                                 # timezone of created_at
    user_provider: 'dh_auditor.user_provider'       # service IDs
    security_provider: 'dh_auditor.security_provider'
    role_checker: 'dh_auditor.role_checker'
    extra_data_provider: ~                          # opt-in, e.g. dh_auditor.extra_data_provider
    providers:
        doctrine:
            table_prefix: ''
            table_suffix: '_audit'
            ignored_columns: []                     # property names ignored on every entity
            ignored_columns_resolver: ~             # service ID: (object $entity): list<string>
            root_resolver: ~                        # service ID, see Histories
            entity_label_resolver: ~                # service ID (EntityLabelResolverInterface)
            read_scope: ~                           # service ID: (Query $query, string $entity): void
            storage_services: ['@doctrine.orm.default_entity_manager']
            auditing_services: ['@doctrine.orm.default_entity_manager']
            storage_mapper: ~                       # invokable CLASS name (not a service), needed with several storage services
            utf8_convert: false
            full_snapshot_on_remove: false          # remove entries keep every audited field as "old"
            batch_size: 0                           # > 0: multi-row INSERTs (LifecycleEvent payload then has no "id")
            compression: false                      # MariaDB only: JSON COMPRESSED diffs/extra_data
            skip_disabled_entities: false           # no audit table for entities disabled by configuration
            entities:
                App\Entity\Post: ~                  # audited with defaults
                App\Entity\User:
                    enabled: true
                    ignored_columns: [password]
                    audited_columns: ~              # allowlist; ignoring always wins
                    roles:
                        view: [ROLE_ADMIN]          # who may read these audits (viewer, export, Twig helpers)
                    roots: [company]                # attach entries to the history of another entity
                    retention:
                        max_age: 'P1Y'              # ISO 8601 duration
                        max_entries: ~
            viewer:                                 # or just `viewer: true`; disabled by default
                enabled: false
                page_size: 50
                activity_graph:
                    enabled: true
                    days: 30                        # 1..30
                    layout: 'bottom'                # 'bottom' or 'inline'
                    cache: { enabled: true, pool: 'cache.app', ttl: 300 }
            retention:                              # scheduled pruning, disabled unless schedule is set
                schedule: ~                         # cron expression, e.g. '0 3 * * *'
                default_max_age: ~                  # ISO 8601 duration for entities without a policy
```

Rules the container enforces at build time: `ignored_columns` / `audited_columns` / `roots` are lists of strings;
entity `retention` only accepts `max_age` / `max_entries`; the four resolver options must be service IDs;
`retention.schedule` must be a valid cron expression and needs `symfony/scheduler` and `dragonmantank/cron-expression`
(then run `bin/console messenger:consume scheduler_dh_auditor_retention`).

Reference: https://damienharper.github.io/auditor-docs/auditor-bundle/configuration ·
multi-database storage: https://damienharper.github.io/auditor-docs/auditor-bundle/configuration/storage

## 4. Mark entities with attributes (alternative or complement to YAML)

```php
use DH\Auditor\Attribute\Auditable;
use DH\Auditor\Attribute\AuditRoot;
use DH\Auditor\Attribute\DiffLabel;
use DH\Auditor\Attribute\Ignore;
use DH\Auditor\Attribute\Security;

#[ORM\Entity]
#[Auditable(maxAge: 'P1Y')]                  // enabled: true by default; maxAge / maxEntries = retention
#[Security(view: ['ROLE_ADMIN', 'ROLE_AUDITOR'])] // any of these roles
#[AuditRoot('company')]                      // repeatable, dotted property path
#[Ignore(field: 'legacyField')]              // class-level form of #[Ignore] (also #[DiffLabel(..., field: ...)])
class User
{
    #[ORM\Column]
    #[Ignore]
    private ?string $password = null;

    #[ORM\Column]
    #[DiffLabel(resolver: CategoryLabelResolver::class)] // class implementing DH\Auditor\Contract\DiffLabelResolverInterface
    private int $categoryId;
}
```

- `#[Auditable]` alone is enough: the entity need not be listed in YAML.
- Combination: YAML `enabled: false` disables an `#[Auditable]` entity, but YAML `enabled: true` never re-enables
  `#[Auditable(enabled: false)]`; `#[Ignore]` adds to `ignored_columns`; YAML `roles` override `#[Security]` scope by
  scope; YAML `retention` overrides `maxAge` / `maxEntries` key by key; YAML `roots` add to `#[AuditRoot]`.
- Diff label resolvers are autoconfigured services (service ID = FQCN).

Docs: https://damienharper.github.io/auditor-docs/auditor-bundle/configuration/attributes ·
https://damienharper.github.io/auditor-docs/auditor-bundle/customization/diff-label-resolvers

## 5. The viewer and its security

Enable it with `providers.doctrine.viewer: true` (it is off by default; when off, no route is registered and
`/audit` is a 404). The routes (export first, so `/audit/export` wins over `/audit/{entity}`):

| Route name | Path |
|---|---|
| `dh_auditor_export` | `/audit/export` (`format=ndjson|json|csv`, `entity`, `id`, `from`, `to`, `blame_id`, `anonymize=1`) |
| `dh_auditor_list_audits` | `/audit` |
| `dh_auditor_show_transaction_stream` | `/audit/transaction/{hash}` |
| `dh_auditor_show_user_stream` | `/audit/user/{id}` |
| `dh_auditor_show_entity_stream` | `/audit/{entity}/{id?}` (entity as `App-Entity-Post`) |
| `dh_auditor_select_revisions` | `/audit/{entity}/{id}/revisions` |
| `dh_auditor_compare_revisions` | `/audit/{entity}/{id}/compare` |

Secure it in two layers:

1. **The bundle requires no role for `/audit` itself.** Always restrict the path in `config/packages/security.yaml`:
   ```yaml
   security:
       access_control:
           - { path: ^/audit, roles: ROLE_ADMIN }
   ```
2. **Per entity**: `roles.view` (YAML) or `#[Security(view: [...])]`. The default `RoleChecker` grants access when no
   `view` roles are set, denies it when roles are set and no user is authenticated, and otherwise requires any one
   of the roles. The same check filters the viewer, `/audit/export`, `dh_auditor_last_change()` and the History
   component.

Link to the viewer from your pages instead of building route parameters: Twig `dh_auditor_entity_url(post)`,
`dh_auditor_transaction_url(entry.transactionId)`, `dh_auditor_user_url(entry.userId)` (all return `null` when the
viewer cannot show the page; combine with `dh_auditor_is_viewable(post)` for access), or the autowirable
`DH\AuditorBundle\Viewer\AuditViewerUrlGenerator` service. Override templates under
`templates/bundles/DHAuditorBundle/`. The viewer has no inline script (`bundles/dhauditor/viewer.js`), so it works
under a strict CSP once assets are installed.

To show a history inside your own pages: `composer require symfony/ux-twig-component`, then
`<twig:DhAuditor:History :subject="post" />`.

Docs: https://damienharper.github.io/auditor-docs/auditor-bundle/viewer ·
https://damienharper.github.io/auditor-docs/auditor-bundle/customization/histories

## 6. Customize who, where and what extra

All three are invokable services referenced by ID in `dh_auditor.yaml`. Defaults: `dh_auditor.user_provider`
(Symfony token user: `getId()` if present, `getUserIdentifier()`, impersonation noted), `dh_auditor.security_provider`
(`[client IP, firewall name]`), `dh_auditor.role_checker`.

```php
use DH\Auditor\User\User;
use DH\Auditor\User\UserInterface;
use DH\Auditor\User\UserProviderInterface;

final readonly class ApiUserProvider implements UserProviderInterface
{
    public function __construct(private ApiTokenManager $tokens) {}

    public function __invoke(): ?UserInterface
    {
        $token = $this->tokens->getCurrentToken();

        return null === $token ? null : new User($token->getClientId(), 'API: '.$token->getClientName());
    }
}
```

```php
use DH\Auditor\Security\SecurityProviderInterface;   // __invoke(): array  -> [?string $ip, ?string $firewall]
use DH\Auditor\Security\RoleCheckerInterface;        // __invoke(string $entity, string $scope): bool  ($scope is 'view')
```

```yaml
dh_auditor:
    user_provider: App\Audit\ApiUserProvider
    security_provider: App\Audit\ProxyAwareSecurityProvider
    role_checker: App\Audit\AuditRoleChecker
    extra_data_provider: dh_auditor.extra_data_provider   # built-in: {"route": "<route name>"} during HTTP requests
```

Extra data per entity: a `LifecycleEvent` listener that sets `$payload['extra_data']` to a **JSON string** (an
`extra_data_provider` returns a plain `?array` instead):

```php
use DH\Auditor\Event\LifecycleEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener(event: LifecycleEvent::class, priority: 10)]
final class OrderAuditExtraData
{
    public function __invoke(LifecycleEvent $event): void
    {
        $payload = $event->getPayload();
        if (Order::class !== $payload['entity'] || null === $event->entity) {
            return;
        }
        $payload['extra_data'] = json_encode(['channel' => $event->entity->getChannel()], JSON_THROW_ON_ERROR);
        $event->setPayload($payload);
    }
}
```

Blame context ("how" a change was made, stored in the `via` key of the `blame` JSON):

- console commands are blamed automatically: user = command name, `via` = `console`;
- Messenger: add the `dh_auditor.messenger.blame_context_middleware` middleware to your buses so a worker blames the
  user who dispatched the message;
- your code: inject `DH\Auditor\Blame\BlameContextStack` and wrap the work in
  `$stack->run(new BlameContext('import', 'Batch #42'), fn () => $em->flush())`.

Other services: `DH\Auditor\Provider\Doctrine\Persistence\Reader\Reader` (autowirable; `createQuery()`,
`createRootQuery()`), `DH\Auditor\Provider\Doctrine\DoctrineProvider` (alias `dh_auditor.provider.doctrine`,
or an argument `DH\Auditor\Provider\ProviderInterface $doctrineProvider`), `DH\Auditor\Auditor`. Custom providers: tag a service `dh_auditor.provider`.

Docs: https://damienharper.github.io/auditor-docs/auditor-bundle/customization (user-provider, security-provider,
role-checker, extra-data, blame-context, custom-provider)

## 7. Audit tables and console commands

Audit tables are added to the Doctrine schema of the storage entity manager, so the usual tools create them:

```bash
bin/console doctrine:migrations:diff && bin/console doctrine:migrations:migrate   # recommended
bin/console audit:schema:update --dump-sql    # preview the audit tables only
bin/console audit:schema:update --force       # needed with separate storage databases
```

| Command | Use |
|---|---|
| `audit:schema:update` | Create/update audit tables (`--dump-sql`, `--force`); refuses legacy v1 tables |
| `audit:schema:migrate` | Convert 7.x (schema v1) audit tables to v2 (`--dump-sql`, `--force`, `--convert-all`) |
| `audit:clean` | Delete old entries (`keep` argument, ISO 8601 interval, default `P12M`; entity `max_age` policies win; `--dry-run`, `--include`, `--exclude`, `--date`, `--no-confirm`) |
| `audit:export` | Export entries (`entity` argument, `--format`, `--id`, `--from`, `--to`, `--blame-id`, `--anonymize`, `--output`) |
| `audit:snapshot` | Write a snapshot `insert` entry for existing rows when auditing is added to a populated database (`--all`, `--dry-run`) |
| `audit:roots:rebuild` | Rebuild the audit root index after adding `roots` / `#[AuditRoot]` (`--entity`, `--dry-run`) |
| `audit:cache:clear` | Clear the viewer activity graph cache (`--entity=FQCN`) |

## 8. Upgrading

- **From 7.x to 8.x**: follow https://damienharper.github.io/auditor-docs/auditor-bundle/upgrade/v8 in order. Back up
  the audit tables, stop writes, `composer require damienharper/auditor-bundle:^8.0`, then
  `bin/console audit:schema:migrate --force --convert-all` **before** any `doctrine:schema:update` or migration
  touching audit tables (they would drop the legacy columns unconverted), then `audit:schema:update --force`.
  Code changes: `$entry->transactionHash` → `$entry->transactionId`; the deprecated 4.x attribute aliases
  `DH\Auditor\Provider\Doctrine\Auditing\Attribute\{Auditable,Ignore,Security}` are gone, import
  `DH\Auditor\Attribute\*`; `NeedsConversionToAuditableType` is now
  `DH\Auditor\Provider\Doctrine\Transaction\NeedsConversionToAuditableType`; viewer templates overridden from 7.x
  must be rewritten.
- **From DoctrineAuditBundle** (`DH\DoctrineAuditBundle`, the former name of this package): there is no direct path. Upgrade one
  major at a time with the guides in https://damienharper.github.io/auditor-docs/auditor-bundle/upgrade/, ending
  with `DH\AuditorBundle\DHAuditorBundle`, the `dh_auditor` configuration root and the `DH\Auditor\Attribute\*`
  attributes (annotations are not supported).

## Common mistakes

- Leaving `/audit` without an `access_control` rule: per-entity roles only apply to entities that declare them.
- Expecting the viewer without `viewer: true`, without the `type: auditor` route import, or without
  `assets:install`.
- Configuring an entity managed by an entity manager missing from `auditing_services`: nothing is audited.
- Using an `#[Auditable]` entity without creating its audit table (flush fails on a missing table).
- Running `doctrine:schema:update` / a migration on 7.x audit tables before `audit:schema:migrate --convert-all`.
- Setting `extra_data` to an array in a `LifecycleEvent` listener (must be a JSON string or `null`), or writing to
  the audited entity manager inside that listener (it runs during the flush).
- Giving `storage_mapper` a service ID: it is an invokable class name.
- Setting `batch_size` while a listener reads `$payload['id']`.
- Symfony user classes without `getId()`: entries get an empty `blame_id` and no user page in the viewer.
- Importing `DH\Auditor\Provider\Doctrine\Auditing\Attribute\*` or `DH\DoctrineAuditBundle\*` classes: they no longer
  exist in 8.x.
- Expecting scheduled pruning without `retention.schedule` and a running `messenger:consume scheduler_dh_auditor_retention`.
