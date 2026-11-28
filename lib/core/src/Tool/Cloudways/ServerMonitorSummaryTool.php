<?php

/**
 * One server's monitoring summary — `GET /server/{id}/monitor/summary`.
 *
 * Port of `WP_MCP_AI_Tool_Cloudways_Server_Monitor_Summary` (Proposal 164
 * section 164.135). Bandwidth and disk, both banded strings in the API's own
 * units.
 *
 * Not an `AppAnalyticsTool`: the path is server-scoped and there is no app id to
 * validate. The two-fields-plus-`values` shape is the same, so a caller reads
 * both summaries the same way.
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

class ServerMonitorSummaryTool extends CloudwaysTool
{
    public function __construct(ErrorFactoryInterface $e, CloudwaysClientInterface $client)
    {
        parent::__construct($e, $client);
    }

    public function getSlug(): string
    {
        return 'cloudways_server_monitor_summary';
    }

    public function getName(): string
    {
        return 'Cloudways: Server Monitoring Summary';
    }

    public function getDescription(): string
    {
        return 'Returns a server\'s bandwidth and disk summary. Read-only.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'server_id' => array(
                    'type'        => 'integer',
                    'description' => 'The Cloudways server id.',
                    'minimum'     => 1,
                ),
            ),
            'required'             => array('server_id'),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'manage_options');

        $serverId = $this->serverId($arguments);

        if ($serverId <= 0) {
            return $this->errors->validationFailed(
                'A valid server id is required.',
                array('server_id' => array('The id shown by cloudways_list_servers.'))
            );
        }

        try {
            $result = $this->client->get('/server/' . $serverId . '/monitor/summary');
        } catch (CloudwaysApiException $e) {
            return $this->failure($e);
        }

        if (! is_array($result['summary'] ?? null)) {
            return $this->malformed('summary');
        }

        $summary = $result['summary'];
        $project = array(
            'bandwidth' => $this->text($summary['bandwidth'] ?? null),
            'disk'      => $this->text($summary['disk'] ?? null),
        );
        $values  = $project;

        foreach ($summary as $key => $value) {
            if (is_string($key) && is_scalar($value)) {
                $values[ $key ] = $value;
            }
        }

        return $this->success(
            sprintf('Monitoring summary retrieved for server %d.', $serverId),
            array(
                'summary' => $project,
                'values'  => $values,
            )
        );
    }
}
