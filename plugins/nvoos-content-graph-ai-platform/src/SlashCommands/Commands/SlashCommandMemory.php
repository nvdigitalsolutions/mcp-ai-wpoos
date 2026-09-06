<?php
/**
 * Memory slash commands — /remember, /forget, /scope.
 *
 * Phase 4 of the chat-client ⇄ memory integration. Each command maps to a
 * specific operation on the existing agent-memory tool stack:
 *
 *  - /remember <text>          → store_agent_context (verbatim by default)
 *  - /forget   <id|query>      → manage_context_lifecycle (delete by ID; soft
 *                                fallback for query → recall_memory + delete)
 *  - /scope    <wing> [room]   → returns a structured "scope chip" payload the
 *                                chat client can use to update its active scope
 *
 * Each command is a thin orchestration over the proxy tools, so server-side
 * permission checks (capability, kill-switch, audit logging) all run inside
 * the underlying tool's `execute()`.
 *
 * @package WP_MCP_AI
 * @subpackage Slash_Commands
 * @since 1.6.0
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
 * Memory slash commands.
 */
class SlashCommandMemory {
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
	 * Resolve agent_id from context. Falls back to `user_<id>` virtual agent.
	 *
	 * @param array $context Slash command context.
	 * @return int|string
	 */
	protected function resolve_agent_id( $context ) {
		if ( ! empty( $context['assistant_id'] ) ) {
			return absint( $context['assistant_id'] );
		}
		if ( ! empty( $context['agent_id'] ) ) {
			return $context['agent_id'];
		}
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		return $user_id > 0 ? 'user_' . $user_id : 0;
	}

	/**
	 * Ensure the chat-memory surface is available to this user.
	 *
	 * @param array $context Context.
	 * @return true|WP_Error
	 */
	protected function check_enabled( $context ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! user_can( $user_id, 'edit_posts' ) ) {
			return new \WP_Error(
				'insufficient_capability',
				__( 'You do not have permission to use memory commands.', 'nvoos-content-graph-ai-platform' )
			);
		}

		if (
			defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_REST_Chat_Memory_Controller' )
			&& ! \WP_MCP_AI_REST_Chat_Memory_Controller::is_chat_memory_enabled( $user_id )
		) {
			return new \WP_Error(
				'chat_memory_disabled',
				__( 'Long-term memory is disabled for this site or user.', 'nvoos-content-graph-ai-platform' )
			);
		}

