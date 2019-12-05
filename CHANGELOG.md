# Changelog

All notable changes to this project will be documented in this file.

The format is inspired by [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [3.2.3] - 2019-12-05

### Changes
* Updated twig dependencies
* Fix a BC break introduced in `3.2.2` (#142)


## [3.2.2] - 2019-12-03

### Changes
* Fixed pagination when the pager has a lot of pages
* Fixed documentation related to `@Security` annotation (thanks to @jean-gui) 


## [3.2.1] - 2019-12-02

### Changes
* Restored `AuditReader::getAuditsCount()` which was removed by error in `3.2.0` 


## [3.2.0] - 2019-12-02

### Changes
* Symfony 5 support
* PHP 7.4 support
* Fixed audit not capturing some entity mutation coming from other Doctrine linteners/subscribers (fixes #122)


## [3.1.0] - 2019-11-21

### Changes
* Fixed Doctrine deprecations
* Ensure compatibility with doctrine/dbal <2.10.0
* Fixed table layout in the timeline (thanks to @Jonathan-Lathiere)
* Allow to filter audits using multiple filter values in `AuditReader::filterBy()`


## [3.0.0] - 2019-11-01
### What's new
* **Annotation support**: audit configuration (audited entities, ignored columns, etc) can be done mostly 
using annotations in entities.
* **Audit events**: an audit event is dispatched for every audit entry opening the doors to add 
custom behavior on audit entry creation.
* **Security controls**: audit access can be now restricted to specific roles.
* The bundle now supports non numeric IDs for the `User` objects (thanks to @Gonzalo1987).
* A transaction hash is now stored in audit tables and makes it easier to identify all operations performed 
in the same transaction.
  - The timeline now displays a transaction hash for each element. By clicking a transaction hash, 
  you get a complete overview of all the operations included into the transaction.
  - `AuditReader::getAudits()` now accepts a transaction hash as fifth parameter to return audits 
  logged in the given transaction for the given entity.
  - `AuditReader::getAuditsByTransactionHash()` accepts a transaction hash as parameter and returns 
  all the audits logged in the given transaction.
* Better single table (`SINGLE_TABLE`) inheritance support.
  - `AuditReader::getAudits()` now accepts a boolean as sixth parameter to return either audits 
  for the given entity only or audits for the given entity hierarchy.
* Add class table (`JOINED`) inheritance support (thanks to @versh23).
* Performance enhancements when saving a lot of entities at once (thanks to @acanicatti).
* Removed deprecation messages (thanks to @maxhelias).
* Fixed an issue with `audit:schema:update` command when run against a database with no audit table.
* A few icons have been added in the timeline.
* Revamped documentation.

### Breaking changes
* The structure of audit tables has changed so you have to run the migration command right after updating
* `AuditEntry::getUserId()` now return a `string` or `null` instead of an `int`.
* **When using a secondary database** Doctrine's `doctrine:schema:update` command do not create 
audit tables automatically anymore (this command cannot work with multiple databases simultaneously), 
use the bundled migration command instead which supports the same options as Doctrine's command.
  - `bin/console audit:schema:update --dump-sql` prints SQL queries that need be executed.
  - `bin/console audit:schema:update --force` executes relevant SQL queries.
* Configuration has changed to use a secondary database: you now only need to declare the dedicated 
entity manager in configuration file as described [here](https://github.com/DamienHarper/DoctrineAuditBundle/blob/master/doc/20-general-configuration.md#storage-configuration).
* The bundle requires `mbstring` extension (cf. https://www.php.net/manual/en/mbstring.installation.php).

### How to upgrade?
Either update your `composer.json` file manually to include `"damienharper/doctrine-audit-bundle": "^3.0"` 
or run 
```bash
composer require damienharper/doctrine-audit-bundle ^3.0
```

Due to internal changes requiring new columns and `blame_id` column type change, run the migration command after updating the bundle to update the structure of your current audit tables.
```bash
bin/console audit:schema:update --force
```


## [2.5.0] - 2019-09-04

### Changes
* Add an option to globally enable/disable auditing from configuration file (thanks to @MylesKingsnorth01)
* Allow to use a custom entity manager/secondary database for audits (thanks to @pawello92)
**Warning:** Using a custom entity manager/secondary database **breaks atomicity** because audited entity operation and audit storage are performed into different transactions.


## [2.4.0] - 2019-08-19

### Changes
* Fix deprecated usage of `spaceless` tag (thanks to @maxhelias)
* Timezone can be set from configuration file. It affects audit entries creation date and defaults to UTC (thanks to @MylesKingsnorth01)


## [2.3.2] - 2019-07-29

### Changes
* Add a new `AuditReader:getEntityTableAuditName()` method allowing to retrieve the audit table name for an entity (#68)

## [2.3.1] - 2019-06-13

### Changes
* Fix `Unknown "spaceless" filter` encountered in some configurations (#65)
* Updated `README.md` regarding Symfony 3.4 configuration and `Unknown "pagerfanta" function` error (#64)


## [2.3.0] - 2019-04-18

### Changes
* Add a way to globally enable and disable audit at runtime (#61 thanks to @webmasterMeyers)
* Fixed a Windows compatibility issue regarding URLs including backslashes (fixes #62)


## [2.2.0] - 2019-04-15

### Changes
* Performance improvements in audit viewer
* Paginated timeline
* listAuditsAction() now also include disabled entities (fixes #58)


## [2.1.0] - 2019-04-12

### Changes
* Reworked default templates to display audit entries in a timeline instead of a *simple* tabular view.
* Fixed some deprecated references
* `AuditReader::getAudits()` and `AuditController::showEntityHistoryAction()` do not return the first 50 history items anymore but the full history. Though, it is still possible to get only a few items by specifying `$pageSize` and `$page` arguments
* `README.md` adjustments

### Breaking changes
* Some default templates have been renamed


## [2.0.0] - 2019-03-25

### Changes
* Complete rewrite of many internal parts to improve testability and lessen complexity.
* Migration command allowing to change structure of concrete (currently used) audit tables.
* Tables with non numeric primary keys auditing support (closes #37)
* Firewall name and user object's Fully Qualified Domain Name are now stored in audit table (cf. #14)
* Improved unit tests
* New design of included audit viewer templates (now Bootstrap 4 based)
* [demo app](https://github.com/DamienHarper/doctrine-audit-bundle-demo) showing off included audit viewer templates

### Breaking changes
* The structure of audit tables has changed so you have to run the migration command right after updating
* `AuditReader` and `AuditEntry` have been moved from `DH\DoctrineAuditBundle` namespace to `DH\DoctrineAuditBundle\Reader` namespace.
* `AuditEntry::getUserId()` and `AuditEntry::getUsername()` now return `null` (instead of `"Unknown"`) when user is undefined

### How to upgrade?
Either update your `composer.json` file manually to include `"damienharper/doctrine-audit-bundle": "^2.0"` 
or run 
```bash
composer require damienharper/doctrine-audit-bundle ^2.0
```

Due to internal changes requiring new columns and `object_id` column type change, run the migration command after updating the bundle to update the structure of your current audit tables.
```bash
bin/console audit:schema:update
```


## [1.6.0] - 2019-03-04

### Changes
* Unit tests and code coverage
* Travis CI integration
* Add support to Identity through foreign Entities (fixes #40)
* Better handling of Proxy objects (fixes #44)

### Breaking changes
* `table` key in diffs (understand join table) is now present only when auditing associate/dissociate operations regarding ManyToMany relationships


## [1.5.0] - 2019-02-06

### Changes
* Tracking of impersonator in `blame_user` column (@webmasterMeyers)
* Add aliases for `@dh_doctrine_audit.configuration` and `dh_doctrine_audit.reader` services (fixes #36)
* `README.md` adjustments


## [1.4.0] - 2019-01-28

### Changes
* Schema support (fixes #32, thanks to @DrummerKH)
* Do not consider an entity as audited if its parent is (fixes #34)


## [1.3.0] - 2019-01-21

### Changes
* Add a way to enable/disable auditing for an entity at runtime (@eduardoweiland)


## [1.2.0] - 2018-12-13

### Changes
* Custom user provider support (thanks @eduardoweiland)
* Small `FAQ` update


## [1.1.0] - 2018-12-05

### Changes
* Added a way to filter audit entries by type in the bundled controller (thanks @kl3sk)
* Added an `FAQ` section to the `README` (thanks @kl3sk)
* Removed unneeded dependency to `SensioFrameworkExtraBundle` (fixes #22)

### Breaking changes
* Entries of type `AuditReader::DISSOCIATE` now refers to owning side ID in `object_id` field (fixes #4)
* Creation date of audit entries are now in `UTC` timezone 


## [1.0.8] - 2018-07-19
## [1.0.7] - 2018-06-22
## [1.0.6] - 2018-05-04
## [1.0.5] - 2018-03-22
## [1.0.4] - 2018-03-17
## [1.0.3] - 2018-03-12
## [1.0.2] - 2018-03-08
## [1.0.1] - 2018-02-10

## [1.0.0] - 2018-02-08

Initial release
