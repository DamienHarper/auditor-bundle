---
description: "Show the history of a business object across entities: audit roots, root queries, field labels, phrases, Twig functions and the History Twig component."
---

# Histories

> **Show users the history of a business object and who changed each field**

auditor 5.0 and its Doctrine provider can gather the audit entries of an object and of the entities attached to it
(a property listing with its features, publications and visits), group them into actions, and tell who changed a
field last. This page covers what the bundle wires; the concepts are in the
[auditor](https://damienharper.github.io/auditor-docs/auditor/history) and
[provider](https://damienharper.github.io/auditor-docs/auditor-doctrine-provider/querying/history) documentation.

## 🌳 Declaring roots

With `#[AuditRoot('path')]` on the entity, or with the `roots` entity option:

```yaml
dh_auditor:
    providers:
        doctrine:
            entities:
                App\Entity\Property: ~
                App\Entity\PropertyFeature:
                    roots: [property]
                App\Entity\VisitReport:
                    roots: [visit.property]
```

The syntax of the paths is checked when the container is built; a path that does not follow single-valued
associations fails at the first flush. After declaring a root on an entity that already has audit entries, update the schema (it adds the `audit_root` table) and index the existing entries:

```bash
php bin/console audit:schema:update --force
php bin/console audit:roots:rebuild
```

For roots that a path cannot express, `root_resolver` takes the ID of an invokable service returning extra roots
keyed by a name of your choice:

```php
final class AttachmentRootResolver
{
    /** @return array<string, ?object> */
    public function __invoke(object $entity): array
    {
        return $entity instanceof Attachment ? ['subject' => $entity->getSubject()] : [];
    }
}
```

```yaml
dh_auditor:
    providers:
        doctrine:
            root_resolver: App\Audit\AttachmentRootResolver
```

## 📜 Reading the history of a root

The `Reader` service is autowirable:

```php
use DH\Auditor\History\ChangeExtractor;
use DH\Auditor\History\HistoryGrouper;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Reader;

public function history(Reader $reader, Property $property, ?string $after): Response
{
    $page = $reader->createRootQuery(Property::class, $property->getId())->fetch(20, $after);
    $grouper = new HistoryGrouper(extractor: new ChangeExtractor([Property::class => ['status', 'price']]));

    return $this->render('property/history.html.twig', [
        'items' => $page->group($grouper),   // list<HistoryItem>: one action per transaction
        'next' => $page->hasMore ? $page->lastCursor : null,
    ]);
}
```

Only the `view` role of the root is checked. Use `read_scope` (an invokable service receiving each query and the
entity it reads) to restrict what can be read, for instance to the current tenant.

## 🏷️ Labels

Field labels come from the `dh_auditor_fields` translation domain, keyed by `FQCN.field`; a field without
translation is humanized (`surfaceArea` gives "Surface area"):

```yaml
# translations/dh_auditor_fields.fr.yaml
App\Entity\Property.price: Prix
App\Entity\Property.surfaceArea: Surface habitable
```

The resolver is the `DH\Auditor\Contract\FieldLabelResolverInterface` service: alias it to your own implementation
to label fields another way.

`entity_label_resolver` takes the ID of a service implementing `EntityLabelResolverInterface`: it builds the label
of an entity written in the diffs ("12 rue des Lilas" rather than `App\Entity\Property#42`), at write time.

## 🔎 In templates

```twig
{% set change = dh_auditor_last_change(property, 'price') %}
{% if change %}
    {{ dh_auditor_field_label(property, 'price') }}: {{ change.value('price') }}, changed
    by {{ change.entry.username ?? 'system' }} {{ change.entry.createdAt|time_ago }}
{% endif %}

{% for field, change in dh_auditor_last_changes(property, ['price', 'status']) %}
    {{ dh_auditor_field_label(property, field) }}: {{ change ? change.entry.username : '-' }}
{% endfor %}
```

| Function | Returns |
|---|---|
| `dh_auditor_last_change(entity, field)` | the last `Change` of the field (its creation when never changed), or null |
| `dh_auditor_last_changes(entity, fields)` | `field => ?Change` |
| `dh_auditor_field_label(entity_or_class, field)` | the label of the field |

The `last_change` functions return null when the entity is not audited, has no identifier yet, or when the current
user cannot view its audits, or when its identifier cannot be turned into a string. For a field never
changed, the change is the creation of the entity, which holds the requested fields: read the value with
`change.value(field)` (`Change::getValue()`) and its label with `change.label(field)`. For a JSON field changed since,
`change.value(field)` is the deep diff tree (`change.kind` is `Json`): do not print it as is.

## 💬 Phrases

The bundle tells an action (a `HistoryItem`) as a phrase a reader understands, such as *alice changed Status:
Draft → Published and added to Tags: symfony and audit*:

```twig
{% for item in items %}
    {{ item|dh_auditor_author }} {{ item|dh_auditor_phrase }}
{% endfor %}
```

`|dh_auditor_phrase` renders the phrase as HTML: its text escaped, its values in `<strong>`, and the objects it names
(the post that was created, the tag that was added, the author that was set) linked to their history in the viewer
when the reader may view the audits of their class. `|dh_auditor_phrase_text` gives the phrase without markup, for a
`title` attribute or an export. Both filters take a `HistoryItem` or a `Phrase`.

In PHP, the `DH\AuditorBundle\History\HistoryPhraser` service gives the `Phrase` (`phrase()`) and the author
(`author()`). A phrase is made of fragments, one per change or group of changes (`fragments`, each with its `parts`, its `icon` and the
`changes` it tells), and `getParts()` lists the parts of the whole phrase: `TextPart`, `ValuePart` and
`ObjectReference` (`class`, `id`, `label`), each with a `kind` (`text`, `value`, `object`). No part holds HTML.

The icon of a phrase (`icon`) is the one of its main change (the first creation, snapshot or deletion, otherwise the
first change of the action):
`created`, `snapshot`, `deleted`, `edited`, `status` (an enum), `toggle` (a boolean), `text` (a long text), `added`,
`removed` or `json`. A describer may give another name, which your templates then need to know.

The markup comes from `@DHAuditor/History/phrase.html.twig` and, for an object, from
`@DHAuditor/History/object_reference.html.twig`: override them under `templates/bundles/DHAuditorBundle/History/` to
change it (Turbo attributes on the links, for instance).

Each change, or group of changes, gives one fragment: the changes of the root first, then those of the attached entities; the fields of an
entry in the order of their Doctrine mapping. Elements added to (or removed from) the same collection make one
fragment, and the same change made to more than 5 objects of an entity is summed up (*deleted 38 Post*). Up to 5
objects, the summary names them when they are created or deleted, or when each one would be told the same way
(*deleted 2 Comment: spam and ads*, *changed Bio on 3 Author: Alice, Bob and Carol*); otherwise each one is told. In an
action that changes the fields of several objects, a field change names its object (*changed Colour of Tag “php”:
blue → red*), unless its phrase names it already or it is the object whose history is shown. Lists read
"a, b and c" ("a, b, c and 4 others" beyond 5), and values longer than 80 characters are cut. An action whose entries
hold no change the bundle can read is told as *changed Post “Why audit?”*.

### Without configuration

| Change | Phrase |
|---|---|
| created, snapshot, deleted | created Post “Why audit?” |
| set | set Published at: Nov 22, 2026, 6:41 PM |
| changed | changed Status: Draft → Published |
| changed (long text) | edited Body |
| cleared | cleared Deadline (Nov 30, 2026) |
| added, removed | added to Tags: symfony and audit |
| JSON field | edited Metadata (seo.description) |

Values are read through the Doctrine mapping of the field: an enum gives its label when it implements
`TranslatableInterface` (its value otherwise), a date, time or datetime the format of the locale (medium date, short
time), a boolean "yes" or "no", no value "empty"; a value that cannot be read as its type is shown as stored. A value
written with a label (`#[DiffLabel]`, the label of an associated entity) shows that label.

The bundle ships these phrases in the languages of the viewer (de, en, es, et, fr, it, nl, ru, uk). For another locale, translate the keys of
`vendor/damienharper/auditor-bundle/src/translations/dh_auditor_history+intl-icu.en.yaml`, or keep `en` among the
translator fallbacks (`framework.translator.fallbacks`): without them, the keys show as they are.

### With translations

Field labels come from the `dh_auditor_fields` domain (see Labels above); so do entity names, keyed by FQCN
(the short class name otherwise). Phrases come from the `dh_auditor_history` domain in the ICU format, keyed by
`FQCN.kind` for a creation, a snapshot or a deletion and `FQCN.field.kind` for a field change, where kind is
`created`, `snapshot`, `deleted`, `set`, `changed`, `cleared`, `added`, `removed` or `json`; add `.bulk` for the
summary of several objects (followed by their names, `named` key, up to 5 objects). The `field_of` key gives the
`{field}` of a field change that names its object (*Colour of Tag “php”*). Use the `+intl-icu` suffix in the file names (a catalogue without it only gets its `{label}`, `{old}`, `{new}`,
`{names}`, `{field}` and `{entity}` placeholders replaced; write the count as `%count%`, also used for its plurals):

```yaml
# translations/dh_auditor_fields+intl-icu.fr.yaml
App\Entity\Post: '{count, plural, one {article} other {articles}}'
App\Entity\Post.status: Statut

# translations/dh_auditor_history+intl-icu.fr.yaml
App\Entity\Post.created: "a créé l'article « {label} »"
App\Entity\Post.status.changed: 'a passé le statut de {old} à {new}'
App\Entity\Post.tags.added: '{count, plural, one {a ajouté le tag {names}} other {a ajouté les tags {names}}}'
App\Entity\Post.deleted.bulk: 'a supprimé {count} {entity}'
```

| Parameter | Value |
|---|---|
| `{label}` | label of the object (or `#id`) |
| `{old}`, `{new}` | formatted values of a field change |
| `{names}` | added or removed elements, changed paths of a JSON field, as "a, b and c" |
| `{count}` | number of elements, or of objects of a summary |
| `{field}` | label of the field |
| `{entity}` | name of the entity, translated with the number of objects of a summary (1 otherwise) |

Only `{count}` is a number an ICU `plural` or `select` can test: the other parameters are inserted after the
translation, as parts of the phrase (the object of `{label}`, the values of `{old}` and `{new}`…).

### With PHP

A change that needs the rest of the action to be told (*deleted initiative Q4 roadmap, which held the ticket*, rather
than *cleared Initiative* followed by *deleted Initiative “Q4 roadmap”*) takes a describer. It returns the fragments
of the change, built from text, values and object references, or null (or an empty phrase) to hand the change on: to
the describer of the entity for a field describer, then to the translated or default phrase:

```php
use DH\Auditor\History\Change;
use DH\Auditor\History\ChangeKind;
use DH\Auditor\History\HistoryItem;
use DH\AuditorBundle\Attribute\AsChangeDescriber;
use DH\AuditorBundle\History\ChangeDescriberInterface;
use DH\AuditorBundle\History\Fragment;
use DH\AuditorBundle\History\Part\ObjectReference;
use DH\AuditorBundle\History\Phrase;

#[AsChangeDescriber(entity: Ticket::class, field: 'initiative')]
final class InitiativeDescriber implements ChangeDescriberInterface
{
    public function describe(Change $change, HistoryItem $item): ?Phrase
    {
        // the summary of the initiative the ticket was in, as the diff holds it
        $old = $change->old;
        if (ChangeKind::Cleared !== $change->kind || !\is_array($old)) {
            return null;
        }

        $id = (string) ($old[$old['pkName'] ?? 'id'] ?? '');

        // the same action deleted that initiative?
        $deletion = array_find($item->getChanges(), static fn (Change $other): bool => ChangeKind::Deleted === $other->kind
            && Initiative::class === $other->entity
            && $id === $other->objectId);
        if (null === $deletion) {
            return null;
        }

        $initiative = new ObjectReference(Initiative::class, $id, $change->oldLabel ?? '#'.$id);

        // both changes are listed: the deletion is not told again
        return Phrase::of(Fragment::of(['deleted initiative ', $initiative, ', which held the ticket'], [$change, $deletion], 'deleted'));
    }
}
```

Without `field`, the describer receives every change of the entity that no field describer tells (a field describer
returning null hands the change to it), the creation and the deletion included. The object references a describer
gives are linked like the others. `#[AsChangeDescriber]` works on autoconfigured services; otherwise, tag the service
`dh_auditor.change_describer` with the `entity` and `field` attributes.

A fragment tells the changes it lists (the described change when it lists none): a describer that tells several
changes at once lists them all, and they are not told again by other fragments. When the phrases of two describers
list the same change, the one telling the most changes is kept, and the changes only the other one told get their
default phrase. Describers run while the history is
displayed: they must cope with old entries (labels or values missing).

### Links

The objects of a phrase lead to the URL that the `DH\AuditorBundle\History\ObjectUrlResolverInterface` service
gives: by default, their history in the viewer, followed only by readers granted the `view` role of their class (by
every reader when no role checker is configured; no link when the viewer is disabled or the class is not audited). Decorate it to send some classes to pages of your
application:

```php
use DH\AuditorBundle\History\ObjectUrl;
use DH\AuditorBundle\History\ObjectUrlResolverInterface;
use DH\AuditorBundle\History\Part\ObjectReference;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[AsDecorator(ObjectUrlResolverInterface::class)]
final readonly class TicketUrlResolver implements ObjectUrlResolverInterface
{
    public function __construct(
        #[AutowireDecorated] private ObjectUrlResolverInterface $inner,
        private UrlGeneratorInterface $urls,
        private Security $security,
    ) {}

    public function resolve(ObjectReference $reference): ?ObjectUrl
    {
        if (Ticket::class !== $reference->class) {
            return $this->inner->resolve($reference);
        }

        return new ObjectUrl(
            $this->urls->generate('ticket_show', ['id' => $reference->id]),
            $this->security->isGranted('TICKET_VIEW', $reference->id),
        );
    }
}
```

`ObjectUrl::$visible` tells whether the reader may follow the link; null keeps the object as plain text.

### Authors

The author is the name recorded with the change ("User 42" when only the user id was recorded); without user,
"Command line" (a console command), "Anonymous user" (a web request behind a firewall) or "System" (anything else,
message handlers included).

To show the current name of a user, implement `DH\AuditorBundle\History\AuthorNameResolverInterface` and alias the
interface to your service: it returns the name, or null for a user that no longer exists ("Deleted user"). It is
called once per action: cache what it looks up.

```yaml
# config/services.yaml
DH\AuditorBundle\History\AuthorNameResolverInterface: '@App\Audit\AuthorNameResolver'
```

### Twig reference

The templates of the bundle, and the partials of `History/` you may override, rely on these functions and filters:

| Function or filter | Returns |
|---|---|
| `item\|dh_auditor_phrase` | the phrase of a `HistoryItem` (or `Phrase`, `Fragment`) as HTML, objects linked to their history |
| `item\|dh_auditor_phrase_text` | the same phrase as plain text |
| `item\|dh_auditor_shorten(n)` | the first `n` fragments of a phrase, followed by "and 4 other changes" |
| `item\|dh_auditor_author` | the author of an action |
| `dh_auditor_context(item, rootClass = null, rootId = null)` | what an action was done on ("on Post “Why audit?”") when it changes the fields of one object only, or null |
| `dh_auditor_entity_name(class, count = 1)` | the name of an entity (`dh_auditor_fields` domain, key FQCN), or its short class name |
| `dh_auditor_object_url(reference)` | where an object reference leads (`ObjectUrl`), or null |
| `dh_auditor_is_deleted(reference)` | whether the object of a reference no longer exists, as far as the page checked it |
| `dh_auditor_history_item(entry)` | the action of a single `Entry` |
| `dh_auditor_word_diff(old, new)` | a word by word comparison of two texts, as runs of `[kind, text]` (`equal`, `delete`, `insert`), or null |
| `dh_auditor_value_text(value)` | a value as the action details show it: a text as it is, between quotes only when it would be misread without them (`dh_auditor_is_ambiguous_text()`), any other value as JSON |

## 🧩 In the pages of your application

The `<twig:DhAuditor:History>` component shows the history of an object in your own pages, next to its form for
instance: the actions on the object and on the entities attached to it, told as phrases, most recent first. It needs
`symfony/ux-twig-component`; the bundle only registers it when the package is installed.

```bash
composer require symfony/ux-twig-component
```

```twig
<twig:DhAuditor:History :subject="post" />
```

| Option | Default | |
|---|---|---|
| `subject` | (required) | The audited entity |
| `with` | `[]` | Elements of your application mixed with the actions (see below) |
| `grouper` | one action per transaction | A `DH\Auditor\History\HistoryGrouper` with a key of your own |
| `limit` | the page size of the viewer (`page_size`) | Actions per page, as in the viewer; with a `grouper` that has a key of its own (an action may then span several transactions), entries per page, more when needed to keep the last action whole |
| `attached` | `true` | Whether the actions on the attached entities are included |
| `details` | `false` | Technical details of the actions: `true`, or a role (`details="ROLE_ADMIN"`) |
| `after` | from the query string | Cursor of the page to show (an invalid one is an error, unlike in the query string) |
| `cursorParameter` | `dh_auditor_after` | Query string parameter of the cursor |

Without access to the audits of its class (`roles.view`), with an object that is not audited or not persisted yet,
the component shows no action.

### Elements of your application

Comments, notes or emails that are not audited can be mixed with the actions. They implement
`DH\AuditorBundle\History\HistoryElementInterface`, whose sort key compares with the one of the audit entries
(`Entry::getSortKey()`): build it with `SortKey::of()`.

```php
use DH\AuditorBundle\History\HistoryElementInterface;
use DH\AuditorBundle\History\SortKey;

final class Comment implements HistoryElementInterface
{
    // ...

    public function getSortKey(): string
    {
        return SortKey::of($this->createdAt, 'comment-'.$this->id);
    }
}
```

```twig
<twig:DhAuditor:History :subject="post" :with="post.comments">
    <twig:block name="element">
        <strong>{{ element.author }}</strong> · {{ element.createdAt|date }}
        <p>{{ element.body }}</p>
    </twig:block>
</twig:DhAuditor:History>
```

The `element` block renders one element (`{{ element }}` by default, which needs a `__toString()` method); the `title`
and `empty` blocks replace the
heading and the text shown without any line.

### Pages

The component shows one page of actions. Its "Older" link reloads the current page with the cursor of the next one in
the query string (`?dh_auditor_after=…`, the other parameters kept; the page the reader sees, also when the component is rendered by `render(controller())`); give two components on the same page their own
`cursorParameter`. An element of your application is shown once, on the page whose actions surround it. In an
application that uses Turbo, wrap the component in a `<turbo-frame>` to load the older actions in place.

On the first page, the `data-dh-auditor-first-cursor` attribute of the component (`firstCursor` in PHP) holds the
position of its most recent entry: read the actions recorded since with `RootQuery::fetch(since: $cursor)`, to push
them with a Turbo Stream for instance. The attribute is absent while the history is empty.

"Open in the viewer" leads to the history of the object in the viewer, when it is enabled.

### Styling

The component renders the rows of the viewer, styled with Tailwind CSS. With Tailwind 4 in your application, add the
bundle templates to the sources of its stylesheet (the path below is relative to `assets/styles/app.css`):

```css
@import "tailwindcss";
@import "../../vendor/damienharper/auditor-bundle/src/Resources/assets/css/theme.css";
@source "../../vendor/damienharper/auditor-bundle/src/Resources/views";
```

`theme.css` declares the `dh-*` colors the templates use (see [Theme](../viewer/index.md#-theme)), with their dark
values under a `dark` class on an ancestor. A page that follows `prefers-color-scheme` without such a class copies the
`.dark` block of `theme.css` into a `@media (prefers-color-scheme: dark) { :root { … } }` rule. To blend the component
into a shadcn/ui theme, map the tokens onto yours:

```css
:root {
    --dh-card: var(--card);
    --dh-foreground: var(--foreground);
    --dh-muted-foreground: var(--muted-foreground);
    --dh-border: var(--border);
}
```

Otherwise, override `@DHAuditor/Component/History.html.twig` and the templates of `@DHAuditor/History/` it renders
(`_row`, `_macros`, `_details`, `phrase`, `object_reference`) under `templates/bundles/DHAuditorBundle/`.
