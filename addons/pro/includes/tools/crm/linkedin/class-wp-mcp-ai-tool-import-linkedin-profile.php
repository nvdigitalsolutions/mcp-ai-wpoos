<?php
/**
 * Tool for importing a LinkedIn profile into the CRM as a contact or lead.
 *
 * Fetches profile data via the LinkedIn REST API when a valid OAuth
 * connection is configured, and creates or updates a CRM contact record.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage CRM_Toolkit
 * @since 2.10.0
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Imports a LinkedIn profile into the CRM.
 *
 * @since 2.10.0
 */
class WP_MCP_AI_Tool_Import_LinkedIn_Profile implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Determine whether CRM toolkit is enabled.
	 *
	 * @since 2.10.0
	 * @return bool
	 */
	public static function is_available() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_crm_toolkit'] ) && class_exists( 'WP_MCP_AI_LinkedIn_Client' );
	}

	/**
	 * {@inheritdoc}
	 */
	public static function get_unavailable_reason() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_crm_toolkit'] ) ) {
			return __( 'The Import LinkedIn Profile tool requires the CRM Toolkit to be enabled.', 'mcp-ai-wpoos-pro' );
		}
		return __( 'The Import LinkedIn Profile tool requires the LinkedIn client integration.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'import_linkedin_profile';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Import LinkedIn Profile', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Import a LinkedIn profile into the CRM as a contact or lead record, enriching it with profile data when a LinkedIn connection is configured.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'linkedin_url'  => array(
					'type'        => 'string',
					'description' => __( 'LinkedIn profile URL to import (e.g. https://www.linkedin.com/in/username/).', 'mcp-ai-wpoos-pro' ),
				),
				'import_as'     => array(
					'type'        => 'string',
					'description' => __( 'CRM entity type to create.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'contact', 'lead' ),
					'default'     => 'contact',
				),
				'name'          => array(
					'type'        => 'string',
					'description' => __( 'Full name (used when API lookup is unavailable).', 'mcp-ai-wpoos-pro' ),
				),
				'email'         => array(
					'type'        => 'string',
					'description' => __( 'Email address to associate with the contact.', 'mcp-ai-wpoos-pro' ),
				),
				'company'       => array(
					'type'        => 'string',
					'description' => __( 'Company name to associate with the contact.', 'mcp-ai-wpoos-pro' ),
				),
				'title'         => array(
					'type'        => 'string',
					'description' => __( 'Job title for the contact.', 'mcp-ai-wpoos-pro' ),
				),
				'notes'         => array(
					'type'        => 'string',
					'description' => __( 'Additional notes about this contact.', 'mcp-ai-wpoos-pro' ),
				),
				'connection_id' => array(
					'type'        => 'string',
					'description' => __( 'Optional Remote Sites LinkedIn connection ID for API enrichment.', 'mcp-ai-wpoos-pro' ),
				),
			),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * {@inheritdoc}
	 */
	public function requires_base_pro() {
		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'write',
			'state-changing',
			'requires-capability',
			'external-api',
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or WP_Error on failure.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id || ! user_can( $user_id, $this->get_required_capability() ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to import LinkedIn profiles.', 'mcp-ai-wpoos-pro' )
			);
		}

		$import_as = ! empty( $arguments['import_as'] ) ? sanitize_text_field( $arguments['import_as'] ) : 'contact';
		$name      = ! empty( $arguments['name'] ) ? sanitize_text_field( $arguments['name'] ) : '';
		$email     = ! empty( $arguments['email'] ) ? sanitize_email( $arguments['email'] ) : '';
		$company   = ! empty( $arguments['company'] ) ? sanitize_text_field( $arguments['company'] ) : '';
		$title     = ! empty( $arguments['title'] ) ? sanitize_text_field( $arguments['title'] ) : '';
		$notes     = ! empty( $arguments['notes'] ) ? sanitize_textarea_field( $arguments['notes'] ) : '';

		// Attempt API enrichment when a connection is available.
		$enriched = false;
		if ( $this->has_valid_connection( $arguments ) && ! empty( $arguments['linkedin_url'] ) ) {
			$enriched_data = $this->fetch_profile_data( $arguments );
			if ( ! is_wp_error( $enriched_data ) && ! empty( $enriched_data ) ) {
				$name     = ! empty( $enriched_data['name'] ) ? $enriched_data['name'] : $name;
				$title    = ! empty( $enriched_data['headline'] ) ? $enriched_data['headline'] : $title;
				$enriched = true;
			}
		}

		// Build the CRM entity data.
		$entity_data = array(
			'name'       => $name,
			'email'      => $email,
			'company'    => $company,
			'job_title'  => $title,
			'notes'      => $notes,
			'source'     => 'linkedin',
			'source_url' => ! empty( $arguments['linkedin_url'] ) ? esc_url_raw( $arguments['linkedin_url'] ) : '',
		);

		$entity_data = array_filter(
			$entity_data,
			function ( $v ) {
				return ! empty( $v );
			}
		);

		if ( empty( $entity_data ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_input',
				__( 'Please provide at least one data field (name, email, company, title, or LinkedIn URL) to import.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Create the CRM record.
		if ( 'lead' === $import_as && class_exists( 'WP_MCP_AI_Lead_CPT' ) ) {
			$post_id = WP_MCP_AI_Lead_CPT::create( $entity_data );
		} else {
			// Default to creating a contact via the manage_crm_contact tool.
			$post_id = $this->create_contact( $entity_data );
		}

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		return array(
			'success'   => true,
			'import_as' => $import_as,
			'entity_id' => $post_id,
			'enriched'  => $enriched,
			'message'   => $enriched
				? __( 'LinkedIn profile imported and enriched successfully.', 'mcp-ai-wpoos-pro' )
				: __( 'Contact created from LinkedIn data. Connect a LinkedIn account for automatic profile enrichment.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Check whether a valid LinkedIn connection is available.
	 *
	 * @param array $arguments Tool arguments.
	 * @return bool
	 */
	protected function has_valid_connection( $arguments ) {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			return false;
		}

		$connection_id = ! empty( $arguments['connection_id'] )
			? sanitize_text_field( $arguments['connection_id'] )
			: '';

		if ( empty( $connection_id ) && class_exists( 'WP_MCP_AI_CRM_Engine' ) ) {
			$settings      = WP_MCP_AI_CRM_Engine::get_toolkit_settings();
			$connection_id = isset( $settings['external_sourcing']['linkedin']['default_connection_id'] )
				? $settings['external_sourcing']['linkedin']['default_connection_id']
				: '';
		}

		if ( empty( $connection_id ) ) {
			return false;
		}

		$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );

		if ( empty( $connection ) ) {
			return false;
		}

		// If explicitly set to web_search mode, never use the API.
		$mode = isset( $connection['linkedin_mode'] ) ? $connection['linkedin_mode'] : 'api';
		if ( 'web_search' === $mode ) {
			return false;
		}

		return ! empty( $connection['refresh_token'] );
	}

	/**
	 * Fetch LinkedIn profile data via the API.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array|WP_Error Profile data or WP_Error.
	 */
	protected function fetch_profile_data( $arguments ) {
		$connection_id = ! empty( $arguments['connection_id'] )
			? sanitize_text_field( $arguments['connection_id'] )
			: '';

		require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-linkedin-client.php';
		$client = new WP_MCP_AI_LinkedIn_Client( $connection_id );

		return $client->get_me();
	}

	/**
	 * Create a CRM contact record.
	 *
	 * @param array $data Contact data.
	 * @return int|WP_Error Post ID or WP_Error.
	 */
	protected function create_contact( $data ) {
		// Use the manage_crm_contact tool if available.
		if ( class_exists( 'WP_MCP_AI_Tool_Manage_CRM_Contact' ) ) {
			$tool = new WP_MCP_AI_Tool_Manage_CRM_Contact();
			$args = array(
				'action' => 'create',
				'data'   => $data,
			);

			$result = $tool->execute( $args, array( 'user_id' => get_current_user_id() ) );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return isset( $result['id'] ) ? (int) $result['id'] : 0;
		}

		// Fallback: create a basic post.
		$post_data = array(
			'post_type'    => 'post',
			'post_title'   => isset( $data['name'] ) ? $data['name'] : __( 'LinkedIn Import', 'mcp-ai-wpoos-pro' ),
			'post_content' => isset( $data['notes'] ) ? $data['notes'] : '',
			'post_status'  => 'publish',
			'meta_input'   => array(
				'_wp_mcp_ai_contact_email'   => isset( $data['email'] ) ? $data['email'] : '',
				'_wp_mcp_ai_contact_company' => isset( $data['company'] ) ? $data['company'] : '',
				'_wp_mcp_ai_contact_title'   => isset( $data['job_title'] ) ? $data['job_title'] : '',
				'_wp_mcp_ai_contact_source'  => 'linkedin',
				'_wp_mcp_ai_contact_url'     => isset( $data['source_url'] ) ? $data['source_url'] : '',
			),
		);

		return wp_insert_post( $post_data, true );
	}
}
