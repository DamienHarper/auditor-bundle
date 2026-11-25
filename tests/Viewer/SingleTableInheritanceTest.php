<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Tests\Viewer;

use DH\Auditor\Provider\Doctrine\Persistence\Reader\Reader;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Inheritance\SingleTable\Bike;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Inheritance\SingleTable\Car;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Inheritance\SingleTable\Vehicle;
use DH\Auditor\Tests\Provider\Doctrine\Traits\Schema\DefaultSchemaSetupTrait;
use DH\AuditorBundle\Viewer\ActivityGraphProvider;
use DH\AuditorBundle\Viewer\Overview;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * The entities of a single-table inheritance share one audit table: each one counts only its own actions, as its
 * entity stream does.
 *
 * @internal
 */
#[Small]
final class SingleTableInheritanceTest extends TestCase
{
    use DefaultSchemaSetupTrait;

    public function testTheActionCountOfAnEntityLeavesOutTheOtherEntitiesOfItsTable(): void
    {
        $reader = new Reader($this->provider);
        $this->assertSame($reader->getEntityAuditTableName(Car::class), $reader->getEntityAuditTableName(Bike::class));

        $this->assertSame(1, new Overview($reader)->actionCount(Car::class));
        $this->assertSame(2, new Overview($reader)->actionCount(Bike::class));
    }

    public function testTheActivityGraphOfAnEntityLeavesOutTheOtherEntitiesOfItsTable(): void
    {
        $reader = new Reader($this->provider);
        $graph = new ActivityGraphProvider(7, 'bottom', true, 300, new ArrayAdapter());

        $this->assertSame(1, array_sum($graph->getActivityDataWithRaw(Car::class, $reader)['raw']));
        $this->assertSame(2, array_sum($graph->getActivityDataWithRaw(Bike::class, $reader)['raw']));
    }

    private function configureEntities(): void
    {
        $this->provider->getConfiguration()->setEntities([
            Vehicle::class => ['enabled' => true],
            Car::class => ['enabled' => true],
            Bike::class => ['enabled' => true],
        ]);
    }

    private function setupEntities(): void
    {
        $entityManager = $this->provider->getStorageServiceForEntity(Car::class)->getEntityManager();

        // one action on a car, two on bikes
        $entityManager->persist(new Car()->setLabel('car')->setWheels(4));
        $entityManager->flush();

        foreach (['bike-1', 'bike-2'] as $label) {
            $entityManager->persist(new Bike()->setLabel($label)->setWheels(2));
            $entityManager->flush();
        }
    }
}
