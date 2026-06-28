<?php
/**
 * Broadcast Pro Slash Command
 *
 * One-shot channel broadcast (Telegram, Slack, Discord, Teams, etc.)
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Slash_Commands
 * @since 2.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Broadcast Command Class
 *
 * Args: $args[0] = message text (alternative to --message=<text>)
 *
 * Flags:
 *   --channel=<name>   Target channel (required): telegram|slack|discord|teams|messenger|whatsapp
 *   --message=<text>   Message text (alternative to positional arg)
 *   --dry-run          Show what would be sent without sending
 *   --json             JSON output
 *
 * @since 2.1.0
 */
class WP_MCP_AI_Pro_Slash_Command_Broadcast {

	/**
	 * Allowed broadcast channels.
	 *
	 * @var array
	 */
	private $allowed_channels = array( 'telegram', 'slack', 'discord', 'teams', 'messenger', 'whatsapp' );

	/**
	 * Execute broadcast command.
	 *
	 * @param array $args    Positional arguments.
	 * @param array $flags   Command flags.
	 * @param array $context Execution context.
	 * @return string|array|WP_Error
	 */
	public function execute( $args, $flags, $context ) {
		// Block guest requests.
		if ( ! empty( $context['guest_request'] ) ) {
			return new WP_Error(
				'guest_forbidden',
				__( 'This command requires authentication.', 'mcp-ai-wpoos-pro' )
			);
		}

		$user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		$as_json = isset( $flags['json'] );
		$dry_run = isset( $flags['dry-run'] );

		// Require manage_options.
		if ( ! user_can( $user_id, 'manage_options' ) ) {
			return new WP_Error(
				'forbidden',
				__( 'Permission denied. Requires manage_options capability.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Validate channel flag.
		if ( empty( $flags['channel'] ) ) {
			return new WP_Error(
				'missing_channel',
				sprintf(
					/* translators: %s: allowed channels */
					__( '--channel flag required. Allowed: %s', 'mcp-ai-wpoos-pro' ),
					implode( ', ', $this->allowed_channels )
				)
			);
		}

		$channel = sanitize_key( $flags['channel'] );

		if ( ! in_array( $channel, $this->allowed_channels, true ) ) {
			return new WP_Error(
				'invalid_channel',
				sprintf(
					/* translators: %1$s: channel name, %2$s: allowed list */
					__( 'Invalid channel "%1$s". Allowed: %2$s', 'mcp-ai-wpoos-pro' ),
					esc_html( $channel ),
					implode( ', ', $this->allowed_channels )
				)
			);
		}

		// Get message: flag takes precedence, then positional arg.
		$message = '';
		if ( ! empty( $flags['message'] ) ) {
			$message = sanitize_textarea_field( $flags['message'] );
		} elseif ( ! empty( $args[0] ) ) {
			$message = sanitize_textarea_field( $args[0] );
		}

		if ( empty( $message ) ) {
			return new WP_Error(
				'missing_message',
				__( 'Message text required. Usage: /broadcast <message> --channel=<channel>', 'mcp-ai-wpoos-pro' )
			);
		}

		// --dry-run: preview without sending.
		if ( $dry_run ) {
			if ( $as_json ) {
				return array(
					'success' => true,
					'message' => __( 'Dry run — message not sent.', 'mcp-ai-wpoos-pro' ),
					'data'    => array(
						'channel' => $channel,
						'message' => $message,
						'dry_run' => true,
					),
				);
			}

			$output  = '## ' . __( 'Broadcast Preview (Dry Run)', 'mcp-ai-wpoos-pro' ) . "\n\n";
			$output .= '- **Channel:** ' . esc_html( $channel ) . "\n";
			$output .= '- **Message:** ' . esc_html( $message ) . "\n\n";
			$output .= "_Run without `--dry-run` to send._\n";

			return $output;
		}

		// Try via Tool Registry first.
		if ( class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
			$registry = WP_MCP_AI_Tool_Registry::get_instance();
			$tool     = $registry->get_tool( 'unified_channel_broadcast' );
			if ( $tool ) {
				$tool_result = $tool->execute(
					array(
						'channel' => $channel,
						'message' => $message,
					),
					$context
				);

				if ( ! is_wp_error( $tool_result ) ) {
					return $this->format_success( $channel, $message, $tool_result, $as_json );
				}
			}
		}

		// Fallback: fire broadcast action hook.
		do_action( 'wp_mcp_ai_broadcast_message', $channel, $message, $context );

		return $this->format_success( $channel, $message, null, $as_json );
	}

	/**
	 * Format a success response.
	 *
	 * @param string     $channel     Channel name.
	 * @param string     $message     Message text.
	 * @param mixed|null $tool_result Tool result if available.
	 * @param bool       $as_json     JSON output.
	 * @return string|array
	 */
	private function format_success( $channel, $message, $tool_result, $as_json ) {
		if ( $as_json ) {
			return array(
				'success' => true,
				'message' => sprintf(
					/* translators: %s: channel name */
					__( 'Message broadcast to %s.', 'mcp-ai-wpoos-pro' ),
					$channel
				),
				'data'    => array(
					'channel'     => $channel,
					'message'     => $message,
					'tool_result' => $tool_result,
				),
			);
		}

		return sprintf(
			/* translators: %1$s: channel name, %2$s: message snippet */
			__( '✅ Message broadcast to %1$s: "%2$s"', 'mcp-ai-wpoos-pro' ),
			esc_html( $channel ),
			esc_html( wp_trim_words( $message, 10 ) )
		);
	}
}
