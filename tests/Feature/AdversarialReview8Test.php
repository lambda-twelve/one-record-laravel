<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Tests\Feature;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LambdaTwelve\OneRecord\Api\AccessDelegation;
use LambdaTwelve\OneRecord\Api\Notification;
use LambdaTwelve\OneRecord\Api\NotificationEventType;
use LambdaTwelve\OneRecord\Api\Permission;
use LambdaTwelve\OneRecord\Api\RequestStatus;
use LambdaTwelve\OneRecord\Laravel\Config\ServerConfigFactory;
use LambdaTwelve\OneRecord\Laravel\Notifications\DeliverNotification;
use LambdaTwelve\OneRecord\Laravel\Notifications\NotificationDeliverer;
use LambdaTwelve\OneRecord\Laravel\OneRecordServiceProvider;
use LambdaTwelve\OneRecord\Laravel\Storage\Database\DatabaseNotificationOutbox;
use LambdaTwelve\OneRecord\Laravel\Storage\Database\Tables;
use LambdaTwelve\OneRecord\Laravel\Tests\Support\FakeDeliverer;
use LambdaTwelve\OneRecord\Laravel\Tests\TestCase;
use LambdaTwelve\OneRecord\Rdf\Iri;
use LambdaTwelve\OneRecord\Server\ActionRequests;
use LambdaTwelve\OneRecord\Server\Event\ActionRequestCreated;
use LambdaTwelve\OneRecord\Server\Event\ActionRequestStatusChanged;
use LambdaTwelve\OneRecord\Server\Spi\AccessDelegationStore;
use LambdaTwelve\OneRecord\Server\Spi\ActionRequestStore;
use LambdaTwelve\OneRecord\Server\Spi\NotificationOutbox;
use LambdaTwelve\OneRecord\Server\Spi\OutboundNotification;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Clock\ClockInterface;
use Psr\Http\Client\ClientInterface;
use RuntimeException;

/**
 * The probes of the eighth adversarial review (2026-10-05, the SDK beta6
 * pass against 9dd0f6f), kept as regression tests: the two findings, and
 * three passing probes that pin how beta6's decision and notification order
 * meets the unit of work and the outbox worker bound here.
 */
#[CoversClass(OneRecordServiceProvider::class)]
#[CoversClass(ServerConfigFactory::class)]
#[CoversClass(DeliverNotification::class)]
final class AdversarialReview8Test extends TestCase
{
    use RefreshDatabase;

    protected function storageDriver(): string
    {
        return 'database';
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->config()->set('one-record.outbox.dispatch', 'none');
    }

    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function httpOptions(): iterable
    {
        yield 'client certificate' => ['cert', '/etc/ssl/partner-client.pem'];
        yield 'private key' => ['ssl_key', '/etc/ssl/partner-client.key'];
        yield 'per-scheme proxy' => ['proxy', ['https' => 'http://proxy.example:8080', 'no' => ['internal.example']]];
        yield 'default headers' => ['headers', ['X-Partner' => 'review']];
    }

    /**
     * AR8-001. Adapting the client binding to Guzzle 8 had filtered the
     * options down to timeouts, verify and a string proxy; everything else
     * the application configured vanished silently.
     */
    #[DataProvider('httpOptions')]
    public function testEveryConfiguredHttpOptionReachesTheClient(string $key, mixed $value): void
    {
        $this->config()->set('one-record.http', [$key => $value]);

        $client = $this->app()->make(ClientInterface::class);

        self::assertInstanceOf(Client::class, $client);
        $configured = $client->getConfig($key);
        if (\is_array($value)) {
            // Guzzle merges its own defaults into array options (a User-Agent header, say): ours must be among them.
            self::assertIsArray($configured);
            foreach ($value as $name => $entry) {
                self::assertSame($entry, $configured[$name] ?? null, $key . '.' . $name);
            }
        } else {
            self::assertSame($value, $configured);
        }
    }

    /**
     * AR8-002. problems() judged the raw `languages` value while fromArray()
     * normalised it, so a configuration the runtime accepted was reported as
     * a problem and the about section skipped the wiring check.
     */
    public function testConfigurationDiagnosticsAgreeWithConstruction(): void
    {
        $settings = ['base_url' => self::BASE, 'data_holder' => 'holder', 'languages' => 'en-US'];

        self::assertSame(['en-US'], ServerConfigFactory::fromArray($settings)->languages);
        self::assertSame([], ServerConfigFactory::problems($settings));
    }

