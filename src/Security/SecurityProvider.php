<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Security;

use DH\Auditor\Security\SecurityProviderInterface;
use Symfony\Bundle\SecurityBundle\Security\FirewallConfig;
use Symfony\Bundle\SecurityBundle\Security\FirewallMap;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final readonly class SecurityProvider implements SecurityProviderInterface
{
    public function __construct(
        private RequestStack $requestStack,
        #[Autowire(service: 'security.firewall.map')]
        private FirewallMap $firewallMap,
    ) {}

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
