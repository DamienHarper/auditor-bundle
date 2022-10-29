<?php
declare(strict_types=1);


namespace DH\AuditorBundle\Tests\Twig\Views;

use DH\Auditor\Model\Entry;
use DH\Auditor\Provider\Doctrine\Auditing\Transaction\AuditTrait;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Issue112\DummyEntity;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;
use Twig\Extension\StringLoaderExtension;

class MacrosTest extends KernelTestCase
{
    use AuditTrait;

    private Environment $twig;
    private EntityManagerInterface $em;

    public function setUp(): void
    {
        parent::setUp();
        self::bootKernel();
        $twig = self::getContainer()->get('twig');
        if (!$twig instanceof Environment) {
            $this->markTestIncomplete('Twig missing');
        }
        $this->twig = $twig;
        $this->twig->addExtension(new StringLoaderExtension());
        $em = self::getContainer()->get('doctrine.orm.default_entity_manager');
        if (!$em instanceof EntityManagerInterface) {
            $this->markTestIncomplete('EntityManager missing');
        }
        $this->em = $em;
    }

    public function testSummarizeOnTargetWithUnusualPK(): void
    {
        $entity = new DummyEntity();
        $entity->setPrimaryKey(1);
        $entry = Entry::fromArray([
            'diffs' => json_encode([
                'source' => [
                    'label' => 'Example1',

                ],
                'target' => $this->summarize($this->em, $entity),
            ]),
            'type' => 'associate',
            'object_id' => '2',
        ]);

        $template = twig_template_from_string($this->twig, $this->getTemplateAsString());
        $response = $template->render([
            'entry' => $entry,
            'entity' => get_class($entity)
        ]);
        //TODO: Asserts
    }

    private function getTemplateAsString(): string
    {
        return <<<TWIG
{% import '@DHAuditor/Audit/helpers/helper.html.twig' as helper %}
{{ helper.summarize(entity, entry) }}
TWIG;

    }
}