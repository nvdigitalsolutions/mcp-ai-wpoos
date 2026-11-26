<?php

/**
 * GTmetrix test browsers and their feature support.
 *
 * Port of `WP_MCP_AI_Tool_GTmetrix_List_Browsers` (Proposal 164 section 164.91).
 * One credit-free `GET /browsers`; the feature map is what makes the list
 * useful, because a browser that cannot run Lighthouse or record video is not a
 * valid choice for those options on `gtmetrix_start_test`.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\GTmetrix;

use Nvoos\Core\Domain\Error\GtmetrixApiException;

class GtmetrixListBrowsersTool extends GtmetrixTool
{
    public function getSlug(): string
    {
        return 'gtmetrix_list_browsers';
    }

    public function getName(): string
    {
        return 'GTmetrix List Browsers';
    }

    public function getDescription(): string
    {
        return 'Lists the GTmetrix test browsers available to this account with platform and feature support (Lighthouse, throttling, video, cookies, DNS, AdBlock, HTTP auth, custom user agent). Costs no credits.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(),
            'required'             => array(),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'read');

        $guard = $this->guard();

        if (null !== $guard) {
            return $guard;
        }

        try {
            $response = $this->client->request('GET', 'browsers');
        } catch (GtmetrixApiException $error) {
            return $this->failure($error);
        }

        $rows = array();

        foreach ($this->resources($response['data']) as $resource) {
            $attrs  = $this->attrs($resource);
            $rows[] = array(
                'id'         => $this->text($resource['id'] ?? ''),
                'name'       => $this->text($attrs['name'] ?? ''),
                'platform'   => $this->text($attrs['platform'] ?? ''),
                'device'     => $this->text($attrs['device'] ?? ''),
                'is_default' => $this->flag($attrs['default'] ?? false),
                'features'   => array(
                    'lighthouse' => $this->flag($attrs['lighthouse'] ?? false),
                    'throttle'   => $this->flag($attrs['throttle'] ?? false),
                    'video'      => $this->flag($attrs['video'] ?? false),
                    'cookies'    => $this->flag($attrs['cookies'] ?? false),
                    'dns'        => $this->flag($attrs['dns'] ?? false),
                    'adblock'    => $this->flag($attrs['adblock'] ?? false),
                    'http_auth'  => $this->flag($attrs['http_auth'] ?? false),
                    'user_agent' => $this->flag($attrs['user_agent'] ?? false),
                    'filtering'  => $this->flag($attrs['filtering'] ?? false),
                    'resolution' => $this->flag($attrs['resolution'] ?? false),
                ),
            );
        }

        return $this->success(
            'GTmetrix test browsers.',
            array('browsers' => $rows, 'count' => count($rows))
        );
    }
}
