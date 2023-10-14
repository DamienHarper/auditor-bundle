<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Security;

use DH\Auditor\Security\SecurityProviderInterface;
use Symfony\Bundle\SecurityBundle\Security\FirewallConfig;
use Symfony\Bundle\SecurityBundle\Security\FirewallMap;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

class SecurityProvider implements SecurityProviderInterface
{
    private RequestStack $requestStack;

    private FirewallMap $firewallMap;

    public function __construct(RequestStack $requestStack, FirewallMap $firewallMap)
    {
        $this->requestStack = $requestStack;
        $this->firewallMap = $firewallMap;
    }

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
