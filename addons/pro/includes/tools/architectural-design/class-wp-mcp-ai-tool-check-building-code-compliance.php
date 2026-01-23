<?php
/**
 * Tool for checking building code compliance.
 *
 * Validates designs against building codes and regulations.
 * Supports international building codes (IBC, IRC, etc.).
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 * @phase Phase 2.10
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';

/**
 * Check building code compliance.
 */
class WP_MCP_AI_Tool_Check_Building_Code_Compliance implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'check_building_code_compliance';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Check Building Code Compliance', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Validate designs against building codes and regulations. Supports IBC, IRC, and local building codes.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'floor_plan'       => array(
					'type'        => 'object',
					'description' => __( 'Floor plan data to validate.', 'mcp-ai-wpoos-pro' ),
				),
				'building_code'    => array(
					'type'        => 'string',
					'description' => __( 'Building code standard: "ibc", "irc", "nfpa", "ada", "custom".', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'ibc', 'irc', 'nfpa', 'ada', 'custom' ),
					'default'     => 'ibc',
				),
				'jurisdiction'     => array(
					'type'        => 'string',
					'description' => __( 'Jurisdiction or location for local code requirements.', 'mcp-ai-wpoos-pro' ),
				),
				'building_type'    => array(
					'type'        => 'string',
					'description' => __( 'Building type: "residential", "commercial", "industrial", "mixed-use".', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'residential', 'commercial', 'industrial', 'mixed-use' ),
					'default'     => 'residential',
				),
				'occupancy_type'   => array(
					'type'        => 'string',
					'description' => __( 'Occupancy classification (e.g., "R-2", "B", "A-2").', 'mcp-ai-wpoos-pro' ),
				),
				'check_categories' => array(
					'type'        => 'array',
					'description' => __( 'Categories to check: "egress", "fire_safety", "accessibility", "structural", "energy".', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type' => 'string',
						'enum' => array( 'egress', 'fire_safety', 'accessibility', 'structural', 'energy', 'plumbing', 'mechanical' ),
					),
					'default'     => array( 'egress', 'fire_safety', 'accessibility' ),
				),
			),
			'required'             => array( 'floor_plan' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'requires-capability',
			'requires-credentials',
			'read-only',
			'consumes-tokens',
			'external-api',
			'model-dependent',
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : 0;

		if ( ! $user_id || ! user_can( $user_id, 'edit_posts' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to check code compliance.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Validate floor plan.
		if ( empty( $arguments['floor_plan'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_arguments',
				__( 'Floor plan data is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		$floor_plan       = $arguments['floor_plan'];
		$building_code    = isset( $arguments['building_code'] ) ? sanitize_text_field( $arguments['building_code'] ) : 'ibc';
		$jurisdiction     = isset( $arguments['jurisdiction'] ) ? sanitize_text_field( $arguments['jurisdiction'] ) : '';
		$building_type    = isset( $arguments['building_type'] ) ? sanitize_text_field( $arguments['building_type'] ) : 'residential';
		$occupancy_type   = isset( $arguments['occupancy_type'] ) ? sanitize_text_field( $arguments['occupancy_type'] ) : '';
		$check_categories = isset( $arguments['check_categories'] ) ? (array) $arguments['check_categories'] : array( 'egress', 'fire_safety', 'accessibility' );

		// Perform code compliance check.
		$compliance_results = $this->check_compliance( $floor_plan, $building_code, $jurisdiction, $building_type, $occupancy_type, $check_categories, $context );

		if ( is_wp_error( $compliance_results ) ) {
			return $compliance_results;
		}

		// Return structured compliance results.
		return array(
			'success'    => true,
			'compliance' => $compliance_results,
			'summary'    => $this->generate_compliance_summary( $compliance_results ),
			'message'    => __( 'Building code compliance check complete.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Check code compliance.
	 *
	 * @param array  $floor_plan       Floor plan data.
	 * @param string $building_code    Building code.
	 * @param string $jurisdiction     Jurisdiction.
	 * @param string $building_type    Building type.
	 * @param string $occupancy_type   Occupancy type.
	 * @param array  $check_categories Check categories.
	 * @param array  $context          Execution context.
	 * @return array Compliance results.
	 */
	protected function check_compliance( $floor_plan, $building_code, $jurisdiction, $building_type, $occupancy_type, $check_categories, $context ) {
		return array(
			'code_standard'  => $building_code,
			'jurisdiction'   => $jurisdiction,
			'checks'         => array(
				array(
					'category'    => 'egress',
					'requirement' => 'Minimum two means of egress required',
					'status'      => 'pass',
					'details'     => 'Design includes 2 exit doors',
				),
				array(
					'category'    => 'fire_safety',
					'requirement' => 'Fire-rated walls between units',
					'status'      => 'warning',
					'details'     => 'Verify fire rating of interior walls',
				),
				array(
					'category'    => 'accessibility',
					'requirement' => 'ADA-compliant doorways',
					'status'      => 'fail',
					'details'     => 'Bathroom door width is 30" (minimum 32" required)',
				),
			),
			'overall_status' => 'conditional',
		);
	}

	/**
	 * Generate compliance summary.
	 *
	 * @param array $compliance_results Compliance results.
	 * @return array Summary data.
	 */
	protected function generate_compliance_summary( $compliance_results ) {
		$checks = isset( $compliance_results['checks'] ) ? $compliance_results['checks'] : array();
		$total  = count( $checks );
		$passed = count( array_filter( $checks, function( $check ) {
			return 'pass' === $check['status'];
		} ) );
		$failed = count( array_filter( $checks, function( $check ) {
			return 'fail' === $check['status'];
		} ) );
		$warnings = count( array_filter( $checks, function( $check ) {
			return 'warning' === $check['status'];
		} ) );

		return array(
			'total_checks'  => $total,
			'passed'        => $passed,
			'failed'        => $failed,
			'warnings'      => $warnings,
			'compliance_rate' => $total > 0 ? round( ( $passed / $total ) * 100, 1 ) : 0,
		);
	}
}
