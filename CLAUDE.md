# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project overview

`auditor-bundle` (namespace `DH\AuditorBundle`) integrates the [`damienharper/auditor`](https://github.com/DamienHarper/auditor) audit-log library and its Doctrine provider into Symfony applications: container wiring and configuration (`dh_auditor`), a web audit viewer, an export endpoint, user/security providers, scheduled retention and an activity graph. Active development targets **8.x** on `master` (PHP ≥ 8.4, Symfony ^8.1, PHPUnit 13). All Doctrine code lives in `auditor-doctrine-provider`.

## auditor family

| Package | Repository | Default branch | Role |
|---|---|---|---|
| `damienharper/auditor` | `DamienHarper/auditor` | `master` | Core: contracts, models, events |
| `damienharper/auditor-doctrine-provider` | `DamienHarper/auditor-doctrine-provider` | `main` | Doctrine ORM provider: change detection, storage, reader, schema |
| `damienharper/auditor-bundle` | `DamienHarper/auditor-bundle` | `master` | Symfony bundle: configuration, viewer, export |

- Dependency order: core → provider → bundle. Downstream packages require the upstream ones at `@dev`, so their CI runs against the upstream default branch.
- A change spanning several packages lands as one PR per repository, in that order; a downstream PR goes green once the upstream one is merged.
- Each repository has its own `CLAUDE.md`, `Makefile` (`wt-*` worktree targets, Docker matrix) and `composer agent-*` scripts.

## Commands

### Run tests locally
```bash
composer test                                  # all tests, no coverage
composer test:coverage                         # with Xdebug coverage
vendor/bin/phpunit --filter=ViewerControllerTest   # single test class
composer setup                                 # composer update + clear tests/App/var/cache
```

### Code quality
```bash
composer cs-fix      # fix style (php-cs-fixer)
composer cs-check    # dry-run style check
composer phpstan     # static analysis (level max)
composer rector      # automated refactorings (rector-check: dry run)
composer qa          # rector + cs-fix + phpstan
```

### Agent commands (token-efficient, silent output)
When running as an AI agent, use these variants instead — they suppress progress bars and
verbose output while still surfacing errors:
```bash
composer agent-cs-fix      # php-cs-fixer --quiet
composer agent-phpstan     # phpstan --no-progress
composer agent-rector      # rector --no-progress-bar
composer agent-test        # phpunit --no-progress
composer agent-qa          # agent-rector + agent-cs-fix + agent-phpstan
```

### Docker matrix (Makefile)
```bash
make tests php=8.5                       # PHPUnit in Docker (PHP 8.4–8.6, Symfony 8.1–8.2); no db option
make tests args='--filter=ViewerControllerTest'
make qa                                  # rector + cs-fix + phpstan in Docker
make build-assets                        # rebuild src/Resources/public/app.css (Tailwind, host PHP)
make help                                # all targets and options
```

### Git worktrees (Makefile)
Parallel work happens in worktrees under `.claude/worktrees/<name>/` (git-ignored), each with its
own `vendor/` and `tools/`. The `wt-*` targets run the `composer agent-*` scripts with the host
PHP (with `intl` and `pdo_sqlite`); `wt=<name>` can be omitted when `make` runs from inside the
worktree.
```bash
make wt-create branch=feature/my-feature   # new branch from the freshly fetched origin/HEAD, then install
make wt-qa wt=feature-my-feature           # composer agent-qa
make wt-test wt=feature-my-feature args='--filter=ViewerControllerTest'
make wt-clean wt=feature-my-feature confirm=yes   # refused while the worktree has uncommitted changes
```
Other targets: `wt-init`, `wt-list`, `wt-exec args=<cmd>`, `wt-phpstan`. The Docker targets also
work from a worktree (`make -C .claude/worktrees/<name> tests`).

## Architecture

### Bundle and configuration

`DHAuditorBundle` (`src/DHAuditorBundle.php`) extends `AbstractBundle`: the `dh_auditor` configuration tree is in `configure()` and the service wiring in `loadExtension()` — there is no `DependencyInjection/Configuration.php` nor Extension class.

- Root keys: `enabled`, `timezone`, `user_provider`, `security_provider`, `role_checker`, `extra_data_provider`, `providers`.
- `providers.doctrine`: `table_prefix`, `table_suffix`, `ignored_columns`, `ignored_columns_resolver` / `root_resolver` / `entity_label_resolver` / `read_scope` (service IDs set by method calls, see `CALLABLE_SERVICE_OPTIONS`), `entities` (with `roles.view`, `retention` and `roots`), `storage_services`, `auditing_services`, `viewer` (off by default; `page_size`, `activity_graph`), `retention` (`schedule`, `default_max_age`), `storage_mapper`.
- The `DoctrineProvider` service is tagged `dh_auditor.provider` and aliased `dh_auditor.provider.doctrine`.

Compiler passes (`src/DependencyInjection/Compiler/`): `DoctrineMiddlewareCompilerPass` adds the auditor DBAL middleware to each auditing connection, `RegisterDoctrineListenersCompilerPass` declares the provider's `DoctrineSubscriber` as a lazy Doctrine listener of those connections (the provider then attaches no listener itself, so flushes made before anything builds it are audited; when an auditing entity manager does not use its DoctrineBundle connection's event manager, the pass logs it and leaves the runtime attachment), `RegisterProvidersCompilerPass` registers the providers on `Auditor`, `RegisterDiffLabelResolversCompilerPass` collects the `DiffLabelResolverInterface` services (service ID = FQCN), `RegisterChangeDescribersCompilerPass` gives `HistoryPhraser` a locator of the `#[AsChangeDescriber]` services.

