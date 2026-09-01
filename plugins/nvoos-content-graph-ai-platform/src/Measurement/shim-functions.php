<?php
/**
 * Measurement subsystem shim — standalone-mode bootstrap.
 *
 * Ported 1:1 from the base plugin's
 * `includes/measurement/class-wp-mcp-ai-measurement-bootstrap.php`
 * (extraction Wave C). The global function names, hook names, priorities,
 * and capability slugs are byte-identical to the base copy so monolith
 * consumers observe zero change; only the classes the functions reference
 * are the ported platform-namespace copies.
 *
 * This file intentionally declares NO namespace so the global functions
 * keep their exact base-plugin names. `MeasurementService::register()`
 * requires it in standalone mode only — in monolith mode the base plugin's
 * own bootstrap file owns this wiring and this file is never loaded.
 *
 * @package NvoosContentGraphAiPlatform
 * @since 2.0.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'wp_mcp_ai_measurement_bootstrap' ) ) {
	/**
	 * Initialize the measurement subsystem.
	 *
	 * Safe to call multiple times — each registry's boot() is idempotent.
	 *
	 * @return void
	 */
	function wp_mcp_ai_measurement_bootstrap() {
		$registry = \NvoosContentGraphAiPlatform\Measurement\MeasurementRegistry::get_instance();
		$registry->boot();

		$verifiers = \NvoosContentGraphAiPlatform\Measurement\VerifierRegistry::get_instance();
		$verifiers->boot();

		$rewards = \NvoosContentGraphAiPlatform\Measurement\RewardFunctionRegistry::get_instance();
		$rewards->boot();

		// Boot the budget registry next so it can attach its collector listener
		// before any metric is recorded during this request. The budget
		// registry is ported (src/Measurement/BudgetRegistry.php), so it boots
		// unconditionally in both matrices.
		\NvoosContentGraphAiPlatform\Measurement\BudgetRegistry::get_instance()->boot();

		// Boot the eval suite registry last — by the time this fires, all
		// verifiers and rewards are registered so suite authors can reference
		// them without ordering headaches. The eval suite is NOT ported; it
		// stays a base-plugin reference and degrades to a no-op in standalone
		// mode.
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Eval_Suite_Registry' ) ) {
			\WP_MCP_AI_Eval_Suite_Registry::get_instance()->boot();
		}

		// Prime the collector singleton so listeners attach early.
		\NvoosContentGraphAiPlatform\Measurement\MetricCollector::get_instance();

		// Attach the tool-execution observer. Kept after the collector is
		// primed so the observer's first `record()` call hits a ready
		// collector. Attach is idempotent and filterable via
		// `wp_mcp_ai_tool_execution_observer_enabled`. NOT ported — base
		// reference, degrades to a no-op in standalone mode.
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Tool_Execution_Observer' ) ) {
			\WP_MCP_AI_Tool_Execution_Observer::get_instance()->attach();
		}

		// Attach the chat-turn observer. Same ordering guarantee as the
		// tool-execution observer — collector must be primed first.
		// Filterable via `wp_mcp_ai_chat_turn_observer_enabled`.
		\NvoosContentGraphAiPlatform\Measurement\ChatTurnObserver::get_instance()->attach();

		// Attach the SSE stream observer. Collector must be primed first.
		// Filterable via `wp_mcp_ai_sse_observer_enabled`. NOT ported — base
		// reference, degrades to a no-op in standalone mode.
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_SSE_Observer' ) ) {
			\WP_MCP_AI_SSE_Observer::get_instance()->attach();
		}

		// Attach the session-log observer (Proposal 029 Phase 5.8, telemetry
		// single-path). The observer is OFF by default — enable via the
		// wp_mcp_ai_session_log_observer_enabled filter after the session log
		// itself is promoted.
		\NvoosContentGraphAiPlatform\Measurement\SessionLogObserver::get_instance()->attach();

		// Ensure the metric-events table exists. `install()` is idempotent
		// and short-circuits when the schema version matches — cheap enough
		// to run on every request. This guards against the case where the
		// activation hook didn't run (e.g. upgrade via `wp plugin update`).
		\NvoosContentGraphAiPlatform\Measurement\MetricEventStore::get_instance()->install();

		// Attach the metric persister. Appends to a per-request buffer on
		// `wp_mcp_ai_metric_recorded` and flushes once on `shutdown` via a
		// single batched INSERT. Filterable via `wp_mcp_ai_persister_enabled`.
		\NvoosContentGraphAiPlatform\Measurement\MetricPersister::get_instance()->attach();

		// Wire the retention cron callback. Scheduling itself happens on
		// activation (and as a belt-and-braces check on `init`); this only
		// ensures the callback is bound when the hook fires.
		\NvoosContentGraphAiPlatform\Measurement\MetricRetention::register_cron_callback();
	}
}

