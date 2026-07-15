<?php

declare(strict_types=1);

namespace AiSdk\Transport;

use AiSdk\Exceptions\TransportException;
use AiSdk\Live\Contracts\TransportConnectionInterface;
use AiSdk\Live\TransportFrame;
use AiSdk\Live\TransportFrameType;
use Amp\Websocket\Client\WebsocketConnection as AmpWebsocketConnection;
use Amp\Websocket\WebsocketCloseCode;
use Throwable;

final readonly class WebSocketConnection implements TransportConnectionInterface
{
    public function __construct(private AmpWebsocketConnection $socket, private int $maximumMessageBytes) {}

    public function send(TransportFrame $frame): void
    {
        try {
            if (strlen($frame->payload) > $this->maximumMessageBytes) {
                throw new TransportException('A WebSocket message exceeded the configured byte limit.');
            }

            match ($frame->type) {
                TransportFrameType::Text => $this->socket->sendText($frame->payload),
                TransportFrameType::Binary => $this->socket->sendBinary($frame->payload),
            };
        } catch (TransportException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new TransportException('Unable to send a WebSocket frame: ' . $exception->getMessage(), [], $exception);
        }
    }

    public function receive(): ?TransportFrame
    {
        try {
            $message = $this->socket->receive();
            if ($message === null) {
                if ($this->socket->getCloseInfo()->getCode() === WebsocketCloseCode::MESSAGE_TOO_LARGE) {
                    throw new TransportException('A WebSocket message exceeded the configured byte limit.');
                }

                return null;
            }
            $payload = $message->buffer(limit: $this->maximumMessageBytes);
            if (strlen($payload) > $this->maximumMessageBytes) {
                throw new TransportException('A WebSocket message exceeded the configured byte limit.');
            }

            return $message->isBinary() ? TransportFrame::binary($payload) : TransportFrame::text($payload);
        } catch (TransportException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new TransportException('Unable to receive a WebSocket frame: ' . $exception->getMessage(), [], $exception);
        }
    }

    public function finishSending(): void
    {
        try {
            if (! $this->socket->isClosed()) {
                $this->socket->close(1000);
            }
        } catch (Throwable $exception) {
            throw new TransportException('Unable to finish the WebSocket connection: ' . $exception->getMessage(), [], $exception);
        }
    }

    public function close(): void
    {
        try {
            if (! $this->socket->isClosed()) {
                $this->socket->close();
            }
        } catch (Throwable $exception) {
            throw new TransportException('Unable to close the WebSocket connection: ' . $exception->getMessage(), [], $exception);
        }
    }

    public function isClosed(): bool
    {
        return $this->socket->isClosed();
    }
}
