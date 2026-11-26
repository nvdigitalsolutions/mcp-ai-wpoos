<?php

namespace App\Support\Diagnostics;

use Carbon\CarbonImmutable;

/**
 * Routing events as portable JSON — Proposal 149 §73.
 *
 * The diagnostics page reads counts; an operator watching a fleet needs the
 * events themselves, in a shape another tool can ingest. Two formats, one
 * source:
 *
 *  - **flat** — `{window_days, generated_at, events: [...]}`, for spreadsheets,
 *    `jq`, and a human reading a file;
 *  - **otel** — OTLP/JSON log records (`resourceLogs → scopeLogs →
 *    logRecords`), for a collector.
 *
 * The OTel mapping follows the GenAI semantic conventions where they apply:
 * `gen_ai.provider.name` on every record, `gen_ai.request.model` /
 * `gen_ai.response.model` for a model fallback (the request/response split the
 * conventions define), and `nvoos.routing.*` for the facts the conventions have
 * no name for (the kind of deviation, the provider a hop came from, whether the
 * attempt succeeded).
 *
 * A deviation is a **log record, not a span**: it is a fact about a request
 * that already finished, with no duration of its own to measure. Severity is
 * `WARN` for a failed attempt and `INFO` for a successful one — OTLP numbers
 * 13 and 9.
 *
 * Every record carries `nvoos.routing.event_id` (the metric row id). Delivery
 * is at-least-once — a crash between the collector's 2xx and the watermark
 * write resends the batch — so the id is what lets a collector drop a
 * duplicate instead of double-counting it.
 */
final class RoutingEventExporter
{
    private const SERVICE_NAME = 'nvoos-runtime';

    private const SCOPE_NAME = 'nvoos.routing';

    private const SCOPE_VERSION = '1.0.0';

    private const SEVERITY_INFO = 9;

    private const SEVERITY_WARN = 13;

    /**
     * Flat event list, wrapped with the window it covers.
     *
     * @param iterable<object> $rows Rows from `nvoos_routing_metrics`.
     * @return array{window_days: int, generated_at: string, events: list<array<string, mixed>>}
     */
    public function flat(iterable $rows, int $days): array
    {
        $events = [];

        foreach ($rows as $row) {
            $events[] = $this->event($row);
        }

        return [
            'window_days' => $days,
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'events' => $events,
        ];
    }

