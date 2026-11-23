<?php

/**
 * Tool: get_site_health — the host's health report, as an agent can read it.
 *
 * The console has a Site Health page and the WordPress plugin has both a page
 * and this tool; the Laravel runtime only had the page. An agent asked "is
 * anything wrong?" could see nothing, even though the operator one click away
 * could.
 *
 * ## Why the contract, not a host class
 *
 * Health probes are host facts: the Laravel app knows about its database, cache,
 * queue and scheduler; the package host knows only whether its tools registered.
 * `HealthReportInterface` keeps that knowledge where it lives, and this tool
 * aggregates the statuses without reinterpreting them — a probe the host calls
 * `critical` produces a `critical` report.
 *
 * ## Unknown statuses are not "good"
 *
 * The vocabulary is closed (`good`, `recommended`, `critical`). A host that
 * reports something else has a bug, and counting it as `good` would hide it;
 * it is counted as `recommended` and passes through unchanged in the probe list.
 * That is the same rule the capability-flag catalogue states: absence means
 * unknown, not safe.
 *
 * @package Nvoos\Core
 * @since   2.1.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\HealthReportInterface;
use Nvoos\Core\Domain\Contract\ToolCapabilityFlagsInterface;

final class GetSiteHealthTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    public function __construct(
        ErrorFactoryInterface $errors,
        private readonly HealthReportInterface $health,
    ) {
        parent::__construct($errors);
    }

    public function getSlug(): string
    {
        return 'get_site_health';
    }

    public function getName(): string
    {
        return 'Get Site Health Status';
    }

    public function getDescription(): string
    {
        return 'Returns the host\'s health probes and an overall status in the WordPress Site Health vocabulary: good, recommended or critical. Probes report configuration flags and missing services only — never credentials, tokens or user data.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(),
            'additionalProperties' => false,
        );
    }

    public function getRequiredCapability(): string
    {
        return 'edit_posts';
    }

    public function getCapabilityFlags(): array
    {
        return array( 'read-only', 'local-only', 'requires-capability' );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $probes = $this->health->probes();

        $counts = array(
            HealthReportInterface::GOOD        => 0,
            HealthReportInterface::RECOMMENDED => 0,
            HealthReportInterface::CRITICAL    => 0,
        );

        foreach ($probes as $probe) {
            $status = (string) ($probe['status'] ?? '');

            // An unrecognised status is not a pass. Count it where a reader will
            // notice it; the probe itself keeps its original value below.
            if (! isset($counts[ $status ])) {
                $status = HealthReportInterface::RECOMMENDED;
            }

            ++$counts[ $status ];
        }

        if ($counts[ HealthReportInterface::CRITICAL ] > 0) {
            $overall = HealthReportInterface::CRITICAL;
        } elseif ($counts[ HealthReportInterface::RECOMMENDED ] > 0) {
            $overall = HealthReportInterface::RECOMMENDED;
        } else {
            $overall = HealthReportInterface::GOOD;
        }

        return $this->success(
            \sprintf(
                'Site Health: %s (%d critical, %d recommended, %d good).',
                $overall,
                $counts[ HealthReportInterface::CRITICAL ],
                $counts[ HealthReportInterface::RECOMMENDED ],
                $counts[ HealthReportInterface::GOOD ]
            ),
            array(
                'status' => $overall,
                'counts' => $counts,
                'probes' => \array_values($probes),
            )
        );
    }
}
