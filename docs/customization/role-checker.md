A role checker determines if the current user can access audit logs for a specific entity.

## Interface

```php
namespace DH\Auditor\Security;

interface RoleCheckerInterface
{
    public function __invoke(string $entity, string $scope): bool;
}
```

Parameters:
- `$entity`: Entity FQCN (e.g., `App\Entity\User`)
- `$scope`: Access type (currently only `'view'`)

Returns `true` to grant access, `false` to deny.

## Built-in Checker

The default `DH\AuditorBundle\Security\RoleChecker`:

```php
public function __invoke(string $entity, string $scope): bool
{
    $user = $this->provider->getAuditor()->getConfiguration()->getUserProvider()();

    if (!$user instanceof UserInterface) {
        return true;  // No user = grant access
    }

    $entities = $this->provider->getConfiguration()->getEntities();
    $entityConfig = $entities[$entity] ?? null;
    $roles = $entityConfig['roles'] ?? null;

    if (null === $roles || !array_key_exists($scope, $roles)) {
        return true;  // No roles configured = grant access
    }

    // Check if user has any of the required roles
    return array_any($roles[$scope], fn($role) => 
        $this->authorizationChecker->isGranted($role)
    );
}
```

### Default Behavior

| Scenario                        | Access  |
|---------------------------------|---------|
| No roles configured for entity  | Granted |
| No user authenticated           | Granted |
| User has required role          | Granted |
| User lacks required role        | Denied  |   

## Configuring Roles

### Via YAML

```yaml
dh_auditor:
    providers:
        doctrine:
            entities:
                App\Entity\User:
                    roles:
                        view:
                            - ROLE_ADMIN
                            - ROLE_AUDITOR
                App\Entity\Post:
                    roles:
                        view:
                            - ROLE_EDITOR
                App\Entity\Comment: ~  # No restrictions
```

### Via Attributes

```php
use DH\Auditor\Provider\Doctrine\Auditing\Annotation as Audit;

#[Audit\Auditable]
#[Audit\Security(view: ['ROLE_ADMIN', 'ROLE_AUDITOR'])]
class User
{
    // ...
}
```

## Creating a Custom Checker

### Basic Example

```php
<?php

namespace App\Audit;

use DH\Auditor\Security\RoleCheckerInterface;
use Symfony\Bundle\SecurityBundle\Security;

class CustomRoleChecker implements RoleCheckerInterface
{
    public function __construct(
        private readonly Security $security,
    ) {}

    public function __invoke(string $entity, string $scope): bool
    {
        // Super admins can view everything
        if ($this->security->isGranted('ROLE_SUPER_ADMIN')) {
            return true;
        }

        // Require authentication for all audits
        if (null === $this->security->getUser()) {
            return false;
        }

        return $this->security->isGranted('ROLE_AUDIT_VIEWER');
    }
}
```

### Registration

```yaml
# config/packages/dh_auditor.yaml
dh_auditor:
    role_checker: 'App\Audit\CustomRoleChecker'
```

## Examples

### Entity-Specific Rules

```php
<?php

namespace App\Audit;

use App\Entity\User;
use App\Entity\Order;
use App\Entity\Payment;
use DH\Auditor\Security\RoleCheckerInterface;
use Symfony\Bundle\SecurityBundle\Security;

class EntityRoleChecker implements RoleCheckerInterface
{
    private const ENTITY_ROLES = [
        User::class => ['ROLE_USER_ADMIN'],
        Order::class => ['ROLE_ORDER_MANAGER', 'ROLE_ACCOUNTANT'],
        Payment::class => ['ROLE_ACCOUNTANT'],
    ];

    public function __construct(
        private readonly Security $security,
    ) {}

    public function __invoke(string $entity, string $scope): bool
    {
        // Super admin bypass
        if ($this->security->isGranted('ROLE_SUPER_ADMIN')) {
            return true;
        }

        $requiredRoles = self::ENTITY_ROLES[$entity] ?? [];

        // No restriction for unlisted entities
        if (empty($requiredRoles)) {
            return true;
        }

        foreach ($requiredRoles as $role) {
            if ($this->security->isGranted($role)) {
                return true;
            }
        }

        return false;
    }
}
```

### Using Symfony Voters

```php
<?php

namespace App\Audit;

use DH\Auditor\Security\RoleCheckerInterface;
use Symfony\Bundle\SecurityBundle\Security;

class VoterRoleChecker implements RoleCheckerInterface
{
    public function __construct(
        private readonly Security $security,
    ) {}

    public function __invoke(string $entity, string $scope): bool
    {
        // Delegate to a Symfony voter
        return $this->security->isGranted('AUDIT_VIEW', $entity);
    }
}
```

With voter:

```php
<?php

namespace App\Security\Voter;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

class AuditVoter extends Voter
{
    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === 'AUDIT_VIEW' && is_string($subject);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        
        if (null === $user) {
            return false;
        }

        // Custom logic based on $subject (entity FQCN)
        // and $user

        return true;
    }
}
```

### External Authorization Service

```php
<?php

namespace App\Audit;

use App\Security\AuthorizationService;
use DH\Auditor\Security\RoleCheckerInterface;
use Symfony\Bundle\SecurityBundle\Security;

class ExternalRoleChecker implements RoleCheckerInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly AuthorizationService $authService,
    ) {}

    public function __invoke(string $entity, string $scope): bool
    {
        $user = $this->security->getUser();

        if (null === $user) {
            return false;
        }

        return $this->authService->isAllowed(
            $user->getUserIdentifier(),
            'audit',
            $entity,
            $scope
        );
    }
}
```

### Time-Based Access

```php
<?php

namespace App\Audit;

use DH\Auditor\Security\RoleCheckerInterface;
use Symfony\Bundle\SecurityBundle\Security;

class TimeBasedRoleChecker implements RoleCheckerInterface
{
    public function __construct(
        private readonly Security $security,
    ) {}

    public function __invoke(string $entity, string $scope): bool
    {
        // Admins always have access
        if ($this->security->isGranted('ROLE_ADMIN')) {
            return true;
        }

        // Others only during business hours
        $hour = (int) date('H');
        $dayOfWeek = (int) date('N');
        
        $isBusinessHours = $hour >= 9 && $hour < 18;
        $isWeekday = $dayOfWeek <= 5;

        return $isBusinessHours && $isWeekday;
    }
}
```

## Next Steps

- [Audit Viewer](../viewer/index.md)
- [User Provider](user-provider.md)
- [Security Provider](security-provider.md)
