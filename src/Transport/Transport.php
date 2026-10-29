<?php

declare(strict_types=1);

namespace Ipase\Transport;

interface Transport
{
    /**
     * POSTs one batch body (uncompressed JSON) to /v1/batch and never throws: every failure
     * to get a response is a Response with status 0.
     */
    public function send(string $json): Response;
}
