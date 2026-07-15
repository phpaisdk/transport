<?php

declare(strict_types=1);

namespace AiSdk\Transport;

use AiSdk\Exceptions\InvalidArgumentException;
use AiSdk\Exceptions\TransportException;
use AiSdk\Live\Contracts\TransportConnectionInterface;
use AiSdk\Live\Contracts\TransportInterface;
use AiSdk\Live\TransportEndpoint;
use AiSdk\Live\WebSocketEndpoint;
use Amp\Websocket\Client\Rfc6455ConnectionFactory;
use Amp\Websocket\Client\Rfc6455Connector;
use Amp\Websocket\Client\WebsocketConnector;
use Amp\Websocket\Client\WebsocketHandshake;
use Amp\Websocket\Parser\Rfc6455ParserFactory;
use Throwable;

final readonly class WebSocketTransport implements TransportInterface
{
    public function __construct(
        private int $maximumMessageBytes = 16_777_216,
        private float $connectTimeout = 10,
        private float $tlsHandshakeTimeout = 10,
        private int $maximumHeaderBytes = 16_384,
        private ?WebsocketConnector $connector = null,
    ) {
        if ($this->maximumMessageBytes < 1 || $this->maximumHeaderBytes < 1) {
            throw new InvalidArgumentException('WebSocket transport byte limits must be at least one byte.');
        }
        if ($this->connectTimeout < 0 || $this->tlsHandshakeTimeout < 0) {
            throw new InvalidArgumentException('WebSocket transport timeouts cannot be negative.');
        }
    }

    public function supports(TransportEndpoint $endpoint): bool
    {
        return $endpoint instanceof WebSocketEndpoint;
    }

    public function connect(TransportEndpoint $endpoint): TransportConnectionInterface
    {
        try {
            if (! $endpoint instanceof WebSocketEndpoint) {
                throw new InvalidArgumentException('WebSocketTransport requires a WebSocketEndpoint.');
            }

            $headers = [];
            foreach ($endpoint->headers as $name => $value) {
                if ($name === '') {
                    throw new InvalidArgumentException('WebSocket header names cannot be empty.');
                }
                $headers[$name] = $value;
            }
            if ($endpoint->subprotocols !== []) {
                $headers['Sec-WebSocket-Protocol'] = implode(', ', $endpoint->subprotocols);
            }

            $handshake = (new WebsocketHandshake($endpoint->url, $headers))
                ->withTcpConnectTimeout($this->connectTimeout)
                ->withTlsHandshakeTimeout($this->tlsHandshakeTimeout)
                ->withHeaderSizeLimit($this->maximumHeaderBytes);

            // Control frames may contain up to 125 bytes and are not exposed
            // as TransportFrame values. Keep Amp's parser large enough for
            // them while WebSocketConnection enforces the configured limit on
            // application messages.
            $parserLimit = max(125, $this->maximumMessageBytes);
            $connector = $this->connector ?? new Rfc6455Connector(
                connectionFactory: new Rfc6455ConnectionFactory(
                    parserFactory: new Rfc6455ParserFactory(
                        messageSizeLimit: $parserLimit,
                        frameSizeLimit: $parserLimit,
                    ),
                ),
            );

            return new WebSocketConnection($connector->connect($handshake), $this->maximumMessageBytes);
        } catch (InvalidArgumentException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new TransportException(
                'Unable to establish the WebSocket connection: ' . $exception->getMessage(),
                ['endpoint' => self::endpointForContext($endpoint->url)],
                $exception,
            );
        }
    }

    private static function endpointForContext(string $url): string
    {
        $parts = parse_url($url);
        if (! is_array($parts)) {
            return '[invalid WebSocket URL]';
        }

        $scheme = is_string($parts['scheme'] ?? null) ? $parts['scheme'] . '://' : '';
        $host = is_string($parts['host'] ?? null) ? $parts['host'] : '';
        $port = is_int($parts['port'] ?? null) ? ':' . $parts['port'] : '';
        $path = is_string($parts['path'] ?? null) ? $parts['path'] : '';

        return $scheme . $host . $port . $path;
    }
}
