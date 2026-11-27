<?php

/**
 * Start a GTmetrix test.
 *
 * Port of `WP_MCP_AI_Tool_GTmetrix_Start_Test` (Proposal 164 section 164.92).
 *
 * ## Corrections
 *
 * 1. **Credits are reported from the response.** The WordPress client discarded
 *    the JSON:API `meta` block, so `credits_left` was always `null` and
 *    `credits_used` was the local estimate. The client now returns `meta`, and
 *    both figures come from the server.
 * 2. **The budget is reserved before the call.** WordPress checked the balance
 *    and a local counter, started the test, then recorded the charge; two
 *    concurrent calls both passed the check. The port reserves, releases on a
 *    refused call, and compares in hundredths so a fractional cost is not lost
 *    to integer rounding.
 * 3. **Invalid optional values are refused.** WordPress `sanitize_key()`d a
 *    location, browser, device or throttle and silently dropped what did not
 *    survive, so a test ran with different options than the caller asked for.
 * 4. **No poll is scheduled.** WordPress scheduled a WP-Cron poll that
 *    snapshotted the report into a CPT. The port returns `test_id` and the next
 *    step; polling or scheduling is the host's decision.
 *
 * The host allowlist is unchanged in intent: only the site's own host (and any
 * configured extras) may be tested. With no host known, everything is refused.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\GTmetrix;

use Nvoos\Core\Domain\Contract\CacheStoreInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\GtmetrixClientInterface;
use Nvoos\Core\Domain\Contract\RuntimeIdentityInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use Nvoos\Core\Domain\Error\GtmetrixApiException;
use Nvoos\Core\Support\Text;

class GtmetrixStartTestTool extends GtmetrixTool
{
    public function __construct(
        ErrorFactoryInterface $e,
        GtmetrixClientInterface $client,
        private readonly CacheStoreInterface $cache,
        private readonly SettingsStoreInterface $settings,
        private readonly ?RuntimeIdentityInterface $identity = null,
    ) {
        parent::__construct($e, $client);
    }

    public function getSlug(): string
    {
        return 'gtmetrix_start_test';
    }

    public function getName(): string
    {
        return 'GTmetrix Start Test';
    }

    public function getDescription(): string
    {
        return 'Starts a GTmetrix page test and spends API credits. Only hosts in the allowlist (the site itself plus configured extras) may be tested; the cost is checked against the account balance and the site daily budget before the call.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'url'             => array(
                    'type'        => 'string',
                    'format'      => 'uri',
                    'description' => 'The http(s) URL to test. Its host must be in the allowlist.',
                ),
                'report'          => array(
                    'type'        => 'string',
                    'description' => 'Report type. Affects the credit cost.',
                    'enum'        => array('lighthouse', 'legacy', 'lighthouse,legacy', 'none'),
                    'default'     => 'lighthouse',
                ),
                'retention'       => array(
                    'type'        => 'integer',
                    'description' => 'Report retention in months. 6, 12 and 24 cost extra credits.',
                    'enum'        => array(1, 6, 12, 24),
                    'default'     => 1,
                ),
                'location'        => array(
                    'type'        => 'string',
                    'description' => 'GTmetrix location ID (see gtmetrix_list_locations).',
                ),
                'browser'         => array(
                    'type'        => 'string',
                    'description' => 'GTmetrix browser ID (see gtmetrix_list_browsers).',
                ),
                'simulate_device' => array(
                    'type'        => 'string',
                    'description' => 'Simulated device ID (PRO plans; see gtmetrix_list_simulated_devices).',
                ),
                'throttle'        => array(
                    'type'        => 'string',
                    'description' => 'Connection throttle as down/up/latency in Kbps and ms, e.g. 9000/5000/125.',
                ),
                'adblock'         => array(
                    'type'        => 'boolean',
                    'description' => 'Enable AdBlock during the test.',
                    'default'     => false,
                ),
                'video'           => array(
                    'type'        => 'boolean',
                    'description' => 'Record a load video. Costs extra credits.',
                    'default'     => false,
                ),
            ),
            'required'             => array('url'),
            'additionalProperties' => false,
        );
    }

    public function getRequiredCapability(): string
    {
        return 'manage_options';
    }

    /**
     * @return string[]
     */
    public function getCapabilityFlags(): array
    {
        return array(
            'external-api',
            'network-dependent',
            'requires-credentials',
            'state-changing',
            'write',
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'manage_options');

        $guard = $this->guard();

        if (null !== $guard) {
            return $guard;
        }

        $url = $this->urlArg($arguments);

        if (null === $url) {
            return $this->errors->validationFailed(
                'A valid http(s) URL is required.',
                array('url' => array('Not an http(s) URL.'))
            );
        }

        $hosts = GtmetrixConfig::allowedHosts($this->settings, $this->identity);

        if (! GtmetrixConfig::hostAllowed($url, $hosts)) {
            return $this->errors->create(
                'gtmetrix_host_denied',
                'That host is not in the GTmetrix test-target allowlist.',
                array('host' => (string) parse_url($url, PHP_URL_HOST), 'allowed_hosts' => $hosts)
            );
        }

        $report = $this->choice($arguments, 'report', array_keys(GtmetrixConfig::REPORT_COSTS), 'lighthouse');

        if (null === $report) {
            return $this->errors->validationFailed(
                'report must be one of: ' . implode(', ', array_keys(GtmetrixConfig::REPORT_COSTS)) . '.',
                array('report' => array('Unknown report type.'))
            );
        }

        $retention = $this->intParam($arguments, 'retention', 1);

        if (! array_key_exists($retention, GtmetrixConfig::RETENTION_COSTS)) {
            return $this->errors->validationFailed(
                'retention must be one of: ' . implode(', ', array_keys(GtmetrixConfig::RETENTION_COSTS)) . '.',
                array('retention' => array('Unknown retention window.'))
            );
        }

        $video = $this->boolParam($arguments, 'video');

        $params = array(
            'url'       => $url,
            'report'    => $report,
            'retention' => $retention,
        );

        foreach (array('location', 'browser', 'simulate_device') as $key) {
            $value = $this->identifier($arguments, $key);

            if (false === $value) {
                return $this->errors->validationFailed(
                    sprintf('%s may contain only letters, digits, hyphens, underscores and dots.', $key),
                    array($key => array('Invalid identifier.'))
                );
            }

            if ('' !== $value) {
                $params[ $key ] = $value;
            }
        }

        $throttle = $this->throttle($arguments);

        if (null === $throttle) {
            return $this->errors->validationFailed(
                'throttle must be down/up/latency, e.g. 9000/5000/125.',
                array('throttle' => array('Invalid throttle string.'))
            );
        }

        if ('' !== $throttle) {
            $params['throttle'] = $throttle;
        }

        if ($this->boolParam($arguments, 'adblock')) {
            $params['adblock'] = 1;
        }

        if ($video) {
            $params['video'] = 1;
        }

        $cost = GtmetrixConfig::cost($report, $retention, $video);

        try {
            $status = $this->client->request('GET', 'status');
        } catch (GtmetrixApiException $error) {
            return $this->failure($error);
        }

        $attrs   = $this->attrs(is_array($status['data']) ? $status['data'] : array());
        $credits = is_numeric($attrs['api_credits'] ?? null) ? (float) $attrs['api_credits'] : null;

        if (null === $credits || $credits < $cost) {
            return $this->errors->create(
                'gtmetrix_insufficient_credits',
                sprintf(
                    'This test needs %s credits but the GTmetrix account has %s.',
                    $this->credits($cost),
                    null === $credits ? 'an unknown number of' : $this->credits($credits)
                ),
                array('required' => $cost, 'available' => $credits)
            );
        }

        $reservation = GtmetrixBudget::reserve($this->cache, $this->settings, $cost);

        if (! $reservation['ok']) {
            return $this->budgetExhausted(
                $reservation['budget_cents'],
                $reservation['used_cents'],
                $reservation['cost_cents']
            );
        }

        $counterKey = $reservation['key'];
        $usedCents  = $reservation['used_cents'];

        try {
            $response = $this->client->request(
                'POST',
                'tests',
                array('data' => array('type' => 'test', 'attributes' => $params))
            );
        } catch (GtmetrixApiException $error) {
            // The test was not started, so the reservation is returned.
            GtmetrixBudget::release($this->cache, $counterKey, $usedCents);

            return $this->failure($error);
        }

        $resource = is_array($response['data']) ? $response['data'] : array();
        $testId   = $this->text($resource['id'] ?? '');
        $resourceAttrs = $this->attrs($resource);

        if ('' === $testId) {
            GtmetrixBudget::release($this->cache, $counterKey, $usedCents);

            return $this->errors->create(
                'gtmetrix_invalid_response',
                'GTmetrix accepted the test but returned no test id.'
            );
        }

        $meta = $response['meta'];

        return $this->success(
            'GTmetrix test queued.',
            array(
                'test_id'          => $testId,
                'state'            => '' !== $this->text($resourceAttrs['state'] ?? '') ? $this->text($resourceAttrs['state']) : 'queued',
                'url'              => $url,
                'report_type'      => $report,
                'retention_months' => $retention,
                'credits_used'     => is_numeric($meta['credits_used'] ?? null) ? (float) $meta['credits_used'] : $cost,
                'credits_left'     => is_numeric($meta['credits_left'] ?? null) ? (float) $meta['credits_left'] : null,
                'daily_budget'     => array(
                    'limit' => round($reservation['budget_cents'] / 100, 2),
                    'used'  => round(GtmetrixBudget::usedCents($this->cache, $counterKey) / 100, 2),
                ),
                'next'             => 'Poll gtmetrix_get_test_status with this test_id; a completed test answers with its report_id.',
            )
        );
    }

    /**
     * A validated enum value, or null.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     * @param list<string>         $allowed   Allowed values.
     */
    private function choice(array $arguments, string $key, array $allowed, string $default): ?string
    {
        if (! array_key_exists($key, $arguments) || ! is_scalar($arguments[$key]) || '' === $arguments[$key]) {
            return $default;
        }

        $value = Text::sanitizeKey((string) $arguments[$key]);

        // `lighthouse,legacy` keeps a comma the key sanitiser strips; accept the
        // documented spelling directly.
        $raw = trim((string) $arguments[$key]);

        if (in_array($raw, $allowed, true)) {
            return $raw;
        }

        return in_array($value, $allowed, true) ? $value : null;
    }

    /**
     * An optional identifier, '' when absent, or false when unusable.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     */
    private function identifier(array $arguments, string $key): string|false
    {
        if (! array_key_exists($key, $arguments) || ! is_scalar($arguments[$key]) || '' === $arguments[$key]) {
            return '';
        }

        $value = trim((string) $arguments[$key]);

        return 1 === preg_match('/^[A-Za-z0-9_.-]+$/', $value) ? $value : false;
    }

    /**
     * The throttle string, '' when absent, or null when malformed.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     */
    private function throttle(array $arguments): ?string
    {
        if (! array_key_exists('throttle', $arguments) || ! is_scalar($arguments['throttle']) || '' === $arguments['throttle']) {
            return '';
        }

        $value = trim((string) $arguments['throttle']);

        return 1 === preg_match('/^\d+\/\d+\/\d+$/', $value) ? $value : null;
    }
}
