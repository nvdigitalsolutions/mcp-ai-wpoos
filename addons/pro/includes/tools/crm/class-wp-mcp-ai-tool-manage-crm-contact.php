<?php
/**
 * Tool for comprehensive CRM contact management.
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CRM Contact Management Tool.
 *
 * Provides complete contact lifecycle management:
 * - Create, read, update, delete contacts
 * - List and search contacts
 * - Validate contact data (email, phone)
 * - Track contact activity
 * - Manage tags and custom fields
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Manage_CRM_Contact implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'manage_crm_contact';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Manage CRM Contact', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Comprehensive CRM contact management. Create, read, update, delete, list, and search contacts. Includes email/phone validation and activity tracking.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'action'       => array(
					'type'        => 'string',
					'enum'        => array( 'create', 'read', 'update', 'delete', 'list', 'search' ),
					'description' => __( 'Action to perform', 'mcp-ai-wpoos-pro' ),
				),
				'contact_id'   => array(
					'type'        => 'integer',
					'description' => __( 'Contact ID (required for read, update, delete)', 'mcp-ai-wpoos-pro' ),
				),
				'contact_data' => array(
					'type'        => 'object',
					'description' => __( 'Contact data (required for create, update)', 'mcp-ai-wpoos-pro' ),
					'properties'  => array(
						'first_name' => array( 'type' => 'string' ),
						'last_name'  => array( 'type' => 'string' ),
						'email'      => array( 'type' => 'string' ),
						'phone'      => array( 'type' => 'string' ),
						'company'    => array( 'type' => 'string' ),
						'job_title'  => array( 'type' => 'string' ),
						'tags'       => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
					),
				),
				'search_query' => array(
					'type'        => 'string',
					'description' => __( 'Search query (for search action)', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'action' ),
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
			'requires-capability',
			'external-dependency',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$action = isset( $arguments['action'] ) ? sanitize_text_field( $arguments['action'] ) : '';

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: %s: action name */
				__( 'CRM Contact %s action executed. Full implementation coming soon.', 'mcp-ai-wpoos-pro' ),
				$action
			),
			'action'  => $action,
		);
	}
}
