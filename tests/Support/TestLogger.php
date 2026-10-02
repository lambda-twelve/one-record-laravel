<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Tests\Support;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * Keeps every record so tests can assert what the package logged.
 */
final class TestLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<mixed>}> */
    public array $records = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => \is_scalar($level) ? (string) $level : 'unknown', 'message' => (string) $message, 'context' => $context];
    }
}
