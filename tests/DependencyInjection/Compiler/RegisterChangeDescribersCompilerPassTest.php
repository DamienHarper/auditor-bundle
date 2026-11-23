<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Tests\DependencyInjection\Compiler;

use DH\AuditorBundle\DependencyInjection\Compiler\RegisterChangeDescribersCompilerPass;
use DH\AuditorBundle\History\ChangeDescriberInterface;
use DH\AuditorBundle\History\HistoryPhraser;
use DH\AuditorBundle\Tests\Fixtures\History\PostTitleDescriber;
use Matthias\SymfonyDependencyInjectionTest\PhpUnit\AbstractCompilerPassTestCase;
use PHPUnit\Framework\Attributes\Small;
use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Reference;

/**
 * @internal
 */
#[Small]
final class RegisterChangeDescribersCompilerPassTest extends AbstractCompilerPassTestCase
{
    public function testNothingWithoutPhraser(): void
    {
        $this->setDefinition('app.describer', new Definition(PostTitleDescriber::class)->addTag(RegisterChangeDescribersCompilerPass::TAG, ['entity' => 'App\Entity\Post']));

        $this->compile();

        $this->assertContainerBuilderNotHasService(HistoryPhraser::class);
    }

    public function testDescribersAreLocatedByEntityAndField(): void
    {
        $this->setDefinition(HistoryPhraser::class, new Definition(HistoryPhraser::class));
        $this->setDefinition('app.post_describer', new Definition(PostTitleDescriber::class)->addTag(RegisterChangeDescribersCompilerPass::TAG, ['entity' => 'App\Entity\Post']));
        $this->setDefinition('app.status_describer', new Definition(PostTitleDescriber::class)
            ->addTag(RegisterChangeDescribersCompilerPass::TAG, ['entity' => 'App\Entity\Post', 'field' => 'status'])
            ->addTag(RegisterChangeDescribersCompilerPass::TAG, ['entity' => 'App\Entity\Ticket', 'field' => 'status']));

        $this->compile();

        $argument = $this->container->getDefinition(HistoryPhraser::class)->getArgument('$describers');
        $this->assertInstanceOf(Reference::class, $argument);

        $locator = $this->container->getDefinition((string) $argument);
        $services = $locator->getArgument(0);
        $this->assertIsArray($services);
        $this->assertSame(['App\Entity\Post', 'App\Entity\Post::status', 'App\Entity\Ticket::status'], array_keys($services));
        $this->assertInstanceOf(ServiceClosureArgument::class, $services['App\Entity\Post::status']);
        $this->assertSame('app.status_describer', (string) $services['App\Entity\Post::status']->getValues()[0]);
    }

    public function testTwoDescribersForTheSameField(): void
    {
        $this->setDefinition(HistoryPhraser::class, new Definition(HistoryPhraser::class));
        $this->setDefinition('app.a', new Definition(PostTitleDescriber::class)->addTag(RegisterChangeDescribersCompilerPass::TAG, ['entity' => 'App\Entity\Post', 'field' => 'status']));
        $this->setDefinition('app.b', new Definition(PostTitleDescriber::class)->addTag(RegisterChangeDescribersCompilerPass::TAG, ['entity' => 'App\Entity\Post', 'field' => 'status']));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('The services "app.a" and "app.b" both describe the changes of App\Entity\Post::status.');

        $this->compile();
    }

    public function testEntityIsRequired(): void
    {
        $this->setDefinition(HistoryPhraser::class, new Definition(HistoryPhraser::class));
        $this->setDefinition('app.a', new Definition(PostTitleDescriber::class)->addTag(RegisterChangeDescribersCompilerPass::TAG));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('The "dh_auditor.change_describer" tag of service "app.a" needs an "entity" attribute.');

        $this->compile();
    }

    public function testTheServiceMustBeADescriber(): void
    {
        $this->setDefinition(HistoryPhraser::class, new Definition(HistoryPhraser::class));
        $this->setDefinition('app.a', new Definition(\stdClass::class)->addTag(RegisterChangeDescribersCompilerPass::TAG, ['entity' => 'App\Entity\Post']));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(\sprintf('The service "app.a" is tagged "dh_auditor.change_describer" but does not implement %s.', ChangeDescriberInterface::class));

        $this->compile();
    }

    public function testAnEmptyFieldIsRefused(): void
    {
        $this->setDefinition(HistoryPhraser::class, new Definition(HistoryPhraser::class));
        $this->setDefinition('app.a', new Definition(PostTitleDescriber::class)->addTag(RegisterChangeDescribersCompilerPass::TAG, ['entity' => 'App\Entity\Post', 'field' => '']));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('The "field" attribute of the "dh_auditor.change_describer" tag of service "app.a" must be a field name; leave it out to describe the whole entity.');

        $this->compile();
    }

    protected function registerCompilerPass(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new RegisterChangeDescribersCompilerPass());
    }
}
