<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Tests\Twig\Views;

use DH\Auditor\Model\Entry;
use DH\Auditor\Provider\Doctrine\Auditing\Transaction\AuditTrait;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Issue112\DummyEntity;
use DH\Auditor\Tests\Provider\Doctrine\Traits\Schema\SchemaSetupTrait;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;
use Twig\Extension\StringLoaderExtension;

/**
 * @internal
 *
 * @small
 *
 * @coversNothing
 */
final class MacrosTest extends KernelTestCase
{
    use AuditTrait;
    use SchemaSetupTrait;

    public function testSummarizeOnTargetWithUnusualPK(): void
    {
        self::bootKernel();
        $container = method_exists(self::class, 'getContainer') ? self::getContainer() : self::$container;
        $twig = $container->get('twig');
        if (!$twig instanceof Environment) {
            self::markTestIncomplete('Twig missing');
        }
        $twig->addExtension(new StringLoaderExtension());
        $em = $this->createEntityManager([
            __DIR__.'/../../../vendor/damienharper/auditor/tests/Provider/Doctrine/Fixtures/Issue112',
        ]);
        $entity = new DummyEntity();
        $entity->setPrimaryKey(1);
        $entry = Entry::fromArray([
            'diffs' => json_encode([
                'source' => [
                    'label' => 'Example1',
                ],
                'target' => $this->summarize($em, $entity),
            ]),
            'type' => 'associate',
            'object_id' => '2',
        ]);

        $template = twig_template_from_string($twig, $this->getTemplateAsString());
        $response = $template->render([
            'entry' => $entry,
            'entity' => $entity::class,
        ]);
        self::assertSame($this->getExpected(), trim($response));
    }

    private function getTemplateAsString(): string
    {
        return <<<'TWIG'
            {% import '@DHAuditor/Audit/helpers/helper.html.twig' as helper %}
            {{ helper.summarize(entity, entry) }}
            TWIG;
    }

    private function getExpected(): string
    {
        return <<<'EXPECTED'
            <code class="text-pink-500">
              <a href="/audit/DH-Auditor-Tests-Provider-Doctrine-Fixtures-Issue112-DummyEntity/2" class="code">DH\Auditor\Tests\Provider\Doctrine\Fixtures\Issue112\DummyEntity#2</a>
            </code> <em>(Example1)</em> has been <b>associated</b> to <code class="text-pink-500">
              <a href="/audit/DH-Auditor-Tests-Provider-Doctrine-Fixtures-Issue112-DummyEntity/1" class="code">DH\Auditor\Tests\Provider\Doctrine\Fixtures\Issue112\DummyEntity#1</a>
            </code> <em></em> by <b>an anonymous user</b>
            EXPECTED;
    }
}
