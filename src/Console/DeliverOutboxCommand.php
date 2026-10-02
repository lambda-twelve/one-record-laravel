<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Bus\Dispatcher as Bus;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use LambdaTwelve\OneRecord\Laravel\Notifications\DeliverNotification;
use LambdaTwelve\OneRecord\Laravel\Storage\Database\DatabaseNotificationOutbox;
use LambdaTwelve\OneRecord\Server\Spi\NotificationOutbox;
use Psr\Clock\ClockInterface;

/**
 * Picks up every due outbox row: the safety net behind queue dispatch (a job
 * the queue lost, a worker that died mid-lease) and the whole delivery
 * mechanism for hosts that run no queue worker (schedule it every minute
 * with --inline).
 */
final class DeliverOutboxCommand extends Command
{
    protected $signature = 'one-record:outbox:deliver
        {--limit=100 : How many due rows to pick up}
        {--inline : Deliver in this process instead of dispatching jobs}';

    protected $description = 'Deliver due ONE Record notifications from the outbox';

    public function handle(Container $app, NotificationOutbox $outbox, ClockInterface $clock, Repository $config, Bus $bus): int
    {
        if (!$outbox instanceof DatabaseNotificationOutbox) {
            $this->components->error('The outbox lives in the database; set one-record.storage.driver to "database".');

            return self::FAILURE;
        }
        $limit = $this->option('limit');
        $inline = $this->option('inline') === true || $config->get('one-record.outbox.dispatch') !== 'queue';
        $ids = $outbox->due($clock->now(), is_numeric($limit) ? max(1, (int) $limit) : 100);

        foreach ($ids as $id) {
            if ($inline) {
                (new DeliverNotification($id))->handle($app);
            } else {
                $bus->dispatch(DeliverNotification::forRow($id, $config));
            }
        }
        $this->components->info(\sprintf('%d due notification(s) %s.', \count($ids), $inline ? 'processed' : 'dispatched'));

        return self::SUCCESS;
    }
}
