<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Http;

use Illuminate\Database\ConnectionInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Runs each request the SDK handles inside one database transaction, so an
 * accepted change (new revision, action request, outbox rows, grants) is
 * committed as a whole or not at all. The SDK turns every exception into a
 * 500 response rather than throwing, so a 5xx status is the signal to roll
 * back; 4xx responses commit on purpose, because some of them are recorded
 * state (a change that failed to apply is a stored, failed action request).
 */
final class TransactionalRequestHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly RequestHandlerInterface $inner,
        private readonly ConnectionInterface $db,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $response = $this->db->transaction(function () use ($request): ResponseInterface {
                $response = $this->inner->handle($request);
                if ($response->getStatusCode() >= 500) {
                    throw new RollbackSignal($response);
                }

                return $response;
            }, 1);
        } catch (RollbackSignal $signal) {
            return $signal->response;
        }

        /** @var ResponseInterface */
        return $response;
    }
}
