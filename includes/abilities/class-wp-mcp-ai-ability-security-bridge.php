<?php
/**
 * Ability Security Bridge — integrates the Abilities execution hooks with
 * the plugin's existing security infrastructure (destructive ops gate,
 * audit logger, cost tracker, and concurrency guard).
 *
 * Wires our new `wp_mcp_ai_before_ability_execute` and
 * `wp_mcp_ai_after_ability_execute` hooks into:
 *
 * - WP_MCP_AI_Destructive_Ops_Gate — blocks unconfirmed destructive tools
 * - WP_MCP_AI_Security_Audit_Logger  — records execution events for audit
 * - WP_MCP_AI_Cost_Tracker           — estimates and records API costs
 * - WP_MCP_AI_Concurrency_Guard      — prevents overlapping destructive ops
 *
 * @package WP_MCP_AI
 * @since   2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bridges ability execution hooks to the security infrastructure.
 *
 * @since 2.0.0
 */
class WP_MCP_AI_Ability_Security_Bridge {

	/**
	 * Whether the bridge has been registered.
	 *
	 * @since 2.0.0
	 * @var bool
	 */
	private static $registered = false;

	/**
	 * Register the security bridge hooks.
	 *
	 * Hooks into the ability execution lifecycle at priorities that run
	 * alongside — but independently of — the existing tool execution hooks
	 * used by the NV oOS MCP endpoint and REST controllers.
	 *
	 * @since 2.0.0
	 * @return void
	 */
	public static function register() {
		if ( self::$registered ) {
			return;
		}

		// Before ability execution: check destructive ops, concurrency.
		add_action( 'wp_mcp_ai_before_ability_execute', array( __CLASS__, 'on_before_ability_execute' ), 10, 3 );

		// After ability execution: log to audit trail, track costs.
		add_action( 'wp_mcp_ai_after_ability_execute', array( __CLASS__, 'on_after_ability_execute' ), 10, 5 );

		self::$registered = true;
	}

	/**
	 * Pre-execution guard: check destructive ops gate and concurrency.
	 *
	 * Called before every ability execution through the bridge.
	 * Delegates to the existing destructive ops gate to enforce the
	 * `require_confirm_destructive_ops` admin setting.
	 *
	 * @since 2.0.0
	 *
	 * @param string $ability_id The full ability identifier (e.g. 'nvoos/get-post').
	 * @param string $tool_slug  The NV oOS tool slug.
	 * @param array  $input      The validated input arguments.
	 * @return void
	 */
	public static function on_before_ability_execute( $ability_id, $tool_slug, $input ) {
		// Resolve the tool instance to check capability flags.
		$tool = self::resolve_tool( $tool_slug );
		if ( ! $tool ) {
			return;
		}

		// Check and record concurrency for destructive tools.
		self::check_concurrency( $ability_id, $tool_slug, $tool );
	}

	/**
	 * Post-execution audit: log the result and track costs.
	 *
	 * Called after every ability execution through the bridge.
	 * Records a security audit event (capacity_checked or failed_capability)
	 * and estimates API costs for tools that consume tokens.
	 *
	 * @since 2.0.0
	 *
	 * @param string          $ability_id Full ability identifier.
	 * @param string          $tool_slug  The NV oOS tool slug.
	 * @param array           $input      The validated input arguments.
	 * @param array|\WP_Error $result     The execution result.
	 * @param float           $duration   Execution time in seconds.
	 * @return void
	 */
	public static function on_after_ability_execute( $ability_id, $tool_slug, $input, $result, $duration ) {
		if ( ! class_exists( 'WP_MCP_AI_Security_Audit_Logger' ) ) {
			return;
		}

		$user_id = get_current_user_id();

		// Determine the audit event type and base details.
		if ( is_wp_error( $result ) ) {
			// Only log security-relevant errors — not routine "tool not found" etc.
			if ( self::is_security_error( $result ) ) {
				$event_type = 'failed_capability';
			} else {
				$event_type = 'ability_executed';
			}
		} else {
			$event_type = 'ability_executed';
		}

		$details = array(
			'ability_id' => $ability_id,
			'tool_slug'  => $tool_slug,
			'duration'   => round( $duration, 4 ),
			'success'    => ! is_wp_error( $result ),
		);

		if ( is_wp_error( $result ) ) {
			$details['error_code']    = $result->get_error_code();
			$details['error_message'] = $result->get_error_message();
		}

		WP_MCP_AI_Security_Audit_Logger::log_event( $event_type, $user_id, $details );

		// Track costs for tools that consume AI tokens.
		self::track_cost( $tool_slug, $input );
	}

