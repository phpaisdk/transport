<?php

declare(strict_types=1);

namespace AiSdk\Transport;

use AiSdk\Exceptions\InvalidArgumentException;
use AiSdk\Live\Contracts\TransportConnectionInterface;
use AiSdk\Live\Contracts\TransportInterface;
use AiSdk\Live\Http2Endpoint;
use AiSdk\Live\TransportEndpoint;
use Amp\Http\Client\DelegateHttpClient;
use Amp\Http\Client\HttpClientBuilder;

final class Http2Transport implements TransportInterface
{
    private readonly DelegateHttpClient $client;

    public function __construct(
        ?DelegateHttpClient $client = null,
        private readonly int $requestBufferBytes = 65_536,
        private readonly float $connectTimeout = 10,
        private readonly float $tlsHandshakeTimeout = 10,
        private readonly float $inactivityTimeout = 0,
        private readonly float $transferTimeout = 0,
        private readonly int $maximumRequestChunkBytes = 16_777_216,
        private readonly int $maximumResponseChunkBytes = 16_777_216,
        private readonly int $maximumErrorBodyBytes = 65_536,
    ) {
        if ($this->requestBufferBytes < 1 || $this->maximumRequestChunkBytes < 1 || $this->maximumResponseChunkBytes < 1 || $this->maximumErrorBodyBytes < 1) {
            throw new InvalidArgumentException('HTTP/2 transport byte limits must be at least one byte.');
        }
        if ($this->connectTimeout < 0 || $this->tlsHandshakeTimeout < 0 || $this->inactivityTimeout < 0 || $this->transferTimeout < 0) {
            throw new InvalidArgumentException('HTTP/2 transport timeouts cannot be negative.');
        }

        $this->client = $client ?? (new HttpClientBuilder())
            ->retry(0)
            ->followRedirects(0)
            ->skipAutomaticCompression()
            ->build();
    }

    public function supports(TransportEndpoint $endpoint): bool
    {
        return $endpoint instanceof Http2Endpoint;
    }

    public function connect(TransportEndpoint $endpoint): TransportConnectionInterface
    {
        if (! $endpoint instanceof Http2Endpoint) {
            throw new InvalidArgumentException('Http2Transport requires an Http2Endpoint.');
        }

        return Http2Connection::open(
            endpoint: $endpoint,
            client: $this->client,
            requestBufferBytes: $this->requestBufferBytes,
            connectTimeout: $this->connectTimeout,
            tlsHandshakeTimeout: $this->tlsHandshakeTimeout,
            inactivityTimeout: $this->inactivityTimeout,
            transferTimeout: $this->transferTimeout,
            maximumRequestChunkBytes: $this->maximumRequestChunkBytes,
            maximumResponseChunkBytes: $this->maximumResponseChunkBytes,
            maximumErrorBodyBytes: $this->maximumErrorBodyBytes,
        );
    }
}
