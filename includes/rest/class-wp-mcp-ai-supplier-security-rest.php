<?php
/**
 * Supplier Security REST API Controller.
 *
 * Provides REST API endpoints for supplier security management.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Supplier Security REST Controller class.
 */
class WP_MCP_AI_Supplier_Security_REST extends WP_REST_Controller {
	/**
	 * Namespace for API routes.
	 *
	 * @var string
	 */
	protected $namespace = 'mcp-ai/v1';

	/**
	 * REST base for suppliers.
	 *
	 * @var string
	 */
	protected $rest_base = 'suppliers';

	/**
	 * Register REST routes.
	 */
	public function register_routes() {
		// Get all suppliers.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_suppliers' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		// Get single supplier.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[a-zA-Z0-9_-]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_supplier' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'id' => array(
							'description' => __( 'Supplier ID', 'mcp-ai-wpoos' ),
							'type'        => 'string',
							'required'    => true,
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_supplier' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => $this->get_supplier_schema(),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_supplier' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		// Get suppliers by category.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/category/(?P<category>[a-z_]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_suppliers_by_category' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'category' => array(
							'description' => __( 'Supplier category', 'mcp-ai-wpoos' ),
							'type'        => 'string',
							'required'    => true,
							'enum'        => array( 'critical', 'important', 'low_risk' ),
						),
					),
				),
			)
		);

		// Get suppliers by risk level.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/risk/(?P<risk>[a-z]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_suppliers_by_risk' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'risk' => array(
							'description' => __( 'Risk level', 'mcp-ai-wpoos' ),
							'type'        => 'string',
							'required'    => true,
							'enum'        => array( 'low', 'medium', 'high', 'critical' ),
						),
					),
				),
			)
		);

		// Get suppliers due for review.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/reviews/due',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_suppliers_due_for_review' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		// Get supplier statistics.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/statistics',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_statistics' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		// Record supplier incident.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[a-zA-Z0-9_-]+)/incidents',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'record_incident' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'id'          => array(
							'description' => __( 'Supplier ID', 'mcp-ai-wpoos' ),
							'type'        => 'string',
							'required'    => true,
						),
						'title'       => array(
							'description' => __( 'Incident title', 'mcp-ai-wpoos' ),
							'type'        => 'string',
							'required'    => true,
						),
						'description' => array(
							'description' => __( 'Incident description', 'mcp-ai-wpoos' ),
							'type'        => 'string',
							'required'    => true,
						),
						'severity'    => array(
							'description' => __( 'Incident severity', 'mcp-ai-wpoos' ),
							'type'        => 'string',
							'required'    => true,
							'enum'        => array( 'low', 'medium', 'high', 'critical' ),
						),
					),
				),
			)
		);

		// Generate SBOM.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/sbom',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'generate_sbom' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		// Scan dependencies.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/scan',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'scan_dependencies' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);
	}

	/**
	 * Check permission for API access.
	 *
	 * @return bool
	 */
	public function check_permission() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Get all suppliers.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_suppliers( $request ) {
		$supplier_security = WP_MCP_AI_Supplier_Security::get_instance();
		$suppliers         = $supplier_security->get_suppliers();

		return rest_ensure_response( array(
			'success'   => true,
			'suppliers' => array_values( $suppliers ),
			'count'     => count( $suppliers ),
		) );
	}

	/**
	 * Get single supplier.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_supplier( $request ) {
		$supplier_id = sanitize_text_field( $request->get_param( 'id' ) );
		$supplier_security = WP_MCP_AI_Supplier_Security::get_instance();
		$supplier          = $supplier_security->get_supplier( $supplier_id );

		if ( ! $supplier ) {
			return new WP_Error(
				'supplier_not_found',
				__( 'Supplier not found', 'mcp-ai-wpoos' ),
				array( 'status' => 404 )
			);
		}

		return rest_ensure_response( array(
			'success'  => true,
			'supplier' => $supplier,
		) );
	}

	/**
	 * Update supplier.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_supplier( $request ) {
		$supplier_id = sanitize_text_field( $request->get_param( 'id' ) );
		$supplier_security = WP_MCP_AI_Supplier_Security::get_instance();

		// Get sanitized data.
		$data = array();
		$params = $request->get_params();

		// Sanitize each field.
		if ( isset( $params['name'] ) ) {
			$data['name'] = sanitize_text_field( $params['name'] );
		}
		if ( isset( $params['service'] ) ) {
			$data['service'] = sanitize_text_field( $params['service'] );
		}
		if ( isset( $params['category'] ) ) {
			$data['category'] = sanitize_text_field( $params['category'] );
		}
		if ( isset( $params['risk_level'] ) ) {
			$data['risk_level'] = sanitize_text_field( $params['risk_level'] );
		}
		if ( isset( $params['status'] ) ) {
			$data['status'] = sanitize_text_field( $params['status'] );
		}
		if ( isset( $params['next_review'] ) ) {
			$data['next_review'] = sanitize_text_field( $params['next_review'] );
		}

		$success = $supplier_security->upsert_supplier( $supplier_id, $data );

		if ( ! $success ) {
			return new WP_Error(
				'update_failed',
				__( 'Failed to update supplier', 'mcp-ai-wpoos' ),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response( array(
			'success'  => true,
			'supplier' => $supplier_security->get_supplier( $supplier_id ),
			'message'  => __( 'Supplier updated successfully', 'mcp-ai-wpoos' ),
		) );
	}

	/**
	 * Delete supplier.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_supplier( $request ) {
		$supplier_id = sanitize_text_field( $request->get_param( 'id' ) );
		$supplier_security = WP_MCP_AI_Supplier_Security::get_instance();

		$success = $supplier_security->delete_supplier( $supplier_id );

		if ( ! $success ) {
			return new WP_Error(
				'delete_failed',
				__( 'Failed to delete supplier', 'mcp-ai-wpoos' ),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response( array(
			'success' => true,
			'message' => __( 'Supplier deleted successfully', 'mcp-ai-wpoos' ),
		) );
	}

	/**
	 * Get suppliers by category.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_suppliers_by_category( $request ) {
		$category = sanitize_text_field( $request->get_param( 'category' ) );
		$supplier_security = WP_MCP_AI_Supplier_Security::get_instance();
		$suppliers         = $supplier_security->get_suppliers_by_category( $category );

		return rest_ensure_response( array(
			'success'   => true,
			'category'  => $category,
			'suppliers' => array_values( $suppliers ),
			'count'     => count( $suppliers ),
		) );
	}

	/**
	 * Get suppliers by risk level.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_suppliers_by_risk( $request ) {
		$risk = sanitize_text_field( $request->get_param( 'risk' ) );
		$supplier_security = WP_MCP_AI_Supplier_Security::get_instance();
		$suppliers         = $supplier_security->get_suppliers_by_risk( $risk );

		return rest_ensure_response( array(
			'success'   => true,
			'risk'      => $risk,
			'suppliers' => array_values( $suppliers ),
			'count'     => count( $suppliers ),
		) );
	}

	/**
	 * Get suppliers due for review.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_suppliers_due_for_review( $request ) {
		$supplier_security = WP_MCP_AI_Supplier_Security::get_instance();
		$suppliers         = $supplier_security->get_suppliers_due_for_review();

		return rest_ensure_response( array(
			'success'   => true,
			'suppliers' => array_values( $suppliers ),
			'count'     => count( $suppliers ),
		) );
	}

	/**
	 * Get supplier statistics.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_statistics( $request ) {
		$supplier_security = WP_MCP_AI_Supplier_Security::get_instance();
		$stats             = $supplier_security->get_statistics();

		return rest_ensure_response( array(
			'success'    => true,
			'statistics' => $stats,
		) );
	}

	/**
	 * Record supplier incident.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function record_incident( $request ) {
		$supplier_id = sanitize_text_field( $request->get_param( 'id' ) );
		
		$incident = array(
			'title'       => sanitize_text_field( $request->get_param( 'title' ) ),
			'description' => sanitize_textarea_field( $request->get_param( 'description' ) ),
			'severity'    => sanitize_text_field( $request->get_param( 'severity' ) ),
		);

		$supplier_security = WP_MCP_AI_Supplier_Security::get_instance();
		$success           = $supplier_security->record_incident( $supplier_id, $incident );

		if ( ! $success ) {
			return new WP_Error(
				'incident_record_failed',
				__( 'Failed to record incident', 'mcp-ai-wpoos' ),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response( array(
			'success'  => true,
			'message'  => __( 'Incident recorded successfully', 'mcp-ai-wpoos' ),
			'incident' => $incident,
		) );
	}

	/**
	 * Generate SBOM.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function generate_sbom( $request ) {
		$supplier_security = WP_MCP_AI_Supplier_Security::get_instance();
		$sbom              = $supplier_security->generate_sbom();

		return rest_ensure_response( array(
			'success' => true,
			'sbom'    => $sbom,
		) );
	}

	/**
	 * Scan dependencies.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function scan_dependencies( $request ) {
		$supplier_security = WP_MCP_AI_Supplier_Security::get_instance();
		$results           = $supplier_security->scan_dependencies();

		return rest_ensure_response( array(
			'success' => true,
			'results' => $results,
		) );
	}

	/**
	 * Get supplier schema.
	 *
	 * @return array
	 */
	protected function get_supplier_schema() {
		return array(
			'name'        => array(
				'description' => __( 'Supplier name', 'mcp-ai-wpoos' ),
				'type'        => 'string',
			),
			'service'     => array(
				'description' => __( 'Service provided', 'mcp-ai-wpoos' ),
				'type'        => 'string',
			),
			'category'    => array(
				'description' => __( 'Supplier category', 'mcp-ai-wpoos' ),
				'type'        => 'string',
				'enum'        => array( 'critical', 'important', 'low_risk' ),
			),
			'risk_level'  => array(
				'description' => __( 'Risk level', 'mcp-ai-wpoos' ),
				'type'        => 'string',
				'enum'        => array( 'low', 'medium', 'high', 'critical' ),
			),
			'status'      => array(
				'description' => __( 'Assessment status', 'mcp-ai-wpoos' ),
				'type'        => 'string',
				'enum'        => array( 'pending', 'approved', 'rejected', 'reviewing' ),
			),
			'next_review' => array(
				'description' => __( 'Next review date (YYYY-MM-DD)', 'mcp-ai-wpoos' ),
				'type'        => 'string',
				'format'      => 'date',
			),
		);
	}
}

// Initialize REST API.
add_action(
	'rest_api_init',
	function() {
		$controller = new WP_MCP_AI_Supplier_Security_REST();
		$controller->register_routes();
	}
);
