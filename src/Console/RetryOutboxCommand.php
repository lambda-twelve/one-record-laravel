<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use LambdaTwelve\OneRecord\Laravel\Notifications\DeliverNotification;
use LambdaTwelve\OneRecord\Laravel\Storage\Database\DatabaseNotificationOutbox;
use LambdaTwelve\OneRecord\Server\Spi\NotificationOutbox;
use Psr\Clock\ClockInterface;

/**
 * Puts a given-up notification back in the queue once the operator has fixed
 * whatever made delivery impossible.
 */
final class RetryOutboxCommand extends Command
{
    protected $signature = 'one-record:outbox:retry {id : The outbox row id}';

    protected $description = 'Retry a ONE Record notification that was given up on';

    public function handle(Container $app, NotificationOutbox $outbox, ClockInterface $clock, Repository $config): int
    {
        if (!$outbox instanceof DatabaseNotificationOutbox) {
            $this->components->error('The outbox lives in the database; set one-record.storage.driver to "database".');

            return self::FAILURE;
        }
        $id = $this->argument('id');
        if (!is_numeric($id) || !$outbox->retry((int) $id, $clock->now())) {
            $this->components->error('No failed outbox row with that id.');

            return self::INVALID;
        }
        if ($config->get('one-record.outbox.dispatch') === 'queue') {
            DeliverNotification::dispatchFor($app, (int) $id);
        }
        $this->components->info('Notification ' . (int) $id . ' queued again.');

        return self::SUCCESS;
    }
}
