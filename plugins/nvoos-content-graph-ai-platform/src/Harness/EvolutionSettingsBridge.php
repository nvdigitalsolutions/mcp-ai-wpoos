<?php
/**
 * Evolution Settings Bridge — surfaces the self-evolution opt-in switches
 * from the Orchestration settings page onto the existing evolution filters.
 *
 * The Artifact Evolution subsystem (proposal 007) is opt-in everywhere and
 * default off: its runtime switches are plain filters consulted by the
 * Continual Harness evolver, the evolved-prompt resolver, the Skill Registry,
 * and the evolution governor. This bridge lets site administrators flip the
 * same switches from Settings → Orchestration Layer instead of writing code:
 *
 *   - A setting only overrides a filter when it has been saved; an unsaved
 *     setting leaves the filter's code-level default untouched (always off
 *     for the enable switches).
 *   - Hooks are registered at priority 5 so developer filters at priority
 *     10+ can still override the saved UI value.
 *   - The bridge is loaded from `harness-init.php` (all requests), not from
 *     the admin-only settings bootstrap, because the evolution filters are
 *     consulted during chat/tool execution on REST and frontend requests.
 *
 * @package WP_MCP_AI
 * @subpackage WP_MCP_AI/includes/harness
 * @since   1.9.0
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Harness;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Prevent parse errors on PHP < 7.4 by exiting before class definition.
if ( version_compare( PHP_VERSION, '7.4.0', '<' ) ) {
	return;
}

if ( ! class_exists( __NAMESPACE__ . '\\EvolutionSettingsBridge' ) ) {
	/**
	 * Settings-to-filter bridge for the Artifact Evolution opt-in switches.
	 *
	 * @since 1.9.0
	 */
	class EvolutionSettingsBridge {

		/**
		 * Register the settings-to-filter hooks. Idempotent and re-entrant.
		 *
		 * Priority 5 keeps the UI value below developer filters (priority 10+),
		 * which remain the authoritative override mechanism. `has_filter()`
		 * guards make the method safe to call repeatedly (e.g., from tests
		 * after another suite removed the hooks via remove_all_filters()).
		 *
		 * @since 1.9.0
		 *
		 * @return void
		 */
		public static function register() {
			if ( ! has_filter( 'wp_mcp_ai_harness_evolution_enabled', array( __CLASS__, 'filter_evolution_enabled' ) ) ) {
				add_filter( 'wp_mcp_ai_harness_evolution_enabled', array( __CLASS__, 'filter_evolution_enabled' ), 5, 1 );
			}
			if ( ! has_filter( 'wp_mcp_ai_harness_use_evolved_prompt', array( __CLASS__, 'filter_use_evolved_prompt' ) ) ) {
				add_filter( 'wp_mcp_ai_harness_use_evolved_prompt', array( __CLASS__, 'filter_use_evolved_prompt' ), 5, 3 );
			}
			if ( ! has_filter( 'wp_mcp_ai_skill_registry_include_evolved', array( __CLASS__, 'filter_include_evolved_skills' ) ) ) {
				add_filter( 'wp_mcp_ai_skill_registry_include_evolved', array( __CLASS__, 'filter_include_evolved_skills' ), 5, 1 );
			}
			if ( ! has_filter( 'wp_mcp_ai_harness_evolution_budget_usd', array( __CLASS__, 'filter_budget_usd' ) ) ) {
				add_filter( 'wp_mcp_ai_harness_evolution_budget_usd', array( __CLASS__, 'filter_budget_usd' ), 5, 2 );
			}
			if ( ! has_filter( 'wp_mcp_ai_evolution_governor_rate_limit', array( __CLASS__, 'filter_rate_limit' ) ) ) {
				add_filter( 'wp_mcp_ai_evolution_governor_rate_limit', array( __CLASS__, 'filter_rate_limit' ), 5, 3 );
			}
			if ( ! has_filter( 'wp_mcp_ai_harness_evolution_warmup', array( __CLASS__, 'filter_warmup' ) ) ) {
				add_filter( 'wp_mcp_ai_harness_evolution_warmup', array( __CLASS__, 'filter_warmup' ), 5, 1 );
			}
			if ( ! has_filter( 'wp_mcp_ai_harness_verification_enabled', array( __CLASS__, 'filter_verification_enabled' ) ) ) {
				add_filter( 'wp_mcp_ai_harness_verification_enabled', array( __CLASS__, 'filter_verification_enabled' ), 5, 3 );
			}
		}

		/**
		 * Master switch: allow the Continual Harness evolver to mutate
		 * prompts, roles, skills, and memory.
		 *
		 * @since 1.9.0
		 *
		 * @param bool $enabled Code-level default (false).
		 * @return bool
		 */
		public static function filter_evolution_enabled( $enabled ) {
			return self::resolve_bool( 'enable_harness_evolution', $enabled );
		}

		/**
		 * Opt-in consumption of the evolved system prompt at chat time.
		 *
		 * @since 1.9.0
		 *
		 * @param bool  $use_evolved Code-level default (false).
		 * @param int   $assistant_id Assistant post ID.
		 * @param array $context      Resolution context.
		 * @return bool
		 */
		public static function filter_use_evolved_prompt( $use_evolved, $assistant_id, $context ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Required by filter signature.
			return self::resolve_bool( 'use_evolved_system_prompt', $use_evolved );
		}

		/**
		 * Opt-in merge of agent-evolved skills into the Skill Registry index.
		 *
		 * @since 1.9.0
		 *
		 * @param bool $include_evolved Code-level default (false).
		 * @return bool
		 */
		public static function filter_include_evolved_skills( $include_evolved ) {
			return self::resolve_bool( 'include_evolved_skills', $include_evolved );
		}

		/**
		 * Hourly per-assistant evolution budget in USD.
		 *
		 * @since 1.9.0
		 *
		 * @param float $limit        Code-level default (5.0).
		 * @param int   $assistant_id Assistant post ID.
		 * @return float
		 */
		public static function filter_budget_usd( $limit, $assistant_id = 0 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Required by filter signature.
			return self::resolve_float( 'harness_evolution_budget_usd', $limit );
		}

		/**
		 * Hourly mutation limit per assistant and path.
		 *
		 * @since 1.9.0
		 *
		 * @param int    $limit        Code-level default (60).
		 * @param int    $assistant_id Assistant post ID.
		 * @param string $path         Mutation path.
		 * @return int
		 */
		public static function filter_rate_limit( $limit, $assistant_id, $path ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Required by filter signature.
			return self::resolve_int( 'evolution_governor_rate_limit', $limit );
		}

		/**
		 * Minimum warmup iterations before the first evolution attempt.
		 *
		 * @since 1.9.0
		 *
		 * @param int $warmup Code-level default (5).
		 * @return int
		 */
		public static function filter_warmup( $warmup ) {
			return self::resolve_int( 'harness_evolution_warmup', $warmup );
		}

		/**
		 * Opt-in post-mutation verification of evolved prompts.
		 *
		 * @since 1.9.0
		 *
		 * @param bool   $enabled      Code-level default (false).
		 * @param int    $assistant_id Assistant post ID.
		 * @param string $component    Component being evolved.
		 * @return bool
		 */
		public static function filter_verification_enabled( $enabled, $assistant_id, $component ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Required by filter signature.
			return self::resolve_bool( 'harness_verification_enabled', $enabled );
		}

		/**
		 * Read a saved setting key.
		 *
		 * Uses the canonical settings registry when available; otherwise falls
		 * back to the raw option (registry class is loaded from the global
		 * bootstrap loader, so this path is defensive only).
		 *
		 * @since 1.9.0
		 *
		 * @param string $key Setting key.
		 * @return mixed|null Stored value, or null when never saved.
		 */
		private static function get_setting( $key ) {
			if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Settings_Registry' ) ) {
				return \WP_MCP_AI_Settings_Registry::get_setting( $key, null );
			}

			$option_name = defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Admin_Settings' ) ? \WP_MCP_AI_Admin_Settings::OPTION_NAME : 'wp_mcp_ai_settings';
			$settings    = get_option( $option_name, array() );
			if ( ! is_array( $settings ) ) {
				return null;
			}

			return isset( $settings[ $key ] ) ? $settings[ $key ] : null;
		}

		/**
		 * Resolve a boolean setting, falling back when never saved.
		 *
		 * @since 1.9.0
		 *
		 * @param string $key      Setting key.
		 * @param bool   $fallback Filter default value.
		 * @return bool
		 */
		private static function resolve_bool( $key, $fallback ) {
			$value = self::get_setting( $key );
			if ( null === $value || '' === $value ) {
				return (bool) $fallback;
			}

			return (bool) $value;
		}

		/**
		 * Resolve an integer setting, falling back when never saved.
		 *
		 * @since 1.9.0
		 *
		 * @param string $key      Setting key.
		 * @param int    $fallback Filter default value.
		 * @return int
		 */
		private static function resolve_int( $key, $fallback ) {
			$value = self::get_setting( $key );
			if ( null === $value || '' === $value ) {
				return (int) $fallback;
			}

			return (int) $value;
		}

		/**
		 * Resolve a float setting, falling back when never saved.
		 *
		 * @since 1.9.0
		 *
		 * @param string $key      Setting key.
		 * @param float  $fallback Filter default value.
		 * @return float
		 */
		private static function resolve_float( $key, $fallback ) {
			$value = self::get_setting( $key );
			if ( null === $value || '' === $value ) {
				return (float) $fallback;
			}

			return (float) $value;
		}
	}
}
