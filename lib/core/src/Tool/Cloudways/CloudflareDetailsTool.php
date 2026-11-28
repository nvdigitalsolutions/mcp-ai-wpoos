<?php

/**
 * An application's Cloudflare state — `GET /app/{server}/{app}/cloudflare`.
 *
 * Port of `WP_MCP_AI_Tool_Cloudways_Cloudflare_Details` (Proposal 164 section
 * 164.141). Three fields: status, enabled and whether DNS is configured.
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

class CloudflareDetailsTool extends CloudwaysTool
{
    public function __construct(ErrorFactoryInterface $e, CloudwaysClientInterface $client)
    {
        parent::__construct($e, $client);
    }

    public function getSlug(): string
    {
        return 'cloudways_cloudflare_details';
    }

    public function getName(): string
    {
        return 'Cloudways: Cloudflare Details';
    }

    public function getDescription(): string
    {
        return 'Returns an application\'s Cloudflare CDN status and whether its DNS is configured. Read-only.';
    }

    public function getParametersSchema(): array
    {
        return $this->targetSchema();
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'manage_options');

        $pair = $this->appPair($arguments);

        if (! is_array($pair) || ! isset($pair['app_id'])) {
            return $pair;
        }

        try {
            $result = $this->client->get(
                '/app/' . $pair['server_id'] . '/' . $pair['app_id'] . '/cloudflare'
            );
        } catch (CloudwaysApiException $e) {
            return $this->failure($e);
        }

        if (! is_array($result['cloudflare'] ?? null)) {
            return $this->malformed('cloudflare');
        }

        $cf = $result['cloudflare'];

        return $this->success(
            'Cloudflare CDN details retrieved.',
            array(
                'cloudflare' => array(
                    'status'         => $this->text($cf['status'] ?? null),
                    'enabled'        => ! empty($cf['enabled']),
                    'dns_configured' => ! empty($cf['dns_configured']),
                ),
            )
        );
    }
}
