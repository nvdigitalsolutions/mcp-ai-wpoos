<?php
/**
 * Tool_Propose_Value_Engineering_Options (ecosystem port - Wave F2, architectural-design tool batch).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/architectural-design/` directory for the
 * standalone `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the
 * class in monolith installs - the addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined (see
 * the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`;
 * per-file seams — the base-owned interface/Logger/media-url-utils requires gain exists-check seams
 * resolving from the addon's D8-compat `src/` copies, the response/subprocess traits are
 * wave-proof-guarded, the openai/gemini client requires stay monolith-gated, and the
 * `WP_MCP_AI_PRO_PATH` refs swap to `NVOOS_CONTENT_GRAPH_PRO_PATH` with the `src/` root.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Architectural_Design
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);




if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Standalone seam (documented deviation): the base-owned interface require gains an
// exists-check seam resolving from the addon's D8-compat copy.
if ( ! interface_exists( 'WP_MCP_AI_Tool_Interface' ) ) {
	$nvoos_content_graph_pro_tool_interface = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/interfaces/interface-wp-mcp-ai-tool.php';
	if ( file_exists( $nvoos_content_graph_pro_tool_interface ) ) {
		require_once $nvoos_content_graph_pro_tool_interface;
	}
}


/**
 * Propose value-engineering options.
 */
