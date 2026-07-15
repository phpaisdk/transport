<?php

declare(strict_types=1);

namespace AiSdk;

use AiSdk\Transport\AutoTransport;
use AiSdk\Transport\Http2Transport;
use AiSdk\Transport\WebSocketTransport;
use Amp\Http\Client\DelegateHttpClient;
use Amp\Websocket\Client\WebsocketConnector;

/** Optional, provider-neutral transport conveniences for Live. */
final class Transport
{
    public static function auto(): AutoTransport
    {
        return new AutoTransport();
    }

    public static function webSocket(
        int $maximumMessageBytes = 16_777_216,
        float $connectTimeout = 10,
        float $tlsHandshakeTimeout = 10,
        int $maximumHeaderBytes = 16_384,
        ?WebsocketConnector $connector = null,
    ): WebSocketTransport {
        return new WebSocketTransport(
            maximumMessageBytes: $maximumMessageBytes,
            connectTimeout: $connectTimeout,
            tlsHandshakeTimeout: $tlsHandshakeTimeout,
            maximumHeaderBytes: $maximumHeaderBytes,
            connector: $connector,
        );
    }

    public static function http2(
        ?DelegateHttpClient $client = null,
        int $requestBufferBytes = 65_536,
        float $connectTimeout = 10,
        float $tlsHandshakeTimeout = 10,
        float $inactivityTimeout = 0,
        float $transferTimeout = 0,
        int $maximumRequestChunkBytes = 16_777_216,
        int $maximumResponseChunkBytes = 16_777_216,
        int $maximumErrorBodyBytes = 65_536,
    ): Http2Transport {
        return new Http2Transport(
            client: $client,
            requestBufferBytes: $requestBufferBytes,
            connectTimeout: $connectTimeout,
            tlsHandshakeTimeout: $tlsHandshakeTimeout,
            inactivityTimeout: $inactivityTimeout,
            transferTimeout: $transferTimeout,
            maximumRequestChunkBytes: $maximumRequestChunkBytes,
            maximumResponseChunkBytes: $maximumResponseChunkBytes,
            maximumErrorBodyBytes: $maximumErrorBodyBytes,
        );
    }
}
