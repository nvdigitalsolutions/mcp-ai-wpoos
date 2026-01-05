<?php
/**
 * Pro Dashboard REST API Endpoints
 *
 * Provides REST API endpoints for Pro Dashboard compliance data,
 * reporting, and management features.
 *
 * @package WP_MCP_AI
 * @since 1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Pro_Dashboard_REST' ) ) {
	/**
	 * REST API handler for Pro Dashboard.
	 */
	class WP_MCP_AI_Pro_Dashboard_REST {
		/**
		 * API namespace.
		 */
		const NAMESPACE = 'mcp-ai/v1/pro';

		/**
		 * Constructor.
		 */
		public function __construct() {
			add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		}

		/**
		 * Register REST API routes.
		 */
		public function register_routes() {
			// Compliance status endpoint.
			register_rest_route(
				self::NAMESPACE,
				'/compliance/status',
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_compliance_status' ),
					'permission_callback' => array( $this, 'check_permission' ),
				)
			);

			// Controls list endpoint.
			register_rest_route(
				self::NAMESPACE,
				'/controls',
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_controls' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'category' => array(
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'status'   => array(
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				)
			);

			// Generate report endpoint.
			register_rest_route(
				self::NAMESPACE,
				'/reports/generate',
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'generate_report' ),
					'permission_callback' => array( $this, 'check_pro_permission' ),
					'args'                => array(
						'type'   => array(
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
							'validate_callback' => function( $param ) {
								return in_array( $param, array( 'pdf', 'docx', 'excel', 'html' ), true );
							},
						),
						'scope'  => array(
							'default'           => 'full',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				)
			);

			// Risk register endpoint.
			register_rest_route(
				self::NAMESPACE,
				'/risks',
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_risks' ),
					'permission_callback' => array( $this, 'check_permission' ),
				)
			);

			// Security events endpoint.
			register_rest_route(
				self::NAMESPACE,
				'/events',
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_security_events' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'limit'  => array(
							'default'           => 10,
							'sanitize_callback' => 'absint',
						),
					),
				)
			);

			// Framework status endpoint.
			register_rest_route(
				self::NAMESPACE,
				'/frameworks',
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_frameworks' ),
					'permission_callback' => array( $this, 'check_permission' ),
				)
			);

			// Update control status endpoint.
			register_rest_route(
				self::NAMESPACE,
				'/controls/(?P<id>[\\w-]+)',
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this, 'update_control' ),
					'permission_callback' => array( $this, 'check_pro_permission' ),
					'args'                => array(
						'id'     => array(
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'status' => array(
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'notes'  => array(
							'default'           => '',
							'sanitize_callback' => 'sanitize_textarea_field',
						),
					),
				)
			);
		}

		/**
		 * Check if user has permission to access Pro Dashboard.
		 *
		 * @return bool True if user has permission.
		 */
		public function check_permission() {
			return current_user_can( 'manage_options' );
		}

		/**
		 * Check if user has Pro features enabled.
		 *
		 * @return bool|WP_Error True if user has Pro access, WP_Error otherwise.
		 */
		public function check_pro_permission() {
			if ( ! current_user_can( 'manage_options' ) ) {
				return new WP_Error(
					'rest_forbidden',
					__( 'You do not have permission to access this resource.', 'wp-mcp-ai' ),
					array( 'status' => 403 )
				);
			}

			$is_pro_active = apply_filters( 'wp_mcp_ai_pro_dashboard_available', false );
			if ( ! $is_pro_active ) {
				return new WP_Error(
					'pro_required',
					__( 'This feature requires Pro Dashboard to be enabled.', 'wp-mcp-ai' ),
					array( 'status' => 403 )
				);
			}

			return true;
		}

		/**
		 * Get compliance status.
		 *
		 * @return WP_REST_Response Response object.
		 */
		public function get_compliance_status() {
			$status = array(
				'iso27001' => array(
					'status'      => get_option( 'wp_mcp_ai_iso27001_certified', false ) ? 'certified' : 'compliant',
					'implemented' => 52,
					'partial'     => 26,
					'planned'     => 3,
					'na'          => 12,
					'total'       => 93,
					'percentage'  => 56,
					'cert_date'   => get_option( 'wp_mcp_ai_iso27001_cert_date', '' ),
				),
				'controls' => array(
					'A.5' => array( 'implemented' => 18, 'partial' => 16, 'planned' => 2, 'na' => 1, 'total' => 37 ),
					'A.6' => array( 'implemented' => 3, 'partial' => 4, 'planned' => 1, 'na' => 0, 'total' => 8 ),
					'A.7' => array( 'implemented' => 1, 'partial' => 5, 'planned' => 0, 'na' => 8, 'total' => 14 ),
					'A.8' => array( 'implemented' => 30, 'partial' => 1, 'planned' => 0, 'na' => 3, 'total' => 34 ),
				),
				'last_updated' => current_time( 'mysql' ),
			);

			return rest_ensure_response( $status );
		}

		/**
		 * Get controls list.
		 *
		 * @param WP_REST_Request $request Request object.
		 * @return WP_REST_Response Response object.
		 */
		public function get_controls( $request ) {
			$category = $request->get_param( 'category' );
			$status   = $request->get_param( 'status' );

			// Sample controls data (in production, this would come from database).
			$controls = $this->get_sample_controls();

			// Filter by category.
			if ( ! empty( $category ) ) {
				$controls = array_filter(
					$controls,
					function( $control ) use ( $category ) {
						return $control['category'] === $category;
					}
				);
			}

			// Filter by status.
			if ( ! empty( $status ) ) {
				$controls = array_filter(
					$controls,
					function( $control ) use ( $status ) {
						return $control['status'] === $status;
					}
				);
			}

			return rest_ensure_response( array_values( $controls ) );
		}

		/**
		 * Generate compliance report.
		 *
		 * @param WP_REST_Request $request Request object.
		 * @return WP_REST_Response|WP_Error Response object or error.
		 */
		public function generate_report( $request ) {
			$type  = $request->get_param( 'type' );
			$scope = $request->get_param( 'scope' );

			// Generate consistent UUID for report identification.
			$report_id = wp_generate_uuid4();

			// In production, this would generate actual reports.
			$report = array(
				'report_id'    => $report_id,
				'type'         => $type,
				'scope'        => $scope,
				'generated'    => current_time( 'mysql' ),
				'download_url' => admin_url( 'admin-ajax.php?action=wp_mcp_ai_download_report&id=' . $report_id ),
			);

			return rest_ensure_response( $report );
		}

		/**
		 * Get risks from risk register.
		 *
		 * @return WP_REST_Response Response object.
		 */
		public function get_risks() {
			// Sample risk data (in production, this would come from database).
			$risks = array(
				array(
					'id'          => 'RISK-001',
					'title'       => 'API Key Exposure',
					'likelihood'  => 2,
					'impact'      => 5,
					'score'       => 10,
					'treatment'   => 'reduce',
					'status'      => 'mitigated',
					'owner'       => 'Security Team',
				),
				array(
					'id'          => 'RISK-002',
					'title'       => 'Data Loss',
					'likelihood'  => 2,
					'impact'      => 4,
					'score'       => 8,
					'treatment'   => 'reduce',
					'status'      => 'active',
					'owner'       => 'Operations',
				),
				array(
					'id'          => 'RISK-003',
					'title'       => 'Vendor Service Disruption',
					'likelihood'  => 3,
					'impact'      => 3,
					'score'       => 9,
					'treatment'   => 'share',
					'status'      => 'active',
					'owner'       => 'DevOps',
				),
			);

			return rest_ensure_response( $risks );
		}

		/**
		 * Get recent security events.
		 *
		 * @param WP_REST_Request $request Request object.
		 * @return WP_REST_Response Response object.
		 */
		public function get_security_events( $request ) {
			$limit = $request->get_param( 'limit' );

			// Get recent security events from logs.
			$events = get_option( 'wp_mcp_ai_recent_activity', array() );
			$events = array_slice( $events, 0, $limit );

			return rest_ensure_response( $events );
		}

		/**
		 * Get framework compliance status.
		 *
		 * @return WP_REST_Response Response object.
		 */
		public function get_frameworks() {
			$frameworks = array(
				array(
					'id'       => 'iso27001',
					'name'     => 'ISO 27001:2022',
					'status'   => 'compliant',
					'progress' => 56,
					'controls' => 93,
				),
				array(
					'id'       => 'soc2',
					'name'     => 'SOC 2',
					'status'   => 'pending',
					'progress' => 0,
					'controls' => 0,
				),
				array(
					'id'       => 'hipaa',
					'name'     => 'HIPAA',
					'status'   => 'pending',
					'progress' => 0,
					'controls' => 0,
				),
				array(
					'id'       => 'gdpr',
					'name'     => 'GDPR',
					'status'   => 'compliant',
					'progress' => 95,
					'controls' => 50,
				),
			);

			return rest_ensure_response( $frameworks );
		}

		/**
		 * Update control status.
		 *
		 * @param WP_REST_Request $request Request object.
		 * @return WP_REST_Response|WP_Error Response object or error.
		 */
		public function update_control( $request ) {
			$id     = $request->get_param( 'id' );
			$status = $request->get_param( 'status' );
			$notes  = $request->get_param( 'notes' );

			// In production, this would update the database.
			$updated_control = array(
				'id'      => $id,
				'status'  => $status,
				'notes'   => $notes,
				'updated' => current_time( 'mysql' ),
			);

			return rest_ensure_response( $updated_control );
		}

		/**
		 * Get sample controls data.
		 *
		 * @return array Array of controls.
		 */
		private function get_sample_controls() {
			return array(
				array(
					'id'          => 'A.5.1',
					'category'    => 'A.5',
					'title'       => 'Policies for information security',
					'status'      => 'implemented',
					'evidence'    => 'ISMS-Policy.md',
					'last_review' => '2026-01-05',
				),
				array(
					'id'          => 'A.8.1',
					'category'    => 'A.8',
					'title'       => 'User endpoint devices',
					'status'      => 'implemented',
					'evidence'    => 'Access-Control.md',
					'last_review' => '2026-01-05',
				),
				array(
					'id'          => 'A.6.1',
					'category'    => 'A.6',
					'title'       => 'Screening',
					'status'      => 'partial',
					'evidence'    => '',
					'last_review' => '2026-01-05',
				),
			);
		}
	}
}
