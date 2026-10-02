<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Console;

use Illuminate\Console\Command;
use LambdaTwelve\OneRecord\Laravel\Storage\Database\DatabaseNotificationOutbox;
use LambdaTwelve\OneRecord\Server\Spi\NotificationOutbox;
use Psr\Clock\ClockInterface;

final class PruneOutboxCommand extends Command
{
    protected $signature = 'one-record:outbox:prune
        {--delivered-days=30 : Remove delivered rows older than this}
        {--failed-days=90 : Remove given-up rows older than this}';

    protected $description = 'Remove old delivered and failed rows from the ONE Record outbox';

    public function handle(NotificationOutbox $outbox, ClockInterface $clock): int
    {
        if (!$outbox instanceof DatabaseNotificationOutbox) {
            $this->components->error('The outbox lives in the database; set one-record.storage.driver to "database".');

            return self::FAILURE;
        }
        $now = $clock->now();
        $delivered = $this->option('delivered-days');
        $failed = $this->option('failed-days');
        $removed = $outbox->prune(
            $now->modify('-' . (is_numeric($delivered) ? (int) $delivered : 30) . ' days'),
            $now->modify('-' . (is_numeric($failed) ? (int) $failed : 90) . ' days'),
        );
        $this->components->info(\sprintf('%d outbox row(s) removed.', $removed));

        return self::SUCCESS;
    }
}
