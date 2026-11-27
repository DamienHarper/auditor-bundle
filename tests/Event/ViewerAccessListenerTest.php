<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Tests\Event;

use DH\AuditorBundle\Controller\ExportController;
use DH\AuditorBundle\Controller\ViewerController;
use DH\AuditorBundle\Event\ViewerAccessListener;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * @internal
 */
#[Small]
final class ViewerAccessListenerTest extends TestCase
{
    /**
     * @var list<array{string, string|\Stringable, array<mixed>}>
     */
    private array $records = [];

    public function testARequestOfTheViewerIsLogged(): void
    {
        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken(new UsernamePasswordToken(new InMemoryUser('dark.vador', null), 'main'));

        $request = Request::create('/audit/App-Entity-Post/1', server: ['REMOTE_ADDR' => '192.168.1.42']);
        $request->query->set('type', 'update');
        $request->attributes->set('_route', 'dh_auditor_show_entity_stream');
        $request->attributes->set('_route_params', ['entity' => 'App-Entity-Post', 'id' => '1']);

        new ViewerAccessListener($this->logger(), $tokenStorage)($this->event([$this->controller(ViewerController::class), 'showEntityStreamAction'], $request));

        $this->assertCount(1, $this->records);
        [$level, $message, $context] = $this->records[0];
        $this->assertSame('info', $level);
        $this->assertSame('Audit viewer request: {route}', $message);
        $this->assertSame([
            'route' => 'dh_auditor_show_entity_stream',
            'route_params' => ['entity' => 'App-Entity-Post', 'id' => '1'],
            'query' => ['type' => 'update'],
            'user' => 'dark.vador',
            'ip' => '192.168.1.42',
        ], $context);
    }

    public function testAnExportWithoutUserIsLogged(): void
    {
        $request = Request::create('/audit/export', parameters: ['format' => 'csv']);
        $request->attributes->set('_route', 'dh_auditor_export');

        new ViewerAccessListener($this->logger())($this->event([$this->controller(ExportController::class), 'exportAction'], $request));

        $this->assertCount(1, $this->records);
        $this->assertSame('dh_auditor_export', $this->records[0][2]['route']);
        $this->assertSame(['format' => 'csv'], $this->records[0][2]['query']);
        $this->assertNull($this->records[0][2]['user']);
    }

    public function testOtherControllersAreNotLogged(): void
    {
        new ViewerAccessListener($this->logger())($this->event(static fn (): null => null, Request::create('/')));

        $this->assertSame([], $this->records);
    }

    public function testNothingHappensWithoutLogger(): void
    {
        new ViewerAccessListener()($this->event([$this->controller(ExportController::class), 'exportAction'], Request::create('/audit/export')));

        $this->assertSame([], $this->records);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function controller(string $class): object
    {
        return new \ReflectionClass($class)->newInstanceWithoutConstructor();
    }

    private function event(callable $controller, Request $request): ControllerEvent
    {
        return new ControllerEvent($this->createStub(HttpKernelInterface::class), $controller, $request, HttpKernelInterface::MAIN_REQUEST);
    }

    private function logger(): AbstractLogger
    {
        $records = &$this->records;

        return new class($records) extends AbstractLogger {
            /**
             * @param list<array{string, string|\Stringable, array<mixed>}> $records
             */
            public function __construct(private array &$records) {}

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [(string) $level, $message, $context];
            }
        };
    }
}
