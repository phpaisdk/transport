<?php

declare(strict_types=1);

use AiSdk\Exceptions\InvalidArgumentException;
use AiSdk\Exceptions\TransportException;
use AiSdk\Live\Http2Endpoint;
use AiSdk\Live\TransportFrame;
use AiSdk\Live\WebSocketEndpoint;
use AiSdk\Transport\WebSocketTransport;

use function Amp\async;

use Amp\ByteStream\BufferedReader;
use Amp\Cancellation;

use function Amp\delay;
use function Amp\Socket\listen;

use Amp\Websocket\Client\WebsocketConnection as AmpWebsocketConnection;
use Amp\Websocket\Client\WebsocketConnector;
use Amp\Websocket\Client\WebsocketHandshake;

/** @return array{opcode: int, payload: string} */
function readClientWebSocketFrame(BufferedReader $reader): array
{
    $header = $reader->readLength(2);
    $opcode = ord($header[0]) & 0x0F;
    $masked = (ord($header[1]) & 0x80) !== 0;
    $length = ord($header[1]) & 0x7F;

    if ($length === 126) {
        $decoded = unpack('nlength', $reader->readLength(2));
        $length = is_array($decoded) ? (int) $decoded['length'] : 0;
    } elseif ($length === 127) {
        $decoded = unpack('Nhigh/Nlow', $reader->readLength(8));
        $length = is_array($decoded)
            ? ((int) $decoded['high'] * 4_294_967_296) + (int) $decoded['low']
            : 0;
    }

    $mask = $masked ? $reader->readLength(4) : "\0\0\0\0";
    $payload = $length > 0 ? $reader->readLength($length) : '';
    if ($masked) {
        for ($index = 0; $index < $length; $index++) {
            $payload[$index] = chr(ord($payload[$index]) ^ ord($mask[$index % 4]));
        }
    }

    return ['opcode' => $opcode, 'payload' => $payload];
}

function serverWebSocketFrame(int $opcode, string $payload): string
{
    $length = strlen($payload);
    $header = chr(0x80 | $opcode);
    if ($length <= 125) {
        return $header . chr($length) . $payload;
    }
    if ($length <= 65_535) {
        return $header . chr(126) . pack('n', $length) . $payload;
    }

    return $header . chr(127) . pack('NN', intdiv($length, 4_294_967_296), $length & 0xFFFFFFFF) . $payload;
}

it('supports only WebSocket endpoints', function () {
    $transport = new WebSocketTransport();

    expect($transport->supports(new WebSocketEndpoint('wss://example.test/live')))->toBeTrue()
        ->and($transport->supports(new Http2Endpoint('https://example.test/live')))->toBeFalse();
});

it('validates the maximum message size', function () {
    new WebSocketTransport(0);
})->throws(InvalidArgumentException::class, 'at least one byte');

it('redacts WebSocket query credentials from transport context', function () {
    $connector = new class implements WebsocketConnector {
        public function connect(WebsocketHandshake $handshake, ?Cancellation $cancellation = null): AmpWebsocketConnection
        {
            throw new RuntimeException('Connection failed.');
        }
    };

    try {
        (new WebSocketTransport(connector: $connector))->connect(new WebSocketEndpoint(
            'wss://example.test/live?key=super-secret&model=voice',
        ));

        throw new LogicException('Expected the WebSocket connection to fail.');
    } catch (TransportException $exception) {
        expect($exception->context()['endpoint'] ?? null)->toBe('wss://example.test/live');
        expect(str_contains((string) json_encode($exception->context()), 'super-secret'))->toBeFalse();
    }
});

it('exchanges text and binary messages with a local WebSocket server', function () {
    $server = listen('127.0.0.1:0');
    $serverTask = async(function () use ($server): array {
        $socket = $server->accept();
        if ($socket === null) {
            throw new LogicException('Expected the local WebSocket server to accept a connection.');
        }

        $reader = new BufferedReader($socket);
        $request = $reader->readUntil("\r\n\r\n", limit: 16_384);
        preg_match('/^Sec-WebSocket-Key:\s*(.+)$/mi', $request, $matches);
        $key = trim($matches[1] ?? '');
        $accept = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));

        $socket->write(
            "HTTP/1.1 101 Switching Protocols\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Accept: {$accept}\r\n"
            . "Sec-WebSocket-Protocol: live-test\r\n\r\n",
        );

        $text = readClientWebSocketFrame($reader);
        $binary = readClientWebSocketFrame($reader);
        $socket->write(
            serverWebSocketFrame(1, 'server-text')
            . serverWebSocketFrame(2, "\x03\x04"),
        );
        $socket->close();

        return ['request' => $request, 'text' => $text, 'binary' => $binary];
    });

    try {
        $connection = (new WebSocketTransport())->connect(new WebSocketEndpoint(
            'ws://' . $server->getAddress() . '/live',
            ['X-Live-Test' => 'yes'],
            ['live-test'],
        ));
        $connection->send(TransportFrame::text('client-text'));
        $connection->send(TransportFrame::binary("\x01\x02"));

        expect($connection->receive())->toMatchObject(['payload' => 'server-text'])
            ->and($connection->receive())->toMatchObject(['payload' => "\x03\x04"]);

        $captured = $serverTask->await();
        expect(strtolower($captured['request']))->toContain('x-live-test: yes')
            ->toContain('sec-websocket-protocol: live-test')
            ->and($captured['text'])->toBe(['opcode' => 1, 'payload' => 'client-text'])
            ->and($captured['binary'])->toBe(['opcode' => 2, 'payload' => "\x01\x02"]);
    } finally {
        $server->close();
    }
});

it('enforces the configured WebSocket message limit', function () {
    $server = listen('127.0.0.1:0');
    $serverTask = async(function () use ($server): void {
        $socket = $server->accept();
        if ($socket === null) {
            throw new LogicException('Expected the local WebSocket server to accept a connection.');
        }

        $reader = new BufferedReader($socket);
        $request = $reader->readUntil("\r\n\r\n", limit: 16_384);
        preg_match('/^Sec-WebSocket-Key:\s*(.+)$/mi', $request, $matches);
        $accept = base64_encode(sha1(trim($matches[1] ?? '') . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
        $socket->write(
            "HTTP/1.1 101 Switching Protocols\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Accept: {$accept}\r\n\r\n",
        );
        $socket->write(serverWebSocketFrame(1, 'too-large'));
        delay(0.05);
        $socket->close();
    });

    try {
        $connection = (new WebSocketTransport(maximumMessageBytes: 3))
            ->connect(new WebSocketEndpoint('ws://' . $server->getAddress() . '/live'));
        expect(fn() => $connection->send(TransportFrame::text('too-large')))
            ->toThrow(TransportException::class, 'exceeded the configured byte limit');
        $connection->receive();
    } finally {
        $serverTask->await();
        $server->close();
    }
})->throws(TransportException::class, 'exceeded the configured byte limit');
