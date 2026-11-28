<?php

/**
 * List an application's Git branches — `GET /app/{server}/{app}/git/branches`.
 *
 * Port of `WP_MCP_AI_Tool_Cloudways_Git_Branches_Get` (Proposal 164 section
 * 164.140). The API answers branch names as strings, so the projection is a list
 * of strings rather than rows; a non-string entry is dropped instead of becoming
 * an empty branch name.
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

class GitBranchesTool extends CloudwaysTool
{
    public function __construct(ErrorFactoryInterface $e, CloudwaysClientInterface $client)
    {
        parent::__construct($e, $client);
    }

    public function getSlug(): string
    {
        return 'cloudways_git_branches_get';
    }

    public function getName(): string
    {
        return 'Cloudways: List Git Branches';
    }

    public function getDescription(): string
    {
        return 'Lists the Git branches available to an application. Read-only.';
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
                '/app/' . $pair['server_id'] . '/' . $pair['app_id'] . '/git/branches'
            );
        } catch (CloudwaysApiException $e) {
            return $this->failure($e);
        }

        if (! is_array($result['branches'] ?? null)) {
            return $this->malformed('branches');
        }

        $branches = array();

        foreach ($result['branches'] as $branch) {
            if (is_scalar($branch)) {
                $branches[] = (string) $branch;
            }
        }

        return $this->success(
            sprintf('Found %d %s.', count($branches), 1 === count($branches) ? 'branch' : 'branches'),
            array('branches' => $branches)
        );
    }
}