### Key classes

| Class | Path | Purpose |
|---|---|---|
| `ViewerController` | `src/Controller/ViewerController.php` | `/audit` overview, object history (entity stream with id), entity stream, action (transaction route), user actions, revisions, compare |
| `ExportController` | `src/Controller/ExportController.php` | `/audit/export`, streamed ndjson / json / csv |
| `RoutingLoader` | `src/Routing/RoutingLoader.php` | Loader of type `auditor`; routes only exist when the viewer is enabled, export first |
| `ViewerEventSubscriber` | `src/Event/ViewerEventSubscriber.php` | 404 on the viewer when auditor or the viewer is disabled |
| `ConsoleEventSubscriber` | `src/Event/ConsoleEventSubscriber.php` | Console user provider during commands |
| `UserProvider`, `ConsoleUserProvider` | `src/User/` | Blame user with its class; the impersonator (switch user) apart |
| `SecurityProvider`, `RoleChecker` | `src/Security/` | Client IP and firewall; per-entity `roles.view` checks |
| `ExtraDataProvider` | `src/ExtraData/ExtraDataProvider.php` | Opt-in `extra_data` (route name only) |
| `PruneAuditLogsHandler`, `RetentionSchedule` | `src/Retention/` | Scheduled pruning through Messenger and Scheduler |
| `ActivityGraphProvider`, `RevisionComparator` | `src/Viewer/` | Activity graph and counts (cached), revision compare |
| `ObjectHistory`, `Overview`, `HistoryFilters`, `ActionNavigator`, `ObjectExistence`, `WordDiff` | `src/Viewer/` | Data of the viewer pages: object history by cursor and its filters, recent activity and action counts, previous / next action, "(deleted)" marker, word diff |
| `HistoryRows` | `src/Viewer/` | Rows of a list of actions (phrase, context, "(deleted)" priming), shared by the viewer and the component |
| Twig extensions | `src/Twig/` | `time_ago`, `dh_auditor_is_viewable()`, `dh_auditor_is_diff_leaf()`, `dh_auditor_last_change(s)()`, `dh_auditor_field_label()`, `\|dh_auditor_phrase`, `\|dh_auditor_phrase_text`, `\|dh_auditor_shorten`, `\|dh_auditor_author`, `dh_auditor_object_url()`, `dh_auditor_context()`, `dh_auditor_entity_name()`, `dh_auditor_is_deleted()`, `dh_auditor_history_item()`, `dh_auditor_word_diff()`, `dh_auditor_entity_url()`, `dh_auditor_transaction_url()` (`root:` option), `dh_auditor_user_url()` (null when the viewer is unavailable), `dh_auditor_user_class()` |
| `History` (`<twig:DhAuditor:History>`) | `src/Twig/Component/` | History of an object in the pages of the host app, on `RootQuery`, one page per "older" link (cursor in the query string of the main request); registered only when `symfony/ux-twig-component` is installed |
| `HistoryElementInterface`, `SortKey` | `src/History/` | Elements of the host app mixed with the actions of the component, by a sort key comparable with `Entry::getSortKey()` |
| `TranslatedFieldLabelResolver` | `src/History/` | `FieldLabelResolverInterface` service: `dh_auditor_fields` domain, key `FQCN.field`, humanized fallback |
| `HistoryPhraser`, `ValueFormatter` | `src/History/` | A `HistoryItem` told as a `Phrase` of `Fragment`s made of typed parts (`Part/`: text, value, object reference; no HTML), from the `dh_auditor_history` ICU domain or `#[AsChangeDescriber]` services, and its author; raw diff values read through the Doctrine mapping |
| `ObjectUrlResolverInterface`, `ViewerObjectUrlResolver` | `src/History/` | URL of an object reference (default: its history in the viewer, visible with the `view` role, or to all without role checker); rendered by `History/phrase.html.twig` and `History/object_reference.html.twig` |
| `ClearActivityCacheCommand` | `src/Command/` | `audit:cache:clear` |

