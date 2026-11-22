# Blame Context

> **Record how a change was made: an import, a console command, a Messenger message…**

A blame context is written in the `via` key of the `blame` JSON of every audit entry, next to the user, and the
viewer shows it as a badge after the author (`via import · Import batch #42`). See the core
[Blame Context](https://damienharper.github.io/auditor-docs/auditor/configuration/blame-context) page for the model.

## 🧭 Pushing a context

The bundle exposes the stack of the auditor configuration as an autowirable service
(`DH\Auditor\Blame\BlameContextStack`, alias `dh_auditor.blame_context_stack`):

```php
use DH\Auditor\Blame\BlameContext;
use DH\Auditor\Blame\BlameContextStack;

final readonly class ProductImporter
{
    public function __construct(
        private BlameContextStack $blameContextStack,
        private EntityManagerInterface $entityManager,
    ) {}

    public function import(Batch $batch): void
    {
        $this->blameContextStack->run(
            new BlameContext('import', 'Import batch #'.$batch->id, ['file' => $batch->file]),
            function () use ($batch): void {
                // ... create or update products ...
                $this->entityManager->flush();
            },
        );
    }
}
```

The service is tagged `kernel.reset`: the stack is emptied between two messages of a Messenger worker and
between two requests of a FrankenPHP or RoadRunner worker, so a context left behind does not leak.

## 🖥️ Console commands

Every command runs in a `console` context labelled with the command name: its audit entries get
`"via": {"type": "console", "label": "app:import-users"}`. The [console user provider](user-provider.md#️-console-commands)
is unchanged: the command name is still the blamed user.

## 📨 Messenger

The `dh_auditor.messenger.blame_context_middleware` middleware blames the changes made by a worker on the user who
dispatched the message. It is opt-in: add it to your buses.

```yaml
# config/packages/messenger.yaml
framework:
    messenger:
        buses:
            messenger.bus.default:
                middleware:
                    - dh_auditor.messenger.blame_context_middleware
```

- On dispatch, it adds a `DH\AuditorBundle\Messenger\BlameStamp` with the current user (the user of the current
  blame context, or else the one returned by the user provider).
- When a worker handles the message, the handlers run in a `messenger` context labelled with the message class
  (`"via": {"type": "messenger", "label": "App\\Message\\ImportProducts", "data": {"transport": "async"}}`) and the
  stamped user is blamed instead of the worker command.

Messages handled synchronously are not affected: they keep the context and the user of the code that dispatched them.

## 📖 Reading it back

```php
$entry->via; // ['type' => 'import', 'label' => 'Import batch #42', 'data' => ['file' => 'products.csv']]
```

Exports (`/audit/export`, `audit:export`) carry it in the `blame_via` field.
