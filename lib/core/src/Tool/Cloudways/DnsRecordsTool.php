<?php

/**
 * List a domain's DNS records — `GET /dns/domain/{domain}/record`.
 *
 * Port of `WP_MCP_AI_Tool_Cloudways_DNS_List_Records` (Proposal 164 section
 * 164.141). Five fields per record.
 *
 * ## One security correction
 *
 * WordPress interpolated the caller's `domain` straight into the path:
 *
 * ```php
 * $path = '/dns/domain/' . $domain . '/record';
 * ```
 *
 * `sanitize_text_field()` leaves `/` and `..` intact, so a value like
 * `../../server/1` built a request for a different endpoint entirely — a path
 * traversal in a URL the runtime then sent with the account's token. The port
 * validates the value as a hostname and encodes the segment with
 * `rawurlencode()`, so the only thing that can occupy that position is one
 * domain label run.
 *
 * The message also no longer HTML-escapes the domain inside a JSON envelope.
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

class DnsRecordsTool extends CloudwaysTool
{
    public function __construct(ErrorFactoryInterface $e, CloudwaysClientInterface $client)
    {
        parent::__construct($e, $client);
    }

    public function getSlug(): string
    {
        return 'cloudways_dns_list_records';
    }

    public function getName(): string
    {
        return 'Cloudways: List DNS Records';
    }

    public function getDescription(): string
    {
        return 'Lists the DNS records of a managed domain with their id, type, name, value and TTL. Read-only.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'domain' => array(
                    'type'        => 'string',
                    'description' => 'The managed domain, e.g. example.com.',
                    'minLength'   => 1,
                ),
            ),
            'required'             => array('domain'),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'manage_options');

        $domain = $this->host($arguments['domain'] ?? null);

        if (null === $domain) {
            return $this->errors->validationFailed(
                'A domain name is required.',
                array('domain' => array('Pass the host alone, without a scheme or a path.'))
            );
        }

        try {
            $result = $this->client->get('/dns/domain/' . rawurlencode($domain) . '/record');
        } catch (CloudwaysApiException $e) {
            return $this->failure($e);
        }

        if (! is_array($result['records'] ?? null)) {
            return $this->malformed('records');
        }

        $records = array();

        foreach ($result['records'] as $record) {
            if (! is_array($record)) {
                continue;
            }

            $records[] = array(
                'id'    => $this->number($record['id'] ?? 0),
                'type'  => $this->text($record['type'] ?? null),
                'name'  => $this->text($record['name'] ?? null),
                'value' => $this->text($record['value'] ?? null),
                'ttl'   => $this->number($record['ttl'] ?? 0),
            );
        }

        return $this->success(
            sprintf(
                'Found %1$d %2$s for %3$s.',
                count($records),
                1 === count($records) ? 'record' : 'records',
                $domain
            ),
            array('records' => $records)
        );
    }
}