### Templates and assets

- Plain Twig in `src/Resources/views/` (`layout.html.twig`, `Audit/*.html.twig`, `History/*.html.twig`: one row per action in `History/_row.html.twig`), overridable under `templates/bundles/DHAuditorBundle/`. No Stimulus nor AssetMapper; one optional Twig component (`Component/History.html.twig`), which renders the `History/` row partials outside the viewer: they link through the viewer URL functions, never `path()`.
- Tailwind CSS 4 with the `dh` prefix: every Tailwind class of the templates (macros and class strings included) is written `dh:…` (`dh:hidden dh:sm:inline`, `dh:bg-dh-card`, `dh:group`), so that it never meets the classes of a host application; `ViewerControllerTest::testViewerClassesCarryThePrefix` and `HistoryTest::testClassesCarryThePrefix` fail on an unprefixed class. Sources in `src/Resources/assets/css/`: `viewer.css` (tokens of `theme.css`, `@source` of the views, controls, bars; importable by an application), entry points `app.css` (no Preflight, loadable next to a host stylesheet) and `preflight.css` (loaded by the bundle layout only). The compiled `src/Resources/public/app.css` and `preflight.css` are **committed**: after a template or CSS change that uses new classes, run `make build-assets` and commit them.
- JSON deep diffs: templates must use `entry.isDeepDiff(field)` and `dh_auditor_is_diff_leaf(node)`, not key checks. Association links use `dh_auditor_is_viewable()`.

## Testing conventions

### Test structure
- `tests/` mirrors `src/`; the test application is in `tests/App/` (MicroKernel, `KERNEL_CLASS=DH\AuditorBundle\Tests\App\Kernel`), config fixtures in `tests/Fixtures/Resources/config/`.
- `tests/bootstrap.php` clears the test app cache (`cache:clear --no-warmup`) before every run.
- Unit tests extend `TestCase`, container tests `KernelTestCase`, HTTP tests `WebTestCase` (`self::createClient()`, `$client->loginUser(new InMemoryUser(…))`), compiler-pass tests `AbstractCompilerPassTestCase`; bundle configuration tests boot a Nyholm `TestKernel`.
- Every test class: `/** @internal */`, `#[Small]`, `final`, assertions through `$this->assert…()`. `createStub()` by default; `createMock()` only in a test that sets an `expects()`.

### Provider fixtures and schema traits
- `DH\Auditor\Tests\` is autoloaded from `vendor/damienharper/auditor-doctrine-provider/tests`: the audited entities are the provider's Blog fixtures (Author, Post, Comment, Tag) and tests reuse its schema traits (`BlogSchemaSetupTrait`, `DefaultSchemaSetupTrait`).
- A test that overrides `setUp()` while using a schema trait must replay the trait steps itself (provider from the container, then `configureEntities()`, `setupEntitySchemas()`, `setupAuditSchemas()`): `parent::setUp()` does not run the trait's `setUp()`.
- Tests always run on SQLite files under `tests/App/var/` (`tests/App/config/packages/doctrine.yaml`): platform-specific behaviour is tested in the provider.

### `Entry` model
`$entry->type` is a plain string, `$entry->extraData` is already decoded, `$entry->blame` is the decoded blame array with the `username`, `userFqdn`, `userFirewall`, `ip`, `impersonator` and `via` shortcuts (schema v2). See the core `CLAUDE.md` for details.

## Tooling details

- **php-cs-fixer**, **phpstan**, and **rector** each have their own isolated Composer project under `tools/`. Never run them directly via `vendor/bin/` — use the `composer` scripts or the `tools/` paths (`php tools/phpstan/vendor/bin/phpstan …`).
- PHPStan runs at level max on `src/` only (tests are not analysed); current suppressions are tracked in `phpstan.neon`.
- `rector.php` covers `src/` and `tests/`. Keep the first `__invoke()` parameter of `PruneAuditLogsHandler`: Messenger resolves the message from it.
- The Docker targets delete `composer.lock` and reinstall on every run.
