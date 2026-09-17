<?php

declare(strict_types=1);

namespace Dock\Ray\Laravel\Tests;

use Dock\Ray\ClientBuilder;
use Dock\Ray\Event;
use Dock\Ray\EventType;
use Dock\Ray\Laravel\Events\TransactionStarting;
use Dock\Ray\Laravel\Http\Middleware\TrackTransaction;
use Dock\Ray\Response;
use Dock\Ray\ResponseStatus;
use Dock\Ray\State\Hub;
use Dock\Ray\State\HubInterface;
use Dock\Ray\Transport\TransportFactoryInterface;
use Dock\Ray\Transport\TransportInterface;
use GuzzleHttp\Promise\FulfilledPromise;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class TrackTransactionTest extends TestCase
{
    private Repository $config;
    private Dispatcher $events;
    private TransportInterface $transport;

    protected function setUp(): void
    {
        parent::setUp();
        $app = new Container();
        $app->instance('app', $app);
        $this->events = new Dispatcher($app);
        $app->instance('events', $this->events);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);

        $this->config = new Repository(['ray' => [
            'token' => 'test-project', 'private_key' => 'test-key',
            'traces_sample_rate' => 1, 'send_default_user' => false,
        ]]);
        $this->transport = $this->createMock(TransportInterface::class);
        $factory = $this->createStub(TransportFactoryInterface::class);
        $factory->method('create')->willReturn($this->transport);
        $client = ClientBuilder::create([
            'token' => 'test-project', 'private_key' => 'test-key',
            'traces_sample_rate' => 1, 'default_integrations' => false,
        ])->setTransportFactory($factory)->getClient();
        $app->instance(HubInterface::class, new Hub($client));
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        parent::tearDown();
    }

    public function test_it_sends_a_transaction_named_after_the_route(): void
    {
        $this->events->listen(TransactionStarting::class, function (TransactionStarting $event) {
            $this->assertSame('/orders/42', $event->request->getPathInfo());
            return null;
        });
        $this->transport->expects($this->once())->method('send')->willReturnCallback(function (Event $event) {
            $this->assertSame(EventType::transaction(), $event->getType());
            $this->assertSame('GET /orders/{order}', $event->getTransaction());
            $this->assertSame('201', (string) $event->getTags()['http.status_code']);
            return new FulfilledPromise(new Response(ResponseStatus::success(), $event));
        });
        $this->request();
    }

    public function test_a_listener_can_suspend_tracking(): void
    {
        $this->events->listen(TransactionStarting::class, function () {
            return false;
        });
        $this->transport->expects($this->never())->method('send');
        $this->request();
    }

    public function test_missing_credentials_disable_tracking(): void
    {
        $this->transport->expects($this->never())->method('send');
        $this->config->set('ray.private_key', '');
        $this->request();
        $this->config->set(['ray.private_key' => 'test-key', 'ray.token' => '']);
        $this->request();
    }

    public function test_zero_sampling_disables_tracking(): void
    {
        $this->transport->expects($this->never())->method('send');
        $this->config->set('ray.traces_sample_rate', 0);
        $this->request();
    }

    public function test_ignored_paths_do_not_start_transactions(): void
    {
        $this->transport->expects($this->never())->method('send');
        $this->config->set('ray.ignore_transactions', ['orders/*']);
        $this->events->listen(TransactionStarting::class, function () {
            $this->fail('Ignored path dispatched a transaction event');
        });
        $this->request();
    }

    private function request(): void
    {
        $request = Request::create('https://app.example/orders/42');
        $request->setRouteResolver(function () {
            return new Route('GET', 'orders/{order}', function () {
            });
        });
        $response = new HttpResponse('created', 201);
        $this->assertSame($response, (new TrackTransaction($this->config))->handle($request, function () use ($response) {
            return $response;
        }));
    }
}
