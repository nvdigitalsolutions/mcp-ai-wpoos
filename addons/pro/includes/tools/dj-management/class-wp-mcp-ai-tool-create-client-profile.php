<?php
/**
 * Tool for creating client profiles.
 *
 * Allows AI assistants to create and manage DJ client profiles.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 * @phase Phase 2.7
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
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
		return __( 'Create a new client profile or update an existing client profile. If client_id is provided, updates the existing client profile instead of creating a new one. Stores contact information, preferences, and booking history. Use this tool for both creating new client profiles and updating existing ones.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'client_id'        => array(
					'type'        => 'integer',
					'description' => __( 'Optional client ID. If provided, updates the existing client profile instead of creating a new one.', 'mcp-ai-wpoos-pro' ),
				),
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
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
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

		// Check if this is an update operation.
		$client_id       = isset( $arguments['client_id'] ) ? absint( $arguments['client_id'] ) : 0;
		$is_update       = false;
		$existing_client = null;

		if ( $client_id ) {
			// Verify client exists and user has permission to update it.
			$existing_client = get_post( $client_id );

			if ( ! $existing_client || 'dj_client' !== $existing_client->post_type ) {
				return array(
					'success' => false,
					'error'   => __( 'Client profile not found.', 'mcp-ai-wpoos-pro' ),
				);
			}

			// Check permissions: must be author or have edit_others_posts capability.
			$current_user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
			$is_author       = absint( $existing_client->post_author ) === $current_user_id;
			$can_edit_others = user_can( $current_user_id, 'edit_others_posts' );

			if ( ! $is_author && ! $can_edit_others ) {
				return array(
					'success' => false,
					'error'   => __( 'You do not have permission to update this client profile.', 'mcp-ai-wpoos-pro' ),
				);
			}

			$is_update = true;
		} else {
			// Check for existing client with same email (only during create).
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
		}

		$phone            = ! empty( $arguments['phone'] ) ? sanitize_text_field( $arguments['phone'] ) : '';
		$company          = ! empty( $arguments['company'] ) ? sanitize_text_field( $arguments['company'] ) : '';
		$address          = ! empty( $arguments['address'] ) ? sanitize_textarea_field( $arguments['address'] ) : '';
		$preferred_genres = ! empty( $arguments['preferred_genres'] ) ? array_map( 'sanitize_text_field', $arguments['preferred_genres'] ) : array();
		$budget_range     = ! empty( $arguments['budget_range'] ) ? sanitize_text_field( $arguments['budget_range'] ) : '';
		$notes            = ! empty( $arguments['notes'] ) ? sanitize_textarea_field( $arguments['notes'] ) : '';

		if ( $is_update ) {
			// Update existing client post.
			$post_data = array(
				'ID'           => $client_id,
				'post_title'   => $name,
				'post_content' => $notes,
			);

			$result = wp_update_post( $post_data );

			if ( is_wp_error( $result ) ) {
				return array(
					'success' => false,
					'error'   => $result->get_error_message(),
				);
			}
		} else {
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
		}

		// Store client metadata.
		update_post_meta( $client_id, '_email', $email );
		update_post_meta( $client_id, '_phone', $phone );
		update_post_meta( $client_id, '_company', $company );
		update_post_meta( $client_id, '_address', $address );
		update_post_meta( $client_id, '_preferred_genres', $preferred_genres );
		update_post_meta( $client_id, '_budget_range', $budget_range );
		if ( ! $is_update ) {
			update_post_meta( $client_id, '_created_date', current_time( 'mysql' ) );
		}

		return array(
			'success'   => true,
			'client_id' => $client_id,
			'updated'   => $is_update,
			'message'   => sprintf(
				/* translators: %s: client name */
				$is_update ? __( 'Client profile "%s" updated successfully.', 'mcp-ai-wpoos-pro' ) : __( 'Client profile "%s" created successfully.', 'mcp-ai-wpoos-pro' ),
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
	public function get_capability_flags() {
		return array( 'write' );
	}
}
