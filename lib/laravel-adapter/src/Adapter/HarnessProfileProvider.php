<?php

/**
 * Harness profile provider — settings-backed storage for the LLM-harness profile.
 *
 * WordPress keeps the profile in assistant post meta with a site-wide option as
 * the fallback (`WP_MCP_AI_Harness_Profile`). The Laravel runtime has no
 * per-assistant meta, so it uses the settings store with the same resolution
 * order and the same sanitiser:
 *
 *   1. `harness_profile:<assistant_id>` — the per-assistant override.
 *   2. `harness_profile_default`        — the site-wide default.
 *   3. the canonical all-off profile.
 *
 * @package Nvoos\Laravel
 * @since   1.6.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Laravel\Adapter;

use Nvoos\Core\Domain\Contract\HarnessProfileProviderInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use Nvoos\Core\Domain\Service\Harness\HarnessProfile;

final class HarnessProfileProvider implements HarnessProfileProviderInterface
{
	/** Site-wide fallback, mirroring `wp_mcp_ai_harness_profile_default`. */
	public const DEFAULT_KEY = 'harness_profile_default';

	/** Per-assistant override prefix. */
	public const KEY_PREFIX = 'harness_profile:';

	/**
	 * Profiles are stored as a JSON document (or an array) — the same shapes
	 * WordPress accepts, so a profile copied between runtimes resolves the same.
	 */
	public function __construct(private readonly SettingsStoreInterface $settings)
	{
	}

	public function getProfile(int $assistantId): array
	{
		$raw = $assistantId > 0
			? $this->settings->get(self::KEY_PREFIX . $assistantId, '')
			: '';

		if (empty($raw)) {
			$raw = $this->settings->get(self::DEFAULT_KEY, '');
		}

		return empty($raw) ? HarnessProfile::defaults() : HarnessProfile::sanitize($raw);
	}
}
