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
				'subject' => array(
					'type'        => 'string',
					'description' => __( 'Email subject line.', 'wp-mcp-ai' ),
				),
				'message' => array(
					'type'        => 'string',
					'description' => __( 'Email HTML content/body.', 'wp-mcp-ai' ),
				),
				'type'    => array(
					'type'        => 'string',
					'description' => __( 'Email type: message (standard newsletter) or followup (automated). Default: message.', 'wp-mcp-ai' ),
					'enum'        => array( 'message', 'followup' ),
					'default'     => 'message',
				),
				'status'  => array(
					'type'        => 'string',
					'description' => __( 'Email status: new (draft), sending, sent, paused. Default: new.', 'wp-mcp-ai' ),
					'enum'        => array( 'new', 'sending', 'sent', 'paused' ),
					'default'     => 'new',
				),
				'track'   => array(
					'type'        => 'boolean',
					'description' => __( 'Enable click and open tracking. Default: true.', 'wp-mcp-ai' ),
					'default'     => true,
				),
				'lists'   => array(
					'type'        => 'array',
					'description' => __( 'Target list IDs (1-40). Empty means all confirmed subscribers.', 'wp-mcp-ai' ),
					'items'       => array(
						'type' => 'integer',
					),
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
