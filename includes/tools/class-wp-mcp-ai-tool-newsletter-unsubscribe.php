<?php
/**
 * Tool for unsubscribing/removing subscribers from the Newsletter plugin.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides functionality to unsubscribe or remove Newsletter plugin subscribers.
 */
class WP_MCP_AI_Tool_Newsletter_Unsubscribe implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * Determine whether Newsletter plugin is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return class_exists( 'Newsletter' ) || class_exists( 'NewsletterSubscription' );
	}

	/**
	 * Message explaining why the tool is unavailable.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason() {
		return __( 'The Newsletter Unsubscribe tool is disabled because the Newsletter plugin is not active.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'newsletter_unsubscribe';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Unsubscribe Newsletter Subscriber', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Unsubscribe or remove a subscriber from the Newsletter plugin by email or ID. Requires Newsletter plugin.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'email'          => array(
					'type'        => 'string',
					'description' => __( 'Email address of the subscriber to unsubscribe.', 'wp-mcp-ai' ),
					'format'      => 'email',
				),
				'subscriber_id'  => array(
					'type'        => 'integer',
					'description' => __( 'Subscriber ID to unsubscribe (alternative to email).', 'wp-mcp-ai' ),
				),
				'action'         => array(
					'type'        => 'string',
					'description' => __( 'Action to perform: unsubscribe (set status to unsubscribed) or delete (remove completely).', 'wp-mcp-ai' ),
					'enum'        => array( 'unsubscribe', 'delete' ),
					'default'     => 'unsubscribe',
				),
			),
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to manage newsletter subscribers.', 'wp-mcp-ai' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'newsletter';

		// Determine subscriber to unsubscribe.
		$subscriber = null;
		if ( ! empty( $arguments['subscriber_id'] ) ) {
			$subscriber_id = absint( $arguments['subscriber_id'] );
			$subscriber = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $subscriber_id ) );
		} elseif ( ! empty( $arguments['email'] ) ) {
			$email = sanitize_email( $arguments['email'] );
			if ( ! is_email( $email ) ) {
				return new WP_Error( 'wp_mcp_ai_invalid_email', __( 'Invalid email address.', 'wp-mcp-ai' ), array( 'status' => 400 ) );
			}
			$subscriber = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE email = %s", $email ) );
		} else {
			return new WP_Error( 'wp_mcp_ai_missing_identifier', __( 'Either email or subscriber_id is required.', 'wp-mcp-ai' ), array( 'status' => 400 ) );
		}

		if ( ! $subscriber ) {
			return new WP_Error( 'wp_mcp_ai_subscriber_not_found', __( 'Subscriber not found.', 'wp-mcp-ai' ), array( 'status' => 404 ) );
		}

		$action = isset( $arguments['action'] ) ? sanitize_key( $arguments['action'] ) : 'unsubscribe';

		if ( 'delete' === $action ) {
			// Permanently delete subscriber.
			$result = $wpdb->delete(
				$table,
				array( 'id' => $subscriber->id ),
				array( '%d' )
			);

			if ( false === $result ) {
				return new WP_Error( 'wp_mcp_ai_delete_failed', __( 'Failed to delete subscriber.', 'wp-mcp-ai' ) );
			}

			// Trigger action for other plugins/automations.
			do_action( 'wp_mcp_ai_newsletter_subscriber_deleted', $subscriber->id, $subscriber, $arguments, $context );

			return array(
				'success'       => true,
				'subscriber_id' => (int) $subscriber->id,
				'email'         => $subscriber->email,
				'action'        => 'deleted',
				'message'       => __( 'Subscriber deleted successfully.', 'wp-mcp-ai' ),
			);
		} else {
			// Mark as unsubscribed.
			$result = $wpdb->update(
				$table,
				array( 'status' => 'U' ),
				array( 'id' => $subscriber->id ),
				array( '%s' ),
				array( '%d' )
			);

			if ( false === $result ) {
				return new WP_Error( 'wp_mcp_ai_update_failed', __( 'Failed to unsubscribe subscriber.', 'wp-mcp-ai' ) );
			}

			// Trigger action for other plugins/automations.
			do_action( 'wp_mcp_ai_newsletter_subscriber_unsubscribed', $subscriber->id, $subscriber, $arguments, $context );

			return array(
				'success'       => true,
				'subscriber_id' => (int) $subscriber->id,
				'email'         => $subscriber->email,
				'action'        => 'unsubscribed',
				'message'       => __( 'Subscriber unsubscribed successfully.', 'wp-mcp-ai' ),
			);
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'requires-capability',  // Requires user capabilities.
			'modifies-data',        // Modifies subscriber data.
			'local-only',           // No external API calls.
		);
	}
}
