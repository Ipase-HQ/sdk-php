<?php

declare(strict_types=1);

namespace Ipase\Transport;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use Ipase\Delivery\Sender;
use Ipase\Version;
use SensitiveParameter;

/**
 * POST /v1/batch over Guzzle with the headers of protocol section 2.3: gzip above 1 KB, no
 * redirects, a 30 second timeout. The server key goes into the Authorization header and
 * nowhere else: this class never logs, and the Guzzle exceptions it turns into network
 * failures keep only their class and message, which name the URL but never the headers.
 */
final class GuzzleTransport implements Transport
{
    public const GZIP_ABOVE_BYTES = 1024;

    private readonly ClientInterface $http;

    private readonly string $url;

    public function __construct(
        #[SensitiveParameter] private readonly string $serverKey,
        string $endpoint = Version::DEFAULT_ENDPOINT,
        ?ClientInterface $http = null,
        private readonly float $timeout = 30.0,
    ) {
        $this->url = rtrim($endpoint, '/').'/v1/batch';
        $this->http = $http ?? new Client;
    }

    /** @return array<string, mixed> what var_dump() and print_r() show: never the key */
    public function __debugInfo(): array
    {
        return ['url' => $this->url, 'serverKey' => Sender::keyLabel($this->serverKey), 'timeout' => $this->timeout];
    }

    public function send(string $json): Response
    {
        $headers = [
            'Authorization' => 'Bearer '.$this->serverKey,
            'Content-Type' => 'application/json',
            'X-SDK' => Version::SDK_NAME.'/'.Version::SDK_VERSION,
        ];
        $body = $json;
        if (strlen($json) > self::GZIP_ABOVE_BYTES) {
            $gzipped = gzencode($json);
            if ($gzipped !== false) {
                $headers['Content-Encoding'] = 'gzip';
                $body = $gzipped;
            }
        }

        try {
            $response = $this->http->request('POST', $this->url, [
                RequestOptions::HEADERS => $headers,
                RequestOptions::BODY => $body,
                RequestOptions::TIMEOUT => $this->timeout,
                RequestOptions::CONNECT_TIMEOUT => $this->timeout,
                RequestOptions::ALLOW_REDIRECTS => false,
                RequestOptions::HTTP_ERRORS => false,
                RequestOptions::DECODE_CONTENT => true,
            ]);
        } catch (GuzzleException $e) {
            return Response::networkFailure($e::class.': '.$e->getMessage());
        }

        $retryAfter = trim($response->getHeaderLine('Retry-After'));
        $decoded = json_decode((string) $response->getBody(), true);

        return new Response(
            $response->getStatusCode(),
            ctype_digit($retryAfter) ? (int) $retryAfter : null,
            is_array($decoded) ? $decoded : null,
        );
    }
}