		return true;
	}

	/**
	 * /remember <text> — store the supplied text as a verbatim memory.
	 *
	 * Flags:
	 *  --tag=<tag>          Add a tag (repeatable via comma separation).
	 *  --importance=<level> low|medium|high|critical (default: medium).
	 *  --wing=<wing>        Optional wing (project/client) scope.
	 *  --room=<room>        Optional room (topic) scope.
	 *  --summary            Summarise instead of storing verbatim.
	 *
	 * @param array $args    Positional args (joined into the memory text).
	 * @param array $flags   Flags.
	 * @param array $context Context.
	 * @return array|WP_Error
	 */
	public function remember( $args, $flags, $context ) {
		$check = $this->check_enabled( $context );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$text = is_array( $args ) ? trim( implode( ' ', array_map( 'strval', $args ) ) ) : '';
		if ( '' === $text ) {
			return new \WP_Error( 'missing_text', __( 'Usage: /remember <text>', 'nvoos-content-graph-ai-platform' ) );
		}

		$content = trim( wp_kses_post( $text ) );
		$title   = mb_substr( wp_strip_all_tags( $content ), 0, 80 );
		if ( '' === $title ) {
			$title = __( 'Untitled memory', 'nvoos-content-graph-ai-platform' );
		}

		$tags = array();
		if ( ! empty( $flags['tag'] ) ) {
			$raw_tags = is_array( $flags['tag'] ) ? $flags['tag'] : array( $flags['tag'] );
			foreach ( $raw_tags as $tag ) {
				foreach ( explode( ',', (string) $tag ) as $piece ) {
					$piece = sanitize_text_field( trim( $piece ) );
					if ( '' !== $piece ) {
						$tags[] = $piece;
					}
				}
			}
		}

		$importance     = isset( $flags['importance'] ) ? sanitize_key( (string) $flags['importance'] ) : 'medium';
		$allowed_levels = array( 'low', 'medium', 'high', 'critical' );
		if ( ! in_array( $importance, $allowed_levels, true ) ) {
			$importance = 'medium';
		}

		$registry = \WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = $registry->get_tool( 'store_agent_context' );
		if ( ! $tool ) {
			return new \WP_Error( 'tool_unavailable', __( 'Memory tool is not registered on this site.', 'nvoos-content-graph-ai-platform' ) );
		}

		$args_for_tool = array(
			'agent_id'     => $this->resolve_agent_id( $context ),
			'context_type' => 'user_note',
			'context_data' => array(
				'title'      => $title,
				'content'    => $content,
				'importance' => $importance,
				'tags'       => array_values( array_unique( $tags ) ),
			),
			'verbatim'     => empty( $flags['summary'] ),
		);

		if ( ! empty( $flags['wing'] ) ) {
			$args_for_tool['wing'] = sanitize_text_field( (string) $flags['wing'] );
		}
		if ( ! empty( $flags['room'] ) ) {
			$args_for_tool['room'] = sanitize_text_field( (string) $flags['room'] );
		}

		$result = $tool->execute(
			$args_for_tool,
			array(
				'user_id' => get_current_user_id(),
				'source'  => 'slash:remember',
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'type'    => 'memory.stored',
			'message' => __( '🧠 Memory stored.', 'nvoos-content-graph-ai-platform' ),
			'data'    => is_array( $result ) ? $result : array( 'result' => $result ),
		);
	}

	/**
	 * /forget <context_id> — delete a memory by its context_id.
	 *
	 * @param array $args    Positional args (first is context_id).
	 * @param array $flags   Flags (currently unused).
	 * @param array $context Context.
	 * @return array|WP_Error
	 */
	public function forget( $args, $flags, $context ) {
		unset( $flags );

		$check = $this->check_enabled( $context );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$context_id = isset( $args[0] ) ? preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $args[0] ) : '';
		if ( '' === $context_id ) {
			return new \WP_Error( 'missing_id', __( 'Usage: /forget <context_id>', 'nvoos-content-graph-ai-platform' ) );
		}

		$registry = \WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = $registry->get_tool( 'manage_context_lifecycle' );
		if ( ! $tool ) {
			return new \WP_Error( 'tool_unavailable', __( 'Memory lifecycle tool is not registered on this site.', 'nvoos-content-graph-ai-platform' ) );
		}

		$result = $tool->execute(
			array(
				'action'     => 'delete',
				'agent_id'   => $this->resolve_agent_id( $context ),
				'context_id' => $context_id,
			),
			array(
				'user_id' => get_current_user_id(),
				'source'  => 'slash:forget',
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'type'    => 'memory.deleted',
			'message' => sprintf( /* translators: %s: context ID */ __( '🧠 Forgot memory %s.', 'nvoos-content-graph-ai-platform' ), $context_id ),
			'data'    => is_array( $result ) ? $result : array( 'result' => $result ),
		);
	}

	/**
	 * /scope <wing> [room] — set the active wing/room for the conversation.
	 *
	 * Returns a structured payload the chat client can use to update its
	 * scope chip and pre-fill subsequent /remember calls. This command does
	 * not write to the server; it's purely UI affordance.
	 *
	 * @param array $args    Positional args (wing, room).
	 * @param array $flags   Flags.
	 * @param array $context Context.
	 * @return array|WP_Error
	 */
	public function scope( $args, $flags, $context ) {
		unset( $flags );

		$check = $this->check_enabled( $context );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		if ( empty( $args ) ) {
			return array(
				'type'    => 'memory.scope.cleared',
				'message' => __( '🧠 Scope cleared.', 'nvoos-content-graph-ai-platform' ),
				'data'    => array(
					'wing' => '',
					'room' => '',
				),
			);
		}

		$wing = isset( $args[0] ) ? sanitize_text_field( (string) $args[0] ) : '';
		$room = isset( $args[1] ) ? sanitize_text_field( (string) $args[1] ) : '';

		return array(
			'type'    => 'memory.scope.set',
			'message' => '' === $room
				? sprintf( /* translators: %s: wing name */ __( '🧠 Active scope: wing "%s".', 'nvoos-content-graph-ai-platform' ), $wing )
				: sprintf( /* translators: 1: wing name, 2: room name */ __( '🧠 Active scope: wing "%1$s" / room "%2$s".', 'nvoos-content-graph-ai-platform' ), $wing, $room ),
			'data'    => array(
				'wing' => $wing,
				'room' => $room,
			),
		);
	}
}
