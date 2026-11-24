# auditor-bundle [![Tweet](https://img.shields.io/twitter/url/http/shields.io.svg?style=social)](https://twitter.com/intent/tweet?text=Create%20audit%20logs%20for%20all%20Doctrine%20ORM%20database%20related%20changes%20with%20auditor-bundle.&url=https://github.com/DamienHarper/auditor-bundle&hashtags=auditor-bundle)
[![Latest Stable Version](https://poser.pugx.org/damienharper/auditor-bundle/v/stable)](https://packagist.org/packages/damienharper/auditor-bundle)
[![Latest Unstable Version](https://poser.pugx.org/damienharper/auditor-bundle/v/unstable)](https://packagist.org/packages/damienharper/auditor-bundle)
[![auditor-bundle 8.x CI](https://github.com/DamienHarper/auditor-bundle/actions/workflows/ci-8.x.yml/badge.svg)](https://github.com/DamienHarper/auditor-bundle/actions/workflows/ci-8.x.yml)
[![License](https://poser.pugx.org/damienharper/auditor-bundle/license)](https://packagist.org/packages/damienharper/auditor-bundle)
[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/DamienHarper/auditor-bundle/badges/quality-score.png?b=master)](https://scrutinizer-ci.com/g/DamienHarper/auditor-bundle/?branch=master)
[![codecov](https://codecov.io/gh/DamienHarper/auditor-bundle/branch/master/graph/badge.svg)](https://app.codecov.io/gh/DamienHarper/auditor-bundle/branch/master)
[![Total Downloads](https://poser.pugx.org/damienharper/auditor-bundle/downloads)](https://packagist.org/packages/damienharper/auditor-bundle)
[![Monthly Downloads](https://poser.pugx.org/damienharper/auditor-bundle/d/monthly)](https://packagist.org/packages/damienharper/auditor-bundle)
[![Daily Downloads](https://poser.pugx.org/damienharper/auditor-bundle/d/daily)](https://packagist.org/packages/damienharper/auditor-bundle)

`auditor-bundle`, formerly known as `DoctrineAuditBundle` integrates `auditor` library into any Symfony 8.1+ application.

It records every insert, update, removal and many-to-many change of your Doctrine ORM entities in
audit tables, with who made the change, when and how, and ships a web viewer to browse the history.
It pulls in [`auditor`](https://github.com/DamienHarper/auditor) (the core) and
[`auditor-doctrine-provider`](https://github.com/DamienHarper/auditor-doctrine-provider) (the Doctrine ORM provider).


## Quick start
```bash
composer require damienharper/auditor-bundle
```

```yaml
# config/packages/dh_auditor.yaml
dh_auditor:
    providers:
        doctrine:
            entities:
                App\Entity\Post: ~
```

Then create the audit tables (`bin/console doctrine:migrations:diff` and `doctrine:migrations:migrate`).
See the [installation guide](https://damienharper.github.io/auditor-docs/auditor-bundle/getting-started/installation)
for the viewer routes and the full configuration.


## Using an AI coding agent?
This package ships an [Agent Skill](https://agentskills.io) in [`skills/auditor-bundle/`](skills/auditor-bundle/SKILL.md)
(`vendor/damienharper/auditor-bundle/skills/` once installed) describing the bundle for the installed version.
With Claude Code:
```
/plugin marketplace add DamienHarper/auditor
/plugin install auditor-bundle@auditor
```
The documentation is also available as [llms.txt](https://damienharper.github.io/auditor-docs/llms.txt).


## Demo
You can try out this bundle by cloning its companion demo app. 
Follow instructions at [auditor-bundle-demo](https://github.com/DamienHarper/auditor-bundle-demo).


## Official Documentation
`auditor-bundle` official documentation can be found [here](https://damienharper.github.io/auditor-docs/auditor-bundle).


## Version Information
| Version | Status                      | Requirements                                                            | Badges                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                         |
|:--------|:----------------------------|:------------------------------------------------------------------------|:-------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| 8.x     | Active development :rocket: | PHP >= 8.4, Symfony >= 8.1, Doctrine DBAL >= 4.6, Doctrine ORM >= 3.7   | [![auditor-bundle 8.x CI](https://github.com/DamienHarper/auditor-bundle/actions/workflows/ci-8.x.yml/badge.svg)](https://github.com/DamienHarper/auditor-bundle/actions/workflows/ci-8.x.yml) <br/>[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/DamienHarper/auditor-bundle/badges/quality-score.png?b=master)](https://scrutinizer-ci.com/g/DamienHarper/auditor-bundle/?branch=master) <br/>[![codecov](https://codecov.io/gh/DamienHarper/auditor-bundle/branch/master/graph/badge.svg)](https://app.codecov.io/gh/DamienHarper/auditor-bundle/branch/master) |
| 7.x     | Bug and security fixes      | PHP >= 8.4, Symfony >= 8.1, Doctrine DBAL >= 4.3, Doctrine ORM >= 3.2   | [![auditor-bundle 7.x CI](https://github.com/DamienHarper/auditor-bundle/actions/workflows/ci-7.x.yml/badge.svg)](https://github.com/DamienHarper/auditor-bundle/actions/workflows/ci-7.x.yml) <br/>[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/DamienHarper/auditor-bundle/badges/quality-score.png?b=7.x)](https://scrutinizer-ci.com/g/DamienHarper/auditor-bundle/?branch=7.x) <br/>[![codecov](https://codecov.io/gh/DamienHarper/auditor-bundle/branch/7.x/graph/badge.svg)](https://app.codecov.io/gh/DamienHarper/auditor-bundle/branch/7.x) |
| 6.x     | End of life                 | PHP >= 8.2, Symfony >= 5.4                                              | [![auditor-bundle 6.x CI](https://github.com/DamienHarper/auditor-bundle/actions/workflows/ci-6.x.yml/badge.svg)](https://github.com/DamienHarper/auditor-bundle/actions/workflows/ci-6.x.yml) <br/>[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/DamienHarper/auditor-bundle/badges/quality-score.png?b=6.x)](https://scrutinizer-ci.com/g/DamienHarper/auditor-bundle/?branch=6.x) <br/>[![codecov](https://codecov.io/gh/DamienHarper/auditor-bundle/branch/6.x/graph/badge.svg)](https://app.codecov.io/gh/DamienHarper/auditor-bundle/branch/6.x)             |
| 5.x     | End of life                 | PHP >= 7.4, Symfony >= 4.4                                              | [![auditor-bundle 5.x CI](https://github.com/DamienHarper/auditor-bundle/actions/workflows/ci-5.x.yml/badge.svg)](https://github.com/DamienHarper/auditor-bundle/actions/workflows/ci-5.x.yml)                                                                                                                                                                                                                                                                                                                                                                                 |
| 4.x     | End of life                 | PHP >= 7.2, Symfony >= 3.4                                              |                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                |

Changelog is available in the [releases](https://github.com/DamienHarper/auditor-bundle/releases).

## Upgrading

See [UPGRADE-8.0.md](UPGRADE-8.0.md) for upgrade instructions from 7.x to 8.0 (the audit tables must be migrated), and [UPGRADE-7.0.md](UPGRADE-7.0.md) from 6.x to 7.0.


## Usage
Once [installed](https://damienharper.github.io/auditor-docs/auditor-bundle/getting-started/installation) and [configured](https://damienharper.github.io/auditor-docs/auditor-bundle/configuration), any database change 
affecting audited entities will be logged to audit logs automatically.
Also, running schema update or similar will automatically setup audit logs for every 
new auditable entity.


## Contributing
`auditor-bundle` is an open source project. Contributions made by the community are welcome. 
Send me your ideas, code reviews, pull requests and feature requests to help us improve this project.

Do not forget to provide unit tests when contributing to this project. 
To do so, follow instructions in this dedicated [README](tests/README.md)


## Credits
- Thanks to [all contributors](https://github.com/DamienHarper/auditor-bundle/graphs/contributors)


## License
`auditor-bundle` is free to use and is licensed under the [MIT license](http://www.opensource.org/licenses/mit-license.php)
