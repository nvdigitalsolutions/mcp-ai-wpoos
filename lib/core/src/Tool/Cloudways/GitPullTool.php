<?php

/**
 * Pull the deployed branch — `POST /app/{server}/{app}/git/pull`.
 *
 * Port of `WP_MCP_AI_Tool_Cloudways_Git_Pull` (Proposal 164 section 164.140). No
 * body; the API answers an operation.
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

class GitPullTool extends CloudwaysTool
{
    public function __construct(ErrorFactoryInterface $e, CloudwaysClientInterface $client)
    {
        parent::__construct($e, $client);
    }

    public function getSlug(): string
    {
        return 'cloudways_git_pull';
    }

    public function getName(): string
    {
        return 'Cloudways: Git Pull';
    }

    public function getDescription(): string
    {
        return 'Pulls the latest commits for an application\'s deployed branch.';
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
            $result = $this->client->post(
                '/app/' . $pair['server_id'] . '/' . $pair['app_id'] . '/git/pull'
            );
        } catch (CloudwaysApiException $e) {
            return $this->failure($e);
        }

        return $this->success(
            sprintf('Git pull initiated for app %d.', $pair['app_id']),
            array('result' => $result)
        );
    }
}
