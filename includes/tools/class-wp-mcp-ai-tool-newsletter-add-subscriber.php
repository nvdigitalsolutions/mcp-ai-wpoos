<?php
/**
 * Tool for adding subscribers to the Newsletter plugin.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides functionality to add/subscribe email addresses to Newsletter plugin.
 */
class WP_MCP_AI_Tool_Newsletter_Add_Subscriber implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
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
		return __( 'The Newsletter Add Subscriber tool is disabled because the Newsletter plugin is not active.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'newsletter_add_subscriber';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Add Newsletter Subscriber', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Add a new email subscriber to the Newsletter plugin. Supports name, lists, and custom fields. Requires Newsletter plugin.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'email'      => array(
					'type'        => 'string',
					'description' => __( 'Email address of the subscriber.', 'wp-mcp-ai' ),
					'format'      => 'email',
				),
				'name'       => array(
					'type'        => 'string',
					'description' => __( 'Optional name of the subscriber.', 'wp-mcp-ai' ),
				),
				'surname'    => array(
					'type'        => 'string',
					'description' => __( 'Optional surname/last name of the subscriber.', 'wp-mcp-ai' ),
				),
				'lists'      => array(
					'type'        => 'array',
					'description' => __( 'Optional array of list IDs to subscribe to.', 'wp-mcp-ai' ),
					'items'       => array(
						'type' => 'integer',
					),
				),
				'status'     => array(
					'type'        => 'string',
					'description' => __( 'Subscription status: confirmed, not_confirmed, or unsubscribed. Default: confirmed.', 'wp-mcp-ai' ),
					'enum'        => array( 'confirmed', 'not_confirmed', 'unsubscribed' ),
					'default'     => 'confirmed',
				),
				'send_emails' => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to send confirmation/welcome emails. Default: false.', 'wp-mcp-ai' ),
					'default'     => false,
				),
			),
			'required'             => array( 'email' ),
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to add newsletter subscribers.', 'wp-mcp-ai' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
		}

		// Validate email.
		if ( empty( $arguments['email'] ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_email', __( 'Email address is required.', 'wp-mcp-ai' ), array( 'status' => 400 ) );
		}

		$email = sanitize_email( $arguments['email'] );
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_email', __( 'Invalid email address.', 'wp-mcp-ai' ), array( 'status' => 400 ) );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'newsletter';

		// Check if subscriber already exists.
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE email = %s", $email ) );

		$name    = isset( $arguments['name'] ) ? sanitize_text_field( $arguments['name'] ) : '';
		$surname = isset( $arguments['surname'] ) ? sanitize_text_field( $arguments['surname'] ) : '';
		$status  = isset( $arguments['status'] ) ? sanitize_key( $arguments['status'] ) : 'confirmed';
		
		// Map status to Newsletter plugin status codes.
		$status_map = array(
			'confirmed'     => 'C',
			'not_confirmed' => 'S',
			'unsubscribed'  => 'U',
		);
		$status_code = isset( $status_map[ $status ] ) ? $status_map[ $status ] : 'C';

		$subscriber_data = array(
			'email'  => $email,
			'name'   => $name,
			'surname' => $surname,
			'status' => $status_code,
			'token'  => $this->generate_token(),
		);

		// Handle lists.
		if ( ! empty( $arguments['lists'] ) && is_array( $arguments['lists'] ) ) {
			foreach ( $arguments['lists'] as $list_id ) {
				$list_id = absint( $list_id );
				if ( $list_id > 0 && $list_id <= 40 ) { // Newsletter supports up to 40 lists.
					$subscriber_data[ 'list_' . $list_id ] = 1;
				}
			}
		}

		if ( $existing ) {
			// Update existing subscriber.
			$subscriber_data['id'] = $existing->id;
			$result = $wpdb->update(
				$table,
				$subscriber_data,
				array( 'id' => $existing->id ),
				array( '%s', '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);

			if ( false === $result ) {
				return new WP_Error( 'wp_mcp_ai_update_failed', __( 'Failed to update subscriber.', 'wp-mcp-ai' ) );
			}

			return array(
				'success'       => true,
				'subscriber_id' => $existing->id,
				'email'         => $email,
				'action'        => 'updated',
				'status'        => $status,
				'message'       => __( 'Subscriber updated successfully.', 'wp-mcp-ai' ),
			);
		} else {
			// Insert new subscriber.
			$subscriber_data['created'] = current_time( 'mysql' );
			
			$result = $wpdb->insert(
				$table,
				$subscriber_data
			);

			if ( false === $result ) {
				return new WP_Error( 'wp_mcp_ai_insert_failed', __( 'Failed to add subscriber.', 'wp-mcp-ai' ) );
			}

			$subscriber_id = $wpdb->insert_id;

			// Trigger action for other plugins/automations.
			do_action( 'wp_mcp_ai_newsletter_subscriber_added', $subscriber_id, $subscriber_data, $arguments, $context );

			return array(
				'success'       => true,
				'subscriber_id' => $subscriber_id,
				'email'         => $email,
				'action'        => 'created',
				'status'        => $status,
				'message'       => __( 'Subscriber added successfully.', 'wp-mcp-ai' ),
			);
		}
	}

	/**
	 * Generate a random token for subscriber.
	 *
	 * @return string
	 */
	protected function generate_token() {
		return md5( wp_generate_password( 32, true, true ) . microtime( true ) );
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
