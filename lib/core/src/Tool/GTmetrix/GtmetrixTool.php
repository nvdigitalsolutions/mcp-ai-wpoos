<?php

/**
 * Shared ground for the GTmetrix toolkit ports.
 *
 * Proposal 164 section 164.91. Two things every tool needs and nothing else:
 *
 * - **The same capability WordPress asks for.** The read tools require `read`;
 *   the credit-spending and account-writing tools override to `manage_options`.
 *
 * - **One place where a failed API call becomes an envelope.** The client throws
 *   a classified exception; `failure()` turns it into the canonical error with
 *   the core code, the HTTP status, the API's own code and any `retry_after`.
 *
 * Payload readers are tolerant: the API omits keys for features an account does
 * not have, and a missing key is an empty string or zero, which is what a
 * caller would have received from the WordPress tools anyway.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\GTmetrix;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\GtmetrixClientInterface;
use Nvoos\Core\Domain\Contract\ToolCapabilityFlagsInterface;
use Nvoos\Core\Domain\Error\GtmetrixApiException;
use Nvoos\Core\Tool\AbstractTool;

abstract class GtmetrixTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    public function __construct(
        ErrorFactoryInterface $e,
        protected readonly GtmetrixClientInterface $client,
    ) {
        parent::__construct($e);
    }

    public function getRequiredCapability(): string
    {
        return 'read';
    }

    /**
     * A credentialed read against a third-party API.
     *
     * The WordPress base declared `cacheable` as well; a live account balance and
     * a 24-hour test list are not, so the flag is not carried.
     *
     * @return string[]
     */
    public function getCapabilityFlags(): array
    {
        return array('external-api', 'network-dependent', 'read-only', 'requires-credentials');
    }

    /**
     * A refused API call, as the canonical error envelope.
     */
    protected function failure(GtmetrixApiException $error): mixed
    {
        $data = array();

        if ($error->statusCode > 0) {
            $data['status'] = $error->statusCode;
        }

        if ('' !== $error->apiCode) {
            $data['api_code'] = $error->apiCode;
        }

        if ($error->retryAfter > 0) {
            $data['retry_after'] = $error->retryAfter;
        }

        return $this->errors->create($error->errorCode, $error->getMessage(), $data);
    }

    /**
     * Refuse before a request when no key is configured.
     */
    protected function guard(): mixed
    {
        if (! $this->client->isConfigured()) {
            return $this->errors->create(
                'gtmetrix_not_configured',
                'No GTmetrix API key is configured for this runtime.'
            );
        }

        return null;
    }

    /**
     * A JSON:API resource list, with non-resource entries dropped.
     *
     * @return list<array<string, mixed>>
     */
    protected function resources(mixed $data): array
    {
        if (! is_array($data)) {
            return array();
        }

        return array_values(array_filter($data, 'is_array'));
    }

    /**
     * One resource's `attributes` block.
     *
     * @param array<string, mixed> $resource Resource.
     *
     * @return array<string, mixed>
     */
    protected function attrs(array $resource): array
    {
        $attrs = $resource['attributes'] ?? null;

        return is_array($attrs) ? $attrs : array();
    }

    /**
     * A string field from an API row, or '' when absent.
     */
    protected function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * An integer field from an API row, or zero when absent or non-numeric.
     */
    protected function number(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * A boolean field from an API row.
     */
    protected function flag(mixed $value): bool
    {
        return ! empty($value);
    }

    /**
     * A list of scalar values as strings.
     *
     * @return list<string>
     */
    protected function strings(mixed $value): array
    {
        if (! is_array($value)) {
            return array();
        }

        $out = array();

        foreach ($value as $item) {
            if (is_scalar($item)) {
                $out[] = (string) $item;
            }
        }

        return $out;
    }
}
