<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Http;

use Illuminate\Database\ConnectionInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The envelope around each request: one database transaction from the first
 * read to the response, so a request reads one snapshot and anything written
 * outside the SDK's unit of work (a listener's own rows) goes with it. The
 * atomicity of the operations themselves is the unit of work's: the SDK runs
 * every mutating request and every DataHolder / ActionRequests call through
 * DatabaseUnitOfWork, which inside this envelope is a savepoint, and unwinds
 * it before an error becomes a response (a lost status race answers 409 with
 * its grants already rolled back). The SDK turns an unhandled exception into
 * a 500 rather than throwing, so a 5xx status is the signal to roll back the
 * envelope too; 4xx responses commit on purpose, because some are recorded
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
