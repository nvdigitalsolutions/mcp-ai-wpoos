<?php
/**
 * Session lifecycle slash commands — /clear, /reset, /resume.
 *
 * These commands send front-end action signals to the chat UI.
 * No server-side transcript is modified.
 *
 * @package WP_MCP_AI
 * @subpackage Slash_Commands
 * @since   2.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\SlashCommands\Commands;

use NvoosContentGraphAiPlatform\SlashCommands\SlashCommandHandler;
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SlashCommandSession
 *
 * Implements /clear, /reset, and /resume commands.
 *
 * @since 2.1.0
 */
class SlashCommandSession {
	/**
	 * Log an event through the base plugin's logger when present
	 * (monolith mode), falling back to error_log in standalone mode.
	 *
	 * @since 2.0.0
	 *
	 * @param string $level   Log level.
	 * @param string $message Log message.
	 */
	private static function log_event( $level, $message ) {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Logger' ) ) {
			\WP_MCP_AI_Logger::log_event( $level, $message );
			return;
		}
		error_log( sprintf( '[nvoos-content-graph-ai-platform] %s: %s', $level, $message ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Standalone fallback when the base logger is absent.
	}

	/**
	 * Execute the /clear command.
	 *
	 * Returns a client signal to clear the visible chat window.
	 *
	 * @param array $args    Positional arguments (unused).
	 * @param array $flags   Parsed flag map (unused).
	 * @param array $context Execution context.
	 * @return array|WP_Error
	 */
	public function clear( $args, $flags, $context ) {
		// Block guest requests.
		if ( ! empty( $context['guest_request'] ) ) {
			return new \WP_Error( 'guest_forbidden', __( 'This command requires authentication.', 'nvoos-content-graph-ai-platform' ) );
		}

		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! user_can( $user_id, 'read' ) ) {
			return new \WP_Error( 'insufficient_capability', __( 'You do not have permission to use /clear.', 'nvoos-content-graph-ai-platform' ) );
		}

		return array(
			'success' => true,
			'message' => __( 'Chat cleared.', 'nvoos-content-graph-ai-platform' ),
			'action'  => 'clear_chat',
		);
	}

	/**
	 * Execute the /reset command.
	 *
	 * Fires the wp_mcp_ai_session_reset action and returns a client signal.
	 *
	 * @param array $args    Positional arguments (unused).
	 * @param array $flags   Parsed flag map (unused).
	 * @param array $context Execution context.
	 * @return array|WP_Error
	 */
	public function reset( $args, $flags, $context ) {
		// Block guest requests.
		if ( ! empty( $context['guest_request'] ) ) {
			return new \WP_Error( 'guest_forbidden', __( 'This command requires authentication.', 'nvoos-content-graph-ai-platform' ) );
		}

		$user_id      = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		$assistant_id = isset( $context['assistant_id'] ) ? absint( $context['assistant_id'] ) : 0;

		if ( ! user_can( $user_id, 'read' ) ) {
			return new \WP_Error( 'insufficient_capability', __( 'You do not have permission to use /reset.', 'nvoos-content-graph-ai-platform' ) );
		}

		/**
		 * Fires when a session reset is requested.
		 *
		 * @since 2.1.0
		 *
		 * @param int $user_id      ID of the requesting user.
		 * @param int $assistant_id ID of the current assistant (0 if none).
		 */
		do_action( 'wp_mcp_ai_session_reset', $user_id, $assistant_id );

		return array(
			'success' => true,
			'action'  => 'reset_session',
			'message' => __( 'Session reset.', 'nvoos-content-graph-ai-platform' ),
		);
	}

	/**
	 * Execute the /resume command.
	 *
	 * Tells the client to load the most recent saved transcript.
	 *
	 * @param array $args    Positional arguments (unused).
	 * @param array $flags   Parsed flag map (unused).
	 * @param array $context Execution context.
	 * @return array|WP_Error
	 */
	public function resume( $args, $flags, $context ) {
		// Block guest requests.
		if ( ! empty( $context['guest_request'] ) ) {
			return new \WP_Error( 'guest_forbidden', __( 'This command requires authentication.', 'nvoos-content-graph-ai-platform' ) );
		}

		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! user_can( $user_id, 'read' ) ) {
			return new \WP_Error( 'insufficient_capability', __( 'You do not have permission to use /resume.', 'nvoos-content-graph-ai-platform' ) );
		}

		return array(
			'success' => true,
			'action'  => 'resume_session',
			'message' => __( 'Resuming last session...', 'nvoos-content-graph-ai-platform' ),
		);
	}
}
