<?php
/**
 * Read-Only Chat Profile Gate — pre-execution enforcement hook.
 *
 * When the resolved chat profile gates capability flags (the `read-only`
 * built-in), this gate intercepts tool executions on
 * `wp_mcp_ai_before_tool_execution` and blocks any tool carrying a gated
 * flag (write, state-changing, destructive, …) unless the profile explicitly
 * allowlists the tool slug.
 *
 * Precedence follows the Claude Code deny → ask → allow model: this gate is
 * registered BEFORE the Destructive Ops Gate at priority 0, so a blocked
 * write under read-only is denied outright — it never degrades into a
 * confirmation prompt.
 *
 * The gate is advisory-only prompt hints and architectural enforcement: the
 * same profile travels in the execution context to the async executor, which
 * enforces at queue time because the worker does not fire the
 * before-execution hook (pre-existing gap tracked in Phase D).
 *
 * @package WP_MCP_AI
 * @since   2.2.0
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license  GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Read_Only_Profile_Gate' ) ) {
	/**
	 * Enforces restrictive chat profiles at the tool boundary.
	 *
	 * Hooks into `wp_mcp_ai_before_tool_execution` at priority 0, registered
	 * before the destructive-ops gate.
	 */
	class WP_MCP_AI_Read_Only_Profile_Gate {

		/**
		 * Register the gate hook.
		 *
		 * @since 2.2.0
		 * @return void
		 */
		public static function register() {
			add_action( 'wp_mcp_ai_before_tool_execution', array( __CLASS__, 'on_before_tool_execution' ), 0, 4 );
		}

		/**
		 * Pre-execution gate: block gated tools under a restrictive profile.
		 *
		 * @since 2.2.0
		 *
		 * @param string                        $tool_slug Tool identifier.
		 * @param array                         $arguments Sanitised tool arguments.
		 * @param array                         $context   Execution context.
		 * @param WP_MCP_AI_Tool_Interface|null $tool      Tool instance.
		 * @return void
		 *
		 * @throws WP_MCP_AI_Chat_Profile_Blocked When the tool is blocked.
		 */
		public static function on_before_tool_execution( $tool_slug, $arguments, $context, $tool = null ) {
			unset( $arguments );

			$profile_slug = self::resolve_profile_slug( $context );
			if ( '' === $profile_slug ) {
				return; // Feature disabled or no restrictive profile — allow.
			}

			$profile = WP_MCP_AI_Chat_Profile_Registry::get_profile( $profile_slug );
			if ( null === $profile || ! $profile->is_restrictive() ) {
				return;
			}

			if ( null === $tool ) {
				$tool = self::get_tool_instance( $tool_slug );
			}
			if ( null === $tool ) {
				return;
			}

			if ( ! self::is_tool_allowed( $tool, $profile ) ) {
				self::reject_blocked( $tool_slug, $tool, $profile );
			}
		}

		/**
		 * Resolve the profile slug governing this execution.
		 *
		 * Prefers the slug the REST handlers placed in the context (already
		 * resolved server-side); falls back to resolving from the context
		 * user or the current user so other emitters (OOS bridge, tests)
		 * remain covered.
		 *
		 * @since 2.2.0
		 *
		 * @param array $context Execution context.
		 * @return string Empty string when no restrictive profile applies.
		 */
		private static function resolve_profile_slug( $context ) {
			if ( ! class_exists( 'WP_MCP_AI_Chat_Profile_Manager' ) || ! WP_MCP_AI_Chat_Profile_Manager::is_enabled() ) {
				return '';
			}

			$slug = isset( $context['chat_profile'] ) ? (string) $context['chat_profile'] : '';
			if ( '' !== $slug ) {
				return $slug;
			}

			$user_id = isset( $context['user_id'] ) ? (int) $context['user_id'] : get_current_user_id();
			return WP_MCP_AI_Chat_Profile_Manager::resolve_slug( $user_id, null );
		}

		/**
		 * Whether a tool may execute under the given profile.
		 *
		 * Public static so the async executor can enforce at queue time.
		 *
		 * @since 2.2.0
		 *
		 * @param WP_MCP_AI_Tool_Interface $tool    Tool instance.
		 * @param WP_MCP_AI_Chat_Profile   $profile Governing profile.
		 * @return bool
		 */
		public static function is_tool_allowed( $tool, $profile ) {
			$flags = array();
			if ( $tool instanceof WP_MCP_AI_Tool_Capability_Flags_Interface ) {
				$flags = (array) $tool->get_capability_flags();
			}

			return ! $profile->blocks_tool( (string) $tool->get_slug(), $flags );
		}

		/**
		 * Reject a blocked tool with a model-actionable envelope.
		 *
		 * @since 2.2.0
		 *
		 * @param string                   $tool_slug Tool identifier.
		 * @param WP_MCP_AI_Tool_Interface $tool      Tool instance.
		 * @param WP_MCP_AI_Chat_Profile   $profile   Governing profile.
		 * @return void
		 *
		 * @throws WP_MCP_AI_Chat_Profile_Blocked Always.
		 */
		private static function reject_blocked( $tool_slug, $tool, $profile ) {
			$flags     = array();
			$tool_name = method_exists( $tool, 'get_name' ) ? $tool->get_name() : $tool_slug;

			if ( $tool instanceof WP_MCP_AI_Tool_Capability_Flags_Interface ) {
				$flags = (array) $tool->get_capability_flags();
			}

			$message = sprintf(
				/* translators: 1: tool name, 2: profile label */
				__( '"%1$s" is blocked: the current chat profile is "%2$s". Answer using read-only tools only.', 'mcp-ai-wpoos' ),
				$tool_name,
				$profile->get_label()
			);

			$payload = array(
				'tool_slug' => $tool_slug,
				'tool_name' => $tool_name,
				'flags'     => $flags,
				'profile'   => $profile->get_slug(),
			);

			if ( class_exists( 'WP_MCP_AI_Security_Audit_Logger' ) ) {
				WP_MCP_AI_Security_Audit_Logger::log_event(
					WP_MCP_AI_Security_Audit_Logger::EVENT_CHAT_PROFILE_BLOCKED,
					get_current_user_id(),
					array(
						'tool_slug' => $tool_slug,
						'profile'   => $profile->get_slug(),
					)
				);
			}

			/**
			 * Filter: observe a profile-gate rejection without catching it.
			 *
			 * @since 2.2.0
			 *
			 * @param string $tool_slug Rejected tool identifier.
			 * @param array  $payload   Rejection payload.
			 */
			do_action( 'wp_mcp_ai_chat_profile_gate_rejected', $tool_slug, $payload );

			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The rejection is caught by the executor and surfaced as a structured tool result, never rendered as HTML.
			throw new WP_MCP_AI_Chat_Profile_Blocked( $tool_slug, $payload, $message );
		}

		/**
		 * Get a tool instance by slug.
		 *
		 * @since 2.2.0
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

// phpcs:disable Generic.Files.OneObjectStructurePerFile -- Companion subscriber.

if ( ! class_exists( 'WP_MCP_AI_Chat_Profile_Prompt_Hint' ) ) {
	/**
	 * Advisory system-prompt hint for restrictive profiles.
	 *
	 * The gate is the enforcement (§4.3 of proposal 015); this hint tells the
	 * model about the boundary so it prefers read-only tools and does not
	 * retry blocked writes in a loop. Never authoritative on its own.
	 *
	 * @since 2.2.0
	 */
	class WP_MCP_AI_Chat_Profile_Prompt_Hint {

		/**
		 * Register the hint filter.
		 *
		 * @since 2.2.0
		 * @return void
		 */
		public static function register() {
			add_filter( 'wp_mcp_ai_resolved_system_prompt', array( __CLASS__, 'append_hint' ), 20, 3 );
		}

		/**
		 * Append the profile mode hint when a restrictive profile governs.
		 *
		 * @since 2.2.0
		 *
		 * @param string $system_prompt Resolved system prompt.
		 * @param int    $assistant_id  Assistant post ID.
		 * @param array  $context       Surface context (contains `request`).
		 * @return string
		 */
		public static function append_hint( $system_prompt, $assistant_id, $context ) {
			unset( $assistant_id, $context );

			if ( ! class_exists( 'WP_MCP_AI_Chat_Profile_Manager' ) || ! WP_MCP_AI_Chat_Profile_Manager::is_enabled() ) {
				return $system_prompt;
			}

			$user_id = get_current_user_id();

			$profile = WP_MCP_AI_Chat_Profile_Manager::resolve( $user_id, null );
			if ( null === $profile || ! $profile->is_restrictive() ) {
				return $system_prompt;
			}

			$hint = sprintf(
				/* translators: %s: chat profile label (e.g. "Read-only") */
				__( "\n\nSystem: You are operating in %s mode. Tools that modify data are blocked at the execution layer; do not attempt them. Prefer read-only tools and answer without side effects.", 'mcp-ai-wpoos' ),
				$profile->get_label()
			);

			return (string) $system_prompt . $hint;
		}
	}
}

// phpcs:enable Generic.Files.OneObjectStructurePerFile
