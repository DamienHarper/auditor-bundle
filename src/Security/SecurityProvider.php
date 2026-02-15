<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Security;

use DH\Auditor\Security\SecurityProviderInterface;
use Symfony\Bundle\SecurityBundle\Security\FirewallConfig;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Http\FirewallMapInterface;

final readonly class SecurityProvider implements SecurityProviderInterface
{
    public function __construct(private RequestStack $requestStack, private FirewallMapInterface $firewallMap) {}

    public function __invoke(): array
    {
        $clientIp = null;
        $firewallName = null;

        $request = $this->requestStack->getCurrentRequest();
        if ($request instanceof Request) {
            $firewallConfig = $this->firewallMap->getFirewallConfig($request);

            $clientIp = $request->getClientIp();
            $firewallName = $firewallConfig instanceof FirewallConfig ? $firewallConfig->getName() : null;
        }

        return [$clientIp, $firewallName];
    }
}
