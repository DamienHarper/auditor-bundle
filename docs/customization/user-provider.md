A user provider returns information about the current user for audit entries.

## Interface

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
    public function getIdentifier(): ?string;
    public function getUsername(): ?string;
}
```

## Built-in Provider

The default `DH\AuditorBundle\User\UserProvider`:

```php
public function __invoke(): ?UserInterface
{
    $tokenUser = $this->getTokenUser();
    $impersonatorUser = $this->getImpersonatorUser();

    // Get identifier if getId() method exists
    $identifier = null;
    if ($tokenUser instanceof SymfonyUserInterface) {
        if (method_exists($tokenUser, 'getId')) {
            $identifier = $tokenUser->getId();
        }
        $username = $tokenUser->getUserIdentifier();
    }

    // Track impersonation
    if ($impersonatorUser instanceof SymfonyUserInterface) {
        $username .= '[impersonator '.$impersonatorUser->getUserIdentifier().']';
    }

    if (null === $identifier && null === $username) {
        return null;
    }

    return new User((string) $identifier, $username);
}
```

## Creating a Custom Provider

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
            $user->getEmail()
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

## Examples

### API Token Authentication

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

### External OAuth/SSO

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

### Combining Multiple Sources

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

## Next Steps

- [Security Provider](security-provider.md)
- [Role Checker](role-checker.md)
