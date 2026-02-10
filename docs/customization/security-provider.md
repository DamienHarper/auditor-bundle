A security provider returns contextual security information for audit entries.

## Interface

```php
namespace DH\Auditor\Security;

interface SecurityProviderInterface
{
    public function __invoke(): array;
}
```

Returns an array with two elements:

```php
return [
    $clientIp,      // string|null - Client IP address
    $firewallName,  // string|null - Firewall or context name
];
```

## Built-in Provider

The default `DH\AuditorBundle\Security\SecurityProvider`:

```php
public function __invoke(): array
{
    $request = $this->requestStack->getCurrentRequest();

    if (!$request instanceof Request) {
        return [null, null];
    }

    $firewallConfig = $this->firewallMap->getFirewallConfig($request);

    return [
        $request->getClientIp(),
        $firewallConfig?->getName(),
    ];
}
```

## Creating a Custom Provider

### Basic Example

```php
<?php

namespace App\Audit;

use DH\Auditor\Security\SecurityProviderInterface;
use Symfony\Component\HttpFoundation\RequestStack;

class CustomSecurityProvider implements SecurityProviderInterface
{
    public function __construct(
        private readonly RequestStack $requestStack,
    ) {}

    public function __invoke(): array
    {
        $request = $this->requestStack->getCurrentRequest();

        if (null === $request) {
            return [null, null];
        }

        return [
            $request->getClientIp(),
            'my-app',
        ];
    }
}
```

### Registration

```yaml
# config/packages/dh_auditor.yaml
dh_auditor:
    security_provider: 'App\Audit\CustomSecurityProvider'
```

## Examples

### Behind a Proxy/Load Balancer

```php
<?php

namespace App\Audit;

use DH\Auditor\Security\SecurityProviderInterface;
use Symfony\Bundle\SecurityBundle\Security\FirewallMap;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

class ProxyAwareSecurityProvider implements SecurityProviderInterface
{
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly FirewallMap $firewallMap,
    ) {}

    public function __invoke(): array
    {
        $request = $this->requestStack->getCurrentRequest();

        if (!$request instanceof Request) {
            return [null, null];
        }

        // Check proxy headers in order of preference
        $clientIp = $request->headers->get('X-Real-IP')
            ?? $request->headers->get('X-Forwarded-For')
            ?? $request->getClientIp();

        // Handle X-Forwarded-For with multiple IPs
        if (str_contains($clientIp ?? '', ',')) {
            $clientIp = trim(explode(',', $clientIp)[0]);
        }

        $firewallConfig = $this->firewallMap->getFirewallConfig($request);

        return [$clientIp, $firewallConfig?->getName()];
    }
}
```

### Cloudflare

```php
<?php

namespace App\Audit;

use DH\Auditor\Security\SecurityProviderInterface;
use Symfony\Bundle\SecurityBundle\Security\FirewallMap;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

class CloudflareSecurityProvider implements SecurityProviderInterface
{
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly FirewallMap $firewallMap,
    ) {}

    public function __invoke(): array
    {
        $request = $this->requestStack->getCurrentRequest();

        if (!$request instanceof Request) {
            return [null, null];
        }

        // Cloudflare provides original IP in CF-Connecting-IP
        $clientIp = $request->headers->get('CF-Connecting-IP')
            ?? $request->getClientIp();

        $firewallConfig = $this->firewallMap->getFirewallConfig($request);

        return [$clientIp, $firewallConfig?->getName()];
    }
}
```

### AWS ALB / API Gateway

```php
<?php

namespace App\Audit;

use DH\Auditor\Security\SecurityProviderInterface;
use Symfony\Component\HttpFoundation\RequestStack;

class AwsSecurityProvider implements SecurityProviderInterface
{
    public function __construct(
        private readonly RequestStack $requestStack,
    ) {}

    public function __invoke(): array
    {
        $request = $this->requestStack->getCurrentRequest();

        if (null === $request) {
            return [null, null];
        }

        // AWS ALB/API Gateway headers
        $clientIp = $request->headers->get('X-Forwarded-For');
        if (null !== $clientIp && str_contains($clientIp, ',')) {
            // First IP is the original client
            $clientIp = trim(explode(',', $clientIp)[0]);
        }

        // Use API Gateway stage as context
        $context = $request->headers->get('X-Amzn-Api-Gateway-Stage', 'unknown');

        return [$clientIp ?? $request->getClientIp(), $context];
    }
}
```

### Console Context Aware

```php
<?php

namespace App\Audit;

use DH\Auditor\Security\SecurityProviderInterface;
use Symfony\Component\HttpFoundation\RequestStack;

class ConsoleAwareSecurityProvider implements SecurityProviderInterface
{
    private ?string $consoleContext = null;

    public function __construct(
        private readonly RequestStack $requestStack,
    ) {}

    public function __invoke(): array
    {
        $request = $this->requestStack->getCurrentRequest();

        // HTTP context
        if (null !== $request) {
            return [$request->getClientIp(), 'http'];
        }

        // Console context
        return ['127.0.0.1', $this->consoleContext ?? 'console'];
    }

    public function setConsoleContext(?string $context): void
    {
        $this->consoleContext = $context;
    }
}
```

## Next Steps

- [Role Checker](role-checker.md)
- [User Provider](user-provider.md)
