<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Tests\DependencyInjection\Compiler;

use DH\Auditor\Auditor;
use DH\AuditorBundle\DependencyInjection\Compiler\RegisterProvidersCompilerPass;
use DH\AuditorBundle\Tests\Fixtures\Provider\StubProviderA;
use DH\AuditorBundle\Tests\Fixtures\Provider\StubProviderB;
use Matthias\SymfonyDependencyInjectionTest\PhpUnit\AbstractCompilerPassTestCase;
use PHPUnit\Framework\Attributes\Small;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/**
 * @internal
 *
 * @see RegisterProvidersCompilerPass
 */
#[Small]
final class RegisterProvidersCompilerPassTest extends AbstractCompilerPassTestCase
{
    public function testCompilerPassIsNoOpWithoutAuditorService(): void
    {
        // No Auditor service — compiler pass must not fail
        $this->compile();

        $this->assertContainerBuilderNotHasService(Auditor::class);
    }

    public function testCompilerPassAddsNoCallsWhenNoTaggedProviders(): void
    {
        $this->setDefinition(Auditor::class, new Definition(Auditor::class));

        $this->compile();

        $calls = $this->container->getDefinition(Auditor::class)->getMethodCalls();
        $registerCalls = array_filter($calls, static fn (array $c): bool => 'registerProvider' === $c[0]);

        $this->assertEmpty($registerCalls, 'No registerProvider call expected when no tagged providers exist');
    }

    public function testCompilerPassRegistersOneTaggedProvider(): void
    {
        $this->setDefinition(Auditor::class, new Definition(Auditor::class));

        $providerDef = new Definition(StubProviderA::class);
        $providerDef->addTag('dh_auditor.provider');
        $this->setDefinition('my_custom_provider', $providerDef);

        $this->compile();

        $auditorCalls = $this->container->getDefinition(Auditor::class)->getMethodCalls();
        $registerCalls = array_values(array_filter($auditorCalls, static fn (array $c): bool => 'registerProvider' === $c[0]));

        $this->assertCount(1, $registerCalls);
        $this->assertSame('my_custom_provider', (string) $registerCalls[0][1][0]);

        // The compiler pass must also inject Auditor into the provider definition
        $providerCalls = $this->container->getDefinition('my_custom_provider')->getMethodCalls();
        $setAuditorCalls = array_values(array_filter($providerCalls, static fn (array $c): bool => 'setAuditor' === $c[0]));

        $this->assertCount(1, $setAuditorCalls, 'setAuditor must be added to the provider definition by the compiler pass');
    }

    public function testCompilerPassRegistersMultipleTaggedProviders(): void
    {
        $this->setDefinition(Auditor::class, new Definition(Auditor::class));

        $provider1 = new Definition(StubProviderA::class);
        $provider1->addTag('dh_auditor.provider');
        $this->setDefinition('my_provider_1', $provider1);

        $provider2 = new Definition(StubProviderB::class);
        $provider2->addTag('dh_auditor.provider');
        $this->setDefinition('my_provider_2', $provider2);

        $this->compile();

        $calls = $this->container->getDefinition(Auditor::class)->getMethodCalls();
        $registerCalls = array_filter($calls, static fn (array $c): bool => 'registerProvider' === $c[0]);

        $this->assertCount(2, $registerCalls, 'Two registerProvider calls expected for two tagged providers');
    }

    public function testCompilerPassIgnoresNonTaggedServices(): void
    {
        $this->setDefinition(Auditor::class, new Definition(Auditor::class));

        // Service WITHOUT the dh_auditor.provider tag
        $nonProviderDef = new Definition(StubProviderA::class);
        $this->setDefinition('my_untagged_service', $nonProviderDef);

        $this->compile();

        $calls = $this->container->getDefinition(Auditor::class)->getMethodCalls();
        $registerCalls = array_filter($calls, static fn (array $c): bool => 'registerProvider' === $c[0]);

        $this->assertEmpty($registerCalls, 'Non-tagged services must not be registered as providers');
    }

    #[\Override]
    protected function registerCompilerPass(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new RegisterProvidersCompilerPass());
    }
}
