# aisdk/transport

<a href="https://github.com/phpaisdk/transport/actions"><img alt="GitHub Workflow Status" src="https://img.shields.io/github/actions/workflow/status/phpaisdk/transport/tests.yml?branch=main&label=Tests"></a>
<a href="https://packagist.org/packages/aisdk/transport"><img alt="Total Downloads" src="https://img.shields.io/packagist/dt/aisdk/transport"></a>
<a href="https://packagist.org/packages/aisdk/transport"><img alt="Latest Version" src="https://img.shields.io/packagist/v/aisdk/transport"></a>
<a href="https://packagist.org/packages/aisdk/transport"><img alt="License" src="https://img.shields.io/packagist/l/aisdk/transport"></a>
<a href="https://whyphp.dev"><img src="https://img.shields.io/badge/Why_PHP-in_2026-7A86E8?style=flat-square&labelColor=18181b" alt="Why PHP in 2026"></a>

------

Ready-made network transports for the provider-neutral Live API in `aisdk/core`.
Provider packages continue to own authentication, endpoint construction, and
event codecs; this package only moves text and binary frames over the network.

## Installation

```bash
composer require aisdk/transport
```

## Automatic selection

```php
use AiSdk\Live;
use AiSdk\OpenAI;
use AiSdk\Transport;

$session = Live::voice()
    ->model(OpenAI::model('gpt-realtime-2.1'))
    ->voice('marin')
    ->connect(Transport::auto());
```

`Transport::auto()` selects the implementation from the endpoint prepared by
the provider adapter:

- WebSocket endpoints use `amphp/websocket-client`.
- Bidirectional HTTP/2 endpoints use `amphp/http-client`.

Use an explicit transport when you want to restrict the connection type:

```php
$webSocket = Transport::webSocket(
    maximumMessageBytes: 16_777_216,
    connectTimeout: 10,
    tlsHandshakeTimeout: 10,
);

$http2 = Transport::http2(
    requestBufferBytes: 65_536,
    inactivityTimeout: 0,
    transferTimeout: 0,
    maximumRequestChunkBytes: 16_777_216,
    maximumResponseChunkBytes: 16_777_216,
);
```

Both explicit factories accept an Amp connector/client for applications that
need custom proxy, DNS, socket, or TLS behavior. Their public constructors are
also available when composing `AutoTransport` manually.

## Concurrent audio and events

`LiveSession::events()` waits for incoming data, so a full-duplex voice agent
should send audio and consume events in separate Amp tasks. Core remains free
of Amp types; concurrency is an application concern:

```php
use AiSdk\Live\AudioDelta;
use AiSdk\Live\ResponseCompleted;
use function Amp\async;
use function Amp\delay;

$sender = async(function () use ($session, $pcmBytes): void {
    foreach (str_split($pcmBytes, 3_200) as $chunk) {
        $session->sendAudio($chunk);
        delay(0.02);
    }

    $session->commitAudio();
});

$receiver = async(function () use ($session, $speaker): void {
    foreach ($session->events() as $event) {
        if ($event instanceof AudioDelta) {
            $speaker->write($event->bytes);
        }

        if ($event instanceof ResponseCompleted) {
            $session->close();

            return;
        }
    }
});

$sender->await();
$receiver->await();
```

For an open-ended microphone session, keep both tasks alive until the
application's own stop signal, then call `$session->close()` and await them.

## Bidirectional HTTP/2

The HTTP/2 implementation writes the streaming request body and reads the
streaming response independently. It supports request-body half-close, applies
backpressure through an Amp pipe, disables whole-transfer and inactivity
timeouts by default for long-lived sessions, and refuses HTTP/1.1 fallback.

Transport configuration such as timeouts, TLS, buffers, and message limits
belongs here. Provider JSON, AWS signing, EventStream framing, WebRTC, and SIP
logic deliberately do not.

Applications can still connect with any implementation of core's
`TransportInterface`; installing this package is a convenience, not a
requirement of `AiSdk\Live`.

## Testing

```bash
composer test
```

## Links

- [Core package](https://github.com/phpaisdk/core)
- [OpenAI provider](https://github.com/phpaisdk/openai)
- [Amazon Bedrock provider](https://github.com/phpaisdk/bedrock)
