<?php

/**
 * GTmetrix test parameters that cost money, and the guards around them.
 *
 * Proposal 164 section 164.92. Two things must be decided before a test is
 * started and neither belongs in a single tool:
 *
 * - **What it costs.** The API charges per report type, per retention window and
 *   for video, and the local `Scheduler::COSTS` in WordPress matched the
 *   published table exactly. Keeping the table here means `start_test` and any
 *   later sweep cannot disagree about the price of the same test.
 * - **What may be tested.** WordPress required the target host to be in an
 *   allowlist that defaulted to the site's own host, which stops an account
 *   being used to probe arbitrary third-party pages. The runtime has no
 *   `home_url()`, so the site host comes from `RuntimeIdentityInterface` when
 *   bound; with no host and no allowlist every URL is refused (fail closed).
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\GTmetrix;

use Nvoos\Core\Domain\Contract\RuntimeIdentityInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;

final class GtmetrixConfig
{
    /** Settings key: the daily credit budget for this site. */
    public const BUDGET_KEY = 'gtmetrix_credit_budget_daily';

    /** Settings key: extra hostnames this site may test beyond its own. */
    public const HOSTS_KEY = 'gtmetrix_allowed_test_hosts';

    public const DEFAULT_BUDGET = 25.0;

    /** Report type → credits, from the published v2.0 table. */
    public const REPORT_COSTS = array(
        'lighthouse'        => 1.0,
        'legacy'            => 0.7,
        'lighthouse,legacy' => 1.1,
        'none'              => 0.6,
    );

    /** Retention in months → extra credits. */
    public const RETENTION_COSTS = array(
        1  => 0.0,
        6  => 0.4,
        12 => 0.9,
        24 => 1.8,
    );

    public const VIDEO_COST = 0.9;

    /**
     * The credits one test configuration costs.
     */
    public static function cost(string $report, int $retention, bool $video): float
    {
        $cost = self::REPORT_COSTS[ $report ] ?? self::REPORT_COSTS['lighthouse'];
        $cost += self::RETENTION_COSTS[ $retention ] ?? 0.0;

        if ($video) {
            $cost += self::VIDEO_COST;
        }

        return round($cost, 2);
    }

    /**
     * The site's daily budget, in credits.
     */
    public static function budget(SettingsStoreInterface $settings): float
    {
        $value = $settings->get(self::BUDGET_KEY, self::DEFAULT_BUDGET);

        return is_numeric($value) && (float) $value >= 0 ? (float) $value : self::DEFAULT_BUDGET;
    }

    /**
     * The hostnames a test may target.
     *
     * The configured list plus the deployment's own host, lowercased.
     *
     * @return list<string>
     */
    public static function allowedHosts(SettingsStoreInterface $settings, ?RuntimeIdentityInterface $identity = null): array
    {
        $configured = $settings->get(self::HOSTS_KEY, array());
        $hosts      = array();

        if (is_array($configured)) {
            foreach ($configured as $host) {
                if (is_scalar($host) && '' !== trim((string) $host)) {
                    $hosts[] = strtolower(trim((string) $host));
                }
            }
        }

        if (null !== $identity) {
            $info = $identity->identity();
            $site = is_array($info) && isset($info['site_url']) && is_string($info['site_url'])
                ? trim($info['site_url'])
                : '';
            $host = '' !== $site ? parse_url($site, PHP_URL_HOST) : null;

            if (is_string($host) && '' !== $host) {
                $hosts[] = strtolower($host);
            }
        }

        return array_values(array_unique($hosts));
    }

    /**
     * Whether a URL targets one of the allowed hosts.
     *
     * @param list<string> $hosts Allowed hostnames.
     */
    public static function hostAllowed(string $url, array $hosts): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || '' === $host) {
            return false;
        }

        return in_array(strtolower($host), $hosts, true);
    }
}
