<?php

/**
 * GTmetrix simulated devices.
 *
 * Port of `WP_MCP_AI_Tool_GTmetrix_List_Simulated_Devices` (Proposal 164 section
 * 164.91). One credit-free `GET /simulated-devices`.
 *
 * **Correction:** WordPress ran `sanitize_key()` over `category` and then silently
 * returned an empty list for anything that did not match, so a typo looked like
 * "no devices on this plan". The port validates the argument against the
 * declared enum and refuses an unknown value.
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
use Nvoos\Core\Support\Text;

class GtmetrixListSimulatedDevicesTool extends GtmetrixTool
{
    public const CATEGORIES = array('phone', 'tablet');

    public function getSlug(): string
    {
        return 'gtmetrix_list_simulated_devices';
    }

    public function getName(): string
    {
        return 'GTmetrix List Simulated Devices';
    }

    public function getDescription(): string
    {
        return 'Lists GTmetrix simulated devices (phones and tablets) with viewport and pixel-ratio details, optionally filtered by category. Requires a GTmetrix PRO plan. Costs no credits.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'category' => array(
                    'type'        => 'string',
                    'enum'        => self::CATEGORIES,
                    'description' => 'Filter by device category.',
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

        $category = array_key_exists('category', $arguments) && is_scalar($arguments['category'])
            ? Text::sanitizeKey((string) $arguments['category'])
            : '';

        if ('' !== $category && ! in_array($category, self::CATEGORIES, true)) {
            return $this->errors->validationFailed(
                'category must be one of: ' . implode(', ', self::CATEGORIES) . '.',
                array('category' => array('Unknown category.'))
            );
        }

        try {
            $response = $this->client->request('GET', 'simulated-devices');
        } catch (GtmetrixApiException $error) {
            return $this->failure($error);
        }

        $rows = array();

        foreach ($this->resources($response['data']) as $resource) {
            $attrs = $this->attrs($resource);
            $type  = $this->text($attrs['category'] ?? '');

            if ('' !== $category && $type !== $category) {
                continue;
            }

            $rows[] = array(
                'id'           => $this->text($resource['id'] ?? ''),
                'name'         => $this->text($attrs['name'] ?? ''),
                'manufacturer' => $this->text($attrs['manufacturer'] ?? ''),
                'category'     => $type,
                'width'        => $this->number($attrs['width'] ?? 0),
                'height'       => $this->number($attrs['height'] ?? 0),
                'dppx'         => is_numeric($attrs['dppx'] ?? null) ? (float) $attrs['dppx'] : 1.0,
            );
        }

        return $this->success(
            'GTmetrix simulated devices.',
            array('devices' => $rows, 'count' => count($rows))
        );
    }
}
