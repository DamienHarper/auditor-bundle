# auditor-bundle [![Tweet](https://img.shields.io/twitter/url/http/shields.io.svg?style=social)](https://twitter.com/intent/tweet?text=Create%20audit%20logs%20for%20all%20Doctrine%20ORM%20database%20related%20changes%20with%20DoctrineAuditBundle.&url=https://github.com/DamienHarper/auditor-bundle&hashtags=auditor-bundle)
[![Latest Stable Version](https://poser.pugx.org/damienharper/auditor-bundle/v/stable)](https://packagist.org/packages/damienharper/auditor-bundle)
[![Latest Unstable Version](https://poser.pugx.org/damienharper/auditor-bundle/v/unstable)](https://packagist.org/packages/damienharper/auditor-bundle)
![CI](https://github.com/DamienHarper/auditor-bundle/workflows/CI/badge.svg?branch=master)
[![License](https://poser.pugx.org/damienharper/auditor-bundle/license)](https://packagist.org/packages/damienharper/auditor-bundle)
[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/DamienHarper/auditor-bundle/badges/quality-score.png?b=master)](https://scrutinizer-ci.com/g/DamienHarper/auditor-bundle/?branch=master)
[![Total Downloads](https://poser.pugx.org/damienharper/auditor-bundle/downloads)](https://packagist.org/packages/damienharper/auditor-bundle)
[![Monthly Downloads](https://poser.pugx.org/damienharper/auditor-bundle/d/monthly)](https://packagist.org/packages/damienharper/auditor-bundle)
[![Daily Downloads](https://poser.pugx.org/damienharper/auditor-bundle/d/daily)](https://packagist.org/packages/damienharper/auditor-bundle)

`auditor-bundle`, formerly known as `DoctrineAuditBundle` integrates `auditor` library into any Symfony 3.4+ application.


## Demo
You can try out this bundle by cloning its companion demo app. 
Follow instructions at [auditor-bundle-demo](https://github.com/DamienHarper/auditor-bundle-demo).


## Official Documentation
`auditor-bundle` official documentation can be found [here](https://damienharper.github.io/auditor-docs/docs/auditor-bundle/index.html).


## Version Information
 Version   | Status                      | Requirements               | Badges
:----------|:----------------------------|:---------------------------|:-----------
 4.x       | Active development :rocket: | PHP >= 7.2, Symfony >= 3.4 | ![CI](https://github.com/DamienHarper/auditor-bundle/workflows/CI/badge.svg?branch=master) <br/>[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/DamienHarper/auditor-bundle/badges/quality-score.png?b=master)](https://scrutinizer-ci.com/g/DamienHarper/auditor-bundle/?branch=master)
 3.x       | Active support :rocket:     | PHP >= 7.1, Symfony >= 3.4 | ![CI](https://github.com/DamienHarper/auditor-bundle/workflows/CI/badge.svg?branch=3.x) <br/>[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/DamienHarper/auditor-bundle/badges/quality-score.png?b=3.x)](https://scrutinizer-ci.com/g/DamienHarper/auditor-bundle/?branch=3.x)
 2.x       | End of life                 | PHP >= 7.1, Symfony >= 3.4 |
 1.x       | End of life                 | PHP >= 7.1, Symfony >= 3.4 |

Changelog is available [here](https://damienharper.github.io/auditor-docs/docs/auditor-bundle/release-notes.html)


## Usage
Once [installed](https://damienharper.github.io/auditor-docs/docs/auditor-bundle/installation.html) and [configured](https://damienharper.github.io/auditor-docs/docs/auditor-bundle/configuration/general.html), any database change 
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
