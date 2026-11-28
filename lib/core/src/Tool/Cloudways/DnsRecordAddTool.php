<?php

/**
 * Add a DNS record — `POST /dns/domain/{domain}/record`.
 *
 * Port of `WP_MCP_AI_Tool_Cloudways_DNS_Add_Record` (Proposal 164 section
 * 164.141). Type, name and value are required; the TTL is optional.
 *
 * ## Two corrections
 *
 * - **The domain is validated and encoded.** WordPress interpolated it straight
 *   into the path with no validation and no encoding, so a crafted value could
 *   address a different endpoint. The port reuses the same host check and
 *   `rawurlencode()` as the record reader.
 * - **`ttl` is validated, not `absint`-ed.** `absint( -300 )` is 300, so a
 *   negative TTL was silently sent as a positive one. A provided value that is
 *   not positive is now named.
 *
 * The record type is a closed enum (A, AAAA, CNAME, MX, TXT, NS) because those
 * are the six the WordPress tool validates and the API answers; the wider DNS
 * type list is not this tool's contract.
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

class DnsRecordAddTool extends CloudwaysTool
{
    /** The DNS record types this tool accepts. */
    public const TYPES = array('A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS');

    public function __construct(ErrorFactoryInterface $e, CloudwaysClientInterface $client)
    {
        parent::__construct($e, $client);
    }

    public function getSlug(): string
    {
        return 'cloudways_dns_add_record';
    }

    public function getName(): string
    {
        return 'Cloudways: Add DNS Record';
    }

    public function getDescription(): string
    {
        return 'Adds a DNS record to a managed domain: type, name, value and an optional TTL.';
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
                'type'   => array(
                    'type'        => 'string',
                    'description' => 'The DNS record type.',
                    'enum'        => self::TYPES,
                ),
                'name'   => array(
                    'type'        => 'string',
                    'description' => 'The record name or host.',
                    'minLength'   => 1,
                ),
                'value'  => array(
                    'type'        => 'string',
                    'description' => 'The record value.',
                    'minLength'   => 1,
                ),
                'ttl'    => array(
                    'type'        => 'integer',
                    'description' => 'TTL in seconds (optional).',
                    'minimum'     => 1,
                ),
            ),
            'required'             => array('domain', 'type', 'name', 'value'),
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

        $type = trim($this->stringParam($arguments, 'type'));

        if (! in_array($type, self::TYPES, true)) {
            return $this->errors->validationFailed(
                'A valid record type is required.',
                array('type' => array('One of: ' . implode(', ', self::TYPES) . '.'))
            );
        }

        $name  = trim($this->stringParam($arguments, 'name'));
        $value = trim($this->stringParam($arguments, 'value'));
        $invalid = array();

        if ('' === $name) {
            $invalid['name'] = array('The record name, or @ for the apex.');
        }

        if ('' === $value) {
            $invalid['value'] = array('The record value.');
        }

        $ints = $this->positiveInts($arguments, array('ttl'));

        if (array() !== $ints['invalid']) {
            $invalid['ttl'] = array('A positive number of seconds is required.');
        }

        if (array() !== $invalid) {
            return $this->errors->validationFailed('One or more arguments are unusable.', $invalid);
        }

        $body = array(
            'type'  => $type,
            'name'  => $name,
            'value' => $value,
        );

        if (isset($ints['set']['ttl'])) {
            $body['ttl'] = $ints['set']['ttl'];
        }

        try {
            $result = $this->client->post('/dns/domain/' . rawurlencode($domain) . '/record', $body);
        } catch (CloudwaysApiException $e) {
            return $this->failure($e);
        }

        return $this->success(
            sprintf('%1$s record "%2$s" added to %3$s.', $type, $name, $domain),
            array(
                'record' => $body,
                'result' => $result,
            )
        );
    }
}
