<?php

/**
 * List the available add-ons — `GET /addon`.
 *
 * Port of `WP_MCP_AI_Tool_Cloudways_Addon_List` (Proposal 164 section 164.141).
 * Five fields per row; `id` stays a string because the add-on identifier is a
 * slug (`dnsmadeeasy`, `cloudflare`), not a number.
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

class AddonListTool extends CloudwaysTool
{
    public function __construct(ErrorFactoryInterface $e, CloudwaysClientInterface $client)
    {
        parent::__construct($e, $client);
    }

    public function getSlug(): string
    {
        return 'cloudways_addon_list';
    }

    public function getName(): string
    {
        return 'Cloudways: List Add-ons';
    }

    public function getDescription(): string
    {
        return 'Lists the add-ons available on the account with their identifier, name, status and price. Read-only.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => new \stdClass(),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'manage_options');

        try {
            $result = $this->client->get('/addon');
        } catch (CloudwaysApiException $e) {
            return $this->failure($e);
        }

        if (! is_array($result['addons'] ?? null)) {
            return $this->malformed('addons');
        }

        $addons = array();

        foreach ($result['addons'] as $addon) {
            if (! is_array($addon)) {
                continue;
            }

            $addons[] = array(
                'id'          => $this->text($addon['id'] ?? null),
                'name'        => $this->text($addon['name'] ?? null),
                'description' => $this->text($addon['description'] ?? null),
                'status'      => $this->text($addon['status'] ?? null),
                'price'       => $this->text($addon['price'] ?? null),
            );
        }

        return $this->success(
            sprintf('Found %d %s.', count($addons), 1 === count($addons) ? 'add-on' : 'add-ons'),
            array('addons' => $addons)
        );
    }
}
