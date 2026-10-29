<?php

declare(strict_types=1);

namespace Ipase\Transport;

/** What came back from POST /v1/batch. Status 0 means no response: DNS, TLS, timeout, reset. */
final class Response
{
    /** @param  array<mixed>|null  $body  the decoded JSON body, when there was one */
    public function __construct(
        public readonly int $status,
        public readonly ?int $retryAfter = null,
        public readonly ?array $body = null,
        public readonly ?string $error = null,
    ) {}

    public static function networkFailure(string $error): self
    {
        return new self(0, error: $error);
    }
}
