<?php

/**
 * Set an application's CORS headers — `PUT /app/{server}/{app}/cors`.
 *
 * Port of `WP_MCP_AI_Tool_Cloudways_App_CORS_Headers_Update` (Proposal 164
 * section 164.137). `allowed_origins` is required; the two other lists are sent
 * only when provided, so an update that names only origins leaves the methods
 * and headers alone.
 *
 * The lists are comma-separated strings in the API's own shape, not arrays, and
 * they are passed through trimmed rather than re-split and re-joined: a caller
 * that writes `https://a.test, https://b.test` should get back what it sent,
 * and the API is the authority on whether an origin is acceptable.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Cloudways;

use Nvoos\Core\Domain\Contract\CloudwaysClientInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Error\CloudwaysApiException;

class AppCorsHeadersUpdateTool extends CloudwaysTool
{
    public function __construct(ErrorFactoryInterface $e, CloudwaysClientInterface $client)
    {
        parent::__construct($e, $client);
    }

    public function getSlug(): string
    {
        return 'cloudways_app_cors_headers_update';
    }

    public function getName(): string
    {
        return 'Cloudways: Update CORS Headers';
    }

    public function getDescription(): string
    {
        return 'Sets an application\'s CORS allowed origins, and optionally its allowed methods and headers.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'server_id'       => array(
                    'type'        => 'integer',
                    'description' => 'The Cloudways server id.',
                    'minimum'     => 1,
                ),
                'app_id'          => array(
                    'type'        => 'integer',
                    'description' => 'The application id.',
                    'minimum'     => 1,
                ),
                'allowed_origins' => array(
                    'type'        => 'string',
                    'description' => 'Comma-separated origins, or "*".',
                    'minLength'   => 1,
                ),
                'allowed_methods' => array(
                    'type'        => 'string',
                    'description' => 'Comma-separated HTTP methods (optional).',
                ),
                'allowed_headers' => array(
                    'type'        => 'string',
                    'description' => 'Comma-separated headers (optional).',
                ),
            ),
            'required'             => array('server_id', 'app_id', 'allowed_origins'),
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
        $origins  = trim($this->stringParam($arguments, 'allowed_origins'));

        if ('' === $origins) {
            return $this->errors->validationFailed(
                'Allowed origins are required.',
                array('allowed_origins' => array('One or more origins, or "*".'))
            );
        }

        $body = array('allowed_origins' => $origins);

        foreach (array('allowed_methods', 'allowed_headers') as $key) {
            $value = trim($this->stringParam($arguments, $key));

            if ('' !== $value) {
                $body[ $key ] = $value;
            }
        }

        try {
            $result = $this->client->put('/app/' . $serverId . '/' . $appId . '/cors', $body);
        } catch (CloudwaysApiException $e) {
            return $this->failure($e);
        }

        return $this->success(
            sprintf('CORS headers updated for app %d.', $appId),
            array(
                'cors'   => $body,
                'result' => $result,
            )
        );
    }
}
