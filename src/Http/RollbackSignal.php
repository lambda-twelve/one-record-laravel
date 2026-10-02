<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Http;

use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * Thrown inside the request transaction to roll it back while keeping the
 * SDK's response. Never leaves TransactionalRequestHandler.
 *
 * @internal
 */
final class RollbackSignal extends RuntimeException
{
    public function __construct(public readonly ResponseInterface $response)
    {
        parent::__construct('Rolling back the request transaction.');
    }
}