    /**
     * beta6: a decision made by a listener, inside the operation that fired
     * it, returns the stored (final) state, and the notifications are queued
     * in the order of the states they announce.
     */
    public function testNestedDecisionsReturnTheStoredStatusAndQueueInOrder(): void
    {
        $app = $this->app();
        $actions = $app->make(ActionRequests::class);
        $events = $app->make(Dispatcher::class);
        $holder = new Iri(self::HOLDER);
        $partner = new Iri(self::PARTNER);
        $object = new Iri(self::BASE . '/one-record/logistics-objects/nested');
        $events->listen(ActionRequestCreated::class, static function (ActionRequestCreated $event) use ($actions, $holder): void {
            $actions->accept($event->request, $holder);
        });
        $events->listen(ActionRequestStatusChanged::class, static function (ActionRequestStatusChanged $event) use ($actions, $holder): void {
            if ($event->request->status === RequestStatus::Accepted) {
                $actions->revoke($event->request, $holder);
            }
        });

        $returned = $actions->create(new AccessDelegation([Permission::GetLogisticsObject], [$partner], [$object], notifyRequestStatusChange: true), $partner);

        self::assertSame(RequestStatus::Revoked, $returned->status, 'create() returns what the listeners made of it');
        self::assertSame(RequestStatus::Revoked, $app->make(ActionRequestStore::class)->get($returned->iri)?->status);
        self::assertSame([], $app->make(AccessDelegationStore::class)->grantsFor($partner, $object));
        $queued = $app->make(ConnectionInterface::class)->table($app->make(Tables::class)->outbox())->orderBy('id')->pluck('event_type')->all();
        self::assertSame([
            NotificationEventType::AccessDelegationRequestPending->name,
            NotificationEventType::LogisticsObjectAccessGranted->name,
            NotificationEventType::AccessDelegationRequestAccepted->name,
            NotificationEventType::AccessDelegationRequestRevoked->name,
        ], $queued, 'queued in the order of the states they announce');
    }

    /**
     * beta6 queues the Pending notification before dispatching the creation
     * event; a listener that throws takes both the request and that row down
     * with the unit of work.
     */
    public function testAListenerFailureRollsBackTheNotificationQueuedBeforeIt(): void
    {
        $app = $this->app();
        $actions = $app->make(ActionRequests::class);
        $outbox = $app->make(ConnectionInterface::class)->table($app->make(Tables::class)->outbox());
        $requests = $app->make(ConnectionInterface::class)->table($app->make(Tables::class)->actionRequests());
        $app->make(Dispatcher::class)->listen(ActionRequestCreated::class, static function () use ($outbox): void {
            self::assertSame(1, (clone $outbox)->count(), 'the Pending notification is queued before the event');

            throw new RuntimeException('listener failed');
        });

        try {
            $actions->create(new AccessDelegation([Permission::GetLogisticsObject], [new Iri(self::PARTNER)], [new Iri(self::HOLDER)], notifyRequestStatusChange: true), new Iri(self::PARTNER));
            self::fail('the listener failure propagates');
        } catch (RuntimeException $e) {
            self::assertSame('listener failed', $e->getMessage());
        }

        self::assertSame(0, (clone $outbox)->count());
        self::assertSame(0, (clone $requests)->count());
    }

    /**
     * beta6's DeliveryVerdict rejects a request PSR-18 reports as unusable
     * instead of retrying it; the worker records it as final.
     */
    public function testAnUnusablePsrRequestIsFinalInTheOutboxWorker(): void
    {
        $app = $this->app();
        $outbox = $app->make(NotificationOutbox::class);
        self::assertInstanceOf(DatabaseNotificationOutbox::class, $outbox);
        $now = $app->make(ClockInterface::class)->now();
        $outbox->enqueue(new OutboundNotification(new Iri(self::PARTNER), new Notification(NotificationEventType::LogisticsObjectCreated, new Iri(self::HOLDER)), $now, 'unusable-request'));
        [$id] = $outbox->due($now, 1);
        $deliverer = new FakeDeliverer();
        $deliverer->failWith(new RequestException('unusable request', new Request('POST', 'https://partner.example/notifications')));
        $app->instance(NotificationDeliverer::class, $deliverer);

        (new DeliverNotification($id))->handle($app);

        $row = $app->make(ConnectionInterface::class)->table($app->make(Tables::class)->outbox())->where('id', $id)->first();
        self::assertNotNull($row);
        self::assertNotNull($row->failed_at, 'final, not retried');
        self::assertSame([], $outbox->due($now->modify('+10 days'), 10));
    }
}
