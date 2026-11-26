<?php

/**
 * A GTmetrix transport that answers from a table.
 *
 * Records every request so a test can assert the path and query the tool built,
 * and returns the queued payload for it. The response shape is the contract's,
 * including the `meta` block the WordPress client used to discard.
 *
 * @package Nvoos\Core\Tests
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Support;

use Nvoos\Core\Domain\Contract\GtmetrixClientInterface;
use Nvoos\Core\Domain\Error\GtmetrixApiException;

final class FakeGtmetrixClient implements GtmetrixClientInterface
{
    public bool $configured = true;

    /** @var list<array{method: string, path: string, body: array<string, mixed>|null}> */
    public array $calls = array();

    /**
     * Responses keyed by the exact path, then by the path before any query.
     *
     * A row may carry `status`, `data`, `meta`, `links`, `location`,
     * `rate_limit` and `document`; the last is the raw body a report-resource
     * request answers with.
     *
     * @var array<string, array<string, mixed>>
     */
    public array $responses = array();

    public ?GtmetrixApiException $fail = null;

    /**
     * Per-path failures, keyed by the path before any query.
     *
     * @var array<string, GtmetrixApiException>
     */
    public array $failOn = array();

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function request(string $method, string $path, ?array $body = null): array
    {
        $this->calls[] = array('method' => $method, 'path' => $path, 'body' => $body);

        if (null !== $this->fail) {
            throw $this->fail;
        }

        $base = explode('?', $path, 2)[0];

        if (isset($this->failOn[ $base ])) {
            throw $this->failOn[ $base ];
        }

        $row  = $this->responses[ $path ] ?? $this->responses[ $base ] ?? array();

        return array(
            'status'     => (int) ( $row['status'] ?? 200 ),
            'data'       => $row['data'] ?? null,
            'meta'       => is_array($row['meta'] ?? null) ? $row['meta'] : array(),
            'links'      => is_array($row['links'] ?? null) ? $row['links'] : array(),
            'location'   => (string) ( $row['location'] ?? '' ),
            'rate_limit' => is_array($row['rate_limit'] ?? null)
                ? $row['rate_limit']
                : array('limit' => 0, 'remaining' => null, 'reset' => null),
            'document'   => is_array($row['document'] ?? null) ? $row['document'] : array(),
        );
    }
}