    /**
     * OTLP/JSON log records.
     *
     * @param iterable<object> $rows Rows from `nvoos_routing_metrics`.
     * @return array{resourceLogs: list<array<string, mixed>>}
     */
    public function otel(iterable $rows): array
    {
        $records = [];

        foreach ($rows as $row) {
            $records[] = $this->logRecord($row);
        }

        return [
            'resourceLogs' => [
                [
                    'resource' => [
                        'attributes' => [
                            $this->stringAttribute('service.name', self::SERVICE_NAME),
                        ],
                    ],
                    'scopeLogs' => [
                        [
                            'scope' => [
                                'name' => self::SCOPE_NAME,
                                'version' => self::SCOPE_VERSION,
                            ],
                            'logRecords' => $records,
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * One normalised event.
     *
     * Empty strings become null: a provider hop has no model, and `""` in a
     * JSON export reads as a model named empty.
     *
     * @param object $row Row from `nvoos_routing_metrics`.
     * @return array<string, mixed>
     */
    private function event(object $row): array
    {
        $kind = $this->kind($row);
        $fromModel = $this->text($row->from_model ?? '');
        $toModel = $this->text($row->to_model ?? '');

        return [
            'id' => (int) ($row->id ?? 0),
            'time' => $this->time($row),
            'kind' => $kind,
            'provider' => (string) ($row->provider ?? ''),
            'from_provider' => $this->text($row->from_provider ?? ''),
            'from_model' => $fromModel,
            'to_model' => $toModel,
            'succeeded' => (bool) ($row->succeeded ?? false),
        ];
    }

    /**
     * One OTLP log record.
     *
     * @param object $row Row from `nvoos_routing_metrics`.
     * @return array<string, mixed>
     */
    private function logRecord(object $row): array
    {
        $event = $this->event($row);
        $nanos = $this->unixNanos($event['time']);

        $attributes = [
            $this->stringAttribute('gen_ai.provider.name', (string) $event['provider']),
            $this->stringAttribute('nvoos.routing.kind', (string) $event['kind']),
            [
                'key' => 'nvoos.routing.event_id',
                'value' => ['intValue' => (string) (int) $event['id']],
            ],
            [
                'key' => 'nvoos.routing.succeeded',
                'value' => ['boolValue' => (bool) $event['succeeded']],
            ],
        ];

        if ('provider_hop' === $event['kind']) {
            if (null !== $event['from_provider']) {
                $attributes[] = $this->stringAttribute(
                    'nvoos.routing.from_provider',
                    (string) $event['from_provider']
                );
            }
        } else {
            // A model fallback: the request/response split the conventions
            // define, so a collector can group by the model that was asked for.
            if (null !== $event['from_model']) {
                $attributes[] = $this->stringAttribute('gen_ai.request.model', (string) $event['from_model']);
            }

            if (null !== $event['to_model']) {
                $attributes[] = $this->stringAttribute('gen_ai.response.model', (string) $event['to_model']);
            }
        }

        $succeeded = (bool) $event['succeeded'];

        return [
            'timeUnixNano' => $nanos,
            'observedTimeUnixNano' => $nanos,
            'severityNumber' => $succeeded ? self::SEVERITY_INFO : self::SEVERITY_WARN,
            'severityText' => $succeeded ? 'INFO' : 'WARN',
            'body' => ['stringValue' => $this->body($event)],
            'attributes' => $attributes,
        ];
    }

    /**
     * Unix nanoseconds as a decimal string.
     *
     * OTLP wants a uint64 rendered as a string. Carbon's
     * `getPreciseTimestamp(9)` returns a **float** at nanosecond precision on
     * this version, and casting it yields `1.795079066E+18` — which a collector
     * rejects. Integer arithmetic from seconds + microseconds is exact, and a
     * database timestamp has no sub-microsecond precision to lose.
     */
    private function unixNanos(string $iso): string
    {
        $date = CarbonImmutable::parse($iso);

        $nanos = $date->getTimestamp() * 1_000_000_000
            + (int) $date->format('u') * 1_000;

        return (string) $nanos;
    }

    /**
     * A one-line human summary of the deviation.
     *
     * @param array<string, mixed> $event Normalised event.
     */
    private function body(array $event): string
    {
        if ('provider_hop' === $event['kind']) {
            return sprintf(
                'provider_hop %s -> %s',
                (string) ($event['from_provider'] ?? '?'),
                (string) $event['provider']
            );
        }

        return sprintf(
            'model_fallback %s %s -> %s',
            (string) $event['provider'],
            (string) ($event['from_model'] ?? '?'),
            (string) ($event['to_model'] ?? '?')
        );
    }

    /**
     * @param object $row Row from `nvoos_routing_metrics`.
     */
    private function kind(object $row): string
    {
        $kind = (string) ($row->kind ?? '');

        return '' !== $kind ? $kind : 'model_fallback';
    }

    private function time(object $row): string
    {
        $value = $row->created_at ?? null;

        if (null === $value || '' === $value) {
            return CarbonImmutable::now()->toIso8601String();
        }

        return CarbonImmutable::parse((string) $value)->toIso8601String();
    }

    private function text(mixed $value): ?string
    {
        $text = trim((string) $value);

        return '' === $text ? null : $text;
    }

    /**
     * @return array{key: string, value: array{stringValue: string}}
     */
    private function stringAttribute(string $key, string $value): array
    {
        return [
            'key' => $key,
            'value' => ['stringValue' => $value],
        ];
    }
}
