<?php

/**
 * Add a domain to Cloudflare — `POST /app/{server}/{app}/cloudflare/domain`.
 *
 * Port of `WP_MCP_AI_Tool_Cloudways_Cloudflare_Add_Domain` (Proposal 164
 * section 164.141). The domain is the only argument beyond the target.
 *
 * ## Two corrections
 *
 * - **The domain is validated as a host.** `sanitize_text_field()` accepts a
 *   pasted URL or a path, and WordPress forwarded either. A value containing
 *   whitespace, a scheme separator or a path separator is now named in a
 *   validation error.
 * - **The message is not HTML-escaped.** WordPress passed the domain through
 *   `esc_html()`, which corrupts the value inside a JSON envelope.
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

class CloudflareAddDomainTool extends CloudwaysTool
{
    public function __construct(ErrorFactoryInterface $e, CloudwaysClientInterface $client)
    {
        parent::__construct($e, $client);
    }

    public function getSlug(): string
    {
        return 'cloudways_cloudflare_add_domain';
    }

    public function getName(): string
    {
        return 'Cloudways: Add Cloudflare Domain';
    }

    public function getDescription(): string
    {
        return 'Adds a domain to an application\'s Cloudflare CDN. The domain\'s DNS must already be verifiable.';
    }

    public function getParametersSchema(): array
    {
        $schema = $this->targetSchema();
        $schema['properties']['domain'] = array(
            'type'        => 'string',
            'description' => 'The domain to add, e.g. example.com.',
            'minLength'   => 1,
        );
        $schema['required'][] = 'domain';

        return $schema;
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'manage_options');

        $pair = $this->appPair($arguments);

        if (! is_array($pair) || ! isset($pair['app_id'])) {
            return $pair;
        }

        $domain = $this->host($arguments['domain'] ?? null);

        if (null === $domain) {
            return $this->errors->validationFailed(
                'A domain name is required.',
                array('domain' => array('Pass the host alone, without a scheme or a path.'))
            );
        }

        try {
            $result = $this->client->post(
                '/app/' . $pair['server_id'] . '/' . $pair['app_id'] . '/cloudflare/domain',
                array('domain' => $domain)
            );
        } catch (CloudwaysApiException $e) {
            return $this->failure($e);
        }

        return $this->success(
            sprintf('Domain "%s" added to Cloudflare CDN.', $domain),
            array(
                'domain' => $domain,
                'result' => $result,
            )
        );
    }
}
