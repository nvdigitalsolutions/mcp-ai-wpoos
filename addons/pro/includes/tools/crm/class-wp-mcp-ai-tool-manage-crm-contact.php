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
 * Provides contact management operations using toolkit data store pattern:
 * - Create, read, update, delete contacts
 * - List and search contacts
 * - Validate contact data (email, phone)
 * - Supports both CCT (JetEngine) and CPT storage backends
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Manage_CRM_Contact implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Data store instance.
	 *
	 * @var WP_MCP_AI_Toolkit_Data_Store
	 */
	private $data_store;

	/**
	 * Constructor.
	 */
	public function __construct() {
		// Initialize data store using factory pattern.
		if ( class_exists( 'WP_MCP_AI_Toolkit_Data_Store_Factory' ) ) {
			$this->data_store = WP_MCP_AI_Toolkit_Data_Store_Factory::get_store( 'crm', 'contacts' );
		}
	}

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
		return __( 'Comprehensive CRM contact management. Create, read, update, delete, list, and search contacts. Includes email/phone validation and CCT/CPT storage support.', 'mcp-ai-wpoos-pro' );
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
				'per_page'     => array(
					'type'        => 'integer',
					'description' => __( 'Results per page (for list/search)', 'mcp-ai-wpoos-pro' ),
					'default'     => 20,
				),
				'page'         => array(
					'type'        => 'integer',
					'description' => __( 'Page number (for list/search)', 'mcp-ai-wpoos-pro' ),
					'default'     => 1,
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
		// Check if data store is available.
		if ( ! $this->data_store ) {
			return array(
				'success' => false,
				'error'   => __( 'CRM data store not available. Please ensure toolkit is enabled.', 'mcp-ai-wpoos-pro' ),
			);
		}

		$action = isset( $arguments['action'] ) ? sanitize_text_field( $arguments['action'] ) : '';

		switch ( $action ) {
			case 'create':
				return $this->create_contact( $arguments );

			case 'read':
				return $this->read_contact( $arguments );

			case 'update':
				return $this->update_contact( $arguments );

			case 'delete':
				return $this->delete_contact( $arguments );

			case 'list':
				return $this->list_contacts( $arguments );

			case 'search':
				return $this->search_contacts( $arguments );

			default:
				return array(
					'success' => false,
					'error'   => __( 'Invalid action. Must be one of: create, read, update, delete, list, search.', 'mcp-ai-wpoos-pro' ),
				);
		}
	}

	/**
	 * Create a new contact.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array Result.
	 */
	private function create_contact( $arguments ) {
		if ( empty( $arguments['contact_data'] ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Contact data is required.', 'mcp-ai-wpoos-pro' ),
			);
		}

		$contact_data = $arguments['contact_data'];

		// Validate required fields.
		if ( empty( $contact_data['email'] ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Email is required.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Validate using validator service.
		require_once WP_MCP_AI_PRO_PATH . 'includes/services/class-wp-mcp-ai-validator-service.php';
		$validator = new WP_MCP_AI_Validator_Service();

		$email_valid = $validator->is_email( $contact_data['email'] );
		if ( is_wp_error( $email_valid ) ) {
			return array(
				'success' => false,
				'error'   => $email_valid->get_error_message(),
			);
		}

		// Create contact using data store.
		$contact_id = $this->data_store->create_item( $contact_data );

		if ( is_wp_error( $contact_id ) ) {
			return array(
				'success' => false,
				'error'   => $contact_id->get_error_message(),
			);
		}

		return array(
			'success'      => true,
			'message'      => __( 'Contact created successfully.', 'mcp-ai-wpoos-pro' ),
			'contact_id'   => $contact_id,
			'storage_type' => $this->data_store->get_storage_type(),
		);
	}

	/**
	 * Read contact data.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array Result.
	 */
	private function read_contact( $arguments ) {
		if ( empty( $arguments['contact_id'] ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Contact ID is required.', 'mcp-ai-wpoos-pro' ),
			);
		}

		$contact_id = absint( $arguments['contact_id'] );
		$contact    = $this->data_store->get_item( $contact_id );

		if ( is_wp_error( $contact ) ) {
			return array(
				'success' => false,
				'error'   => $contact->get_error_message(),
			);
		}

		return array(
			'success'      => true,
			'contact'      => $contact,
			'storage_type' => $this->data_store->get_storage_type(),
		);
	}

	/**
	 * Update contact data.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array Result.
	 */
	private function update_contact( $arguments ) {
		if ( empty( $arguments['contact_id'] ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Contact ID is required.', 'mcp-ai-wpoos-pro' ),
			);
		}

		if ( empty( $arguments['contact_data'] ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Contact data is required.', 'mcp-ai-wpoos-pro' ),
			);
		}

		$contact_id   = absint( $arguments['contact_id'] );
		$contact_data = $arguments['contact_data'];

		// Validate if email provided.
		if ( isset( $contact_data['email'] ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/services/class-wp-mcp-ai-validator-service.php';
			$validator   = new WP_MCP_AI_Validator_Service();
			$email_valid = $validator->is_email( $contact_data['email'] );
			if ( is_wp_error( $email_valid ) ) {
				return array(
					'success' => false,
					'error'   => $email_valid->get_error_message(),
				);
			}
		}

		// Update using data store.
		$result = $this->data_store->update_item( $contact_id, $contact_data );

		if ( is_wp_error( $result ) ) {
			return array(
				'success' => false,
				'error'   => $result->get_error_message(),
			);
		}

		return array(
			'success'      => true,
			'message'      => __( 'Contact updated successfully.', 'mcp-ai-wpoos-pro' ),
			'storage_type' => $this->data_store->get_storage_type(),
		);
	}

	/**
	 * Delete contact.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array Result.
	 */
	private function delete_contact( $arguments ) {
		if ( empty( $arguments['contact_id'] ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Contact ID is required.', 'mcp-ai-wpoos-pro' ),
			);
		}

		$contact_id = absint( $arguments['contact_id'] );
		$result     = $this->data_store->delete_item( $contact_id );

		if ( is_wp_error( $result ) ) {
			return array(
				'success' => false,
				'error'   => $result->get_error_message(),
			);
		}

		return array(
			'success'      => true,
			'message'      => __( 'Contact deleted successfully.', 'mcp-ai-wpoos-pro' ),
			'storage_type' => $this->data_store->get_storage_type(),
		);
	}

	/**
	 * List contacts.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array Result.
	 */
	private function list_contacts( $arguments ) {
		$per_page = isset( $arguments['per_page'] ) ? absint( $arguments['per_page'] ) : 20;
		$page     = isset( $arguments['page'] ) ? absint( $arguments['page'] ) : 1;

		$query_args = array(
			'per_page' => $per_page,
			'page'     => $page,
		);

		$contacts = $this->data_store->query_items( $query_args );

		return array(
			'success'      => true,
			'contacts'     => $contacts,
			'per_page'     => $per_page,
			'page'         => $page,
			'storage_type' => $this->data_store->get_storage_type(),
		);
	}

	/**
	 * Search contacts.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array Result.
	 */
	private function search_contacts( $arguments ) {
		if ( empty( $arguments['search_query'] ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Search query is required.', 'mcp-ai-wpoos-pro' ),
			);
		}

		$per_page = isset( $arguments['per_page'] ) ? absint( $arguments['per_page'] ) : 20;
		$page     = isset( $arguments['page'] ) ? absint( $arguments['page'] ) : 1;

		$query_args = array(
			'search'   => sanitize_text_field( $arguments['search_query'] ),
			'per_page' => $per_page,
			'page'     => $page,
		);

		$contacts = $this->data_store->query_items( $query_args );

		return array(
			'success'       => true,
			'contacts'      => $contacts,
			'search_query'  => $arguments['search_query'],
			'per_page'      => $per_page,
			'page'          => $page,
			'storage_type'  => $this->data_store->get_storage_type(),
		);
	}
}

