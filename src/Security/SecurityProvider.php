<?php

namespace DH\AuditorBundle\Security;

use DH\Auditor\Security\SecurityProviderInterface;
use Symfony\Bundle\SecurityBundle\Security\FirewallMap;
use Symfony\Component\HttpFoundation\RequestStack;

class SecurityProvider implements SecurityProviderInterface
{
    private $requestStack;
    private $firewallMap;

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
        if (null !== $request) {
            $firewallConfig = $this->firewallMap->getFirewallConfig($request);

            $clientIp = $request->getClientIp();
            $firewallName = null === $firewallConfig ? null : $firewallConfig->getName();
        }

        return [$clientIp, $firewallName];
    }
}
