<?php

namespace DH\AuditorBundle\Security;

use DH\Auditor\Security\IpProviderInterface;
use Symfony\Bundle\SecurityBundle\Security\FirewallMap;
use Symfony\Component\HttpFoundation\RequestStack;

class IpProvider implements IpProviderInterface
{
    private $requestStack;
    private $firewallMap;

    public function __construct(RequestStack $requestStack, FirewallMap $firewallMap)
    {
        $this->requestStack = $requestStack;
        $this->firewallMap = $firewallMap;
    }

    public function getClientIpAndFirewall(): array
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
