<?php
/**
 * Tool for saving WebChat messages to CCT.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';

/**
 * Saves messages to the WebChat messages CCT.
 */
class WP_MCP_AI_Tool_Save_WebChat_Message implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * Check if this tool is available.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True if WebChat is enabled and JetEngine is active.
	 */
	public static function is_available() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_webchat_integration'] ) && function_exists( 'jet_engine' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'save_webchat_message';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Save WebChat Message', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Saves a message to the webchat_messages CCT for persistent storage. Requires JetEngine Custom Content Types.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'room_id'      => array(
					'type'        => 'integer',
					'description' => __( 'WebChat room post ID.', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'peer_id'      => array(
					'type'        => 'string',
					'description' => __( 'WebRTC peer identifier.', 'mcp-ai-wpoos-pro' ),
				),
				'user_id'      => array(
					'type'        => 'integer',
					'description' => __( 'WordPress user ID (0 for anonymous).', 'mcp-ai-wpoos-pro' ),
					'default'     => 0,
				),
				'sender_name'  => array(
					'type'        => 'string',
					'description' => __( 'Display name of the sender.', 'mcp-ai-wpoos-pro' ),
				),
				'message'      => array(
					'type'        => 'string',
					'description' => __( 'Message content.', 'mcp-ai-wpoos-pro' ),
				),
				'message_type' => array(
					'type'        => 'string',
					'description' => __( 'Type of message: text, image, file, or system.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'text', 'image', 'file', 'system' ),
					'default'     => 'text',
				),
				'is_encrypted' => array(
					'type'        => 'boolean',
					'description' => __( 'Whether message was end-to-end encrypted.', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
				'metadata'     => array(
					'type'        => 'string',
					'description' => __( 'Optional JSON metadata for the message.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'             => array( 'room_id', 'peer_id', 'sender_name', 'message' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		$default_capability  = 'manage_options';
		$required_capability = apply_filters( 'wp_mcp_ai_save_webchat_message_capability', $default_capability, $context, $arguments, $this );

		if ( $required_capability && ( ! $user_id || ! user_can( $user_id, $required_capability ) ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to save WebChat messages.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( is_multisite() && $user_id && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos-pro' ) );
		}

		// Check if JetEngine is available.
		if ( ! function_exists( 'jet_engine' ) ) {
			return new WP_Error(
				'wp_mcp_ai_jetengine_unavailable',
				__( 'JetEngine Custom Content Types is required to save WebChat messages.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Load the CCT handler.
		$cct_file = WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-jetengine-webchat-messages-cct.php';
		if ( file_exists( $cct_file ) ) {
			require_once $cct_file;
		}

		if ( ! class_exists( 'WP_MCP_AI_JetEngine_WebChat_Messages_CCT' ) ) {
			return new WP_Error(
				'wp_mcp_ai_cct_unavailable',
				__( 'WebChat messages CCT is not available.', 'mcp-ai-wpoos-pro' )
			);
		}

		$handler = WP_MCP_AI_JetEngine_WebChat_Messages_CCT::get_item_handler();

		if ( ! $handler ) {
			return new WP_Error(
				'wp_mcp_ai_cct_handler_unavailable',
				__( 'WebChat messages CCT handler is not available. Ensure JetEngine Custom Content Types module is active.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Sanitize and validate inputs.
		$room_id      = isset( $arguments['room_id'] ) ? absint( $arguments['room_id'] ) : 0;
		$peer_id      = isset( $arguments['peer_id'] ) ? sanitize_text_field( $arguments['peer_id'] ) : '';
		$msg_user_id  = isset( $arguments['user_id'] ) ? absint( $arguments['user_id'] ) : 0;
		$sender_name  = isset( $arguments['sender_name'] ) ? sanitize_text_field( $arguments['sender_name'] ) : '';
		$message      = isset( $arguments['message'] ) ? sanitize_textarea_field( $arguments['message'] ) : '';
		$message_type = isset( $arguments['message_type'] ) ? sanitize_text_field( $arguments['message_type'] ) : 'text';
		$is_encrypted = isset( $arguments['is_encrypted'] ) ? (bool) $arguments['is_encrypted'] : false;
		$metadata     = isset( $arguments['metadata'] ) ? sanitize_textarea_field( $arguments['metadata'] ) : '';

		if ( ! $room_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_room_id', __( 'Room ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( '' === $peer_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_peer_id', __( 'Peer ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( '' === $sender_name ) {
			return new WP_Error( 'wp_mcp_ai_missing_sender_name', __( 'Sender name is required.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( '' === $message ) {
			return new WP_Error( 'wp_mcp_ai_missing_message', __( 'Message content is required.', 'mcp-ai-wpoos-pro' ) );
		}

		// Validate message type.
		$valid_types = array( 'text', 'image', 'file', 'system' );
		if ( ! in_array( $message_type, $valid_types, true ) ) {
			$message_type = 'text';
		}

		// Build the record.
		$record = array(
			'room_id'      => $room_id,
			'peer_id'      => $peer_id,
			'user_id'      => $msg_user_id,
			'sender_name'  => $sender_name,
			'message'      => $message,
			'message_type' => $message_type,
			'is_encrypted' => $is_encrypted,
			'timestamp'    => current_time( 'mysql' ),
		);

		if ( '' !== $metadata ) {
			$record['metadata'] = $metadata;
		}

		WP_MCP_AI_Logger::log_event(
			'webchat_save_message',
			'Saving WebChat message to CCT.',
			array(
				'room_id'     => $room_id,
				'peer_id'     => $peer_id,
				'sender_name' => $sender_name,
				'type'        => $message_type,
			)
		);

		try {
			$result = $handler->update_item( $record );

			if ( is_wp_error( $result ) ) {
				WP_MCP_AI_Logger::log_error(
					'Failed to save WebChat message to CCT.',
					array(
						'room_id'       => $room_id,
						'error_code'    => $result->get_error_code(),
						'error_message' => $result->get_error_message(),
					)
				);
				return $result;
			}

			WP_MCP_AI_Logger::log_event(
				'webchat_message_saved',
				'WebChat message saved successfully.',
				array(
					'message_id' => $result,
					'room_id'    => $room_id,
				)
			);

			return array(
				'success'    => true,
				'message_id' => $result,
				'room_id'    => $room_id,
				'timestamp'  => $record['timestamp'],
			);
		} catch ( Throwable $exception ) {
			WP_MCP_AI_Logger::log_error(
				'Unexpected error while saving WebChat message.',
				array(
					'room_id'   => $room_id,
					'exception' => $exception->getMessage(),
				)
			);
			return new WP_Error(
				'wp_mcp_ai_save_error',
				__( 'An unexpected error occurred while saving the message.', 'mcp-ai-wpoos-pro' )
			);
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'write',
			'local-only',
			'requires-capability',
		);
	}
}
