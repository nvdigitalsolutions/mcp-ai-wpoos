<?php

/**
 * List the DNS Made Easy domains — `GET /dns/domain`.
 *
 * Port of `WP_MCP_AI_Tool_Cloudways_DNS_List_Domains` (Proposal 164 section
 * 164.141). Three fields; `id` is an integer here because the API numbers its
 * domains, unlike add-on identifiers.
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

class DnsDomainsTool extends CloudwaysTool
{
    public function __construct(ErrorFactoryInterface $e, CloudwaysClientInterface $client)
    {
        parent::__construct($e, $client);
    }

    public function getSlug(): string
    {
        return 'cloudways_dns_list_domains';
    }

    public function getName(): string
    {
        return 'Cloudways: List DNS Domains';
    }

    public function getDescription(): string
    {
        return 'Lists the domains managed by DNS Made Easy with their id, name and status. Read-only.';
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
            $result = $this->client->get('/dns/domain');
        } catch (CloudwaysApiException $e) {
            return $this->failure($e);
        }

        if (! is_array($result['domains'] ?? null)) {
            return $this->malformed('domains');
        }

        $domains = array();

        foreach ($result['domains'] as $domain) {
            if (! is_array($domain)) {
                continue;
            }

            $domains[] = array(
                'id'     => $this->number($domain['id'] ?? 0),
                'name'   => $this->text($domain['name'] ?? null),
                'status' => $this->text($domain['status'] ?? null),
            );
        }

        return $this->success(
            sprintf('Found %d %s.', count($domains), 1 === count($domains) ? 'domain' : 'domains'),
            array('domains' => $domains)
        );
    }
}
