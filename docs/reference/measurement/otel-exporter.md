# OTel JSON Exporter

The OpenTelemetry JSON exporter serializes the collector's buffered
events into an [OTLP/JSON](https://opentelemetry.io/docs/specs/otlp/#otlpjson)
payload ready for ingestion by any OpenTelemetry Collector. It is
deliberately **dependency-free** — we do not require the
`opentelemetry-php` SDK, which would:

1. Add ~1 MB of vendor code to a WordPress plugin, and
2. Raise the base plugin's minimum PHP from 7.4 to 8.0+.

What you get instead is a thin seam: build a payload, dispatch it, or
download it. Transport is explicitly the operator's call.

## Building a payload

```php
$exporter = new WP_MCP_AI_OTel_Exporter();
$payload  = $exporter->build_payload( [
    'service_name'           => 'my-site',
    'service_version'        => '1.0.0',
    'deployment_environment' => 'production',
    'resource_attributes'    => [
        'host.name'          => wp_parse_url( home_url(), PHP_URL_HOST ),
        'nvoos.multisite'    => is_multisite(),
    ],
] );
```

The return value is an assoc array in OTLP shape:

```
resourceMetrics[]
  resource.attributes[] { key, value: { stringValue|intValue|doubleValue|boolValue } }
  scopeMetrics[]
    scope { name: 'wp-mcp-ai', version }
    metrics[]
      name, unit, description
      sum | gauge { dataPoints[] { attributes[], timeUnixNano, asDouble } }
```

Counter/rate metrics become `sum` with cumulative temporality and
`isMonotonic: true`. Gauges and histograms are emitted as `gauge` data
points (we leave aggregation to the downstream Collector, which is
where it belongs).

## Dispatching

For pipelines that want a "just ship it" seam, `dispatch()` builds the
payload, fires the `wp_mcp_ai_otel_payload_ready` action with the
payload and source events, and appends the events to a persistent
rolling buffer:

```php
add_action( 'wp_mcp_ai_otel_payload_ready', function ( $payload ) {
    wp_remote_post( 'https://otel.example/v1/metrics', [
        'headers' => [ 'Content-Type' => 'application/json' ],
        'body'    => wp_json_encode( $payload ),
        'timeout' => 5,
    ] );
}, 10, 1 );
```

## Rolling persistent buffer

The exporter maintains a size-capped, non-autoloaded option at
`wp_mcp_ai_otel_rolling_buffer` so sites without an APM agent can still
inspect recent history after the PHP request ends. Defaults:

- Size cap: 512 events (filter `wp_mcp_ai_otel_buffer_max`).
- Redaction: every event runs through `wp_mcp_ai_otel_redact` before
  storage, so returning `null` from that filter drops events entirely.

The buffer is read-only by design. Use the admin "Clear Buffers" action
(or `$exporter->clear_rolling_buffer()`) to flush it.

## Redaction seam

Two filters let deployments shape what leaves the site:

```php
// Per-event redaction. Return null to drop.
add_filter( 'wp_mcp_ai_otel_redact', function ( $event ) {
    if ( isset( $event['context']['user_id'] ) ) {
        $event['context']['user_id'] = hash_hmac( 'sha256', (string) $event['context']['user_id'], 'salt' );
    }
    return $event;
} );

// Final payload shaping.
add_filter( 'wp_mcp_ai_otel_payload', function ( $payload, $events, $options ) {
    // Add custom resource attributes, strip metrics, etc.
    return $payload;
}, 10, 3 );
```

The base plugin already hashes user content at collection time (see
the privacy-tier documentation); these filters exist for deployment-
specific overrides, not as a substitute for baseline hygiene.

## Admin download

The read-only measurement dashboard ships a **Download OTel JSON
Export** button (nonce + `manage_options`). It streams the current
buffer as an OTLP/JSON attachment suitable for `curl --data-binary @…`
ingestion into a local Collector. This is the smallest-possible path
for operators doing ad-hoc analysis without a live APM agent.

## What the exporter deliberately does NOT do

- **No HTTP transport.** Transport is ops-policy (timeouts, retries,
  auth, TLS) — we won't make that decision for a WordPress site.
- **No histogram aggregation.** Bucketing belongs in the downstream
  Collector, which has the memory and CPU budget for it.
- **No span/trace emission.** This exporter is metrics-only. Tracing
  will be a separate seam when the core gains span-capable events.
- **No schema negotiation.** OTLP version is pinned at the JSON shape
  as of OTLP 1.3.x. If that shape ever changes incompatibly, we'll
  ship a v2 exporter rather than mutating this one.
