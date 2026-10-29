<?php

declare(strict_types=1);

namespace Ipase\Laravel;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Ipase\Identity\ForwardedIds;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reads X-Session-Id and X-Device-Id from the incoming request so events tracked while handling
 * it carry the client's ids (protocol section 11.3). Values that are not lowercase UUIDs are
 * ignored. ForwardedIds is a scoped binding, so the ids never outlive the request under Octane
 * or a queue worker.
 */
final class ForwardIdentityHeaders
{
    public function __construct(private readonly Container $container) {}

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        $this->container->instance(ForwardedIds::class, new ForwardedIds(
            $request->headers->get(ForwardedIds::SESSION_HEADER),
            $request->headers->get(ForwardedIds::DEVICE_HEADER),
        ));

        return $next($request);
    }
}
