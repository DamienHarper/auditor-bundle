# Custom user provider

If you don't use Symfony's `TokenStorage` to save your current user, you can configure 
a custom user provider. You just need to implement the `UserProviderInterface` and 
configure it as a service named `dh_doctrine_audit.user_provider`.

```php
<?php

use DH\DoctrineAuditBundle\User\User;
use DH\DoctrineAuditBundle\User\UserInterface;
use DH\DoctrineAuditBundle\User\UserProviderInterface;

class CustomUserProvider implements UserProviderInterface
{
    public function getUser(): ?UserInterface
    {
        // Your logic goes here...
        return new User($yourUserId, $yourUsername);
    }
}
```

Then add this to your `services.yaml` file:

```yaml
services:
    dh_doctrine_audit.user_provider:
        class: App\CustomUserProvider
```
