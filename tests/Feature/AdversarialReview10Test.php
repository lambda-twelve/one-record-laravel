<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Tests\Feature;

use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Testing\PendingCommand;
use LambdaTwelve\OneRecord\Api\Notification;
use LambdaTwelve\OneRecord\Api\NotificationEventType;
use LambdaTwelve\OneRecord\Laravel\Console\CreateClientCommand;
use LambdaTwelve\OneRecord\Laravel\Console\PruneOutboxCommand;
use LambdaTwelve\OneRecord\Laravel\OneRecord;
use LambdaTwelve\OneRecord\Laravel\OneRecordServiceProvider;
use LambdaTwelve\OneRecord\Laravel\Storage\Database\ClientIdTaken;
use LambdaTwelve\OneRecord\Laravel\Storage\Database\DatabaseClientCredentials;
use LambdaTwelve\OneRecord\Laravel\Storage\Database\DatabaseNotificationOutbox;
use LambdaTwelve\OneRecord\Laravel\Tests\TestCase;
use LambdaTwelve\OneRecord\Rdf\Iri;
use LambdaTwelve\OneRecord\Server\Spi\NotificationOutbox;
use LambdaTwelve\OneRecord\Server\Spi\OutboundNotification;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Clock\ClockInterface;

/**
 * The findings of the tenth adversarial review (2026-10-05, the SDK beta7
 * pass against 1171014), as regression tests. The review left no probe
 * files; these are written from its reproductions.
 */
#[CoversClass(OneRecord::class)]
#[CoversClass(CreateClientCommand::class)]
#[CoversClass(DatabaseClientCredentials::class)]
#[CoversClass(PruneOutboxCommand::class)]
#[CoversClass(OneRecordServiceProvider::class)]
final class AdversarialReview10Test extends TestCase
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
     * AR10-001. A string under auth.token_endpoint.middleware was dropped,
     * and the token endpoint ran without its throttle.
     */
    public function testTheTokenRouteKeepsMiddlewareGivenAsAString(): void
    {
        $this->config()->set('one-record.auth.token_endpoint', ['enabled' => true, 'path' => '/oauth/token', 'middleware' => 'throttle:60,1']);
        $this->config()->set('one-record.auth.jwks', ['enabled' => false]);

        OneRecord::tokenRoutes(['name' => 'review.']);

        $route = $this->app()->make(Router::class)->getRoutes()->getByName('review.token');
        self::assertNotNull($route);
        self::assertSame(['throttle:60,1'], $route->gatherMiddleware());
    }

    /**
     * AR10-002. Registering a client id twice ended in the database's
     * unique-key exception and a stack trace.
     */
    public function testRegisteringAClientIdTwiceIsACleanError(): void
    {
        $first = $this->artisan('one-record:client:create', ['agent' => self::PARTNER, '--client-id' => 'partner-one']);
        self::assertInstanceOf(PendingCommand::class, $first);
        $first->assertSuccessful()->run();

        $second = $this->artisan('one-record:client:create', ['agent' => self::PARTNER, '--client-id' => 'partner-one']);
        self::assertInstanceOf(PendingCommand::class, $second);
        $second->expectsOutputToContain('A client with id "partner-one" already exists.')->assertExitCode(Command::INVALID)->run();

        $credentials = $this->app()->make(DatabaseClientCredentials::class);
        $this->expectException(ClientIdTaken::class);
        $credentials->create('partner-one', 'another-secret', new Iri(self::PARTNER));
    }

    /**
     * AR10-003. A negative day count became "--1 days", a day into the
     * future, and pruned every delivered row including today's.
     */
    public function testPruneRefusesNegativeDaysInsteadOfPruningToday(): void
    {
        $outbox = $this->app()->make(NotificationOutbox::class);
        self::assertInstanceOf(DatabaseNotificationOutbox::class, $outbox);
        $now = $this->app()->make(ClockInterface::class)->now();
        $outbox->enqueue(new OutboundNotification(new Iri(self::PARTNER), new Notification(NotificationEventType::LogisticsObjectCreated), $now, 'delivered-today'));
        [$id] = $outbox->due($now, 1);
        $lease = $outbox->claim($id, $now, 60);
        self::assertNotNull($lease);
        self::assertTrue($outbox->markDelivered($lease, $now));

        $prune = $this->artisan('one-record:outbox:prune', ['--delivered-days' => '-1']);
        self::assertInstanceOf(PendingCommand::class, $prune);
        $prune->expectsOutputToContain('--delivered-days must be a whole number of days, zero or more')->assertExitCode(Command::INVALID)->run();
        self::assertNotNull($outbox->find($id), 'the row delivered today is still there');

        $fraction = $this->artisan('one-record:outbox:prune', ['--failed-days' => '1.5']);
        self::assertInstanceOf(PendingCommand::class, $fraction);
        $fraction->assertExitCode(Command::INVALID)->run();

        $zero = $this->artisan('one-record:outbox:prune', ['--delivered-days' => '0']);
        self::assertInstanceOf(PendingCommand::class, $zero);
        $zero->assertSuccessful()->run();
        self::assertNull($outbox->find($id), 'zero is allowed and means everything delivered before this instant');
    }

    /**
     * AR10-004. A configuration error outside one-record.server surfaced as
     * "Wiring: Not wired" under "Configuration: OK".
     */
    public function testAboutReportsAnAuthConfigurationErrorAsConfiguration(): void
    {
        $this->config()->set('one-record.auth.issuers', 'not-an-array');

        self::assertSame(0, Artisan::call('about', ['--only' => 'one_record']));
        $output = Artisan::output();

        self::assertStringContainsString('one-record.auth.issuers must be an array', $output);
        self::assertDoesNotMatchRegularExpression('/Configuration \.+ OK/', $output);
        self::assertDoesNotMatchRegularExpression('/Not wired/', $output);
        self::assertMatchesRegularExpression('/Wiring \.+ Not checked/', $output);
    }
}
