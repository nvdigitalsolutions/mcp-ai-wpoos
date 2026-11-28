<?php

/**
 * The account's projects — `GET /project`.
 *
 * Port of `WP_MCP_AI_Tool_Cloudways_List_Projects` (Proposal 164 section
 * 164.133). One call, two fields per row, no arguments.
 *
 * WordPress cast each row with `absint()` and `sanitize_text_field()` without
 * checking the key existed, so an API row missing `name` produced a warning and
 * an empty string. The port reads tolerantly and drops entries that are not
 * arrays, which keeps a malformed row from becoming an empty project.
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

class ListProjectsTool extends CloudwaysTool
{
    public function __construct(ErrorFactoryInterface $e, CloudwaysClientInterface $client)
    {
        parent::__construct($e, $client);
    }

    public function getSlug(): string
    {
        return 'cloudways_list_projects';
    }

    public function getName(): string
    {
        return 'Cloudways: List Projects';
    }

    public function getDescription(): string
    {
        return 'Lists the Cloudways projects in the account with their ids and names. Read-only.';
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
            $result = $this->client->get('/project');
        } catch (CloudwaysApiException $e) {
            return $this->failure($e);
        }

        if (! is_array($result['projects'] ?? null)) {
            return $this->malformed('projects');
        }

        $projects = array();

        foreach ($result['projects'] as $project) {
            if (! is_array($project)) {
                continue;
            }

            $projects[] = array(
                'id'   => $this->number($project['id'] ?? 0),
                'name' => $this->text($project['name'] ?? null),
            );
        }

        return $this->success(
            sprintf('Found %d %s.', count($projects), 1 === count($projects) ? 'project' : 'projects'),
            array('projects' => $projects)
        );
    }
}
