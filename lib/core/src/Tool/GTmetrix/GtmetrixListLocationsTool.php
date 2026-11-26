<?php

/**
 * GTmetrix test locations.
 *
 * Port of `WP_MCP_AI_Tool_GTmetrix_List_Locations` (Proposal 164 section
 * 164.91). One credit-free `GET /locations`; `available_only` filters to the
 * locations the account can actually test from (access and at least one server
 * up), which is the pair the API reports separately.
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

class GtmetrixListLocationsTool extends GtmetrixTool
{
    public function getSlug(): string
    {
        return 'gtmetrix_list_locations';
    }

    public function getName(): string
    {
        return 'GTmetrix List Locations';
    }

    public function getDescription(): string
    {
        return 'Lists GTmetrix test locations with region, account access, server availability and supported browsers. Costs no credits.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'available_only' => array(
                    'type'        => 'boolean',
                    'description' => 'Only return locations the account can test from and that have a server up.',
                    'default'     => false,
                ),
            ),
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
            $response = $this->client->request('GET', 'locations');
        } catch (GtmetrixApiException $error) {
            return $this->failure($error);
        }

        $availableOnly = $this->boolParam($arguments, 'available_only');
        $rows          = array();

        foreach ($this->resources($response['data']) as $resource) {
            $attrs = $this->attrs($resource);
            $row   = array(
                'id'         => $this->text($resource['id'] ?? ''),
                'name'       => $this->text($attrs['name'] ?? ''),
                'region'     => $this->text($attrs['region'] ?? ''),
                'has_access' => $this->flag($attrs['account_has_access'] ?? false),
                'is_default' => $this->flag($attrs['default'] ?? false),
                'active'     => $this->flag($attrs['active_location'] ?? false),
                'servers_up' => $this->number($attrs['servers'] ?? 0),
                'browsers'   => $this->strings($attrs['browsers'] ?? array()),
            );

            if ($availableOnly && ( ! $row['has_access'] || ! $row['active'] )) {
                continue;
            }

            $rows[] = $row;
        }

        return $this->success(
            'GTmetrix test locations.',
            array('locations' => $rows, 'count' => count($rows))
        );
    }
}
