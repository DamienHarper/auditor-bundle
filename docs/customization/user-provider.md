# User Provider

> **Customize how the current user is identified in audit entries**

A user provider returns information about the current user for audit entries.

## 📝 Interface

```php
namespace DH\Auditor\User;

interface UserProviderInterface
{
    public function __invoke(): ?UserInterface;
}
```

The provider must return a `UserInterface` or `null`:

```php
namespace DH\Auditor\User;

interface UserInterface
{
    public ?string $identifier { get; }

    public ?string $username { get; }

    public ?string $fqdn { get; }                 // class of the user, written as user_fqdn

    public ?UserInterface $impersonator { get; }  // user who impersonates this one (switch user)
}
```

The interface declares read-only property hooks, not getter methods: read `$user->identifier`, `$user->username`,
`$user->fqdn` and `$user->impersonator`.
The built-in `DH\Auditor\User\User` implements it and is what the examples below return.

## 📦 Built-in Provider

The default `DH\AuditorBundle\User\UserProvider`:

- blames the user of the security token: its `getId()` (an empty identifier without that method), its
  `getUserIdentifier()` and its class (Doctrine proxies resolved), written as `user_fqdn`;
- when that user is impersonated (switch user, `SwitchUserToken`), the user of the original token is its
  impersonator, written apart in the `impersonator` key of the `blame` JSON:

```json
{
  "username": "alice",
  "user_fqdn": "App\\Entity\\User",
  "user_firewall": "main",
  "ip": "10.0.0.1",
  "impersonator": {"id": "1", "username": "admin", "fqdn": "App\\Entity\\User"}
}
```

The viewer shows an "impersonated by" badge next to the origin of the action. To find the actions made by
impersonating someone, use the `ImpersonatorFilter` of auditor-doctrine-provider, or `$entry->impersonator`.

> [!NOTE]
> Before 8.0, the built-in provider appended the impersonator to the username (`alice[impersonator admin]`) and
> `user_fqdn` held `DH\Auditor\User\User`. Those entries are not rewritten; the viewer does not show that class.

## 🖥️ Console Commands

For CLI commands, the bundle provides `DH\AuditorBundle\User\ConsoleUserProvider` which automatically tracks the command name:

```php
public function __invoke(): ?UserInterface
{
    if (null === $this->currentCommand) {
        return null;
    }

    // Command name is used as both ID and username
    return new User(
        $this->currentCommand,  // e.g., 'app:import-users'
        $this->currentCommand
    );
}
```

> [!TIP]
> This allows you to:
> - 🔍 Identify which command made specific changes
> - 🔎 Filter audit entries by command in the viewer
> - 📊 Track automated processes separately from user actions

**Example audit entries:**
- `app:import-users` - Import command
- `doctrine:fixtures:load` - Fixtures loading
- `app:sync-products` - Sync command

While a command runs, the console user provider replaces the configured `user_provider`; the configured one is
restored when the command ends.

## 🔧 Creating a Custom Provider

### Basic Example

```php
<?php

namespace App\Audit;

use DH\Auditor\User\User;
use DH\Auditor\User\UserInterface;
use DH\Auditor\User\UserProviderInterface;

class CustomUserProvider implements UserProviderInterface
{
    public function __construct(
        private readonly MyAuthService $authService,
    ) {}

    public function __invoke(): ?UserInterface
    {
        $user = $this->authService->getCurrentUser();

        if (null === $user) {
            return null;
        }

        return new User(
            (string) $user->getId(),
            $user->getEmail(),
            $user::class, // written as user_fqdn
        );
    }
}
```

### Registration

```yaml
# config/packages/dh_auditor.yaml
dh_auditor:
    user_provider: 'App\Audit\CustomUserProvider'
```

The service is auto-wired. If you need explicit configuration:

```yaml
# config/services.yaml
services:
    App\Audit\CustomUserProvider:
        arguments:
            - '@App\Security\MyAuthService'
```

## 📚 Examples

### 🔑 API Token Authentication

```php
<?php

namespace App\Audit;

use App\Security\ApiTokenManager;
use DH\Auditor\User\User;
use DH\Auditor\User\UserInterface;
use DH\Auditor\User\UserProviderInterface;

class ApiUserProvider implements UserProviderInterface
{
    public function __construct(
        private readonly ApiTokenManager $tokenManager,
    ) {}

    public function __invoke(): ?UserInterface
    {
        $token = $this->tokenManager->getCurrentToken();

        if (null === $token) {
            return null;
        }

        return new User(
            $token->getClientId(),
            sprintf('API: %s', $token->getClientName())
        );
    }
}
```

### 🌐 External OAuth/SSO

```php
<?php

namespace App\Audit;

use DH\Auditor\User\User;
use DH\Auditor\User\UserInterface;
use DH\Auditor\User\UserProviderInterface;
use Symfony\Component\HttpFoundation\RequestStack;

class OAuthUserProvider implements UserProviderInterface
{
    public function __construct(
        private readonly RequestStack $requestStack,
    ) {}

    public function __invoke(): ?UserInterface
    {
        $request = $this->requestStack->getCurrentRequest();

        if (null === $request) {
            return null;
        }

        // User info from OAuth headers
        $userId = $request->headers->get('X-User-Id');
        $userName = $request->headers->get('X-User-Email');

        if (null === $userId) {
            return null;
        }

        return new User($userId, $userName ?? 'unknown');
    }
}
```

### 🔀 Combining Multiple Sources

```php
<?php

namespace App\Audit;

use App\Security\ApiTokenManager;
use DH\Auditor\User\User;
use DH\Auditor\User\UserInterface;
use DH\Auditor\User\UserProviderInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

class CompositeUserProvider implements UserProviderInterface
{
    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
        private readonly ApiTokenManager $apiTokenManager,
    ) {}

    public function __invoke(): ?UserInterface
    {
        // Try Symfony security first
        $token = $this->tokenStorage->getToken();
        if (null !== $token && null !== $token->getUser()) {
            $user = $token->getUser();
            return new User(
                method_exists($user, 'getId') ? (string) $user->getId() : '',
                $user->getUserIdentifier()
            );
        }

        // Fall back to API token
        $apiToken = $this->apiTokenManager->getCurrentToken();
        if (null !== $apiToken) {
            return new User(
                $apiToken->getClientId(),
                'API: ' . $apiToken->getClientName()
            );
        }

        return null;
    }
}
```

---

## 🚀 Next Steps

- 🧭 [Blame Context](blame-context.md) - Record how a change was made (import, command, Messenger message…)
- 🔒 [Security Provider](security-provider.md) - Customize IP/context detection
- 🛡️ [Role Checker](role-checker.md) - Customize access control
