<?php

declare(strict_types=1);

use AiSdk\Exceptions\TransportException;
use AiSdk\Live\Http2Endpoint;
use AiSdk\Live\TransportFrame;
use AiSdk\Live\WebSocketEndpoint;
use AiSdk\Transport\AutoTransport;
use AiSdk\Transport\Http2Transport;
use Amp\ByteStream\ReadableBuffer;
use Amp\Cancellation;
use Amp\Http\Client\DelegateHttpClient;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;

final class FakeHttp2Client implements DelegateHttpClient
{
    public ?Request $request = null;

    public string $firstBodyChunk = '';

    /** @param '1.0'|'1.1'|'2' $protocol */
    public function __construct(
        private readonly string $protocol = '2',
        private readonly int $status = 200,
        private readonly string $responseBody = 'server-chunk',
    ) {}

    public function request(Request $request, Cancellation $cancellation): Response
    {
        $this->request = $request;
        $this->firstBodyChunk = $request->getBody()->getContent()->read($cancellation) ?? '';

        return new Response(
            protocolVersion: $this->protocol,
            status: $this->status,
            reason: null,
            headers: [],
            body: new ReadableBuffer($this->responseBody),
            request: $request,
        );
    }
}

final class HalfCloseHttp2Client implements DelegateHttpClient
{
    public string $requestBody = '';

    public bool $requestBodyEnded = false;

    public function request(Request $request, Cancellation $cancellation): Response
    {
        $content = $request->getBody()->getContent();
        while (($chunk = $content->read($cancellation)) !== null) {
            $this->requestBody .= $chunk;
        }
        $this->requestBodyEnded = true;

        return new Response(
            protocolVersion: '2',
            status: 200,
            reason: null,
            headers: [],
            body: new ReadableBuffer('after-half-close'),
            request: $request,
        );
    }
}

it('writes and reads a full-duplex HTTP/2 stream without waiting for response headers', function () {
    $client = new FakeHttp2Client();
    $transport = new Http2Transport($client);
    $connection = $transport->connect(new Http2Endpoint(
        'https://example.test/live',
        ['Content-Type' => 'application/octet-stream'],
    ));

    // connect() has already started the request task, while the fake server is
    // blocked waiting for the first independently-written request body chunk.
    $connection->send(TransportFrame::binary('client-chunk'));
    $incoming = $connection->receive();

    $request = $client->request;
    if (! $request instanceof Request) {
        throw new LogicException('Expected the HTTP/2 client to receive a request.');
    }

    expect($request)->toBeInstanceOf(Request::class)
        ->and($request->getProtocolVersions())->toBe(['2'])
        ->and($request->getHeader('content-type'))->toBe('application/octet-stream')
        ->and($client->firstBodyChunk)->toBe('client-chunk')
        ->and($incoming?->payload)->toBe('server-chunk');

    $connection->finishSending();
    $connection->close();

    expect($connection->isClosed())->toBeTrue();
});

it('rejects an HTTP protocol downgrade', function () {
    $connection = (new Http2Transport(new FakeHttp2Client(protocol: '1.1')))
        ->connect(new Http2Endpoint('https://example.test/live'));
    $connection->send(TransportFrame::binary('start'));
    $connection->receive();
})->throws(TransportException::class, 'did not negotiate HTTP/2');

it('half-closes request sending while keeping response reads available', function () {
    $client = new HalfCloseHttp2Client();
    $connection = (new Http2Transport($client))
        ->connect(new Http2Endpoint('https://example.test/live'));

    $connection->send(TransportFrame::binary('first'));
    $connection->send(TransportFrame::binary('-second'));
    $connection->finishSending();

    expect($connection->isClosed())->toBeFalse()
        ->and($connection->receive()?->payload)->toBe('after-half-close')
        ->and($client->requestBody)->toBe('first-second')
        ->and($client->requestBodyEnded)->toBeTrue()
        ->and($connection->receive())->toBeNull()
        ->and($connection->isClosed())->toBeTrue();
});

it('surfaces non-success HTTP responses with bounded response details', function () {
    $connection = (new Http2Transport(new FakeHttp2Client(status: 429, responseBody: 'throttled')))
        ->connect(new Http2Endpoint('https://example.test/live'));
    $connection->send(TransportFrame::binary('start'));

    try {
        $connection->receive();

        throw new LogicException('Expected the HTTP/2 response to fail.');
    } catch (TransportException $exception) {
        expect($exception->context())->toMatchArray([
            'status' => 429,
            'body' => 'throttled',
        ]);
    }
});

it('truncates oversized HTTP error bodies without losing status context', function () {
    $connection = (new Http2Transport(
        new FakeHttp2Client(status: 429, responseBody: 'too-much-detail'),
        maximumErrorBodyBytes: 7,
    ))->connect(new Http2Endpoint('https://example.test/live'));
    $connection->send(TransportFrame::binary('start'));

    try {
        $connection->receive();

        throw new LogicException('Expected the HTTP/2 response to fail.');
    } catch (TransportException $exception) {
        expect($exception->context())->toBe([
            'status' => 429,
            'body' => 'too-muc',
            'truncated' => true,
        ]);
    }
});

it('accepts only binary frames for HTTP/2 byte streams', function () {
    $connection = (new Http2Transport(new FakeHttp2Client()))
        ->connect(new Http2Endpoint('https://example.test/live'));

    expect(fn() => $connection->send(TransportFrame::text('not-binary')))
        ->toThrow(TransportException::class, 'accepts binary frames only');

    $connection->close();
});

it('enforces the configured HTTP/2 request chunk limit', function () {
    $connection = (new Http2Transport(
        new FakeHttp2Client(),
        maximumRequestChunkBytes: 3,
    ))->connect(new Http2Endpoint('https://example.test/live'));

    expect(fn() => $connection->send(TransportFrame::binary('large')))
        ->toThrow(TransportException::class, 'request chunk exceeded the configured byte limit');

    $connection->close();
});

it('validates HTTP/2 transport timeout configuration', function () {
    new Http2Transport(connectTimeout: -1);
})->throws(\AiSdk\Exceptions\InvalidArgumentException::class, 'timeouts cannot be negative');

it('selects both supported endpoint types automatically', function () {
    $transport = new AutoTransport(http2: new Http2Transport(new FakeHttp2Client()));

    expect($transport->supports(new Http2Endpoint('https://example.test/live')))->toBeTrue()
        ->and($transport->supports(new WebSocketEndpoint('wss://example.test/live')))->toBeTrue();
});