class WP_MCP_AI_Tool_Propose_Value_Engineering_Options implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/* WP_MCP_AI_AVAILABILITY_BLOCK */
	/**
	 * Whether this tool is available for registration.
	 *
	 * @since 1.4.0
	 * @return bool
	 */
	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() && ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_architectural_design_toolkit'] );
	}

	/**
	 * Reason this tool is unavailable, if any.
	 *
	 * @since 1.4.0
	 * @return string
	 */
	public static function get_unavailable_reason() {
		return __( 'Architectural Design toolkit is not enabled.', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'propose_value_engineering_options';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Propose Value Engineering Options', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Return a ranked list of cost-saving alternatives drawn from the toolkit value-engineering library. Filters by country applicability and category and applies the savings range against an optional baseline cost so each option carries indicative savings in the chosen currency.', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'country_code'  => array(
					'type'        => 'string',
					'description' => __( 'ISO country code; only options applicable to this country are returned.', 'nvoos-content-graph-pro' ),
					'enum'        => array( 'LK', 'JM', 'US' ),
				),
				'baseline_cost' => array(
					'type'        => 'number',
					'description' => __( 'Project baseline cost in the chosen currency. Used to compute absolute savings from the indicative percentages.', 'nvoos-content-graph-pro' ),
					'minimum'     => 0,
					'default'     => 0,
				),
				'currency'      => array(
					'type'        => 'string',
					'description' => __( 'ISO 4217 currency code for the savings figures.', 'nvoos-content-graph-pro' ),
				),
				'categories'    => array(
					'type'        => 'array',
					'description' => __( 'Restrict to these categories (e.g. structure, finishes, mep, envelope, foundation).', 'nvoos-content-graph-pro' ),
					'items'       => array( 'type' => 'string' ),
				),
				'top_n'         => array(
					'type'        => 'integer',
					'description' => __( 'Return at most this many ranked options.', 'nvoos-content-graph-pro' ),
					'minimum'     => 1,
					'maximum'     => 50,
					'default'     => 10,
				),
			),
			'required'             => array( 'country_code' ),
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
		$user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $user_id || ! user_can( $user_id, 'edit_posts' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to propose value-engineering options.', 'nvoos-content-graph-pro' )
			);
		}

		$country = isset( $arguments['country_code'] ) ? strtoupper( sanitize_text_field( $arguments['country_code'] ) ) : '';
		if ( '' === $country ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_arguments',
				__( 'country_code is required.', 'nvoos-content-graph-pro' )
			);
		}

		if ( ! class_exists( 'WP_MCP_AI_Architectural_Sustainability' ) ) {
			return new WP_Error(
				'wp_mcp_ai_engine_missing',
				__( 'Architectural sustainability engine is unavailable.', 'nvoos-content-graph-pro' )
			);
		}

		$baseline_cost = isset( $arguments['baseline_cost'] ) ? max( 0.0, (float) $arguments['baseline_cost'] ) : 0.0;
		$currency      = isset( $arguments['currency'] ) && '' !== $arguments['currency']
			? strtoupper( sanitize_text_field( $arguments['currency'] ) )
			: $this->default_currency_for_country( $country );

		$categories = array();
		if ( isset( $arguments['categories'] ) && is_array( $arguments['categories'] ) ) {
			foreach ( $arguments['categories'] as $cat ) {
				$cat = sanitize_key( (string) $cat );
				if ( '' !== $cat ) {
					$categories[] = $cat;
				}
			}
		}
		$top_n = isset( $arguments['top_n'] ) ? max( 1, min( 50, intval( $arguments['top_n'] ) ) ) : 10;

		$library = WP_MCP_AI_Architectural_Sustainability::get_value_engineering_library();
		$options = array();

		foreach ( $library as $entry ) {
			if ( empty( $entry['applies_to'] ) || ! in_array( $country, (array) $entry['applies_to'], true ) ) {
				continue;
			}
			$entry_category = isset( $entry['category'] ) ? sanitize_key( (string) $entry['category'] ) : '';
			if ( ! empty( $categories ) && ! in_array( $entry_category, $categories, true ) ) {
				continue;
			}

			$range_min          = isset( $entry['savings_pct_range'][0] ) ? (float) $entry['savings_pct_range'][0] : 0.0;
			$range_max          = isset( $entry['savings_pct_range'][1] ) ? (float) $entry['savings_pct_range'][1] : 0.0;
			$range_mid          = ( $range_min + $range_max ) / 2.0;
			$savings_amount_min = $baseline_cost > 0 ? round( $baseline_cost * $range_min / 100.0, 2 ) : null;
			$savings_amount_max = $baseline_cost > 0 ? round( $baseline_cost * $range_max / 100.0, 2 ) : null;
			$savings_amount_mid = $baseline_cost > 0 ? round( $baseline_cost * $range_mid / 100.0, 2 ) : null;

			$options[] = array(
				'id'                  => isset( $entry['id'] ) ? sanitize_text_field( $entry['id'] ) : '',
				'category'            => $entry_category,
				'label'               => isset( $entry['label'] ) ? sanitize_text_field( $entry['label'] ) : '',
				'savings_pct_range'   => array(
					'min' => $range_min,
					'max' => $range_max,
					'mid' => round( $range_mid, 2 ),
				),
				'savings_amount_min'  => $savings_amount_min,
				'savings_amount_mid'  => $savings_amount_mid,
				'savings_amount_max'  => $savings_amount_max,
				'currency'            => $currency,
				'schedule_days_delta' => isset( $entry['schedule_days_delta'] ) ? (int) $entry['schedule_days_delta'] : 0,
				'design_impact'       => isset( $entry['design_impact'] ) ? sanitize_text_field( $entry['design_impact'] ) : '',
				'lifecycle_note'      => isset( $entry['lifecycle_note'] ) ? sanitize_text_field( $entry['lifecycle_note'] ) : '',
			);
		}

		// Rank by mid-range savings (% if no baseline, else amount), descending.
		usort(
			$options,
			static function ( $a, $b ) use ( $baseline_cost ) {
				if ( $baseline_cost > 0 ) {
					$av = isset( $a['savings_amount_mid'] ) ? (float) $a['savings_amount_mid'] : 0.0;
					$bv = isset( $b['savings_amount_mid'] ) ? (float) $b['savings_amount_mid'] : 0.0;
				} else {
					$av = isset( $a['savings_pct_range']['mid'] ) ? (float) $a['savings_pct_range']['mid'] : 0.0;
					$bv = isset( $b['savings_pct_range']['mid'] ) ? (float) $b['savings_pct_range']['mid'] : 0.0;
				}
				if ( $av === $bv ) {
					return 0;
				}
				return ( $av > $bv ) ? -1 : 1;
			}
		);
		$options = array_slice( $options, 0, $top_n );

		// Aggregate combined savings (assumes options are independent — caveat noted).
		$total_min_pct = 0.0;
		$total_max_pct = 0.0;
		foreach ( $options as $opt ) {
			$total_min_pct += $opt['savings_pct_range']['min'];
			$total_max_pct += $opt['savings_pct_range']['max'];
		}
		$total_min_pct = min( 60.0, $total_min_pct );
		$total_max_pct = min( 60.0, $total_max_pct );
		$total_min_amt = $baseline_cost > 0 ? round( $baseline_cost * $total_min_pct / 100.0, 2 ) : null;
		$total_max_amt = $baseline_cost > 0 ? round( $baseline_cost * $total_max_pct / 100.0, 2 ) : null;

		$result = array(
			'success'                  => true,
			'country_code'             => $country,
			'baseline_cost'            => $baseline_cost,
			'currency'                 => $currency,
			'options_returned'         => count( $options ),
			'options'                  => $options,
			'aggregate_savings_pct'    => array(
				'min' => round( $total_min_pct, 2 ),
				'max' => round( $total_max_pct, 2 ),
			),
			'aggregate_savings_amount' => array(
				'min' => $total_min_amt,
				'max' => $total_max_amt,
			),
			'method'                   => __( 'Indicative ranges from the toolkit VE library; mid-point used for ranking. Aggregate is capped at 60 % of baseline.', 'nvoos-content-graph-pro' ),
			'disclaimer'               => __( 'Options are not independent in practice — engage a chartered Quantity Surveyor before adopting any combination.', 'nvoos-content-graph-pro' ),
		);

		/**
		 * Fires after value-engineering options have been proposed.
		 *
		 * @since 1.4.0
		 *
		 * @param array $result  Result.
		 * @param array $args    Tool arguments.
		 * @param array $context Tool context.
		 */
		do_action( 'wp_mcp_ai_arch_ve_proposed', $result, $arguments, $context );

		return $result;
	}

	/**
	 * Default currency for a country.
	 *
	 * @param string $country Country code.
	 * @return string
	 */
	private function default_currency_for_country( $country ) {
		switch ( strtoupper( (string) $country ) ) {
			case 'LK':
				return 'LKR';
			case 'JM':
				return 'JMD';
			case 'US':
				return 'USD';
			default:
				return 'USD';
		}
	}
}
