# Audit Viewer

> **Web interface for browsing audit logs**

The bundle includes a web interface for browsing audit logs.

## ✨ Enabling the Viewer

```yaml
# config/packages/dh_auditor.yaml
dh_auditor:
    providers:
        doctrine:
            viewer: true
```

Or with options:

```yaml
dh_auditor:
    providers:
        doctrine:
            viewer:
                enabled: true
                page_size: 50
```

## 🛤️ Routes

The viewer registers these routes:

| Route                                | URL                                | Description                         |
|--------------------------------------|------------------------------------|-------------------------------------|
| `dh_auditor_export`                  | `/audit/export`                    | Export of audit entries (streamed)  |
| `dh_auditor_list_audits`             | `/audit`                           | List all audited entities           |
| `dh_auditor_show_entity_stream`      | `/audit/{entity}/{id?}`            | Entity audit stream                 |
| `dh_auditor_show_transaction_stream` | `/audit/transaction/{hash}`        | Transaction details                 |
| `dh_auditor_show_user_stream`        | `/audit/user/{id}`                 | Activity timeline of one user       |
| `dh_auditor_select_revisions`        | `/audit/{entity}/{id}/revisions`   | Pick two revisions of an object     |
| `dh_auditor_compare_revisions`       | `/audit/{entity}/{id}/compare`     | Compare two revisions of an object  |

### Route Configuration

With Symfony Flex, routes are auto-configured. Otherwise, create `config/routes/dh_auditor.yaml`:

```yaml
dh_auditor:
    resource: "@DHAuditorBundle/Controller/"
    type: auditor
```

## 📄 Pages

