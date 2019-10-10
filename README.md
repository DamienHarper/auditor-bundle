# DoctrineAuditBundle [![Tweet](https://img.shields.io/twitter/url/http/shields.io.svg?style=social)](https://twitter.com/intent/tweet?text=Create%20audit%20logs%20for%20all%20Doctrine%20ORM%20database%20related%20changes%20with%20DoctrineAuditBundle.&url=https://github.com/DamienHarper/DoctrineAuditBundle&hashtags=doctrine-audit-log-bundle)
[![Latest Stable Version](https://poser.pugx.org/damienharper/doctrine-audit-bundle/v/stable)](https://packagist.org/packages/damienharper/doctrine-audit-bundle)
[![Latest Unstable Version](https://poser.pugx.org/damienharper/doctrine-audit-bundle/v/unstable)](https://packagist.org/packages/damienharper/doctrine-audit-bundle)
[![Build Status](https://travis-ci.com/DamienHarper/DoctrineAuditBundle.svg?branch=master)](https://travis-ci.com/DamienHarper/DoctrineAuditBundle)
[![License](https://poser.pugx.org/damienharper/doctrine-audit-bundle/license)](https://packagist.org/packages/damienharper/doctrine-audit-bundle)
[![Maintainability](https://api.codeclimate.com/v1/badges/2b8ef891de14763f043b/maintainability)](https://codeclimate.com/github/DamienHarper/DoctrineAuditBundle/maintainability)
[![Test Coverage](https://api.codeclimate.com/v1/badges/2b8ef891de14763f043b/test_coverage)](https://codeclimate.com/github/DamienHarper/DoctrineAuditBundle/test_coverage)
[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/DamienHarper/DoctrineAuditBundle/badges/quality-score.png?b=master)](https://scrutinizer-ci.com/g/DamienHarper/DoctrineAuditBundle/?branch=master)<br/>
[![Total Downloads](https://poser.pugx.org/damienharper/doctrine-audit-bundle/downloads)](https://packagist.org/packages/damienharper/doctrine-audit-bundle)
[![Monthly Downloads](https://poser.pugx.org/damienharper/doctrine-audit-bundle/d/monthly)](https://packagist.org/packages/damienharper/doctrine-audit-bundle)
[![Daily Downloads](https://poser.pugx.org/damienharper/doctrine-audit-bundle/d/daily)](https://packagist.org/packages/damienharper/doctrine-audit-bundle)

This bundle creates audit logs for all Doctrine ORM database related changes:

- inserts and updates including their diffs and relation field diffs.
- many to many relation changes, association and dissociation actions.
- if there is an user in token storage, it is used to identify the user who made the changes.
- audit entries are inserted within the same transaction during **flush** event 
so that even if something fails the global state remains clean.

Basically you can track any change of any entity from audit logs.

**NOTE:** this bundle cannot track DQL or direct SQL update or delete statement executions.

You can try out this bundle by cloning its companion demo app. 
Follow instructions at [doctrine-audit-bundle-demo](https://github.com/DamienHarper/doctrine-audit-bundle-demo).


## Official Documentation
The official documentation can be found [here](doc/00-index.md).


## Version Information
 Version   | Status                  | PHP Version
:----------|:------------------------|:------------
 3.x       | Active support :rocket: | >= 7.1
 2.x       | Active support          | >= 7.1
 1.x       | End of life             | >= 7.1

Changelog is available [here](CHANGELOG.md)


## Usage
Once [installed](doc/11-installation.md) and [configured](doc/20-general-configuration.md), any database change 
affecting audited entities will be logged to audit logs automatically.
Also, running schema update or similar will automatically setup audit logs for every 
new auditable entity.

## Contributing
DoctrineAuditBundle is an open source project. Contributions made by the community are welcome. 
Send us your ideas, code reviews, pull requests and feature requests to help us improve this project.

Do not forget to provide unit tests when contributing to this project. 
To do so, follow instructions in this dedicated [README](tests/README.md)

## Supported DB
* MySQL
* MariaDB
* PostgreSQL
* SQLite

*This bundle should work with **any other** database supported by Doctrine. 
Though, we can only really support the ones we can test with [Travis CI](https://travis-ci.com).*

## Credits
- Thanks to [all contributors](https://github.com/DamienHarper/DoctrineAuditBundle/graphs/contributors)
- This bundle initially took some inspiration from [data-dog/audit-bundle](https://github.com/DATA-DOG/DataDogAuditBundle.git) and 
[simplethings/entity-audit-bundle](https://github.com/simplethings/EntityAuditBundle.git)

## License
DoctrineAuditBundle is free to use and is licensed under the [MIT license](http://www.opensource.org/licenses/mit-license.php)
