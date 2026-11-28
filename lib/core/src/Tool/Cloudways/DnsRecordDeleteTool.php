<?php

/**
 * Delete a DNS record — `DELETE /dns/domain/{domain}/record/{record_id}`.
 *
 * Port of `WP_MCP_AI_Tool_Cloudways_DNS_Delete_Record` (Proposal 164 section
 * 164.143). The second of the family's destructive tools.
 *
 * ## The domain is a path segment, so it is checked like one
 *
 * WordPress interpolated `sanitize_text_field( $domain )` straight into the
 * path. That function removes markup but leaves `/`, `..` and `:` intact, so a
 * caller could address a different endpoint with the account's token. The port
 * validates the value as a hostname and `rawurlencode()`s it, the same rule the
 * DNS readers and `dns_add_record` already follow (§164.141).
 *
 * A wrong record id is how a site goes offline: the record is removed and the
 * name stops resolving until someone recreates it, which is why the description
 * points at `cloudways_dns_list_records` and the confirmation is required.
 *
 * WordPress HTML-escaped the domain in its success message; escaping belongs at
 * the render boundary, so the port returns it raw.
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

class DnsRecordDeleteTool extends CloudwaysTool
{
    public function __construct(ErrorFactoryInterface $e, CloudwaysClientInterface $client)
    {
        parent::__construct($e, $client);
    }

    public function getSlug(): string
    {
        return 'cloudways_dns_delete_record';
    }

    public function getName(): string
    {
        return 'Cloudways: Delete DNS Record';
    }

    public function getDescription(): string
    {
        return 'Deletes one DNS record from a managed domain. Irreversible and able to take a site offline; confirm the id with cloudways_dns_list_records first. Requires confirm: true.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'domain'    => array(
                    'type'        => 'string',
                    'description' => 'The managed domain, e.g. example.com.',
                    'minLength'   => 1,
                ),
                'record_id' => array(
                    'type'        => 'integer',
                    'description' => 'The DNS record id, as listed by cloudways_dns_list_records.',
                    'minimum'     => 1,
                ),
                'confirm'   => array(
                    'type'        => 'boolean',
                    'description' => 'Set to true to confirm this irreversible action.',
                ),
            ),
            'required'             => array('domain', 'record_id', 'confirm'),
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

        $recordId = max(0, $this->intParam($arguments, 'record_id'));

        if ($recordId <= 0) {
            return $this->errors->validationFailed(
                'A valid record id is required.',
                array('record_id' => array('The id shown by cloudways_dns_list_records.'))
            );
        }

        if (! $this->confirmed($arguments)) {
            return $this->errors->create(
                'cloudways_confirm_required',
                'You must set confirm to true to perform this destructive action.'
            );
        }

        try {
            $result = $this->client->delete(
                '/dns/domain/' . rawurlencode($domain) . '/record/' . $recordId
            );
        } catch (CloudwaysApiException $e) {
            return $this->failure($e);
        }

        return $this->success(
            sprintf('DNS record %1$d deleted from %2$s.', $recordId, $domain),
            array('result' => $result)
        );
    }
}
