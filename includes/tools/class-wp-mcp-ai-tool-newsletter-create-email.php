<?php
/**
 * Tool for creating newsletter emails in the Newsletter plugin.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides functionality to create newsletter emails in the Newsletter plugin.
 */
class WP_MCP_AI_Tool_Newsletter_Create_Email implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * Determine whether Newsletter plugin is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return class_exists( 'Newsletter' ) || class_exists( 'NewsletterEmails' );
	}

	/**
	 * Message explaining why the tool is unavailable.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason() {
		return __( 'The Newsletter Create Email tool is disabled because the Newsletter plugin is not active.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'newsletter_create_email';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Create Newsletter Email', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Create a new newsletter email campaign with subject, content, and settings. Requires Newsletter plugin.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'subject'           => array(
					'type'        => 'string',
					'description' => __( 'Email subject line.', 'wp-mcp-ai' ),
				),
				'message'           => array(
					'type'        => 'string',
					'description' => __( 'Email HTML content/body.', 'wp-mcp-ai' ),
				),
				'type'              => array(
					'type'        => 'string',
					'description' => __( 'Email type: message (standard newsletter) or followup (automated). Default: message.', 'wp-mcp-ai' ),
					'enum'        => array( 'message', 'followup' ),
					'default'     => 'message',
				),
				'status'            => array(
					'type'        => 'string',
					'description' => __( 'Email status: new (draft), sending, sent, paused. Default: new.', 'wp-mcp-ai' ),
					'enum'        => array( 'new', 'sending', 'sent', 'paused' ),
					'default'     => 'new',
				),
				'track'             => array(
					'type'        => 'boolean',
					'description' => __( 'Enable click and open tracking. Default: true.', 'wp-mcp-ai' ),
					'default'     => true,
				),
				'lists'             => array(
					'type'        => 'array',
					'description' => __( 'Target list IDs (1-40). Empty means all confirmed subscribers.', 'wp-mcp-ai' ),
					'items'       => array(
						'type' => 'integer',
					),
				),
				// Enhanced parameters for comprehensive newsletter creation.
				'preheader'         => array(
					'type'        => 'string',
					'description' => __( 'Preheader text (preview text shown in email clients).', 'wp-mcp-ai' ),
					'maxLength'   => 150,
				),
				'sender_name'       => array(
					'type'        => 'string',
					'description' => __( 'Sender name to display in email from field.', 'wp-mcp-ai' ),
				),
				'sender_email'      => array(
					'type'        => 'string',
					'description' => __( 'Sender email address.', 'wp-mcp-ai' ),
					'format'      => 'email',
				),
				'send_time'         => array(
					'type'        => 'string',
					'description' => __( 'Schedule send time in ISO 8601 format (e.g., "2024-12-31T10:00:00"). Leave empty to send immediately.', 'wp-mcp-ai' ),
					'format'      => 'date-time',
				),
				'featured_image_id' => array(
					'type'        => 'integer',
					'description' => __( 'Attachment ID for the email featured image/banner.', 'wp-mcp-ai' ),
					'minimum'     => 1,
				),
				'tags'              => array(
					'type'        => 'array',
					'description' => __( 'Array of tag names for email organization (custom meta).', 'wp-mcp-ai' ),
					'items'       => array( 'type' => 'string' ),
				),
				'meta_input'        => array(
					'type'                 => 'object',
					'description'          => __( 'Array of custom field key-value pairs for email metadata.', 'wp-mcp-ai' ),
					'additionalProperties' => true,
				),
			),
			'required'             => array( 'subject', 'message' ),
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
		if ( ! self::is_available() ) {
			return new WP_Error( 'wp_mcp_ai_newsletter_missing', __( 'Newsletter plugin is not active on this site.', 'wp-mcp-ai' ) );
		}

		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id || ! user_can( $user_id, 'manage_options' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to create newsletter emails.', 'wp-mcp-ai' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
		}

		// Validate required fields.
		if ( empty( $arguments['subject'] ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_subject', __( 'Email subject is required.', 'wp-mcp-ai' ), array( 'status' => 400 ) );
		}

		if ( empty( $arguments['message'] ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_message', __( 'Email message is required.', 'wp-mcp-ai' ), array( 'status' => 400 ) );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'newsletter_emails';

		$subject = sanitize_text_field( $arguments['subject'] );
		$message = wp_kses_post( $arguments['message'] );
		$type    = isset( $arguments['type'] ) ? sanitize_key( $arguments['type'] ) : 'message';
		$status  = isset( $arguments['status'] ) ? sanitize_key( $arguments['status'] ) : 'new';
		$track   = isset( $arguments['track'] ) ? (int) (bool) $arguments['track'] : 1;

		$email_data = array(
			'subject' => $subject,
			'message' => $message,
			'type'    => $type,
			'status'  => $status,
			'track'   => $track,
			'created' => current_time( 'mysql' ),
			'updated' => current_time( 'mysql' ),
		);

		// Handle enhanced parameters.
		if ( isset( $arguments['preheader'] ) && '' !== $arguments['preheader'] ) {
			$email_data['preheader'] = sanitize_text_field( $arguments['preheader'] );
		}

		if ( isset( $arguments['sender_name'] ) && '' !== $arguments['sender_name'] ) {
			$email_data['sender_name'] = sanitize_text_field( $arguments['sender_name'] );
		}

		if ( isset( $arguments['sender_email'] ) && '' !== $arguments['sender_email'] ) {
			$email_data['sender_email'] = sanitize_email( $arguments['sender_email'] );
		}

		// Handle scheduled send time.
		if ( isset( $arguments['send_time'] ) && '' !== $arguments['send_time'] ) {
			$send_timestamp = strtotime( $arguments['send_time'] );
			if ( $send_timestamp && $send_timestamp > current_time( 'timestamp' ) ) {
				$email_data['send_on'] = gmdate( 'Y-m-d H:i:s', $send_timestamp );
			}
		}

		// Handle list targeting.
		if ( ! empty( $arguments['lists'] ) && is_array( $arguments['lists'] ) ) {
			$preferences = array();
			foreach ( $arguments['lists'] as $list_id ) {
				$list_id = absint( $list_id );
				if ( $list_id > 0 && $list_id <= 40 ) {
					$preferences[ 'list_' . $list_id ] = 1;
				}
			}
			if ( ! empty( $preferences ) ) {
				$email_data['preferences'] = wp_json_encode( $preferences );
			}
		}

		$result = $wpdb->insert(
			$table,
			$email_data
		);

		if ( false === $result ) {
			return new WP_Error( 'wp_mcp_ai_insert_failed', __( 'Failed to create newsletter email.', 'wp-mcp-ai' ) );
		}

		$email_id = $wpdb->insert_id;

		// Handle enhanced metadata.
		$this->handle_email_metadata( $email_id, $arguments );

		// Trigger action for other plugins/automations.
		do_action( 'wp_mcp_ai_newsletter_email_created', $email_id, $email_data, $arguments, $context );

		return array(
			'success'  => true,
			'email_id' => $email_id,
			'subject'  => $subject,
			'type'     => $type,
			'status'   => $status,
			'message'  => __( 'Newsletter email created successfully.', 'wp-mcp-ai' ),
			'edit_url' => admin_url( 'admin.php?page=newsletter_emails_edit&id=' . $email_id ),
		);
	}

	/**
	 * Handles newsletter email metadata.
	 *
	 * @param int   $email_id  The email ID.
	 * @param array $arguments Tool arguments.
	 */
	protected function handle_email_metadata( $email_id, $arguments ) {
		// Handle featured image (stored as custom meta).
		if ( isset( $arguments['featured_image_id'] ) ) {
			$thumbnail_id = absint( $arguments['featured_image_id'] );
			if ( $thumbnail_id > 0 && wp_attachment_is_image( $thumbnail_id ) ) {
				update_option( 'wp_mcp_ai_newsletter_email_' . $email_id . '_featured_image', $thumbnail_id );
			}
		}

		// Handle tags (stored as custom meta).
		if ( isset( $arguments['tags'] ) && is_array( $arguments['tags'] ) ) {
			$sanitized_tags = array_map( 'sanitize_text_field', $arguments['tags'] );
			update_option( 'wp_mcp_ai_newsletter_email_' . $email_id . '_tags', $sanitized_tags );
		}

		// Handle custom meta fields.
		if ( isset( $arguments['meta_input'] ) && is_array( $arguments['meta_input'] ) ) {
			foreach ( $arguments['meta_input'] as $key => $value ) {
				$sanitized_key = sanitize_key( $key );

				// Recursively sanitize arrays.
				if ( is_array( $value ) ) {
					$sanitized_value = array_map( 'sanitize_text_field', $value );
				} else {
					$sanitized_value = sanitize_text_field( $value );
				}

				update_option( 'wp_mcp_ai_newsletter_email_' . $email_id . '_' . $sanitized_key, $sanitized_value );
			}
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'requires-capability',  // Requires user capabilities.
			'modifies-data',        // Creates new data.
			'local-only',           // No external API calls.
		);
	}
}
