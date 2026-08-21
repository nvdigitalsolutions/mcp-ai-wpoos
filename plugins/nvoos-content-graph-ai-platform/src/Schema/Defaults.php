<?php
/**
 * Centralized default settings for the NV Platform addon.
 *
 * Every default value for the `ai_platform_settings` option lives here.
 * Sections merge their partial defaults via the `ai_platform/default_settings`
 * filter at registration time.
 *
 * @since 1.0.0
 * @package NvoosContentGraphAiPlatform\Schema
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Schema;

/**
 * Canonical defaults — no magic values scattered across section classes.
 */
final class Defaults {

	/**
	 * Return the full default settings array.
	 *
	 * Addons and sections may extend this via the
	 * `ai_platform/default_settings` filter.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string,mixed>
	 */
	public static function platformSettings(): array {
		$defaults = array(
			// ─── General ─────────────────────────────────────────
			'default_provider'           => 'openai',
			'enable_agent_auto_seed'     => true,
			'max_agents'                 => 50,
			'log_level'                  => 'warning',

			// ─── Federation ──────────────────────────────────────
			'federation_enabled'         => false,
			'federation_sync_freq'       => 'daily',

			// ─── Protocols ───────────────────────────────────────
			'a2a_enabled'                => true,
			'acp_enabled'                => true,

			// ─── Blueprints ──────────────────────────────────────
			'blueprint_auto_apply'       => false,

			// ─── Harness ─────────────────────────────────────────
			'harness_default_timeout'    => 30,

			// ─── Measurement ─────────────────────────────────────
			'measurement_retention_days' => 90,
		);

		/**
		 * Filter the default platform settings.
		 *
		 * Allows subsystem sections to contribute their own defaults.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string,mixed> $defaults Key-value pairs.
		 */
		return apply_filters( 'nvoos_content_graph_ai_platform_default_settings', $defaults );
	}

	/** Private constructor — not instantiable. */
	private function __construct() {}
}
