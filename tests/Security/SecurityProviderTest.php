<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Tests\Security;

use DH\AuditorBundle\Security\SecurityProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security\FirewallMap;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @internal
 */
#[Small]
final class SecurityProviderTest extends TestCase
{
    public function testTheClientIpIsKeptByDefault(): void
    {
        $this->assertSame(['192.168.1.42', null], new SecurityProvider($this->requestStack('192.168.1.42'), $this->firewallMap())());
    }

    #[DataProvider('provideTheClientIpIsAnonymizedCases')]
    public function testTheClientIpIsAnonymized(string $ip, string $expected): void
    {
        $this->assertSame([$expected, null], new SecurityProvider($this->requestStack($ip), $this->firewallMap(), true)());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideTheClientIpIsAnonymizedCases(): iterable
    {
        yield 'IPv4' => ['192.168.1.42', '192.168.1.0'];

        yield 'IPv6' => ['2001:db8:85a3:8d3:1319:8a2e:370:7344', '2001:db8:85a3:8d3::'];
    }

    public function testNoRequestGivesNoIp(): void
    {
        $this->assertSame([null, null], new SecurityProvider(new RequestStack(), $this->firewallMap(), true)());
    }

    private function requestStack(string $ip): RequestStack
    {
        return new RequestStack([Request::create('/', server: ['REMOTE_ADDR' => $ip])]);
    }

    private function firewallMap(): FirewallMap
    {
        return new FirewallMap(new Container(), []);
    }
}