The viewer tells each action (a database transaction) as a phrase, such as *alice changed Status: Draft → Published
and added to Tags: symfony and audit*, with its technical details one click away. The phrases come from the
[history phrases](../customization/histories.md#-phrases): translate or describe them to speak the words of your
application. The objects they name link to their history, and are marked *(deleted)* when they no longer exist (when
their identifier is an integer or a string; an object hidden by a Doctrine SQL filter, such as a soft delete, is
marked as well).

### Overview (`/audit`)

- **Recent activity**: the last 10 actions across the audited entities the reader may view, with what they were done
  on (*alice on Post “Why audit?”: …*). An action touching many objects is summed up by type (*deleted 38 Post*); it
  is read whole, whatever the number of its entries (a page reads 1,000 entries at most: past that, the largest
  actions tell only their most recent entries).
- **What is audited**: the audited entities (their name from the `dh_auditor_fields` domain, key FQCN), their number of
  actions (distinct transactions, cached with the activity graph when its cache is on), their latest activity and,
  when enabled, their [activity graph](#-activity-graph). The list can be sorted by name, number of actions or latest
  activity (`?sort=name_asc`, `name_desc`, `count_desc`, `count_asc`, `activity_desc`, `activity_asc`).

### Object History (`/audit/{entity}/{id}`)

The history of one object and of the entities attached to it (`#[AuditRoot]`, `roots` entity option, see
[Histories](../customization/histories.md)), newest first, grouped by day:

- one row per action: icon, author (linked to their actions), phrase, time, origin (Web, Command line, Messenger,
  System) and blame context; the whole row leads to the action page, the objects it names to their own history; its
  details list every change with its raw field and values (long texts compared word by word; a text shows between
  quotes only when it would be misread without them: empty, with spaces at its ends, or looking like a number, a
  boolean or null), the transaction and the blame, and link to the action page;
- **filters**: who, what (`insert`, `update`, `remove`, `associate`, `dissociate`), field (needs native JSON
  search), origin (`http`, `console`, `messenger`, `system`), when (last 7, 30 or 90 days), and, when entities can
  be attached, with or without them;
- **Show older actions** reads the next `page_size` actions through a cursor (`?after=`): a link, which `viewer.js`
  loads in place; the header counts the actions of the history;
- **Who changed what last**: the 8 fields changed most recently, with who changed them and when (or *at creation*);
- **Technical side**: class, identifier, audit tables read, number of entries.

```
/audit/App-Entity-Post/42?who=12&what=update&field=status
/audit/App-Entity-Post/42?origin=console&when=30&attached=0
```

Only the `view` role of the object's class is checked: its history shows the entries of the attached entities even
to a reader who cannot view their class (their links to their own history are hidden).

### Entity Stream (`/audit/{entity}`)

Every action on an entity, with the filters of the stream: type, user (`__anonymous__` for the entries without
user), changed field, and period.

> [!NOTE]
> The changed field filter requires native JSON support (MySQL 5.7+, MariaDB 10.2.3+, PostgreSQL 9.4+, SQLite 3.38+) and an audit table migrated to the schema v2 structure (`audit:schema:migrate`). It is hidden otherwise. On large audit tables, combine it with a period: the database has to read the diffs of every entry left by the other filters.

```
/audit/App-Entity-User?type=update&user=42
/audit/App-Entity-User?type=insert&user=__anonymous__
/audit/App-Entity-User?field=email
/audit/App-Entity-User?date_from=2026-01-01&date_to=2026-03-31
```

The dates are days, inclusive, in the `dh_auditor.timezone` time zone. Filters are kept by the pager.

Pages hold `page_size` actions (transactions), whatever their number of entries: every page but the last holds as
many actions, each one whole. A page reads 1,000 entries at most: past that, the small actions stay whole and the
largest ones share the rest, each telling only its most recent entries. The header counts the actions the filters keep,
and a page beyond the last one shows the last one. The actions of a page are found by grouping the entries the filters
keep by transaction in the database: on a large audit table, a filter (type, user, period) keeps that cheap.

### Action (`/audit/transaction/{hash}`)

One action: its phrase, the list of what it did, then every entry it wrote (up to 200), with its table and id, raw
fields and values, the transaction (and the date of its ULID), the blame and the raw diffs, which can be copied as
JSON. Opened from the history of an object (`?root=App\Entity\Post:42`), it links to the previous and next
actions of that history.

### Actions of a User (`/audit/user/{id}`)

Everything a user did, across the audited entities the reader may view, newest first, each action with what it was
done on (*On Post “Why audit?”: …*), with filters on the entity (`on`), the type (`what`) and the period (`when`). The user is identified by the
`blame_id` recorded with each entry (up to 255 characters).

- Anonymous entries (no `blame_id`) have no page. Neither do entries recorded with an empty `blame_id`, which is what
  happens when the Symfony user class has no `getId()` method.
- Pages hold `page_size` actions, as in the entity stream: an action that wrote in several audit tables is one
  action, shown and counted once (the audit tables of one connection are read in one statement).

### Compare Revisions (`/audit/{entity}/{id}/compare`)

From the history of an object, **Compare two states** lists its revisions: pick two of them to see the state of the
object before and after the changes made between them, field by field. The page links to the previous and next
revisions, so that the comparison can be moved along the history.

## 📤 Export

`/audit/export` streams audit entries, so that large exports do not load everything in memory:

| Parameter   | Description                                                                  |
|-------------|------------------------------------------------------------------------------|
| `format`    | `ndjson` (default, recommended for large exports), `json` or `csv`           |
| `entity`    | Entity to export (`App-Entity-User`); all the entities the user can view when omitted |
| `id`        | Object ID                                                                    |
| `from`, `to`| Creation date range (ISO 8601, e.g. `2026-01-01`)                            |
| `blame_id`  | User ID                                                                      |
| `anonymize` | `1` to remove the user (ID, name, class, firewall) and the IP address        |

```
/audit/export?entity=App-Entity-User&format=csv&from=2026-01-01
```

The export follows the same access rules as the viewer (see [Access Control](#-access-control)): an entity the user
cannot view is refused, or left out when no entity is given. From the command line, the provider's `audit:export`
command offers the same filters.

## 📊 Activity Graph

Each entity card displays a visual sparkline graph showing audit activity over time.

### Configuration

```yaml
dh_auditor:
    providers:
        doctrine:
            viewer:
                enabled: true
                activity_graph:
                    enabled: true       # Show/hide the graph (default: true)
                    days: 30            # Number of days to display (default: 30, max: 30)
                    layout: 'bottom'    # Graph layout: 'bottom' or 'inline' (default: 'bottom')
                    cache:
                        enabled: true   # Enable caching (default: true)
                        pool: 'cache.app'  # Cache pool service ID
                        ttl: 300        # Cache TTL in seconds (default: 300)
```

### Layout Options

| Layout   | Description                                                       |
|----------|-------------------------------------------------------------------|
| `bottom` | Sparkline displayed below the entity info, full width (default)  |
| `inline` | Compact sparkline displayed in the header row, next to event count |

### Caching

The activity graph uses caching to improve performance on large audit tables. The number of actions shown for each
entity on the audit index page is cached with it (same lifetime and tag): counting reads the whole audit table, so a
count may lag behind by up to the cache lifetime, as the graph does. Without the activity graph, or with its cache
disabled, the actions are counted on every page load.

> [!NOTE]
> Caching requires `symfony/cache`:
> ```bash
> composer require symfony/cache
> ```
> If `symfony/cache` is not installed, the graph will still work but without caching.

#### Cache with Tags

If your cache pool supports tags (`TagAwareCacheInterface`), the bundle uses the tag `dh_auditor.activity` for efficient cache invalidation.

### 🗑️ Clear Cache Command

Clear the activity graph cache manually:

```bash
# Clear all activity graph cache
php bin/console audit:cache:clear

# Clear cache for a specific entity
php bin/console audit:cache:clear --entity="App\Entity\User"
```

> [!IMPORTANT]
> Clearing all cache requires a cache pool that supports tags. If your cache pool doesn't support tags, you can only clear cache for specific entities.

### Display Behavior

| State                            | Behavior                                         |
|----------------------------------|--------------------------------------------------|
| Graph disabled (`enabled: false`)| Activity graph section is completely hidden      |
| Graph enabled, no data           | Placeholder with "No recent activity" message    |
| Graph enabled, with data         | Bars are normalized (tallest bar = 100%)         |

## 🎨 Theme

The viewer follows the conventions of [shadcn/ui](https://ui.shadcn.com) without depending on it: every color of its
templates is a CSS variable, exposed to Tailwind as a `dh-*` color (`bg-dh-card`, `text-dh-muted-foreground`,
`border-dh-input`…). The palette is neutral and the primary button near black; the teal of the auditor logo marks the activity
bars, the selected revision and the focus ring (`dh-brand`), and, darkened to read as text, the links, the time of an
action and the current page (`dh-link`). The icon of an action tells its meaning: green for a creation, red for a
deletion, blue for any other change. Controls share one size (`h-8`, `rounded-lg`), badges are pills (`h-5`).

The tokens live in `src/Resources/assets/css/theme.css`, already compiled into `bundles/dhauditor/app.css`:

| Token                                         | Role                                             |
|-----------------------------------------------|--------------------------------------------------|
| `--dh-background`, `--dh-foreground`          | Page                                             |
| `--dh-card`, `--dh-card-foreground`           | Cards, tables                                    |
| `--dh-primary`, `--dh-primary-foreground`     | Primary button (Apply), checkboxes               |
| `--dh-muted`, `--dh-muted-foreground`         | Table headers, icons, secondary text             |
| `--dh-accent`, `--dh-accent-foreground`       | Hovered and selected rows                        |
| `--dh-border`, `--dh-input`, `--dh-ring`      | Borders, control borders, focus ring             |
| `--dh-brand`, `--dh-brand-from`, `--dh-brand-to` | Teal of the logo (bars, selection), its gradient |
| `--dh-deleted`, `--dh-inserted` (+ `-foreground`) | Removed and added text in the diffs          |
| `--dh-warning` (+ `-foreground`, `-border`)   | Notices of the comparison page                   |
| `--dh-link`, `--dh-link-foreground`           | Links, the clickable time of an action, current page |
| `--dh-create`, `--dh-update`, `--dh-delete` (+ `-foreground`) | Icon of an action by its meaning     |

Redefine any of them to restyle the viewer, after the bundle stylesheet (for instance in a stylesheet block of an
overridden `layout.html.twig`):

```css
:root { --dh-primary: #4f46e5; --dh-brand: #4f46e5; }
.dark { --dh-primary: #a5b4fc; --dh-brand: #a5b4fc; }
```

## 🌙 Dark Mode

The viewer includes a dark/light mode toggle button in the header. The dark values of the tokens apply under a `dark`
class on an ancestor, set on `<html>` by `viewer.js`.

### Behavior

- **Default**: Follows system preference (`prefers-color-scheme`)
- **Manual toggle**: Click the sun/moon icon in the header
- **Persistence**: User preference is saved in `localStorage`

The toggle works without page reload and persists across sessions.

## 🔐 Content Security Policy

The viewer carries no inline script, event handler attribute nor `style` attribute: its behaviours (theme, sort of
the entity list, filters submitted on change, technical mode, copy of the raw diffs, older actions loaded in place)
live in `bundles/dhauditor/viewer.js`, loaded by `layout.html.twig`, and
its styles in `bundles/dhauditor/app.css`. It therefore works under a strict policy such as
`script-src 'self'; style-src 'self'`, as long as the bundle assets are served from your origin
(`bin/console assets:install`).

## 🔒 Access Control

### Via Configuration

```yaml
dh_auditor:
    providers:
        doctrine:
            entities:
                App\Entity\User:
                    roles:
                        view:
                            - ROLE_ADMIN
```

### Via Attributes

```php
#[Audit\Auditable]
#[Audit\Security(view: ['ROLE_ADMIN'])]
class User {}
```

### Via Symfony Security

```yaml
# config/packages/security.yaml
security:
    access_control:
        - { path: ^/audit, roles: ROLE_ADMIN }
```

> [!NOTE]
> In association diffs (`associate` / `dissociate`), the related entity links to its audit stream
> only when the current user can open it: the entity must be audited and its `view` roles granted.
> Otherwise it is displayed as plain text. Templates can apply the same rule with the
> `dh_auditor_is_viewable(entity)` Twig function (an entity object or class name).

## 🔗 URL Format

Entity namespaces use dashes instead of backslashes:

```
App\Entity\User → App-Entity-User
```

Helper methods:

```php
use DH\AuditorBundle\Helper\UrlHelper;

// Namespace to URL parameter
$param = UrlHelper::namespaceToParam('App\Entity\User');
// Returns: 'App-Entity-User'

// URL parameter to namespace
$namespace = UrlHelper::paramToNamespace('App-Entity-User');
// Returns: 'App\Entity\User'
```

## 🔧 Generating URLs

Link your own pages to the viewer with the `AuditViewerUrlGenerator` service (autowirable) or its
Twig functions. They hide the route names and parameters of the viewer, and the encoding of the
entity class in its URLs.

### Twig

```twig
{# Audit stream of an entity: an object, or a class name with an optional id #}
<a href="{{ dh_auditor_entity_url(post) }}">History</a>
<a href="{{ dh_auditor_entity_url('App\\Entity\\Post') }}">All posts</a>
<a href="{{ dh_auditor_entity_url('App\\Entity\\Post', 42, {what: 'update'}) }}">Updates of post #42</a>

{# Transaction and user timeline #}
<a href="{{ dh_auditor_transaction_url(entry.transactionId) }}">Transaction</a>
{# ... opened from the history of post #42: the action page offers its previous and next actions #}
<a href="{{ dh_auditor_transaction_url(entry.transactionId, root: 'App\\Entity\\Post:42') }}">Transaction</a>
<a href="{{ dh_auditor_user_url(entry.userId) }}">User activity</a>

{# Absolute URL, e.g. in an email #}
{{ dh_auditor_entity_url(post, absolute: true) }}
```

Each function returns `null` when the viewer cannot show the page: viewer disabled, auditor
disabled, entity not audited, or entity not persisted yet. Access rights are not checked: combine
the link with `dh_auditor_is_viewable()` to show it only to users allowed to open it.

```twig
{% set history_url = dh_auditor_entity_url(post) %}
{% if history_url and dh_auditor_is_viewable(post) %}
    <a href="{{ history_url }}">History</a>
{% endif %}
```

### PHP

```php
use DH\AuditorBundle\Viewer\AuditViewerUrlGenerator;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

public function __construct(private AuditViewerUrlGenerator $auditUrls) {}

// Audit stream of an entity object (its identifier is read from the entity)
$url = $this->auditUrls->generateEntityUrl($post);

// Audit stream of a class, of one object, with viewer filters
$url = $this->auditUrls->generateEntityUrl(Post::class);
$url = $this->auditUrls->generateEntityUrl(Post::class, 42, ['what' => 'update', 'who' => '42']);

// Absolute URL
$url = $this->auditUrls->generateEntityUrl($post, referenceType: UrlGeneratorInterface::ABSOLUTE_URL);

// Transaction and user timeline
$url = $this->auditUrls->generateTransactionUrl($entry->transactionId);
$url = $this->auditUrls->generateUserUrl($entry->userId);
```

The filters are the query parameters of the page: `type`, `user`, `field`, `date_from`, `date_to` and `page` for
the stream of an entity; `what`, `who`, `field`, `origin`, `when` and `attached` for the history of an object, which
gets the `type` and `user` filters as `what` and `who`. The identifier of an entity object is converted like the `object_id` stored in
the audit table (UUID, enum, custom type), and a Doctrine reference is not loaded to read it.

## 🎨 Customization

### Override Templates

Create files in `templates/bundles/DHAuditorBundle/`:

```
templates/bundles/DHAuditorBundle/
├── Audit/
│   ├── audits.html.twig              # Overview
│   ├── object_history.html.twig      # History of an object
│   ├── entity_stream.html.twig       # Stream of an entity
│   ├── transaction_stream.html.twig  # Action
│   ├── user_stream.html.twig         # Actions of a user
│   ├── revisions.html.twig           # Revisions of an object
│   ├── compare.html.twig             # Comparison of two revisions
│   └── helpers/
│       ├── helper.html.twig          # Helper macros
│       └── pager.html.twig           # Pagination
├── History/
│   ├── _actions.html.twig            # Actions grouped by day, and the link to older ones
│   ├── _row.html.twig                # One action
│   ├── _details.html.twig            # Technical details of an action
│   ├── _macros.html.twig             # Icons, origin badges, day headings, change rendering
│   ├── phrase.html.twig              # A phrase
│   └── object_reference.html.twig    # An object named by a phrase
├── Component/
│   └── History.html.twig             # <twig:DhAuditor:History> component
└── layout.html.twig                  # Base layout
```

> [!NOTE]
> The changeset of a JSON column is a deep diff: a tree whose leaves are `{new}`, `{old}` or `{new, old}`.
> A JSON object whose own keys are `new`/`old` therefore looks like a field diff, so a custom template must not
> tell them apart by their keys. Use `entry.isDeepDiff(field)` for the root of a changeset, and the
> `dh_auditor_is_diff_leaf(node)` Twig function for the nodes inside a deep diff.

> [!IMPORTANT]
> `bundles/dhauditor/app.css` only contains the classes of the bundle templates. An overridden template styles
> itself with the `dh-*` tokens (see [Theme](#-theme)); a class that no bundle template uses any more, such as the
> 7.x `bg-slate-50` or `text-indigo-600`, has no style.

### Custom Layout

```twig
{# templates/bundles/DHAuditorBundle/layout.html.twig #}
{% extends 'base.html.twig' %}

{% block stylesheets %}
    {{ parent() }}
    <link rel="stylesheet" href="{{ asset('bundles/dhauditor/app.css') }}">
    {# behaviours of the viewer (theme, sort, filters, technical mode, copy, older actions); loaded without defer so
       that the theme applies before the first paint #}
    <script src="{{ asset('bundles/dhauditor/viewer.js') }}"></script>
{% endblock %}

{% block body %}
    <div class="container">
        {% block dh_auditor_content %}{% endblock %}
    </div>
{% endblock %}
```

### Template Blocks

| Block                | Description                          | Available in                     |
|----------------------|--------------------------------------|----------------------------------|
| `title`              | Page title                           | All pages                        |
| `stylesheets`        | CSS includes                         | All pages                        |
| `dh_auditor_content` | Main content area                    | All pages                        |
| `dh_auditor_header`  | Sub-header with back link & filters  | Entity stream, revisions, comparison |
| `dh_auditor_pager`   | Pagination                           | Entity stream, revisions         |
| `javascripts`        | JavaScript includes                  | All pages                        |      

## 📦 Assets

Install assets:

```bash
bin/console assets:install
```

Assets are installed to `public/bundles/dhauditor/`.

## 🌍 Translations

Available translations:

| Language   | Code |
|------------|------|
| English    | `en` |
| French     | `fr` |
| German     | `de` |
| Spanish    | `es` |
| Italian    | `it` |
| Dutch      | `nl` |
| Russian    | `ru` |
| Ukrainian  | `uk` |
| Estonian   | `et` |

The viewer uses your application's locale automatically.

## 🚫 Disabling the Viewer

```yaml
dh_auditor:
    providers:
        doctrine:
            viewer: false
```

Routes are not registered, `/audit` returns 404.

---

## 🚀 Next Steps

- 🛡️ [Role Checker](../customization/role-checker.md) - Access control
- ⚙️ [Configuration](../configuration/index.md) - All options