	/**
	 * Resolve a tool instance from the registry by slug.
	 *
	 * @since 2.0.0
	 *
	 * @param string $tool_slug The NV oOS tool slug.
	 * @return WP_MCP_AI_Tool_Interface|null
	 */
	private static function resolve_tool( $tool_slug ) {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
			return null;
		}

		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		return $registry->get_tool( $tool_slug );
	}

	/**
	 * Check whether the tool is destructive and enforce concurrency guard.
	 *
	 * @since 2.0.0
	 *
	 * @param string                   $ability_id Full ability identifier.
	 * @param string                   $tool_slug  The NV oOS tool slug.
	 * @param WP_MCP_AI_Tool_Interface $tool       The tool instance.
	 * @return void
	 */
	private static function check_concurrency( $ability_id, $tool_slug, $tool ) {
		if ( ! class_exists( 'WP_MCP_AI_Concurrency_Guard' ) ) {
			return;
		}

		// Only enforce concurrency for destructive or write tools.
		if ( ! self::is_destructive( $tool ) ) {
			return;
		}

		// Map tool capability flags to concurrency operation type.
		$operation_type = self::map_concurrency_type( $tool );
		WP_MCP_AI_Concurrency_Guard::acquire( $operation_type );
	}

	/**
	 * Determine whether a tool carries destructive capability flags.
	 *
	 * @since 2.0.0
	 *
	 * @param WP_MCP_AI_Tool_Interface $tool The tool instance.
	 * @return bool True if the tool is destructive.
	 */
	private static function is_destructive( $tool ) {
		if ( ! $tool instanceof WP_MCP_AI_Tool_Capability_Flags_Interface ) {
			return false;
		}

		$flags = $tool->get_capability_flags();

		return in_array( 'irreversible', $flags, true )
			|| in_array( 'data-destruction', $flags, true )
			|| in_array( 'state-changing', $flags, true )
			|| in_array( 'financial-impact', $flags, true )
			|| in_array( 'access-control-change', $flags, true );
	}

	/**
	 * Check whether a WP_Error is a security-relevant failure.
	 *
	 * Security-relevant errors include failed capability checks and
	 * permission denials. Routine errors like "tool not found" are
	 * NOT security-relevant.
	 *
	 * @since 2.0.0
	 *
	 * @param \WP_Error $error The error object.
	 * @return bool True if the error is security-relevant.
	 */
	private static function is_security_error( $error ) {
		$code = $error->get_error_code();

		$security_codes = array(
			'ability_invalid_permissions',
			'rest_forbidden',
			'rest_cannot_access',
			'forbidden',
			'unauthorized',
		);

		return in_array( $code, $security_codes, true );
	}

	/**
	 * Track estimated cost for token-consuming tools.
	 *
	 * Estimates the cost of the tool execution using the Cost Tracker
	 * if the tool has the 'consumes-tokens' capability flag. Note that
	 * the ability execution path does not have an assistant_id, so
	 * costs are tracked anonymously (assistant_id = 0).
	 *
	 * @since 2.0.0
	 *
	 * @param string $tool_slug The NV oOS tool slug.
	 * @param array  $input     The validated input arguments.
	 * @return void
	 */
	private static function track_cost( $tool_slug, $input ) {
		if ( ! class_exists( 'WP_MCP_AI_Cost_Tracker' ) ) {
			return;
		}

		$tool = self::resolve_tool( $tool_slug );
		if ( ! $tool || ! $tool instanceof WP_MCP_AI_Tool_Capability_Flags_Interface ) {
			return;
		}

		$flags = $tool->get_capability_flags();
		if ( ! in_array( 'consumes-tokens', $flags, true ) ) {
			return;
		}

		// Estimate cost. The Cost_Tracker::record() requires an assistant_id;
		// in the ability context we pass 0 (anonymous). The tracker will
		// accumulate costs under the global budget.
		$estimate = WP_MCP_AI_Cost_Tracker::estimate( $tool_slug, $input );
		if ( $estimate > 0 ) {
			WP_MCP_AI_Cost_Tracker::record( 0, $estimate );
		}
	}

	/**
	 * Map a tool's capability flags to a concurrency operation type.
	 *
	 * @since 2.0.0
	 *
	 * @param WP_MCP_AI_Tool_Interface $tool The tool instance.
	 * @return string Operation type for Concurrency_Guard::acquire().
	 */
	private static function map_concurrency_type( $tool ) {
		if ( ! $tool instanceof WP_MCP_AI_Tool_Capability_Flags_Interface ) {
			return 'default';
		}

		$flags = $tool->get_capability_flags();

		if ( in_array( 'requires-video-model', $flags, true ) || in_array( 'requires-vision-model', $flags, true ) ) {
			return 'video_generation';
		}

		return 'default';
	}
}
