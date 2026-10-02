<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Notifications;

/**
 * Delay before the next delivery attempt: a minute, then minutes, then
 * hours, capped at a day. With the default ten attempts a notification is
 * retried for about three days before it is given up on.
 */
final class Backoff
{
    private const array SECONDS = [60, 300, 900, 3600, 14_400, 43_200, 86_400];

    private function __construct() {}

    /**
     * @param int $attempt the attempt that just failed, counting from 1
     */
    public static function seconds(int $attempt): int
    {
        return self::SECONDS[max(0, min($attempt, \count(self::SECONDS)) - 1)];
    }
}
