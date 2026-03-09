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
						'type'  => array(
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
							'validate_callback' => function ( $param ) {
								return in_array( $param, array( 'pdf', 'docx', 'excel', 'html' ), true );
							},
						),
						'scope' => array(
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
						'limit' => array(
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

			// Evidence endpoints.
			register_rest_route(
				self::NAMESPACE,
				'/evidence/(?P<control_id>[\\w.-]+)',
				array(
					array(
						'methods'             => 'GET',
						'callback'            => array( $this, 'get_evidence' ),
						'permission_callback' => array( $this, 'check_permission' ),
					),
					array(
						'methods'             => 'POST',
						'callback'            => array( $this, 'add_evidence' ),
						'permission_callback' => array( $this, 'check_pro_permission' ),
					),
				)
			);

			// Audit trail endpoint.
			register_rest_route(
				self::NAMESPACE,
				'/audit-trail',
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_audit_trail' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'limit' => array(
							'default'           => 50,
							'sanitize_callback' => 'absint',
						),
					),
				)
			);

			// Risk matrix data endpoint.
			register_rest_route(
				self::NAMESPACE,
				'/risks/matrix',
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_risk_matrix' ),
					'permission_callback' => array( $this, 'check_permission' ),
				)
			);

			// Compliance checks endpoint.
			register_rest_route(
				self::NAMESPACE,
				'/compliance/checks',
				array(
					array(
						'methods'             => 'GET',
						'callback'            => array( $this, 'get_compliance_checks' ),
						'permission_callback' => array( $this, 'check_permission' ),
					),
					array(
						'methods'             => 'POST',
						'callback'            => array( $this, 'run_compliance_check' ),
						'permission_callback' => array( $this, 'check_pro_permission' ),
						'args'                => array(
							'check_type' => array(
								'required'          => true,
								'sanitize_callback' => 'sanitize_text_field',
							),
						),
					),
				)
			);

			// Update chart data endpoints.
			register_rest_route(
				self::NAMESPACE,
				'/chart-data/risks',
				array(
					array(
						'methods'             => 'GET',
						'callback'            => array( $this, 'get_risk_chart_data' ),
						'permission_callback' => array( $this, 'check_permission' ),
					),
					array(
						'methods'             => 'POST',
						'callback'            => array( $this, 'update_risk_chart_data' ),
						'permission_callback' => array( $this, 'check_pro_permission' ),
						'args'                => array(
							'critical' => array(
								'required'          => true,
								'sanitize_callback' => 'absint',
							),
							'high'     => array(
								'required'          => true,
								'sanitize_callback' => 'absint',
							),
							'medium'   => array(
								'required'          => true,
								'sanitize_callback' => 'absint',
							),
							'low'      => array(
								'required'          => true,
								'sanitize_callback' => 'absint',
							),
						),
					),
				)
			);

			register_rest_route(
				self::NAMESPACE,
				'/chart-data/metrics',
				array(
					array(
						'methods'             => 'GET',
						'callback'            => array( $this, 'get_metrics_chart_data' ),
						'permission_callback' => array( $this, 'check_permission' ),
					),
					array(
						'methods'             => 'POST',
						'callback'            => array( $this, 'update_metrics_chart_data' ),
						'permission_callback' => array( $this, 'check_pro_permission' ),
						'args'                => array(
							'incidents'             => array(
								'required'          => true,
								'validate_callback' => function ( $param ) {
									return is_array( $param ) && count( $param ) === 6;
								},
							),
							'vulnerabilities_fixed' => array(
								'required'          => true,
								'validate_callback' => function ( $param ) {
									return is_array( $param ) && count( $param ) === 6;
								},
							),
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
					__( 'You do not have permission to access this resource.', 'mcp-ai-wpoos' ),
					array( 'status' => 403 )
				);
			}

			// Check for wp-config.php constant first (recommended method).
			$is_pro_active = defined( 'WP_MCP_AI_PRO_DASHBOARD_ENABLED' ) && WP_MCP_AI_PRO_DASHBOARD_ENABLED;

			// Fall back to filter for backward compatibility.
			if ( ! $is_pro_active ) {
				$is_pro_active = apply_filters( 'wp_mcp_ai_pro_dashboard_available', false );
			}

			if ( ! $is_pro_active ) {
				return new WP_Error(
					'pro_required',
					__( 'This feature requires Pro Dashboard to be enabled.', 'mcp-ai-wpoos' ),
					array( 'status' => 403 )
				);
			}

			return true;
		}

		/**
		 * Get compliance status.
		 *
		 * @return WP_REST_Response|WP_Error Response object or error.
		 */
		public function get_compliance_status() {
			// Get actual controls data from Statement of Applicability.
			$controls = $this->get_iso27001_controls();

			// Check if controls were loaded successfully.
			if ( empty( $controls ) ) {
				return new WP_Error(
					'soa_not_found',
					__( 'Statement of Applicability file not found or could not be parsed. Please ensure the file exists at docs/compliance/iso27001/Statement-of-Applicability.md', 'mcp-ai-wpoos' ),
					array( 'status' => 500 )
				);
			}

			$stats = $this->calculate_controls_stats( $controls );

			// Calculate overall percentage.
			$total_applicable = $stats['total'] - $stats['not_applicable'];
			$percentage       = $total_applicable > 0 ? round( ( $stats['implemented'] / $total_applicable ) * 100 ) : 0;

			$status = array(
				'iso27001'     => array(
					'status'      => get_option( 'wp_mcp_ai_iso27001_certified', false ) ? 'certified' : 'compliant',
					'implemented' => $stats['implemented'],
					'partial'     => $stats['partial'],
					'planned'     => $stats['planned'],
					'na'          => $stats['not_applicable'],
					'total'       => $stats['total'],
					'percentage'  => $percentage,
					'cert_date'   => get_option( 'wp_mcp_ai_iso27001_cert_date', '' ),
				),
				'controls'     => array(
					'implemented'    => $stats['implemented'],
					'partial'        => $stats['partial'],
					'planned'        => $stats['planned'],
					'not_applicable' => $stats['not_applicable'],
					'total'          => $stats['total'],
				),
				'metrics'      => $this->get_metrics_data(),
				'risks'        => $this->get_risk_data(),
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

			// Try to get from database first.
			if ( class_exists( 'WP_MCP_AI_Pro_Database' ) ) {
				$args = array();
				if ( ! empty( $category ) ) {
					$args['category'] = $category;
				}
				if ( ! empty( $status ) ) {
					$args['status'] = $status;
				}

				$controls = WP_MCP_AI_Pro_Database::get_controls( $args );

				if ( ! empty( $controls ) ) {
					return rest_ensure_response( $controls );
				}
			}

			// Fallback to sample controls data.
			$controls = $this->get_sample_controls();

			// Filter by category.
			if ( ! empty( $category ) ) {
				$controls = array_filter(
					$controls,
					function ( $control ) use ( $category ) {
						return $control['category'] === $category;
					}
				);
			}

			// Filter by status.
			if ( ! empty( $status ) ) {
				$controls = array_filter(
					$controls,
					function ( $control ) use ( $status ) {
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

			// Use the report generator.
			$generator = new WP_MCP_AI_Report_Generator();
			$result    = $generator->generate_report( $type, $scope );

			if ( ! $result['success'] ) {
				return new WP_Error(
					'report_generation_failed',
					$result['message'],
					array( 'status' => 500 )
				);
			}

			return rest_ensure_response( $result );
		}

		/**
		 * Get risks from risk register.
		 *
		 * @return WP_REST_Response Response object.
		 */
		public function get_risks() {
			// Try to get from database first.
			if ( class_exists( 'WP_MCP_AI_Pro_Database' ) ) {
				$risks = WP_MCP_AI_Pro_Database::get_risks();

				if ( ! empty( $risks ) ) {
					return rest_ensure_response( $risks );
				}
			}

			// Fallback to sample risk data.
			$risks = array(
				array(
					'id'         => 'RISK-001',
					'title'      => 'API Key Exposure',
					'likelihood' => 2,
					'impact'     => 5,
					'score'      => 10,
					'treatment'  => 'reduce',
					'status'     => 'mitigated',
					'owner'      => 'Security Team',
				),
				array(
					'id'         => 'RISK-002',
					'title'      => 'Data Loss',
					'likelihood' => 2,
					'impact'     => 4,
					'score'      => 8,
					'treatment'  => 'reduce',
					'status'     => 'active',
					'owner'      => 'Operations',
				),
				array(
					'id'         => 'RISK-003',
					'title'      => 'Vendor Service Disruption',
					'likelihood' => 3,
					'impact'     => 3,
					'score'      => 9,
					'treatment'  => 'share',
					'status'     => 'active',
					'owner'      => 'DevOps',
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
		 * Get evidence for a control.
		 *
		 * @param WP_REST_Request $request Request object.
		 * @return WP_REST_Response Response object.
		 */
		public function get_evidence( $request ) {
			$control_id = $request->get_param( 'control_id' );

			if ( class_exists( 'WP_MCP_AI_Pro_Database' ) ) {
				$evidence = WP_MCP_AI_Pro_Database::get_evidence( $control_id );
				return rest_ensure_response( $evidence );
			}

			return rest_ensure_response( array() );
		}

		/**
		 * Add evidence for a control.
		 *
		 * @param WP_REST_Request $request Request object.
		 * @return WP_REST_Response|WP_Error Response object or error.
		 */
		public function add_evidence( $request ) {
			$control_id    = $request->get_param( 'control_id' );
			$title         = $request->get_param( 'title' );
			$description   = $request->get_param( 'description' );
			$evidence_type = $request->get_param( 'evidence_type' );

			if ( empty( $title ) ) {
				return new WP_Error(
					'missing_title',
					__( 'Evidence title is required.', 'mcp-ai-wpoos' ),
					array( 'status' => 400 )
				);
			}

			if ( class_exists( 'WP_MCP_AI_Pro_Database' ) ) {
				$evidence_data = array(
					'control_id'    => $control_id,
					'evidence_type' => $evidence_type ?? 'document',
					'title'         => $title,
					'description'   => $description ?? '',
					'uploaded_by'   => get_current_user_id(),
				);

				$evidence_id = WP_MCP_AI_Pro_Database::add_evidence( $evidence_data );

				if ( $evidence_id ) {
					return rest_ensure_response(
						array(
							'success'     => true,
							'evidence_id' => $evidence_id,
							'message'     => __( 'Evidence added successfully.', 'mcp-ai-wpoos' ),
						)
					);
				}
			}

			return new WP_Error(
				'evidence_add_failed',
				__( 'Failed to add evidence.', 'mcp-ai-wpoos' ),
				array( 'status' => 500 )
			);
		}

		/**
		 * Get audit trail.
		 *
		 * @param WP_REST_Request $request Request object.
		 * @return WP_REST_Response Response object.
		 */
		public function get_audit_trail( $request ) {
			$limit = $request->get_param( 'limit' );

			if ( class_exists( 'WP_MCP_AI_Pro_Database' ) ) {
				$audit = WP_MCP_AI_Pro_Database::get_audit_trail( array( 'limit' => $limit ) );
				return rest_ensure_response( $audit );
			}

			// Fallback to recent activity.
			$activity = get_option( 'wp_mcp_ai_recent_activity', array() );
			return rest_ensure_response( array_slice( $activity, 0, $limit ) );
		}

		/**
		 * Get risk matrix data for visualization.
		 *
		 * @return WP_REST_Response Response object.
		 */
		public function get_risk_matrix() {
			// Initialize 5x5 matrix.
			$matrix = array();
			for ( $likelihood = 1; $likelihood <= 5; $likelihood++ ) {
				for ( $impact = 1; $impact <= 5; $impact++ ) {
					$key            = sprintf( '%d-%d', $likelihood, $impact );
					$matrix[ $key ] = array(
						'likelihood' => $likelihood,
						'impact'     => $impact,
						'score'      => $likelihood * $impact,
						'count'      => 0,
						'risks'      => array(),
					);
				}
			}

			// Get all risks.
			if ( class_exists( 'WP_MCP_AI_Pro_Database' ) ) {
				$risks = WP_MCP_AI_Pro_Database::get_risks();
			} else {
				// Fallback sample data.
				$risks = array(
					array(
						'risk_id'    => 'R-001',
						'title'      => 'Unauthorized access',
						'likelihood' => 3,
						'impact'     => 5,
						'risk_level' => 'high',
					),
					array(
						'risk_id'    => 'R-002',
						'title'      => 'Data loss',
						'likelihood' => 2,
						'impact'     => 4,
						'risk_level' => 'medium',
					),
				);
			}

			// Place risks in matrix.
			foreach ( $risks as $risk ) {
				$likelihood = isset( $risk['likelihood'] ) ? (int) $risk['likelihood'] : 3;
				$impact     = isset( $risk['impact'] ) ? (int) $risk['impact'] : 3;
				$key        = sprintf( '%d-%d', $likelihood, $impact );

				if ( isset( $matrix[ $key ] ) ) {
					++$matrix[ $key ]['count'];
					$matrix[ $key ]['risks'][] = array(
						'id'    => $risk['risk_id'],
						'title' => $risk['title'],
						'level' => $risk['risk_level'] ?? 'medium',
					);
				}
			}

			return rest_ensure_response(
				array(
					'matrix' => array_values( $matrix ),
					'totals' => array(
						'total_risks' => count( $risks ),
						'high_risks'  => count(
							array_filter(
								$risks,
								function ( $r ) {
									return ( $r['risk_level'] ?? '' ) === 'high';
								}
							)
						),
					),
				)
			);
		}

		/**
		 * Get compliance checks.
		 *
		 * @return WP_REST_Response Response object.
		 */
		public function get_compliance_checks() {
			if ( class_exists( 'WP_MCP_AI_Pro_Database' ) ) {
				global $wpdb;
				$checks_table = $wpdb->prefix . 'mcp_ai_compliance_checks';
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table or query variable should not be parameterized
				$checks = $wpdb->get_results( "SELECT * FROM $checks_table ORDER BY last_run DESC LIMIT 20", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct query required for performance-critical aggregation on custom plugin table; WP_Query does not support custom table queries of this type. Table name interpolated from $wpdb->prefix-derived constant or validated list; not user input.

				if ( ! empty( $checks ) ) {
					return rest_ensure_response( $checks );
				}
			}

			// Fallback sample data.
			return rest_ensure_response(
				array(
					array(
						'check_type' => 'authentication',
						'check_name' => 'Multi-factor authentication check',
						'status'     => 'completed',
						'result'     => 'pass',
						'score'      => 100,
						'last_run'   => current_time( 'mysql' ),
					),
					array(
						'check_type' => 'encryption',
						'check_name' => 'Data encryption check',
						'status'     => 'completed',
						'result'     => 'pass',
						'score'      => 100,
						'last_run'   => current_time( 'mysql' ),
					),
				)
			);
		}

		/**
		 * Run a compliance check.
		 *
		 * @param WP_REST_Request $request Request object.
		 * @return WP_REST_Response|WP_Error Response object or error.
		 */
		public function run_compliance_check( $request ) {
			$check_type = $request->get_param( 'check_type' );

			if ( ! in_array( $check_type, array( 'authentication', 'encryption', 'logging', 'backup' ), true ) ) {
				return new WP_Error(
					'invalid_check_type',
					__( 'Invalid compliance check type.', 'mcp-ai-wpoos' ),
					array( 'status' => 400 )
				);
			}

			if ( class_exists( 'WP_MCP_AI_Pro_Database' ) ) {
				$check_name = ucfirst( $check_type ) . ' Compliance Check';
				$result     = WP_MCP_AI_Pro_Database::run_compliance_check( $check_type, $check_name );

				return rest_ensure_response( $result );
			}

			return new WP_Error(
				'check_failed',
				__( 'Compliance check system not available.', 'mcp-ai-wpoos' ),
				array( 'status' => 500 )
			);
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

		/**
		 * Get ISO 27001 controls from Statement of Applicability.
		 *
		 * @return array Array of controls with id, name, status, applicable, and justification.
		 */
		private function get_iso27001_controls() {
			// Try embedded data first (always available in production).
			if ( class_exists( 'WP_MCP_AI_Compliance_Data' ) ) {
				$controls = WP_MCP_AI_Compliance_Data::get_iso27001_controls();
				if ( ! empty( $controls ) ) {
					return $controls;
				}
			}

			// Fallback to parsing markdown file (development/debugging).
			$soa_file = WP_MCP_AI_PATH . 'docs/compliance/iso27001/Statement-of-Applicability.md';

			if ( ! file_exists( $soa_file ) ) {
				return array();
			}

			$content = file_get_contents( $soa_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file read; WP_Filesystem not available in this context.
			if ( empty( $content ) ) {
				return array();
			}

			$controls        = array();
			$lines           = explode( "\n", $content );
			$current_control = null;

			foreach ( $lines as $line ) {
				// Match control ID header (e.g., "### A.5.1 Policies for Information Security").
				if ( preg_match( '/^###\s+(A\.\d+\.\d+(?:\.\d+)?)\s+(.+)$/', $line, $matches ) ) {
					// Save previous control if exists.
					if ( $current_control ) {
						$controls[] = $current_control;
					}

					// Start new control.
					$current_control = array(
						'id'            => $matches[1],
						'name'          => trim( $matches[2] ),
						'status'        => '',
						'status_key'    => '',
						'applicable'    => true,
						'justification' => '',
					);
				} elseif ( $current_control && preg_match( '/^\*\*Status:\*\*\s+(.+)$/', $line, $matches ) ) {
					$status_text               = trim( $matches[1] );
					$current_control['status'] = $status_text;

					// Map status to key.
					if ( strpos( $status_text, 'Implemented' ) !== false ) {
						$current_control['status_key'] = 'implemented';
					} elseif ( strpos( $status_text, 'Partial' ) !== false ) {
						$current_control['status_key'] = 'partial';
					} elseif ( strpos( $status_text, 'Planned' ) !== false ) {
						$current_control['status_key'] = 'planned';
					} elseif ( strpos( $status_text, 'Not Applicable' ) !== false ) {
						$current_control['status_key'] = 'not_applicable';
						$current_control['applicable'] = false;
					}
				} elseif ( $current_control && preg_match( '/^\*\*Applicability:\*\*\s+(.+)$/', $line, $matches ) ) {
					$applicable_text               = trim( $matches[1] );
					$current_control['applicable'] = ( strcasecmp( $applicable_text, 'Yes' ) === 0 );
				} elseif ( $current_control && preg_match( '/^\*\*Justification:\*\*\s+(.+)$/', $line, $matches ) ) {
					$current_control['justification'] = trim( $matches[1] );
				}
			}

			// Save last control.
			if ( $current_control ) {
				$controls[] = $current_control;
			}

			return $controls;
		}

		/**
		 * Calculate statistics for controls.
		 *
		 * @param array $controls Array of controls.
		 * @return array Statistics with counts for each status.
		 */
		private function calculate_controls_stats( $controls ) {
			$stats = array(
				'implemented'    => 0,
				'partial'        => 0,
				'planned'        => 0,
				'not_applicable' => 0,
				'total'          => count( $controls ),
			);

			foreach ( $controls as $control ) {
				$status_key = $control['status_key'] ?? '';
				if ( isset( $stats[ $status_key ] ) ) {
					++$stats[ $status_key ];
				}
			}

			return $stats;
		}

		/**
		 * Get risk chart data.
		 *
		 * @return WP_REST_Response Response object.
		 */
		public function get_risk_chart_data() {
			$risk_data = $this->get_risk_data();

			return rest_ensure_response(
				array(
					'success' => true,
					'data'    => $risk_data,
				)
			);
		}

		/**
		 * Update risk chart data.
		 *
		 * @param WP_REST_Request $request Request object.
		 * @return WP_REST_Response|WP_Error Response object or error.
		 */
		public function update_risk_chart_data( $request ) {
			$risk_data = array(
				'critical' => $request->get_param( 'critical' ),
				'high'     => $request->get_param( 'high' ),
				'medium'   => $request->get_param( 'medium' ),
				'low'      => $request->get_param( 'low' ),
			);

			$updated = update_option( 'wp_mcp_ai_risk_data', $risk_data );

			if ( ! $updated ) {
				return new WP_Error(
					'update_failed',
					__( 'Failed to update risk data.', 'mcp-ai-wpoos' ),
					array( 'status' => 500 )
				);
			}

			return rest_ensure_response(
				array(
					'success' => true,
					'message' => __( 'Risk data updated successfully.', 'mcp-ai-wpoos' ),
					'data'    => $risk_data,
				)
			);
		}

		/**
		 * Get metrics chart data.
		 *
		 * @return WP_REST_Response Response object.
		 */
		public function get_metrics_chart_data() {
			$metrics_data = $this->get_metrics_data();

			return rest_ensure_response(
				array(
					'success' => true,
					'data'    => $metrics_data,
				)
			);
		}

		/**
		 * Update metrics chart data.
		 *
		 * @param WP_REST_Request $request Request object.
		 * @return WP_REST_Response|WP_Error Response object or error.
		 */
		public function update_metrics_chart_data( $request ) {
			$incidents             = $request->get_param( 'incidents' );
			$vulnerabilities_fixed = $request->get_param( 'vulnerabilities_fixed' );

			// Sanitize array values.
			$incidents             = array_map( 'absint', $incidents );
			$vulnerabilities_fixed = array_map( 'absint', $vulnerabilities_fixed );

			$metrics_data = array(
				'incidents'             => $incidents,
				'vulnerabilities_fixed' => $vulnerabilities_fixed,
			);

			$updated = update_option( 'wp_mcp_ai_metrics_data', $metrics_data );

			if ( ! $updated ) {
				return new WP_Error(
					'update_failed',
					__( 'Failed to update metrics data.', 'mcp-ai-wpoos' ),
					array( 'status' => 500 )
				);
			}

			return rest_ensure_response(
				array(
					'success' => true,
					'message' => __( 'Metrics data updated successfully.', 'mcp-ai-wpoos' ),
					'data'    => $metrics_data,
				)
			);
		}

		/**
		 * Get risk data for charts.
		 *
		 * Returns risk distribution by severity level.
		 * Data is sourced from WordPress options or defaults to sample data.
		 *
		 * @return array Risk counts by severity.
		 */
		private function get_risk_data() {
			// Try to get from stored option first.
			$stored_risks = get_option( 'wp_mcp_ai_risk_data', false );

			if ( false !== $stored_risks && is_array( $stored_risks ) ) {
				return wp_parse_args(
					$stored_risks,
					array(
						'critical' => 0,
						'high'     => 0,
						'medium'   => 0,
						'low'      => 0,
					)
				);
			}

			// Fallback to sample/default data.
			// These values can be updated via Settings or Pro Dashboard features.
			return array(
				'critical' => 0,
				'high'     => 3,
				'medium'   => 12,
				'low'      => 8,
			);
		}

		/**
		 * Get metrics data for charts.
		 *
		 * Returns security metrics trends over the last 6 months.
		 * Data is sourced from WordPress options or defaults to sample data.
		 *
		 * @return array Metrics data with incidents and vulnerabilities fixed.
		 */
		private function get_metrics_data() {
			// Try to get from stored option first.
			$stored_metrics = get_option( 'wp_mcp_ai_metrics_data', false );

			if ( false !== $stored_metrics && is_array( $stored_metrics ) ) {
				return wp_parse_args(
					$stored_metrics,
					array(
						'incidents'             => array( 0, 0, 0, 0, 0, 0 ),
						'vulnerabilities_fixed' => array( 0, 0, 0, 0, 0, 0 ),
					)
				);
			}

			// Fallback to sample/default data for the last 6 months.
			// These values can be updated via Settings or Pro Dashboard features.
			return array(
				'incidents'             => array( 5, 3, 2, 4, 1, 2 ),
				'vulnerabilities_fixed' => array( 8, 12, 10, 15, 14, 12 ),
			);
		}
	}
}
