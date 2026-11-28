<?php

/**
 * Known WordPress vulnerabilities for one application — `GET /app/{server}/{app}/vulnerabilities`.
 *
 * Port of `WP_MCP_AI_Tool_Cloudways_App_Vulnerabilities_List` (Proposal 164
 * section 164.142).
 *
 * ## Three fields, as WordPress projected them
 *
 * The API's rows carry more than this — scores, affected and fixed versions,
 * disclosure dates, recommendations — and WordPress kept `id`, `title` and
 * `severity`. The port keeps the same three so a caller that already reads this
 * tool sees the same row shape. Widening the projection is a separate decision
 * for a later section, not a silent improvement here.
 *
 * ## Missing keys are empty, not warnings
 *
 * WordPress read `$vuln['id']` directly. A row that omits a key is a PHP
 * warning there and a zero or an empty string here, which is what the caller
 * would have received anyway. A non-array row is skipped rather than fatal.
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

class AppVulnerabilitiesTool extends CloudwaysTool
{
    public function __construct(ErrorFactoryInterface $e, CloudwaysClientInterface $client)
    {
        parent::__construct($e, $client);
    }

    public function getSlug(): string
    {
        return 'cloudways_app_vulnerabilities_list';
    }

    public function getName(): string
    {
        return 'Cloudways: List App Vulnerabilities';
    }

    public function getDescription(): string
    {
        return 'Lists known WordPress vulnerabilities for an application: id, title and severity. Read-only.';
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
                '/app/' . $pair['server_id'] . '/' . $pair['app_id'] . '/vulnerabilities'
            );
        } catch (CloudwaysApiException $e) {
            return $this->failure($e);
        }

        $rows = $result['vulnerabilities'] ?? null;

        if (! is_array($rows)) {
            return $this->malformed('vulnerabilities');
        }

        $vulnerabilities = array();

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $vulnerabilities[] = array(
                'id'       => $this->number($row['id'] ?? 0),
                'title'    => $this->text($row['title'] ?? null),
                'severity' => $this->text($row['severity'] ?? null),
            );
        }

        $count = count($vulnerabilities);

        return $this->success(
            sprintf('Found %d %s.', $count, 1 === $count ? 'vulnerability' : 'vulnerabilities'),
            array('vulnerabilities' => $vulnerabilities)
        );
    }
}
