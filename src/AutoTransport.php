<?php

declare(strict_types=1);

namespace AiSdk\Transport;

use AiSdk\Exceptions\UnsupportedTransportException;
use AiSdk\Live\Contracts\TransportConnectionInterface;
use AiSdk\Live\Contracts\TransportInterface;
use AiSdk\Live\Http2Endpoint;
use AiSdk\Live\TransportEndpoint;
use AiSdk\Live\WebSocketEndpoint;

final readonly class AutoTransport implements TransportInterface
{
    public function __construct(
        private WebSocketTransport $webSocket = new WebSocketTransport(),
        private Http2Transport $http2 = new Http2Transport(),
    ) {}

    public function supports(TransportEndpoint $endpoint): bool
    {
        return $endpoint instanceof WebSocketEndpoint || $endpoint instanceof Http2Endpoint;
    }

    public function connect(TransportEndpoint $endpoint): TransportConnectionInterface
    {
        return match (true) {
            $endpoint instanceof WebSocketEndpoint => $this->webSocket->connect($endpoint),
            $endpoint instanceof Http2Endpoint => $this->http2->connect($endpoint),
            default => throw UnsupportedTransportException::for($endpoint),
        };
    }
}
