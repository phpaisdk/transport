# aisdk/transport: guide for coding agents

Optional network transports for the provider-neutral Live API in `aisdk/core`: WebSocket and bidirectional HTTP/2, built on Amp. Provider packages own authentication, endpoints and event codecs; this package only moves text and binary frames.

## Rules

1. **Frames only.** No provider knowledge, no JSON event handling, no auth. Implement `TransportInterface` / `TransportConnectionInterface` from core and nothing more.
2. **Selection is automatic.** `AutoTransport` picks WebSocket or HTTP/2 from the endpoint a provider hands over (`TransportEndpoint`, `Http2Endpoint`). Add a new transport by implementing the SPI and extending that selection, never by special-casing a provider.
3. **Normal HTTP generation never uses this package.** It is Live only.
4. Close connections deterministically; Live sessions rely on `close()` to release sockets.

## Layout

`src/WebSocketTransport.php`, `src/Http2Transport.php`, `src/AutoTransport.php`, `src/*Connection.php`, `src/Root/Transport.php` (facade), `tests/`.

## Commands

```bash
composer lint && composer analyze && composer test
```

Tests must not open real sockets to third parties; use local Amp servers.

## Docs

https://phpaisdk.com/docs/transports.md · https://phpaisdk.com/llms.txt
