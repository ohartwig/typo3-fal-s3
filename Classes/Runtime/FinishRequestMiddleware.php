<?php

/*
 * SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Koh\FalS3\Runtime;

use Koh\FalS3\Metrics\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Ends the per-request state of the driver (metrics line, temporary files)
 * when the HTTP request has been handled, whatever runs the request.
 *
 * Destructors alone are not enough: a worker server that keeps the container
 * between requests (FrankenPHP with a persistent bootstrap) keeps the storages
 * and their driver objects too, so nothing is destructed until the worker
 * exits. The middleware runs outermost in the frontend and backend stacks and
 * calls EndOfRequest::finishAll() once the handler has returned or thrown.
 *
 * Anything counted before the request started (bootstrap, or work after the
 * previous response) is written first, as a line without host and path, so it
 * is not attributed to this request. An internal sub-request (error page)
 * passes the middleware again; only the outermost request finishes.
 *
 * The response body is emitted after the middleware returns; S3 work done
 * while streaming it (a file dump's body) is not part of the line.
 */
final class FinishRequestMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $outermost = !RequestContext::inRequest();
        if ($outermost) {
            EndOfRequest::finishAll();
        }
        $uri = $request->getUri();
        RequestContext::enter($uri->getHost(), $uri->getPath());
        try {
            return $handler->handle($request);
        } finally {
            RequestContext::leave();
            if ($outermost) {
                try {
                    EndOfRequest::finishAll();
                } finally {
                    RequestContext::reset();
                }
            }
        }
    }
}
