# PHP AI SDK — Live Transports

WebSocket and bidirectional HTTP/2 transports for [PHP AI SDK](https://phpaisdk.com) live sessions. Transport selection follows the endpoint supplied by your provider.

## Installation

```bash
composer require aisdk/transport aisdk/xai
```

Set `XAI_API_KEY` in your environment for this example. Install the provider package you intend to use.

## Usage

```php
use AiSdk\Live;
use AiSdk\Transport;
use AiSdk\XAI;

$session = Live::voice()
    ->model(XAI::model('grok-voice-latest'))
    ->voice('eve')
    ->connect(Transport::auto());

// Send microphone audio and consume session events in your application.
$session->close();
```

Use `Transport::webSocket()` or `Transport::http2()` when you need an explicit transport.

## Documentation

[Read the documentation](https://phpaisdk.com/docs/0.x/real-time/transports) for configuration, supported features, and more examples.

[Contributing](https://github.com/phpaisdk/.github/blob/main/CONTRIBUTING.md) · [Support](https://github.com/phpaisdk/.github/blob/main/SUPPORT.md)

## License

The MIT License (MIT). See [LICENSE](LICENSE) for details.
