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
        $delivered = $this->days('delivered-days');
        $failed = $this->days('failed-days');
        if ($delivered === null || $failed === null) {
            return self::INVALID;
        }
        $removed = $outbox->prune($now->modify('-' . $delivered . ' days'), $now->modify('-' . $failed . ' days'));
        $this->components->info(\sprintf('%d outbox row(s) removed.', $removed));

        return self::SUCCESS;
    }

    /**
     * A whole number of days, zero or more. A negative number used to become
     * "--1 days", which PHP reads as a day into the future, so a typo pruned
     * every delivered row including today's (AR10-003).
     */
    private function days(string $option): ?int
    {
        $value = $this->option($option);
        if (!is_numeric($value) || (int) $value < 0 || (string) (int) $value !== (string) $value) {
            $this->components->error(\sprintf('--%s must be a whole number of days, zero or more; got "%s".', $option, \is_scalar($value) ? (string) $value : \gettype($value)));

            return null;
        }

        return (int) $value;
    }
}