if ( ! function_exists( 'wp_mcp_ai_register_reference_verifiers' ) ) {
	/**
	 * Register the reference verifiers (rule, schema, LLM-judge).
	 *
	 * Attached to `wp_mcp_ai_register_verifiers` at priority 20 so third-party
	 * verifiers (priority 10) can pre-empt the default instances by slug.
	 *
	 * Sites may disable any reference verifier via the
	 * `wp_mcp_ai_enable_reference_verifiers` filter — returning an array of
	 * slugs to keep, or an empty array to disable all of them.
	 *
	 * The reference verifier classes live in the base plugin
	 * (includes/measurement/verifiers/) and are NOT ported — registration
	 * degrades to a no-op in standalone mode.
	 *
	 * @param \NvoosContentGraphAiPlatform\Measurement\VerifierRegistry $registry Registry.
	 * @return void
	 */
	function wp_mcp_ai_register_reference_verifiers( $registry ) {
		if ( ! $registry instanceof \NvoosContentGraphAiPlatform\Measurement\VerifierRegistry ) {
			return;
		}
		/**
		 * Filters which reference verifiers get registered.
		 *
		 * @since 1.3.0
		 *
		 * @param array<int,string> $slugs Slugs to register.
		 */
		$enabled = apply_filters(
			'wp_mcp_ai_enable_reference_verifiers',
			array( 'rule_verifier', 'schema_verifier', 'llm_judge' )
		);
		if ( ! is_array( $enabled ) ) {
			return;
		}

		if ( in_array( 'rule_verifier', $enabled, true ) && null === $registry->get( 'rule_verifier' ) ) {
			if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Rule_Verifier' ) ) {
				$registry->register( new \WP_MCP_AI_Rule_Verifier( 'rule_verifier' ) );
			}
		}
		if ( in_array( 'schema_verifier', $enabled, true ) && null === $registry->get( 'schema_verifier' ) ) {
			if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Schema_Verifier' ) ) {
				$registry->register( new \WP_MCP_AI_Schema_Verifier( 'schema_verifier' ) );
			}
		}
		if ( in_array( 'llm_judge', $enabled, true ) && null === $registry->get( 'llm_judge' ) ) {
			// No judge callable by default — the verifier abstains until one is
			// supplied via `wp_mcp_ai_llm_judge_callable`.
			if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_LLM_Judge_Verifier' ) ) {
				$registry->register( new \WP_MCP_AI_LLM_Judge_Verifier( 'llm_judge' ) );
			}
		}
	}
}

if ( ! function_exists( 'wp_mcp_ai_register_reference_rewards' ) ) {
	/**
	 * Register the reference reward functions.
	 *
	 * The reference reward registrar lives in the base plugin
	 * (includes/measurement/rewards/) and is NOT ported — registration
	 * degrades to a no-op in standalone mode.
	 *
	 * @param \NvoosContentGraphAiPlatform\Measurement\RewardFunctionRegistry $registry Registry.
	 * @return void
	 */
	function wp_mcp_ai_register_reference_rewards( $registry ) {
		if ( ! $registry instanceof \NvoosContentGraphAiPlatform\Measurement\RewardFunctionRegistry ) {
			return;
		}
		/**
		 * Filters whether reference reward functions get registered.
		 *
		 * @since 1.3.0
		 *
		 * @param bool $enabled Whether to register defaults. Default true.
		 */
		$enabled = (bool) apply_filters( 'wp_mcp_ai_enable_reference_rewards', true );
		if ( ! $enabled ) {
			return;
		}
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Reference_Rewards' ) ) {
			\WP_MCP_AI_Reference_Rewards::register( $registry );
		}
	}
}

if ( ! function_exists( 'wp_mcp_ai_measurement_ensure_capabilities' ) ) {
	/**
	 * Lightweight capability bootstrap — grants the `manage_wp_mcp_ai_measurements`
	 * and `view_wp_mcp_ai_measurements` capabilities to administrators unless the
	 * site has explicitly filtered the default role map. Called on first admin
	 * request; does nothing if the capabilities are already present.
	 *
	 * @return void
	 */
	function wp_mcp_ai_measurement_ensure_capabilities() {
		if ( ! function_exists( 'get_role' ) ) {
			return;
		}
		$role = get_role( 'administrator' );
		if ( null === $role ) {
			return;
		}

		/**
		 * Filters the measurement capabilities added to administrators.
		 *
		 * Site owners can remove capabilities to split measurement roles among
		 * different users. Removing a capability here does not grant it back
		 * automatically — use `user_has_cap` or a membership plugin to delegate.
		 *
		 * @since 1.3.0
		 *
		 * @param array<int,string> $caps Capability slugs.
		 */
		$caps = apply_filters(
			'wp_mcp_ai_measurement_admin_capabilities',
			// phpcs:ignore WordPress.WP.Capabilities.Unknown -- Plugin-issued custom capabilities, not core WP caps.
			array( 'manage_wp_mcp_ai_measurements', 'view_wp_mcp_ai_measurements' )
		);

		if ( ! is_array( $caps ) ) {
			return;
		}
		foreach ( $caps as $cap ) {
			if ( is_string( $cap ) && '' !== $cap && ! $role->has_cap( $cap ) ) {
				// phpcs:ignore WordPress.WP.Capabilities.Unknown -- Plugin-issued custom capability, not a core WP cap.
				$role->add_cap( $cap );
			}
		}
	}
}

// Runtime wiring lives in MeasurementService::register()'s standalone
// branch (mirroring the base bootstrap file's lifecycle hooks). Keeping the
// wiring there — instead of at file scope — means the service re-registers
// the hooks on every register() call, which stays idempotent in production
// (add_action dedupes by tuple) and robust under test-framework hook-global
// resets. The function surface above is loaded by the same service via
// require_once before the hooks are added.
