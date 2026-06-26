<?php
/**
 * Tool — Generate Bill of Quantities.
 *
 * Generates a Bill of Quantities (BoQ) skeleton in the requested format
 * (POMI / SMM7 / NRM2 / CSI MasterFormat 2020). The format is auto-selected
 * from the country code if not explicitly provided. When `line_items` are
 * supplied they are mapped into their format sections; section totals and a
 * grand total are computed, and currency is taken from the toolkit settings or
 * the country default.
 *
 * The BoQ is **not** a substitute for a chartered Quantity Surveyor; it is a
 * structured first pass that can be exported and refined.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.4.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';

/**
 * Generate a Bill of Quantities.
 */
class WP_MCP_AI_Tool_Generate_Bill_Of_Quantities implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/* WP_MCP_AI_AVAILABILITY_BLOCK */
	/**
	 * Whether this tool is available for registration.
	 *
	 * @since 1.4.0
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
	 * @since 1.4.0
	 * @return string
	 */
	public static function get_unavailable_reason() {
		return __( 'Architectural Design toolkit is not enabled.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'generate_bill_of_quantities';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Generate Bill of Quantities', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Generate a Bill of Quantities (BoQ) skeleton in POMI (Sri Lanka), SMM7 / NRM2 (Caribbean / UK), or CSI MasterFormat 2020 (US). Section is auto-selected from country if format is omitted. Supply line items with section + quantity + unit + rate to produce a fully-totalled BoQ.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'country_code'         => array(
					'type'        => 'string',
					'description' => __( 'ISO 3166-1 alpha-2 country code. Used to auto-pick the BoQ format and currency.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'LK', 'JM', 'US' ),
				),
				'format'               => array(
					'type'        => 'string',
					'description' => __( 'BoQ format (overrides country auto-pick).', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'pomi', 'smm7', 'csi_masterformat_2020' ),
				),
				'currency'             => array(
					'type'        => 'string',
					'description' => __( 'ISO 4217 currency code. Defaults to country default (LKR / JMD / USD).', 'mcp-ai-wpoos-pro' ),
				),
				'project_name'         => array(
					'type'        => 'string',
					'description' => __( 'Project name to print on the cover.', 'mcp-ai-wpoos-pro' ),
				),
				'line_items'           => array(
					'type'        => 'array',
					'description' => __( 'BoQ line items.', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type'                 => 'object',
						'properties'           => array(
							'section'     => array(
								'type'        => 'string',
								'description' => __( 'Section key (e.g. "D" for POMI/SMM7 or "03" for CSI).', 'mcp-ai-wpoos-pro' ),
							),
							'description' => array(
								'type'        => 'string',
								'description' => __( 'Item description.', 'mcp-ai-wpoos-pro' ),
							),
							'quantity'    => array(
								'type'        => 'number',
								'minimum'     => 0,
								'description' => __( 'Quantity.', 'mcp-ai-wpoos-pro' ),
							),
							'unit'        => array(
								'type'        => 'string',
								'description' => __( 'Unit of measure (m³, m², m, no., kg, lump, etc.).', 'mcp-ai-wpoos-pro' ),
							),
							'rate'        => array(
								'type'        => 'number',
								'minimum'     => 0,
								'description' => __( 'Rate per unit in the chosen currency.', 'mcp-ai-wpoos-pro' ),
							),
						),
						'required'             => array( 'section', 'description', 'quantity', 'unit', 'rate' ),
						'additionalProperties' => true,
					),
				),
				'contingency_pct'      => array(
					'type'        => 'number',
					'description' => __( 'Contingency percentage applied to the subtotal.', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
					'maximum'     => 50,
					'default'     => 10,
				),
				'overheads_profit_pct' => array(
					'type'        => 'number',
					'description' => __( 'Combined overheads + profit percentage applied to the subtotal.', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
					'maximum'     => 35,
					'default'     => 15,
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
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $user_id || ! user_can( $user_id, 'edit_posts' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to generate a Bill of Quantities.', 'mcp-ai-wpoos-pro' )
			);
		}

		$country = isset( $arguments['country_code'] ) ? strtoupper( sanitize_text_field( $arguments['country_code'] ) ) : '';
		if ( '' === $country ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_arguments',
				__( 'country_code is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( ! class_exists( 'WP_MCP_AI_Architectural_Sustainability' ) ) {
			return new WP_Error(
				'wp_mcp_ai_engine_missing',
				__( 'Architectural sustainability engine is unavailable.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Resolve format.
		$format = isset( $arguments['format'] ) && '' !== $arguments['format']
			? sanitize_key( $arguments['format'] )
			: WP_MCP_AI_Architectural_Sustainability::preferred_boq_format( $country );

		$catalog = WP_MCP_AI_Architectural_Sustainability::get_boq_format_catalog();
		if ( ! isset( $catalog[ $format ] ) ) {
			return new WP_Error(
				'wp_mcp_ai_unknown_format',
				/* translators: %s: BoQ format key. */
				sprintf( __( 'Unknown BoQ format: %s.', 'mcp-ai-wpoos-pro' ), $format )
			);
		}
		$format_def = $catalog[ $format ];

		// Resolve currency.
		$currency = isset( $arguments['currency'] ) && '' !== $arguments['currency']
			? strtoupper( sanitize_text_field( $arguments['currency'] ) )
			: $this->default_currency_for_country( $country );

		$project_name    = isset( $arguments['project_name'] ) ? sanitize_text_field( $arguments['project_name'] ) : '';
		$contingency_pct = isset( $arguments['contingency_pct'] ) ? max( 0.0, min( 50.0, (float) $arguments['contingency_pct'] ) ) : 10.0;
		$op_pct          = isset( $arguments['overheads_profit_pct'] ) ? max( 0.0, min( 35.0, (float) $arguments['overheads_profit_pct'] ) ) : 15.0;

		// Build empty section skeleton.
		$sections = array();
		foreach ( (array) $format_def['sections'] as $key => $label ) {
			$sections[ $key ] = array(
				'key'      => (string) $key,
				'label'    => sanitize_text_field( (string) $label ),
				'items'    => array(),
				'subtotal' => 0.0,
			);
		}

		// Map line items.
		$unknown_sections = array();
		$line_items       = isset( $arguments['line_items'] ) && is_array( $arguments['line_items'] )
			? $arguments['line_items']
			: array();
		foreach ( $line_items as $idx => $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}
			$section_key = isset( $raw['section'] ) ? sanitize_text_field( (string) $raw['section'] ) : '';
			$description = isset( $raw['description'] ) ? sanitize_text_field( (string) $raw['description'] ) : '';
			$qty         = isset( $raw['quantity'] ) ? max( 0.0, (float) $raw['quantity'] ) : 0.0;
			$unit        = isset( $raw['unit'] ) ? sanitize_text_field( (string) $raw['unit'] ) : '';
			$rate        = isset( $raw['rate'] ) ? max( 0.0, (float) $raw['rate'] ) : 0.0;
			$amount      = round( $qty * $rate, 2 );
			$item        = array(
				'description' => $description,
				'quantity'    => $qty,
				'unit'        => $unit,
				'rate'        => $rate,
				'amount'      => $amount,
				'currency'    => $currency,
			);

			if ( ! isset( $sections[ $section_key ] ) ) {
				$unknown_sections[] = array(
					'index'   => (int) $idx,
					'section' => $section_key,
				);
				continue;
			}
			$sections[ $section_key ]['items'][]   = $item;
			$sections[ $section_key ]['subtotal'] += $amount;
		}

		// Aggregate totals.
		$subtotal = 0.0;
		foreach ( $sections as $key => $section ) {
			$sections[ $key ]['subtotal'] = round( (float) $section['subtotal'], 2 );
			$subtotal                    += $sections[ $key ]['subtotal'];
		}
		$contingency = round( $subtotal * ( $contingency_pct / 100.0 ), 2 );
		$op_amount   = round( $subtotal * ( $op_pct / 100.0 ), 2 );
		$grand_total = round( $subtotal + $contingency + $op_amount, 2 );

		$result = array(
			'success'                 => true,
			'country_code'            => $country,
			'format'                  => $format,
			'format_label'            => $format_def['label'],
			'standard_source'         => isset( $format_def['standard_source'] ) ? $format_def['standard_source'] : '',
			'unit_system'             => isset( $format_def['unit_system'] ) ? $format_def['unit_system'] : '',
			'currency'                => $currency,
			'project_name'            => $project_name,
			'sections'                => array_values( $sections ),
			'subtotal'                => round( $subtotal, 2 ),
			'contingency_pct'         => $contingency_pct,
			'contingency_amount'      => $contingency,
			'overheads_profit_pct'    => $op_pct,
			'overheads_profit_amount' => $op_amount,
			'grand_total'             => $grand_total,
			'unknown_sections'        => $unknown_sections,
			'method'                  => __( 'BoQ skeleton with classification per requested format. Items are summed by section.', 'mcp-ai-wpoos-pro' ),
			'disclaimer'              => __( 'Indicative BoQ only. Engage a chartered Quantity Surveyor before tender.', 'mcp-ai-wpoos-pro' ),
		);

		/**
		 * Fires after a BoQ has been generated.
		 *
		 * @since 1.4.0
		 *
		 * @param array $result  Generated BoQ.
		 * @param array $args    Tool arguments.
		 * @param array $context Tool context.
		 */
		do_action( 'wp_mcp_ai_arch_boq_generated', $result, $arguments, $context );

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
