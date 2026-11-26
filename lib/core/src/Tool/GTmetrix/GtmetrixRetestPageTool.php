<?php

/**
 * Retest a GTmetrix page with its saved analysis options.
 *
 * Port of `WP_MCP_AI_Tool_GTmetrix_Retest_Page` (Proposal 164 section 164.96).
 * A retest is the regression primitive: the page already carries the URL,
 * browser, location and device combination, so the call is one request and the
 * comparison is a second tool.
 *
 * ## Corrections
 *
 * 1. **The budget is reserved before the call.** `gtmetrix_start_test` used to
 *    check, start and only then record the charge, so two concurrent calls both
 *    passed. The same reservation (section 164.92) now guards the retest, and a
 *    refused call returns the reservation.
 * 2. **A URL resolves through the API, not a local snapshot map.** WordPress
 *    looked the page id up in its snapshot store, which the core does not have
 *    (section 164.92). `filter[url]` is the authoritative lookup, and an exact
 *    URL match is required: no match is `gtmetrix_page_not_found` with the
 *    candidates, more than one is `gtmetrix_page_ambiguous`. Guessing between
 *    two pages would retest the wrong URL.
 *
 * The host allowlist still applies when a URL is supplied: a retest may not use
 * an account to probe a host the site was never allowed to test.
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

class GtmetrixRetestPageTool extends GtmetrixTool
{
    /** How many fuzzy matches the URL resolver inspects. */
    public const LOOKUP_LIMIT = 50;

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
        return 'gtmetrix_retest_page';
    }

    public function getName(): string
    {
        return 'GTmetrix Retest Page';
    }

    public function getDescription(): string
    {
        return 'Retest a GTmetrix page reusing its saved analysis options. Consumes API credits and respects the site daily credit budget. Pass a page id, or a URL that maps to exactly one page.';
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

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'page_id' => array(
                    'type'        => 'string',
                    'description' => 'The GTmetrix page id to retest (see gtmetrix_list_pages).',
                    'minLength'   => 1,
                ),
                'url'     => array(
                    'type'        => 'string',
                    'format'      => 'uri',
                    'description' => 'Retest the page that maps to this exact URL. Refused when no page matches or when several do.',
                ),
            ),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'manage_options');

        $guard = $this->guard();

        if (null !== $guard) {
            return $guard;
        }

        $pageId = $this->identifierArg($arguments, 'page_id');
        $url    = $this->urlArg($arguments);

        if ('' === $pageId && null === $url) {
            return $this->errors->validationFailed(
                'A page id or an http(s) URL is required.',
                array(
                    'page_id' => array('Required when url is absent.'),
                    'url'     => array('Required when page_id is absent.'),
                )
            );
        }

        if (null !== $url) {
            $hosts = GtmetrixConfig::allowedHosts($this->settings, $this->identity);

            if (! GtmetrixConfig::hostAllowed($url, $hosts)) {
                return $this->errors->create(
                    'gtmetrix_host_denied',
                    'That host is not in the GTmetrix test-target allowlist.',
                    array('host' => (string) parse_url($url, PHP_URL_HOST), 'allowed_hosts' => $hosts)
                );
            }
        }

        if ('' === $pageId && null !== $url) {
            $resolved = $this->resolvePageId($url);

            if (is_array($resolved)) {
                return $resolved;
            }

            $pageId = $resolved;
        }

        $cost        = GtmetrixConfig::cost('lighthouse', 1, false);
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
                'pages/' . rawurlencode($pageId) . '/retest'
            );
        } catch (GtmetrixApiException $error) {
            GtmetrixBudget::release($this->cache, $counterKey, $usedCents);

            return $this->failure($error);
        }

        $resource      = is_array($response['data']) ? $response['data'] : array();
        $testId        = $this->text($resource['id'] ?? '');
        $resourceAttrs = $this->attrs($resource);

        if ('' === $testId) {
            GtmetrixBudget::release($this->cache, $counterKey, $usedCents);

            return $this->errors->create(
                'gtmetrix_invalid_response',
                'GTmetrix accepted the retest but returned no test id.'
            );
        }

        $meta = $response['meta'];

        return $this->success(
            'GTmetrix page retest queued.',
            array(
                'page_id'      => $pageId,
                'test_id'      => $testId,
                'state'        => '' !== $this->text($resourceAttrs['state'] ?? '') ? $this->text($resourceAttrs['state']) : 'queued',
                'credits_used' => is_numeric($meta['credits_used'] ?? null) ? (float) $meta['credits_used'] : $cost,
                'credits_left' => is_numeric($meta['credits_left'] ?? null) ? (float) $meta['credits_left'] : null,
                'daily_budget' => array(
                    'limit' => round($reservation['budget_cents'] / 100, 2),
                    'used'  => round(GtmetrixBudget::usedCents($this->cache, $counterKey) / 100, 2),
                ),
                'next'         => 'Poll gtmetrix_get_test_status, then compare with gtmetrix_compare_reports.',
            )
        );
    }

    /**
     * The page id whose stored URL equals this one, or an error envelope.
     *
     * @return string|array<string, mixed>
     */
    private function resolvePageId(string $url): string|array
    {
        try {
            $response = $this->client->request(
                'GET',
                'pages?filter[url]=' . rawurlencode($url) . '&page[size]=' . self::LOOKUP_LIMIT
            );
        } catch (GtmetrixApiException $error) {
            return $this->failure($error);
        }

        $candidates = array();
        $matches    = array();

        foreach ($this->resources($response['data']) as $page) {
            $attrs   = $this->attrs($page);
            $stored  = $this->text($attrs['url'] ?? '');
            $id      = $this->text($page['id'] ?? '');

            if ('' === $id || '' === $stored) {
                continue;
            }

            $candidates[] = array('page_id' => $id, 'url' => $stored);

            if (strtolower($stored) === strtolower($url)) {
                $matches[] = $id;
            }
        }

        if (1 === count($matches)) {
            return $matches[0];
        }

        if (count($matches) > 1) {
            return $this->errors->create(
                'gtmetrix_page_ambiguous',
                'Several GTmetrix pages match that URL; pass page_id instead.',
                array('url' => $url, 'page_ids' => $matches)
            );
        }

        return $this->errors->create(
            'gtmetrix_page_not_found',
            'No GTmetrix page matches that URL; pass page_id instead.',
            array('url' => $url, 'candidates' => array_slice($candidates, 0, 10))
        );
    }
}
