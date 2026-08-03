<?php
/**
 * Destructive Operations Gate — Pre-execution confirmation hook.
 *
 * When the admin setting `require_confirm_destructive_ops` is enabled,
 * this gate intercepts all tool executions and requires an explicit
 * `confirm_destructive=true` parameter for tools flagged as destructive,
 * state-changing, irreversible, or carrying write capability flags.
 *
 * Without confirmation, the tool is short-circuited with a preview of
 * what it would affect instead of executing.
 *
 * @package WP_MCP_AI
 * @since   1.2.0
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license  GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Destructive_Ops_Gate' ) ) {
	/**
	 * Enforces destructive operation confirmation.
	 *
	 * Hooks into `wp_mcp_ai_before_tool_execution` at priority 0 (before
	 * the capability boundary) so it runs for every tool execution regardless
	 * of whether a capability boundary is active.
	 */
	class WP_MCP_AI_Destructive_Ops_Gate {

		/**
		 * Register the gate hook.
		 *
		 * @since 1.2.0
		 * @return void
		 */
		public static function register() {
			add_action( 'wp_mcp_ai_before_tool_execution', array( __CLASS__, 'on_before_tool_execution' ), 0, 4 );
		}

		/**
		 * Check whether a destructive tool requires confirmation.
		 *
		 * @since 1.2.0
		 *
		 * @param string                        $tool_slug Tool identifier.
		 * @param array                         $arguments Sanitised tool arguments.
		 * @param array                         $context   Execution context.
		 * @param WP_MCP_AI_Tool_Interface|null $tool Tool instance.
		 * @return void
		 *
		 * @throws WP_MCP_AI_Destructive_Confirmation_Required When a destructive
		 *         tool is invoked without confirmation. Callers (tool executors)
		 *         must catch this and convert it via to_wp_error() so the
		 *         rejection flows through the normal REST error pipeline.
		 */
		public static function on_before_tool_execution( $tool_slug, $arguments, $context, $tool = null ) {
			// Check if the admin setting is enabled.
			if ( ! self::is_enabled() ) {
				return;
			}

			// Resolve the tool instance if not provided.
			if ( null === $tool ) {
				$tool = self::get_tool_instance( $tool_slug );
			}

			if ( null === $tool ) {
				return;
			}

			// Determine if the tool is destructive.
			if ( ! self::is_tool_destructive( $tool ) ) {
				return;
			}

			// Check if confirmation was explicitly provided.
			if ( self::is_confirmed( $arguments ) ) {
				return;
			}

			// Short-circuit: reject with a preview instead of executing.
			self::reject_unconfirmed( $tool_slug, $arguments, $tool );
		}

		/**
		 * Check if the destructive ops confirmation setting is enabled.
		 *
		 * @since 1.2.0
		 * @return bool
		 */
		private static function is_enabled() {
			$settings = function_exists( 'wp_mcp_ai_get_settings_repository' )
				? wp_mcp_ai_get_settings_repository()
				: null;

			if ( null === $settings ) {
				return true; // Default: enabled (fail-safe).
			}

			return (bool) $settings->get( 'require_confirm_destructive_ops', true );
		}

		/**
		 * Determine if a tool carries destructive capability flags.
		 *
		 * @since 1.2.0
		 *
		 * @param WP_MCP_AI_Tool_Interface $tool Tool instance.
		 * @return bool
		 */
		private static function is_tool_destructive( $tool ) {
			$flags = array();

			if ( $tool instanceof WP_MCP_AI_Tool_Capability_Flags_Interface ) {
				$flags = (array) $tool->get_capability_flags();
			}

			$destructive_flags = array(
				'destructive',
				'data-destruction',
				'irreversible',
				'state-changing',
				'write',
				'financial-impact',
				'access-control-change',
				'mass-email',
			);

			/**
			 * Filter: customize which capability flags trigger the confirmation gate.
			 *
			 * @since 1.2.0
			 *
			 * @param array $destructive_flags Flags that require confirmation.
			 * @param array $flags             Actual flags on the tool.
			 */
			$destructive_flags = apply_filters( 'wp_mcp_ai_destructive_confirmation_flags', $destructive_flags, $flags );

			foreach ( $destructive_flags as $flag ) {
				if ( in_array( $flag, $flags, true ) ) {
					return true;
				}
			}

			return false;
		}

		/**
		 * Check if the destructive operation was explicitly confirmed.
		 *
		 * @since 1.2.0
		 *
		 * @param array $arguments Tool arguments.
		 * @return bool
		 */
		private static function is_confirmed( $arguments ) {
			return ! empty( $arguments['confirm_destructive'] )
				&& (
					true === $arguments['confirm_destructive']
					|| 'true' === $arguments['confirm_destructive']
					|| 1 === $arguments['confirm_destructive']
					|| '1' === $arguments['confirm_destructive']
					|| 'yes' === strtolower( (string) $arguments['confirm_destructive'] )
				);
		}

		/**
		 * Reject an unconfirmed destructive tool call with a preview.
		 *
		 * @since 1.2.0
		 *
		 * @param string                   $tool_slug Tool identifier.
		 * @param array                    $arguments Tool arguments.
		 * @param WP_MCP_AI_Tool_Interface $tool   Tool instance.
		 * @return void
		 *
		 * @throws WP_MCP_AI_Destructive_Confirmation_Required Always, so the
		 *         executor can convert the rejection into a WP_Error envelope.
		 */
		private static function reject_unconfirmed( $tool_slug, $arguments, $tool ) {
			$flags       = array();
			$tool_name   = method_exists( $tool, 'get_name' ) ? $tool->get_name() : $tool_slug;
			$description = method_exists( $tool, 'get_description' ) ? $tool->get_description() : '';

			if ( $tool instanceof WP_MCP_AI_Tool_Capability_Flags_Interface ) {
				$flags = (array) $tool->get_capability_flags();
			}

			$message = sprintf(
				/* translators: %s: tool name */
				__( '"%s" is a destructive operation that requires explicit confirmation.', 'mcp-ai-wpoos' ),
				$tool_name
			);

			$payload = array(
				'tool_slug' => $tool_slug,
				'tool_name' => $tool_name,
				'flags'     => $flags,
				'preview'   => array(
					'message'      => $description,
					'arguments'    => $arguments,
					'confirmation' => array(
						'required_parameter' => 'confirm_destructive',
						'instructions'       => __( 'To proceed, call this tool again with the parameter "confirm_destructive" set to true.', 'mcp-ai-wpoos' ),
					),
				),
			);

			// Log the denial before throwing.
			if ( class_exists( 'WP_MCP_AI_Security_Audit_Logger' ) ) {
				WP_MCP_AI_Security_Audit_Logger::log_event(
					WP_MCP_AI_Security_Audit_Logger::EVENT_DESTRUCTIVE_OP_DENIED,
					get_current_user_id(),
					array( 'tool_slug' => $tool_slug )
				);
			}

			/**
			 * Filter: observe the gate rejection without catching the exception.
			 *
			 * Primarily intended for tests and integrations that want to assert
			 * the gate fired without intercepting exceptions.
			 *
			 * @since 1.1.44
			 *
			 * @param string $tool_slug Rejected tool identifier.
			 * @param array  $payload   Preview/confirmation payload.
			 */
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Tool slug, payload, and message are constructor parameters, not direct output.
			do_action( 'wp_mcp_ai_destructive_gate_rejected', $tool_slug, $payload );

			throw new WP_MCP_AI_Destructive_Confirmation_Required( $tool_slug, $payload, $message );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		/**
		 * Get a tool instance by slug.
		 *
		 * @since 1.2.0
		 *
		 * @param string $tool_slug Tool identifier.
		 * @return WP_MCP_AI_Tool_Interface|null
		 */
		private static function get_tool_instance( $tool_slug ) {
			if ( ! function_exists( 'wp_mcp_ai_container' ) ) {
				return null;
			}

			$container = wp_mcp_ai_container();
			if ( ! $container || ! method_exists( $container, 'get' ) ) {
				return null;
			}

			try {
				$registry = $container->get( 'tool.registry' );
				if ( ! $registry instanceof WP_MCP_AI_Tool_Registry ) {
					return null;
				}

				return $registry->get_tool( $tool_slug );
			} catch ( Exception $e ) {
				return null;
			}
		}
	}
}
