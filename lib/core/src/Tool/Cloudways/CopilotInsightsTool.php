<?php

/**
 * List the account's Copilot insights — `GET /copilot/insights`.
 *
 * Port of `WP_MCP_AI_Tool_Cloudways_Copilot_Insights_List` (Proposal 164 section
 * 164.141). Six fields per insight.
 *
 * ## One correction
 *
 * The WordPress schema advertises optional `server_id` and `app_id` filters, and
 * its `execute()` reads neither: the request is always the unfiltered endpoint.
 * A caller who passed `server_id` therefore received every insight on the
 * account with no indication that the filter was ignored. The port forwards them
 * as query parameters, so the documented filter either narrows the result or the
 * API rejects the parameter by name — both of which are visible, unlike silence.
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

class CopilotInsightsTool extends CloudwaysTool
{
    public function __construct(ErrorFactoryInterface $e, CloudwaysClientInterface $client)
    {
        parent::__construct($e, $client);
    }

    public function getSlug(): string
    {
        return 'cloudways_copilot_insights_list';
    }

    public function getName(): string
    {
        return 'Cloudways: List Copilot Insights';
    }

    public function getDescription(): string
    {
        return 'Lists the account\'s infrastructure insights with severity and category, optionally scoped to a server or app. Read-only.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'server_id' => array(
                    'type'        => 'integer',
                    'description' => 'Scope to one server (optional).',
                    'minimum'     => 1,
                ),
                'app_id'    => array(
                    'type'        => 'integer',
                    'description' => 'Scope to one application (optional).',
                    'minimum'     => 1,
                ),
            ),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'manage_options');

        $query = array();

        foreach (array('server_id', 'app_id') as $key) {
            $value = max(0, $this->intParam($arguments, $key));

            if ($value > 0) {
                $query[ $key ] = $value;
            }
        }

        try {
            $result = $this->client->get('/copilot/insights', $query);
        } catch (CloudwaysApiException $e) {
            return $this->failure($e);
        }

        if (! is_array($result['insights'] ?? null)) {
            return $this->malformed('insights');
        }

        $insights = array();

        foreach ($result['insights'] as $insight) {
            if (! is_array($insight)) {
                continue;
            }

            $insights[] = array(
                'id'          => $this->text($insight['id'] ?? null),
                'title'       => $this->text($insight['title'] ?? null),
                'description' => $this->text($insight['description'] ?? null),
                'severity'    => $this->text($insight['severity'] ?? null),
                'category'    => $this->text($insight['category'] ?? null),
                'timestamp'   => $this->text($insight['timestamp'] ?? null),
            );
        }

        return $this->success(
            sprintf('Found %d %s.', count($insights), 1 === count($insights) ? 'insight' : 'insights'),
            array('insights' => $insights)
        );
    }
}
