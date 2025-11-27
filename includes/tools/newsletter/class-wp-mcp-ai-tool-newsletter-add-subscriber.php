<?php
/**
 * Tool for adding or updating Newsletter plugin subscribers.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds a new subscriber or updates an existing one using the Newsletter Plugin API.
 */
class WP_MCP_AI_Tool_Newsletter_Add_Subscriber implements WP_MCP_AI_Tool_Interface {
	/**
	 * Determine whether the Newsletter plugin is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return function_exists( 'newsletter_user_save' );
	}

	/**
	 * Message explaining why the tool is unavailable.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason() {
		return __( 'The Newsletter Add Subscriber tool is disabled because The Newsletter Plugin is not installed or active.', 'wp-mcp-ai' );
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
		return __( 'Newsletter Add/Update Subscriber', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Adds a new subscriber or updates an existing one in The Newsletter Plugin.', 'wp-mcp-ai' );
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
					'description' => __( 'The subscriber email address (required).', 'wp-mcp-ai' ),
					'format'      => 'email',
				),
				'first_name' => array(
					'type'        => 'string',
					'description' => __( 'The subscriber first name.', 'wp-mcp-ai' ),
				),
				'last_name'  => array(
					'type'        => 'string',
					'description' => __( 'The subscriber last name.', 'wp-mcp-ai' ),
				),
				'status'     => array(
					'type'        => 'string',
					'description' => __( 'The subscriber status: C (confirmed), S (not confirmed), U (unsubscribed).', 'wp-mcp-ai' ),
					'enum'        => array( 'C', 'S', 'U' ),
					'default'     => 'C',
				),
				'lists'      => array(
					'type'        => 'array',
					'description' => __( 'List IDs to subscribe the user to.', 'wp-mcp-ai' ),
					'items'       => array(
						'type' => 'integer',
					),
				),
				'tags'       => array(
					'type'        => 'array',
					'description' => __( 'Tags to assign to the subscriber.', 'wp-mcp-ai' ),
					'items'       => array(
						'type' => 'string',
					),
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
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		if ( ! self::is_available() ) {
			return new WP_Error( 'wp_mcp_ai_newsletter_unavailable', __( 'The Newsletter Plugin is not available.', 'wp-mcp-ai' ) );
		}

		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id || ! user_can( $user_id, 'manage_options' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to manage newsletter subscribers.', 'wp-mcp-ai' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
		}

		if ( empty( $arguments['email'] ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_email', __( 'Email address is required.', 'wp-mcp-ai' ) );
		}

		$email = sanitize_email( $arguments['email'] );

		if ( ! is_email( $email ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_email', __( 'Invalid email address provided.', 'wp-mcp-ai' ) );
		}

		$subscriber_data = array(
			'email' => $email,
		);

		if ( isset( $arguments['first_name'] ) ) {
			$subscriber_data['name'] = sanitize_text_field( $arguments['first_name'] );
		}

		if ( isset( $arguments['last_name'] ) ) {
			$subscriber_data['surname'] = sanitize_text_field( $arguments['last_name'] );
		}

		if ( isset( $arguments['status'] ) ) {
			$status = strtoupper( sanitize_text_field( $arguments['status'] ) );
			if ( in_array( $status, array( 'C', 'S', 'U' ), true ) ) {
				$subscriber_data['status'] = $status;
			}
		}

		if ( isset( $arguments['lists'] ) && is_array( $arguments['lists'] ) ) {
			foreach ( $arguments['lists'] as $list_id ) {
				$list_id = absint( $list_id );
				if ( $list_id > 0 ) {
					$subscriber_data[ 'list_' . $list_id ] = 1;
				}
			}
		}

		$result = newsletter_user_save( $subscriber_data );

		if ( ! $result || is_wp_error( $result ) ) {
			$error_message = is_wp_error( $result ) ? $result->get_error_message() : __( 'Failed to save subscriber.', 'wp-mcp-ai' );
			return new WP_Error( 'wp_mcp_ai_newsletter_save_failed', $error_message );
		}

		// Handle tags if provided and the API supports it.
		if ( isset( $arguments['tags'] ) && is_array( $arguments['tags'] ) && ! empty( $result->id ) ) {
			$tags = array_map( 'sanitize_text_field', $arguments['tags'] );
			$tags = array_filter( $tags );

			if ( ! empty( $tags ) && class_exists( 'TNP' ) && method_exists( 'TNP', 'add_user_tags' ) ) {
				TNP::add_user_tags( $result->id, $tags );
			}
		}

		return array(
			'success'       => true,
			'subscriber_id' => isset( $result->id ) ? absint( $result->id ) : 0,
			'email'         => $email,
			'status'        => isset( $result->status ) ? $result->status : 'C',
			'message'       => __( 'Subscriber saved successfully.', 'wp-mcp-ai' ),
		);
	}
}
