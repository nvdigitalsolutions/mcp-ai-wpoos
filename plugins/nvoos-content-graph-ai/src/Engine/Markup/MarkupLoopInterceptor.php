<?php
/**
 * Markup loop interceptor (Wave E6, sub-cluster 2).
 *
 * Aligned port of the base plugin's `WP_MCP_AI_Markup_Loop_Interceptor`
 * (`includes/markup/`): byte-identical `wp_mcp_ai_pre_execute_tool`
 * filter wiring (priority 10, 4 args), the short-circuit-respecting
 * entry, the markup-aware tool gate, the `markup_enabled` setting +
 * `wp_mcp_ai_markup_enabled` filter toggle (default true), the
 * deterministic `needs_markup()` probe with exception containment, the
 * requester stamping (user ID, arguments, context), the store save with
 * the cap-exceeded envelope surfacing, the
 * `wp_mcp_ai_markup_request_created` action, and the widget-payload
 * short-circuit result.
 *
 * Documented deviations:
 *  - Class name/namespace — the AI addon's PSR-4 tree (decision D4).
 *  - The tool gate checks this package's `MarkupAwareToolInterface` —
 *    the ported interceptor is wired standalone-only (the base owns the
 *    same filter monolith), so it only ever sees ecosystem tools that
 *    implement the ported contract.
 *  - The audit log is monolith-only (`WP_MCP_AI_Logger`), dormant
 *    standalone.
 *  - `Exception` and `WP_Error` are fully qualified.
 *  - Text domain `nvoos-content-graph-ai`.
 *
 * @since 1.1.0
 * @package NvoosContentGraphAi\Engine\Markup
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Engine\Markup;

/**
 * Hooks the markup subsystem into the agentic loop.
 *
 * @since 1.1.0
 */
class MarkupLoopInterceptor {

	/**
	 * Markup store.
	 *
	 * @var MarkupStore
	 */
	private $store;

	/**
	 * Constructor.
	 *
	 * @param MarkupStore|null $store Optional store override.
	 */
	public function __construct( $store = null ) {
		$this->store = $store instanceof MarkupStore ? $store : new MarkupStore();
	}

	/**
	 * Register the filter hook.
	 *
	 * @return void
	 */
	public function register() {
		\add_filter( 'wp_mcp_ai_pre_execute_tool', array( $this, 'maybe_intercept' ), 10, 4 );
	}

	/**
	 * Intercept a tool execution and substitute a markup elicitation if
	 * the tool requests one.
	 *
	 * @param mixed  $result    Default null. Non-null short-circuits.
	 * @param object $tool      Tool being executed.
	 * @param mixed  $arguments Tool arguments.
	 * @param mixed  $context   Execution context.
	 * @return mixed Replacement result or original $result.
	 */
	public function maybe_intercept( $result, $tool, $arguments, $context ) {
		// Already short-circuited by another filter — respect it.
		if ( null !== $result ) {
			return $result;
		}

		if ( ! \is_object( $tool ) ) {
			return $result;
		}

		if ( ! $tool instanceof MarkupAwareToolInterface ) {
			return $result;
		}

		// Subsystem can be globally disabled in settings.
		if ( ! self::is_enabled() ) {
			return $result;
		}

		try {
			$request = $tool->needs_markup( \is_array( $arguments ) ? $arguments : array(), \is_array( $context ) ? $context : array() );
		} catch ( \Exception $e ) {
			return $result;
		}

		if ( ! $request instanceof MarkupRequest ) {
			return $result;
		}

		// Stamp who is asking.
		if ( $request->get_user_id() <= 0 && \function_exists( 'get_current_user_id' ) ) {
			$reflection_args                   = $request->to_array();
			$reflection_args['user_id']        = (int) \get_current_user_id();
			$reflection_args['tool_arguments'] = \is_array( $arguments ) ? $arguments : array();
			$reflection_args['tool_context']   = \is_array( $context ) ? $context : array();
			$rebuilt                           = MarkupRequest::from_array( $reflection_args );
			if ( ! \is_wp_error( $rebuilt ) ) {
				$request = $rebuilt;
			}
		}

		$saved = $this->store->save( $request );
		if ( \is_wp_error( $saved ) ) {
			// Surface the cap-exceeded error so the LLM sees a clear failure.
			return new \WP_Error( 'wp_mcp_ai_error', $saved->get_error_message(), $saved->get_error_code() );
		}

		/**
		 * Fires immediately after a markup request has been persisted.
		 *
		 * @param MarkupRequest $request Persisted request.
		 * @param object        $tool    Originating tool.
		 */
		\do_action( 'wp_mcp_ai_markup_request_created', $request, $tool );

		if ( defined( 'WP_MCP_AI_PATH' ) && \class_exists( 'WP_MCP_AI_Logger' ) ) {
			\WP_MCP_AI_Logger::log_event(
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

		return MarkupElicitation::to_widget_payload( $request );
	}

	/**
	 * Whether the markup subsystem is enabled. Default true.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		$settings = \get_option( 'wp_mcp_ai_settings', array() );
		if ( \is_array( $settings ) && \array_key_exists( 'markup_enabled', $settings ) ) {
			return (bool) $settings['markup_enabled'];
		}
		/**
		 * Filter the default enabled state of the markup subsystem.
		 *
		 * @param bool $enabled Default true.
		 */
		return (bool) \apply_filters( 'wp_mcp_ai_markup_enabled', true );
	}
}
