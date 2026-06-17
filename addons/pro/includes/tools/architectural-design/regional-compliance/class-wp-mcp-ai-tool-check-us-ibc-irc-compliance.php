<?php
/**
 * Tool for checking US IBC / IRC compliance.
 *
 * Dispatches to the appropriate US code pack (IBC for commercial / multi-
 * family, IRC for 1-2 family dwellings) and evaluates egress, fire safety,
 * accessibility (ADA), structural and energy requirements.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.3.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';

/**
 * Check US IBC/IRC compliance.
 */
class WP_MCP_AI_Tool_Check_US_IBC_IRC_Compliance implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/* WP_MCP_AI_AVAILABILITY_BLOCK */
	/**
	 * Whether this tool is available for registration.
	 *
	 * @since 1.3.0
	 * @return bool
	 */
	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_architectural_design_toolkit'] );
	}

	/**
	 * Reason this tool is unavailable, if any.
	 *
	 * @since 1.3.0
	 * @return string
	 */
	public static function get_unavailable_reason() {
		return __( 'Architectural Design toolkit is not enabled.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'check_us_ibc_irc_compliance';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Check US IBC / IRC Compliance', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Validate a US project against the appropriate ICC code (IBC for commercial / multi-family, IRC for 1-2 family dwellings) plus IECC 2024 envelope minima and ADA 2010 accessibility.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'code_path'    => array(
					'type'        => 'string',
					'description' => __( 'Which ICC code path to evaluate: "ibc" (commercial / multi-family), "irc" (1-2 family dwelling), or "auto".', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'ibc', 'irc', 'auto' ),
					'default'     => 'auto',
				),
				'jurisdiction' => array(
					'type'        => 'string',
					'description' => __( 'State / city for record (e.g. "FL", "CA-Los Angeles").', 'mcp-ai-wpoos-pro' ),
				),
				'climate_zone' => array(
					'type'        => 'string',
					'description' => __( 'IECC climate zone (1A through 8). Influences envelope U-values.', 'mcp-ai-wpoos-pro' ),
				),
				'building'     => array(
					'type'        => 'object',
					'description' => __( 'Building geometry & occupancy.', 'mcp-ai-wpoos-pro' ),
					'properties'  => array(
						'occupancy_classification'    => array(
							'type'        => 'string',
							'description' => __( 'IBC occupancy: A, B, E, F, H, I, M, R-1, R-2, R-3, S, U.', 'mcp-ai-wpoos-pro' ),
						),
						'building_height_m'           => array( 'type' => 'number' ),
						'num_storeys'                 => array( 'type' => 'integer' ),
						'num_dwelling_units'          => array( 'type' => 'integer' ),
						'corridor_width_m'            => array( 'type' => 'number' ),
						'stair_width_m'               => array( 'type' => 'number' ),
						'travel_distance_m'           => array( 'type' => 'number' ),
						'min_door_clear_width_mm'     => array( 'type' => 'number' ),
						'sprinklered'                 => array( 'type' => 'boolean' ),
						'smoke_alarms'                => array( 'type' => 'boolean' ),
						'co_alarms'                   => array( 'type' => 'boolean' ),
						'envelope_u_value_wall_w_m2k' => array( 'type' => 'number' ),
						'envelope_u_value_roof_w_m2k' => array( 'type' => 'number' ),
					),
				),
			),
			'required'             => array( 'building' ),
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
			'read-only',
			'cacheable',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : 0;
		if ( ! $user_id || ! user_can( $user_id, 'edit_posts' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to check US IBC/IRC compliance.', 'mcp-ai-wpoos-pro' )
			);
		}
		if ( ! class_exists( 'WP_MCP_AI_Architectural_Codes' ) ) {
			return new WP_Error( 'wp_mcp_ai_engine_missing', __( 'Architectural engine is unavailable.', 'mcp-ai-wpoos-pro' ) );
		}

		$code_path    = isset( $arguments['code_path'] ) ? sanitize_text_field( $arguments['code_path'] ) : 'auto';
		$jurisdiction = isset( $arguments['jurisdiction'] ) ? sanitize_text_field( $arguments['jurisdiction'] ) : '';
		$climate_zone = isset( $arguments['climate_zone'] ) ? sanitize_text_field( $arguments['climate_zone'] ) : '';
		$building     = isset( $arguments['building'] ) ? (array) $arguments['building'] : array();

		$occupancy   = isset( $building['occupancy_classification'] ) ? strtoupper( sanitize_text_field( $building['occupancy_classification'] ) ) : '';
		$num_storeys = isset( $building['num_storeys'] ) ? absint( $building['num_storeys'] ) : 0;
		$dwelling    = isset( $building['num_dwelling_units'] ) ? absint( $building['num_dwelling_units'] ) : 0;

		// Auto-select IRC vs IBC.
		if ( 'auto' === $code_path ) {
			$is_irc = ( 'R-3' === $occupancy && $dwelling > 0 && $dwelling <= 2 && $num_storeys <= 3 );
			// IRC scope: detached 1-2 family dwellings and townhouses ≤ 3 stories.
			$code_path = $is_irc ? 'irc' : 'ibc';
		}

		$packs = array();
		if ( 'irc' === $code_path ) {
			$packs[] = 'us_irc_2024';
		} else {
			$packs[] = 'us_ibc_2024';
			$packs[] = 'us_nfpa_101';
			$packs[] = 'us_ada_2010';
		}
		$packs[] = 'us_iecc_2024';
		$packs[] = 'us_asce_7_22';

		$rules = WP_MCP_AI_Architectural_Codes::merge_rules( $packs );

		$checks = array();

		// Egress.
		$egress        = isset( $rules['egress'] ) ? $rules['egress'] : array();
		$min_corridor  = isset( $egress['min_corridor_width_m'] ) ? floatval( $egress['min_corridor_width_m'] ) : 0.0;
		$plan_corridor = isset( $building['corridor_width_m'] ) ? floatval( $building['corridor_width_m'] ) : 0.0;
		if ( $min_corridor > 0 && 'ibc' === $code_path ) {
			$status   = ( $plan_corridor <= 0 ) ? 'warning' : ( ( $plan_corridor + 1e-6 >= $min_corridor ) ? 'pass' : 'fail' );
			$checks[] = $this->mk( 'egress', sprintf( __( 'Minimum corridor width %.3f m (44").', 'mcp-ai-wpoos-pro' ), $min_corridor ), $status, $plan_corridor > 0 ? sprintf( __( 'Provided: %.2f m.', 'mcp-ai-wpoos-pro' ), $plan_corridor ) : __( 'Corridor width not provided.', 'mcp-ai-wpoos-pro' ) );
		}

		$min_stair  = isset( $egress['min_stair_width_m'] ) ? floatval( $egress['min_stair_width_m'] ) : 0.0;
		$plan_stair = isset( $building['stair_width_m'] ) ? floatval( $building['stair_width_m'] ) : 0.0;
		if ( $min_stair > 0 ) {
			$status   = ( $plan_stair <= 0 ) ? 'warning' : ( ( $plan_stair + 1e-6 >= $min_stair ) ? 'pass' : 'fail' );
			$checks[] = $this->mk( 'egress', sprintf( __( 'Minimum stair width %.3f m.', 'mcp-ai-wpoos-pro' ), $min_stair ), $status, $plan_stair > 0 ? sprintf( __( 'Provided: %.2f m.', 'mcp-ai-wpoos-pro' ), $plan_stair ) : __( 'Stair width not provided.', 'mcp-ai-wpoos-pro' ) );
		}

		$max_travel  = isset( $egress['max_travel_distance_m'] ) ? floatval( $egress['max_travel_distance_m'] ) : 0.0;
		$plan_travel = isset( $building['travel_distance_m'] ) ? floatval( $building['travel_distance_m'] ) : 0.0;
		if ( $max_travel > 0 && 'ibc' === $code_path ) {
			$status   = ( $plan_travel <= 0 ) ? 'warning' : ( ( $plan_travel <= $max_travel + 1e-6 ) ? 'pass' : 'fail' );
			$checks[] = $this->mk( 'egress', sprintf( __( 'Maximum travel distance %.1f m.', 'mcp-ai-wpoos-pro' ), $max_travel ), $status, $plan_travel > 0 ? sprintf( __( 'Provided: %.1f m.', 'mcp-ai-wpoos-pro' ), $plan_travel ) : __( 'Travel distance not provided.', 'mcp-ai-wpoos-pro' ) );
		}

		// Accessibility (IBC + ADA — IRC is less strict).
		if ( 'ibc' === $code_path ) {
			$access    = isset( $rules['accessibility'] ) ? $rules['accessibility'] : array();
			$min_door  = isset( $access['min_door_clear_width_mm'] ) ? floatval( $access['min_door_clear_width_mm'] ) : 0.0;
			$plan_door = isset( $building['min_door_clear_width_mm'] ) ? floatval( $building['min_door_clear_width_mm'] ) : 0.0;
			if ( $min_door > 0 ) {
				$status   = ( $plan_door <= 0 ) ? 'warning' : ( ( $plan_door + 1e-6 >= $min_door ) ? 'pass' : 'fail' );
				$checks[] = $this->mk( 'accessibility', sprintf( __( 'Minimum door clear width %.0f mm (32").', 'mcp-ai-wpoos-pro' ), $min_door ), $status, $plan_door > 0 ? sprintf( __( 'Provided: %.0f mm.', 'mcp-ai-wpoos-pro' ), $plan_door ) : __( 'Door clear width not provided.', 'mcp-ai-wpoos-pro' ) );
			}
		}

		// Fire safety.
		$fire         = isset( $rules['fire_safety'] ) ? $rules['fire_safety'] : array();
		$plan_height  = isset( $building['building_height_m'] ) ? floatval( $building['building_height_m'] ) : 0.0;
		$sprinkler_at = isset( $fire['sprinkler_above_m'] ) ? floatval( $fire['sprinkler_above_m'] ) : 0.0;
		if ( $sprinkler_at > 0 && $plan_height > 0 && 'ibc' === $code_path ) {
			if ( $plan_height >= $sprinkler_at ) {
				$has      = ! empty( $building['sprinklered'] );
				$checks[] = $this->mk( 'fire_safety', sprintf( __( 'Sprinklers required for buildings ≥ %.1f m (55 ft).', 'mcp-ai-wpoos-pro' ), $sprinkler_at ), $has ? 'pass' : 'fail', $has ? __( 'Sprinkler system declared.', 'mcp-ai-wpoos-pro' ) : __( 'Provide a sprinkler system per IBC §403.', 'mcp-ai-wpoos-pro' ) );
			}
		}
		if ( 'irc' === $code_path ) {
			$smoke    = ! empty( $building['smoke_alarms'] );
			$co       = ! empty( $building['co_alarms'] );
			$checks[] = $this->mk( 'fire_safety', __( 'Smoke alarms required (IRC R314).', 'mcp-ai-wpoos-pro' ), $smoke ? 'pass' : 'fail', $smoke ? '' : __( 'Provide hardwired interconnected smoke alarms.', 'mcp-ai-wpoos-pro' ) );
			$checks[] = $this->mk( 'fire_safety', __( 'CO alarms required (IRC R315).', 'mcp-ai-wpoos-pro' ), $co ? 'pass' : 'fail', $co ? '' : __( 'Provide CO alarms outside each sleeping area.', 'mcp-ai-wpoos-pro' ) );
		}

		// Energy / IECC envelope.
		$energy      = isset( $rules['energy'] ) ? $rules['energy'] : array();
		$max_u_wall  = isset( $energy['envelope_u_value_max_wall_w_m2k'] ) ? floatval( $energy['envelope_u_value_max_wall_w_m2k'] ) : 0.0;
		$max_u_roof  = isset( $energy['envelope_u_value_max_roof_w_m2k'] ) ? floatval( $energy['envelope_u_value_max_roof_w_m2k'] ) : 0.0;
		$plan_u_wall = isset( $building['envelope_u_value_wall_w_m2k'] ) ? floatval( $building['envelope_u_value_wall_w_m2k'] ) : 0.0;
		$plan_u_roof = isset( $building['envelope_u_value_roof_w_m2k'] ) ? floatval( $building['envelope_u_value_roof_w_m2k'] ) : 0.0;
		if ( $max_u_wall > 0 && $plan_u_wall > 0 ) {
			$status   = ( $plan_u_wall <= $max_u_wall + 1e-6 ) ? 'pass' : 'fail';
			$checks[] = $this->mk( 'energy', sprintf( __( 'IECC 2024 wall U-value ≤ %.2f W/m²K.', 'mcp-ai-wpoos-pro' ), $max_u_wall ), $status, sprintf( __( 'Provided: %.2f W/m²K.', 'mcp-ai-wpoos-pro' ), $plan_u_wall ) );
		}
		if ( $max_u_roof > 0 && $plan_u_roof > 0 ) {
			$status   = ( $plan_u_roof <= $max_u_roof + 1e-6 ) ? 'pass' : 'fail';
			$checks[] = $this->mk( 'energy', sprintf( __( 'IECC 2024 roof U-value ≤ %.2f W/m²K.', 'mcp-ai-wpoos-pro' ), $max_u_roof ), $status, sprintf( __( 'Provided: %.2f W/m²K.', 'mcp-ai-wpoos-pro' ), $plan_u_roof ) );
		}

		// Structural references — informational.
		$checks[] = $this->mk( 'structural', __( 'Wind and seismic loads per ASCE 7-22.', 'mcp-ai-wpoos-pro' ), 'warning', __( 'Engage a structural engineer for ASCE 7 wind / seismic verification.', 'mcp-ai-wpoos-pro' ) );

		$overall = 'pass';
		foreach ( $checks as $c ) {
			if ( 'fail' === $c['status'] ) {
				$overall = 'fail';
				break; }
			if ( 'warning' === $c['status'] && 'fail' !== $overall ) {
				$overall = 'conditional'; }
		}

		return array(
			'success'                  => true,
			'country_code'             => 'US',
			'code_path'                => $code_path,
			'code_packs'               => $packs,
			'jurisdiction'             => $jurisdiction,
			'climate_zone'             => $climate_zone,
			'occupancy_classification' => $occupancy,
			'checks'                   => $checks,
			'overall_status'           => $overall,
			'disclaimer'               => __( 'Analytical / advisory output only. AHJ amendments and state-specific code adoptions may modify these requirements.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Build a check entry.
	 *
	 * @param string $category    Category.
	 * @param string $requirement Requirement.
	 * @param string $status      Status.
	 * @param string $details     Details.
	 * @return array
	 */
	protected function mk( $category, $requirement, $status, $details ) {
		return array(
			'category'    => (string) $category,
			'requirement' => (string) $requirement,
			'status'      => in_array( $status, array( 'pass', 'fail', 'warning' ), true ) ? $status : 'warning',
			'details'     => (string) $details,
		);
	}
}
