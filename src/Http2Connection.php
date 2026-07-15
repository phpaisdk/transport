<?php

declare(strict_types=1);

namespace AiSdk\Transport;

use AiSdk\Exceptions\TransportException;
use AiSdk\Live\Contracts\TransportConnectionInterface;
use AiSdk\Live\Http2Endpoint;
use AiSdk\Live\TransportFrame;
use AiSdk\Live\TransportFrameType;

use function Amp\async;

use Amp\ByteStream\Pipe;
use Amp\ByteStream\WritableStream;
use Amp\DeferredCancellation;
use Amp\Future;
use Amp\Http\Client\DelegateHttpClient;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use Amp\Http\Client\StreamedContent;
use Throwable;

final class Http2Connection implements TransportConnectionInterface
{
    private bool $closed = false;

    private ?Response $response = null;

    /** @param Future<Response> $responseFuture */
    private function __construct(
        private readonly WritableStream $requestBody,
        private readonly Future $responseFuture,
        private readonly DeferredCancellation $cancellation,
        private readonly int $maximumRequestChunkBytes,
        private readonly int $maximumResponseChunkBytes,
        private readonly int $maximumErrorBodyBytes,
    ) {}

    /**
     * Starts request writing in a separate Amp task. Bidirectional servers may
     * wait for the first body event before returning response headers.
     */
    public static function open(
        Http2Endpoint $endpoint,
        DelegateHttpClient $client,
        int $requestBufferBytes,
        float $connectTimeout,
        float $tlsHandshakeTimeout,
        float $inactivityTimeout,
        float $transferTimeout,
        int $maximumRequestChunkBytes,
        int $maximumResponseChunkBytes,
        int $maximumErrorBodyBytes,
    ): self {
        $pipe = new Pipe($requestBufferBytes);
        $method = strtoupper($endpoint->method);
        if ($method === '') {
            throw new TransportException('The HTTP/2 request method cannot be empty.');
        }

        $request = new Request(
            $endpoint->url,
            $method,
            StreamedContent::fromStream($pipe->getSource()),
        );
        $request->setProtocolVersions(['2']);
        $request->setTcpConnectTimeout($connectTimeout);
        $request->setTlsHandshakeTimeout($tlsHandshakeTimeout);
        $request->setInactivityTimeout($inactivityTimeout);
        $request->setTransferTimeout($transferTimeout);
        $request->setBodySizeLimit(0);

        foreach ($endpoint->headers as $name => $value) {
            if ($name === '') {
                throw new TransportException('HTTP/2 header names cannot be empty.');
            }
            $request->setHeader($name, $value);
        }

        $cancellation = new DeferredCancellation();
        $future = async(
            static fn(): Response => $client->request($request, $cancellation->getCancellation()),
        );

        return new self(
            $pipe->getSink(),
            $future,
            $cancellation,
            $maximumRequestChunkBytes,
            $maximumResponseChunkBytes,
            $maximumErrorBodyBytes,
        );
    }

    public function send(TransportFrame $frame): void
    {
        if ($this->closed || ! $this->requestBody->isWritable()) {
            throw new TransportException('The HTTP/2 request stream is closed.');
        }
        if ($frame->type !== TransportFrameType::Binary) {
            throw new TransportException('The HTTP/2 transport accepts binary frames only.');
        }
        if (strlen($frame->payload) > $this->maximumRequestChunkBytes) {
            throw new TransportException('An HTTP/2 request chunk exceeded the configured byte limit.');
        }

        try {
            $this->requestBody->write($frame->payload);
        } catch (Throwable $exception) {
            throw new TransportException('Unable to write to the HTTP/2 request stream: ' . $exception->getMessage(), [], $exception);
        }
    }

    public function receive(): ?TransportFrame
    {
        if ($this->closed) {
            return null;
        }

        try {
            $response = $this->response ??= $this->responseFuture->await();
            if ($response->getProtocolVersion() !== '2') {
                throw new TransportException('The server did not negotiate HTTP/2.');
            }

            if ($response->getStatus() < 200 || $response->getStatus() >= 300) {
                [$body, $truncated] = $this->readErrorBody($response);
                $context = ['status' => $response->getStatus(), 'body' => $body];
                if ($truncated) {
                    $context['truncated'] = true;
                }

                throw new TransportException(
                    'The HTTP/2 endpoint returned status ' . $response->getStatus() . '.',
                    $context,
                );
            }

            $chunk = $response->getBody()->read();
            if ($chunk === null) {
                $this->closed = true;
                $this->requestBody->close();

                return null;
            }
            if (strlen($chunk) > $this->maximumResponseChunkBytes) {
                throw new TransportException('An HTTP/2 response chunk exceeded the configured byte limit.');
            }

            return TransportFrame::binary($chunk);
        } catch (TransportException $exception) {
            $this->closed = true;
            $this->requestBody->close();
            $this->cancellation->cancel();

            throw $exception;
        } catch (Throwable $exception) {
            $this->closed = true;
            $this->requestBody->close();
            $this->cancellation->cancel();

            throw new TransportException('Unable to read the HTTP/2 response stream: ' . $exception->getMessage(), [], $exception);
        }
    }

    public function finishSending(): void
    {
        try {
            if ($this->requestBody->isWritable()) {
                $this->requestBody->end();
            }
        } catch (Throwable $exception) {
            throw new TransportException('Unable to finish the HTTP/2 request stream: ' . $exception->getMessage(), [], $exception);
        }
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        try {
            $this->closed = true;
            $this->requestBody->close();
            $this->response?->getBody()->close();
            $this->cancellation->cancel();
        } catch (Throwable $exception) {
            throw new TransportException('Unable to close the HTTP/2 connection: ' . $exception->getMessage(), [], $exception);
        }
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    /** @return array{string, bool} */
    private function readErrorBody(Response $response): array
    {
        $body = '';
        $truncated = false;
        $stream = $response->getBody();

        while (($chunk = $stream->read()) !== null) {
            $remaining = $this->maximumErrorBodyBytes - strlen($body);
            if (strlen($chunk) > $remaining) {
                $body .= substr($chunk, 0, max(0, $remaining));
                $truncated = true;
                $stream->close();

                break;
            }

            $body .= $chunk;
        }

        return [$body, $truncated];
    }
}
