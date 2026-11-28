<?php

/**
 * Change an application's primary domain — `PUT /app/{server}/{app}/cname`.
 *
 * Port of `WP_MCP_AI_Tool_Cloudways_App_CNAME_Update` (Proposal 164 section
 * 164.137). One field, required.
 *
 * ## Two corrections
 *
 * - **The dead `sprintf` is gone.** WordPress called
 *   `sprintf( __( 'CNAME updated.' ), esc_html( $cname ) )` — the format string
 *   has no placeholder, so the domain was escaped and then discarded. The
 *   message now names the domain that was set.
 * - **A value that is obviously not a hostname is refused locally.** WordPress
 *   passed anything through `sanitize_text_field()`, so a pasted URL
 *   (`https://example.com/`) reached the API and came back as a generic
 *   rejection. Whitespace, a scheme separator or a path separator is now named
 *   as a validation error. The check is deliberately minimal — an
 *   internationalised domain is not rejected here, because this runtime has no
 *   IDN table and inventing one would refuse valid input.
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

class AppCnameUpdateTool extends CloudwaysTool
{
    public function __construct(ErrorFactoryInterface $e, CloudwaysClientInterface $client)
    {
        parent::__construct($e, $client);
    }

    public function getSlug(): string
    {
        return 'cloudways_app_cname_update';
    }

    public function getName(): string
    {
        return 'Cloudways: Update App CNAME';
    }

    public function getDescription(): string
    {
        return 'Sets an application\'s primary domain (CNAME). The domain must already point at the application.';
    }

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
                'cname'     => array(
                    'type'        => 'string',
                    'description' => 'The new primary domain, e.g. www.example.com.',
                    'minLength'   => 1,
                ),
            ),
            'required'             => array('server_id', 'app_id', 'cname'),
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
        $cname    = trim($this->stringParam($arguments, 'cname'));

        if ('' === $cname) {
            return $this->errors->validationFailed(
                'A valid CNAME is required.',
                array('cname' => array('The domain to set, e.g. www.example.com.'))
            );
        }

        if (1 === preg_match('/[\s\/:]/', $cname)) {
            return $this->errors->validationFailed(
                'That is not a domain name.',
                array('cname' => array('Pass the host alone, without a scheme or a path.'))
            );
        }

        try {
            $result = $this->client->put(
                '/app/' . $serverId . '/' . $appId . '/cname',
                array('cname' => $cname)
            );
        } catch (CloudwaysApiException $e) {
            return $this->failure($e);
        }

        return $this->success(
            sprintf('CNAME updated to %s.', $cname),
            array(
                'cname'  => $cname,
                'result' => $result,
            )
        );
    }
}
