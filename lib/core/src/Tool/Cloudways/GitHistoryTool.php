<?php

/**
 * An application's deployment history — `GET /app/{server}/{app}/git/history`.
 *
 * Port of `WP_MCP_AI_Tool_Cloudways_Git_History_Get` (Proposal 164 section
 * 164.140). Four fields per entry, all strings.
 *
 * **The WordPress tool declared itself a write.** Its capability flags were
 * `write`, `state-changing`, `reversible`, although the only call it makes is a
 * GET. This runtime derives the read-only hint from the tool's own behaviour in
 * the readiness row, so the port is annotated as the read it is.
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

class GitHistoryTool extends CloudwaysTool
{
    public function __construct(ErrorFactoryInterface $e, CloudwaysClientInterface $client)
    {
        parent::__construct($e, $client);
    }

    public function getSlug(): string
    {
        return 'cloudways_git_history_get';
    }

    public function getName(): string
    {
        return 'Cloudways: Get Git History';
    }

    public function getDescription(): string
    {
        return 'Lists an application\'s deployment history with commit, message and timestamp. Read-only.';
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
                '/app/' . $pair['server_id'] . '/' . $pair['app_id'] . '/git/history'
            );
        } catch (CloudwaysApiException $e) {
            return $this->failure($e);
        }

        if (! is_array($result['history'] ?? null)) {
            return $this->malformed('history');
        }

        $history = array();

        foreach ($result['history'] as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $history[] = array(
                'id'        => $this->text($entry['id'] ?? null),
                'commit'    => $this->text($entry['commit'] ?? null),
                'message'   => $this->text($entry['message'] ?? null),
                'timestamp' => $this->text($entry['timestamp'] ?? null),
            );
        }

        return $this->success(
            sprintf('Found %d deployment history %s.', count($history), 1 === count($history) ? 'entry' : 'entries'),
            array('history' => $history)
        );
    }
}
