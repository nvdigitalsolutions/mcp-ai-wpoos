<?php
/**
 * Hooks the markup subsystem into the agentic loop.
 *
 * The interceptor listens to the new `wp_mcp_ai_pre_execute_tool` filter
 * fired right before a registered tool's `execute()` method runs. If the
 * tool implements `WP_MCP_AI_Markup_Aware_Tool_Interface` and returns a
 * `WP_MCP_AI_Markup_Request` from `needs_markup()`, the interceptor:
 *
 *  1. Persists the request in the markup store.
 *  2. Returns a structured `markup_elicitation` payload so the agentic
 *     loop short-circuits this iteration without calling `execute()`.
 *  3. Fires the `wp_mcp_ai_markup_request_created` action.
 *
 * The agentic loop streams the resulting payload as a tool result. The
 * chat client recognises the `type: 'markup_elicitation'` discriminator
 * and renders the canvas widget. When the user submits, the REST
 * controller invokes `consume_markup()` and returns the real result.
 *
 * @package WP_MCP_AI
 * @since   1.3.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_MCP_AI_Markup_Loop_Interceptor
 *
 * @since 1.3.0
 */
class WP_MCP_AI_Markup_Loop_Interceptor {

	/**
	 * Markup store.
	 *
	 * @var WP_MCP_AI_Markup_Store
	 */
	private $store;

	/**
	 * Constructor.
	 *
	 * @param WP_MCP_AI_Markup_Store|null $store Optional store override.
	 */
	public function __construct( $store = null ) {
		$this->store = $store instanceof WP_MCP_AI_Markup_Store ? $store : new WP_MCP_AI_Markup_Store();
	}

	/**
	 * Register the filter hook.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'wp_mcp_ai_pre_execute_tool', array( $this, 'maybe_intercept' ), 10, 4 );
	}

	/**
	 * Intercept a tool execution and substitute a markup elicitation if
	 * the tool requests one.
	 *
	 * @param mixed                    $result    Default null. Non-null short-circuits.
	 * @param WP_MCP_AI_Tool_Interface $tool      Tool being executed.
	 * @param array                    $arguments Tool arguments.
	 * @param array                    $context   Execution context.
	 * @return mixed Replacement result or original $result.
	 */
	public function maybe_intercept( $result, $tool, $arguments, $context ) {
		// Already short-circuited by another filter — respect it.
		if ( null !== $result ) {
			return $result;
		}

		if ( ! is_object( $tool ) ) {
			return $result;
		}

		if ( ! $tool instanceof WP_MCP_AI_Markup_Aware_Tool_Interface ) {
			return $result;
		}

		// Subsystem can be globally disabled in settings.
		if ( ! self::is_enabled() ) {
			return $result;
		}

		try {
			$request = $tool->needs_markup( is_array( $arguments ) ? $arguments : array(), is_array( $context ) ? $context : array() );
		} catch ( Exception $e ) {
			return $result;
		}

		if ( ! $request instanceof WP_MCP_AI_Markup_Request ) {
			return $result;
		}

		// Stamp who is asking.
		if ( $request->get_user_id() <= 0 && function_exists( 'get_current_user_id' ) ) {
			$reflection_args                   = $request->to_array();
			$reflection_args['user_id']        = (int) get_current_user_id();
			$reflection_args['tool_arguments'] = is_array( $arguments ) ? $arguments : array();
			$reflection_args['tool_context']   = is_array( $context ) ? $context : array();
			$rebuilt                           = WP_MCP_AI_Markup_Request::from_array( $reflection_args );
			if ( ! is_wp_error( $rebuilt ) ) {
				$request = $rebuilt;
			}
		}

		$saved = $this->store->save( $request );
		if ( is_wp_error( $saved ) ) {
			// Surface the cap-exceeded error so the LLM sees a clear failure.
			return new WP_Error( 'wp_mcp_ai_error', $saved->get_error_message(), $saved->get_error_code() );
		}

		/**
		 * Fires immediately after a markup request has been persisted.
		 *
		 * @param WP_MCP_AI_Markup_Request $request Persisted request.
		 * @param WP_MCP_AI_Tool_Interface $tool    Originating tool.
		 */
		do_action( 'wp_mcp_ai_markup_request_created', $request, $tool );

		if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
			WP_MCP_AI_Logger::log_event(
				'info',
				'markup_request_created',
				array(
					'request_id'  => $request->get_request_id(),
					'tool'        => $request->get_tool_slug(),
					'mode'        => $request->get_mode(),
					'target_type' => $request->get_target_type(),
					'assistant'   => $request->get_assistant_id(),
				)
			);
		}

		return WP_MCP_AI_Markup_Elicitation::to_widget_payload( $request );
	}

	/**
	 * Whether the markup subsystem is enabled. Default true.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( is_array( $settings ) && array_key_exists( 'markup_enabled', $settings ) ) {
			return (bool) $settings['markup_enabled'];
		}
		/**
		 * Filter the default enabled state of the markup subsystem.
		 *
		 * @param bool $enabled Default true.
		 */
		return (bool) apply_filters( 'wp_mcp_ai_markup_enabled', true );
	}
}
