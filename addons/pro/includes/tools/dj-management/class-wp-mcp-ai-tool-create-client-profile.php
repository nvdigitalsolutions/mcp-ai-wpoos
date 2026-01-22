<?php
/**
 * Tool for creating client profiles.
 *
 * Allows AI assistants to create and manage DJ client profiles.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 * @phase Phase 2.7
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates DJ client profiles.
 */
class WP_MCP_AI_Tool_Create_Client_Profile implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'create_client_profile';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Create Client Profile', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Creates a new client profile for DJ business management. Stores contact information, preferences, and booking history.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'name'             => array(
					'type'        => 'string',
					'description' => __( 'Client name (required)', 'mcp-ai-wpoos-pro' ),
					'minLength'   => 1,
					'maxLength'   => 200,
				),
				'email'            => array(
					'type'        => 'string',
					'description' => __( 'Client email (required)', 'mcp-ai-wpoos-pro' ),
					'format'      => 'email',
					'maxLength'   => 100,
				),
				'phone'            => array(
					'type'        => 'string',
					'description' => __( 'Phone number (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 20,
				),
				'company'          => array(
					'type'        => 'string',
					'description' => __( 'Company name (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 200,
				),
				'address'          => array(
					'type'        => 'string',
					'description' => __( 'Address (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 500,
				),
				'preferred_genres' => array(
					'type'        => 'array',
					'description' => __( 'Preferred music genres (optional)', 'mcp-ai-wpoos-pro' ),
					'items'       => array( 'type' => 'string' ),
				),
				'budget_range'     => array(
					'type'        => 'string',
					'description' => __( 'Budget range (optional)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'budget', 'mid-range', 'premium', 'luxury' ),
				),
				'notes'            => array(
					'type'        => 'string',
					'description' => __( 'Additional notes (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 2000,
				),
			),
			'required'             => array( 'name', 'email' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function execute( array $arguments, array $context = array() ) {
		if ( empty( $arguments['name'] ) || empty( $arguments['email'] ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Client name and email are required.', 'mcp-ai-wpoos-pro' ),
			);
		}

		$name  = sanitize_text_field( $arguments['name'] );
		$email = sanitize_email( $arguments['email'] );

		if ( ! is_email( $email ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Invalid email address.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Check for existing client with same email.
		$existing = get_posts(
			array(
				'post_type'   => 'dj_client',
				'meta_key'    => '_email',
				'meta_value'  => $email,
				'numberposts' => 1,
			)
		);

		if ( ! empty( $existing ) ) {
			return array(
				'success'   => false,
				'error'     => __( 'Client with this email already exists.', 'mcp-ai-wpoos-pro' ),
				'client_id' => $existing[0]->ID,
			);
		}

		$phone            = ! empty( $arguments['phone'] ) ? sanitize_text_field( $arguments['phone'] ) : '';
		$company          = ! empty( $arguments['company'] ) ? sanitize_text_field( $arguments['company'] ) : '';
		$address          = ! empty( $arguments['address'] ) ? sanitize_textarea_field( $arguments['address'] ) : '';
		$preferred_genres = ! empty( $arguments['preferred_genres'] ) ? array_map( 'sanitize_text_field', $arguments['preferred_genres'] ) : array();
		$budget_range     = ! empty( $arguments['budget_range'] ) ? sanitize_text_field( $arguments['budget_range'] ) : '';
		$notes            = ! empty( $arguments['notes'] ) ? sanitize_textarea_field( $arguments['notes'] ) : '';

		// Create client post.
		$post_data = array(
			'post_title'   => $name,
			'post_content' => $notes,
			'post_status'  => 'publish',
			'post_type'    => 'dj_client',
		);

		$client_id = wp_insert_post( $post_data );

		if ( is_wp_error( $client_id ) ) {
			return array(
				'success' => false,
				'error'   => $client_id->get_error_message(),
			);
		}

		// Store client metadata.
		update_post_meta( $client_id, '_email', $email );
		update_post_meta( $client_id, '_phone', $phone );
		update_post_meta( $client_id, '_company', $company );
		update_post_meta( $client_id, '_address', $address );
		update_post_meta( $client_id, '_preferred_genres', $preferred_genres );
		update_post_meta( $client_id, '_budget_range', $budget_range );
		update_post_meta( $client_id, '_created_date', current_time( 'mysql' ) );

		return array(
			'success'   => true,
			'client_id' => $client_id,
			'message'   => sprintf(
				/* translators: %s: client name */
				__( 'Client profile "%s" created successfully.', 'mcp-ai-wpoos-pro' ),
				$name
			),
			'client'    => array(
				'id'      => $client_id,
				'name'    => $name,
				'email'   => $email,
				'phone'   => $phone,
				'company' => $company,
			),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'manage_options';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_flag_capabilities() {
		return array( 'write' );
	}
}
