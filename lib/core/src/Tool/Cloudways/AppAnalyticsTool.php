<?php

/**
 * Shared ground for the application-scoped Cloudways analytics reads.
 *
 * Proposal 164 section 164.135. Four tools read one block each from
 * `/app/{server}/{app}/...` and differ in exactly three things: the path, the
 * response key, and the projection. Everything else — the capability, the
 * server/app guard, the request, the malformed-response refusal and the envelope
 * — is the same, so it lives here rather than in four copies.
 *
 * ## The projection is faithful, and `values` is additive
 *
 * WordPress projected two fields per tool and dropped the rest of the block. The
 * two documented fields keep their place and type; `values` carries every scalar
 * field the block returned, so a caller can see a metric the toolkit never
 * named without a second endpoint. Nested structures are not flattened: this
 * runtime has not seen the payload, and inventing a shape for it would be a
 * guess.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Cloudways;

use Nvoos\Core\Domain\Error\CloudwaysApiException;

abstract class AppAnalyticsTool extends CloudwaysTool
{
    /**
     * The path segment after `/app/{server}/{app}`, e.g. `analytics/mysql`.
     */
    abstract protected function segment(): string;

    /**
     * The key the response carries the block under, e.g. `mysql_analytics`.
     */
    abstract protected function block(): string;

    /**
     * The two documented fields.
     *
     * @param array<string, mixed> $block The block the API returned.
     *
     * @return array<string, mixed>
     */
    abstract protected function project(array $block): array;

    /**
     * The success message, naming the app.
     */
    abstract protected function message(int $appId): string;

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'server_id' => array(
                    'type'        => 'integer',
                    'description' => 'The Cloudways server id.',
                    'minimum'     => 1,
                ),
                'app_id'    => array(
                    'type'        => 'integer',
                    'description' => 'The application id.',
                    'minimum'     => 1,
                ),
            ),
            'required'             => array('server_id', 'app_id'),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'manage_options');

        $pair = $this->appPair($arguments);

        if (! is_array($pair) || ! isset($pair['app_id'])) {
            return $pair;
        }

        $serverId = $pair['server_id'];
        $appId    = $pair['app_id'];
        $key      = $this->block();

        try {
            $result = $this->client->get(
                '/app/' . $serverId . '/' . $appId . '/' . $this->segment()
            );
        } catch (CloudwaysApiException $e) {
            return $this->failure($e);
        }

        if (! is_array($result[ $key ] ?? null)) {
            return $this->malformed($key);
        }

        $block = $result[ $key ];

        return $this->success(
            $this->message($appId),
            array(
                $key     => $this->project($block),
                'values' => array_merge($this->project($block), $this->scalars($block)),
            )
        );
    }

    /**
     * Every scalar field in the block.
     *
     * @param array<string, mixed> $block The block the API returned.
     *
     * @return array<string, scalar>
     */
    private function scalars(array $block): array
    {
        $values = array();

        foreach ($block as $name => $value) {
            if (is_string($name) && is_scalar($value)) {
                $values[ $name ] = $value;
            }
        }

        return $values;
    }
}
