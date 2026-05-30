<?php
/**
 * Vehicle Cleaning Estimate Tool
 *
 * A car-wash package and add-on pricing engine that:
 *  1. Ingests one or more vehicle images
 *  2. Classifies vehicle into a size tier (Car / Small Truck-SUV / Oversize Truck-SUV)
 *     using the existing multimodal vision pipeline (OpenAI / Anthropic / Gemini)
 *  3. Accepts a selected package tier and optional add-ons
 *  4. Runs deterministic pricing against a structured menu config
 *  5. Returns a strict JSON line-item breakdown with totals
 *
 * Designed for public "customer quote" workflows — no VIN required.
 * Vehicle identification uses probabilistic visual classification with
 * adaptive photo-request prompts when confidence is low.
 *
 * @package    WP_MCP_AI
 * @subpackage Pro\Tools
 * @since      2.2.0
 * @author     NV Digital Solutions
 * @copyright  Copyright (c) 2025-2026 NV Digital Solutions
 * @license    GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Vehicle Cleaning Estimate Tool class.
 *
 * Produces a structured car-wash / detailing estimate from vehicle
 * photos, a chosen package tier, and selected add-ons.
 *
 * @since 2.2.0
 */
class WP_MCP_AI_Tool_Vehicle_Cleaning_Estimate implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Rules_Interface {

	use WP_MCP_AI_Attachment_File_Resolver;
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * Vehicle size tiers.
	 *
	 * @var string[]
	 */
	const SIZE_TIERS = array(
		'car',
		'small_truck_suv',
		'oversize_truck_suv',
	);

	/**
	 * Human-readable labels for size tiers.
	 *
	 * @var array<string, string>
	 */
	const SIZE_TIER_LABELS = array(
		'car'                => 'Car',
		'small_truck_suv'    => 'Small Truck / SUV',
		'oversize_truck_suv' => 'Oversize Truck / SUV',
	);

	/**
	 * Package tiers matching London Prestige Car Wash menu.
	 *
	 * @var string[]
	 */
	const PACKAGE_TIERS = array(
		'premium_exterior_express',
		'practical_interior_express',
		'popular_interior_express',
		'prestige_interior_express',
	);

	/**
	 * Confidence threshold for vehicle size classification.
	 *
	 * @var float
	 */
	const SIZE_CONFIDENCE_THRESHOLD = 0.75;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'vehicle_cleaning_estimate';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Vehicle Cleaning Estimate', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Generates a car-wash or detailing estimate from vehicle photos. Classifies the vehicle into a size tier (Car, Small Truck/SUV, Oversize Truck/SUV) using AI vision, applies the selected package and add-ons, and returns a line-item breakdown with totals. No VIN required.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'image_attachment_ids' => array(
					'type'        => 'array',
					'description' => __( 'Array of WordPress attachment IDs for vehicle photos. At least one photo showing the full vehicle is recommended for size classification.', 'mcp-ai-wpoos' ),
					'items'       => array(
						'type' => 'integer',
					),
					'minItems'    => 1,
					'maxItems'    => 10,
				),
				'package'              => array(
					'type'        => 'string',
					'description' => __( 'Selected package tier: premium_exterior_express, practical_interior_express, popular_interior_express, or prestige_interior_express.', 'mcp-ai-wpoos' ),
					'enum'        => self::PACKAGE_TIERS,
				),
				'add_ons'              => array(
					'type'        => 'array',
					'description' => __( 'Array of add-on service codes to include. Each add-on may have an optional severity (light/moderate/severe) for condition-based pricing.', 'mcp-ai-wpoos' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'code'     => array(
								'type'        => 'string',
								'description' => __( 'Add-on service code (e.g., soil_mud_sap_oil, pet_hair_removal, additional_interior_clean, premium_hand_wash_upgrade, rims_tire_dressing, trunk_bed_shampoo, carpet_seat_deodorizer).', 'mcp-ai-wpoos' ),
							),
							'severity' => array(
								'type'        => 'string',
								'description' => __( 'Optional severity for condition-based add-ons: light, moderate, or severe.', 'mcp-ai-wpoos' ),
								'enum'        => array( 'light', 'moderate', 'severe' ),
							),
						),
						'required'   => array( 'code' ),
					),
					'maxItems'    => 20,
				),
				'size_override'        => array(
					'type'        => 'string',
					'description' => __( 'Manual vehicle size override. Skips visual classification.', 'mcp-ai-wpoos' ),
					'enum'        => self::SIZE_TIERS,
				),
				'menu_config_id'       => array(
					'type'        => 'integer',
					'description' => __( 'WordPress attachment ID for a custom menu config (JSON). If omitted, the built-in default menu is used.', 'mcp-ai-wpoos' ),
				),
				'tax_rate'             => array(
					'type'        => 'number',
					'description' => __( 'Tax rate as a decimal (e.g., 0.13 for 13% HST). Defaults to 0.', 'mcp-ai-wpoos' ),
					'minimum'     => 0,
					'maximum'     => 1,
				),
				'currency'             => array(
					'type'        => 'string',
					'description' => __( 'Currency code (e.g., CAD, USD). Defaults to CAD.', 'mcp-ai-wpoos' ),
					'default'     => 'CAD',
				),
			),
			'required'             => array( 'package' ),
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
			'requires-vision-model',
			'read-only',
			'cacheable',
			'consumes-tokens',
			'non-deterministic',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_tool_rules() {
		return array(
			'rate_limits'  => array(
				'requests_per_minute' => 15,
				'requests_per_hour'   => 200,
				'max_concurrent'      => 5,
			),
			'timeout'      => array(
				'single_request' => 30,
			),
			'dependencies' => array(
				'required' => array(),
				'optional' => array( 'analyze_image' ),
			),
			'cache'        => array(
				'ttl'    => 900,
				'key_by' => array( 'image_attachment_ids', 'package', 'add_ons', 'size_override' ),
			),
			'retry'        => array(
				'strategy'    => 'exponential_backoff',
				'max_retries' => 2,
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
	 * Get extended tool definition (toolkit metadata).
	 *
	 * @since 2.2.0
	 * @return array
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'vehicle_estimation',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'profession_tags'       => array( 'auto_detailer', 'car_wash_operator', 'service_advisor' ),
			'risk_level'            => 'info',
		);
	}

	/**
	 * Whether this tool is available.
	 *
	 * @since 2.2.0
	 * @return bool
	 */
	public static function is_available() {
		return true;
	}

	/**
	 * Indicate that this tool requires the Pro add-on.
	 *
	 * @since 2.2.0
	 * @return bool
	 */
	public function requires_base_pro() {
		return true;
	}

	/**
	 * Execute the vehicle cleaning estimate pipeline.
	 *
	 * @since 2.2.0
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error  Structured estimate or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// --- Permission check ---
		$user_id = $context['user_id'] ?? get_current_user_id();

		/**
		 * Filter the required capability for the vehicle cleaning estimate tool.
		 *
		 * Set to 'read' to allow guest-token access for public quote workflows.
		 *
		 * @since 2.2.0
		 *
		 * @param string $capability WordPress capability required.
		 */
		$required_cap = apply_filters( 'wp_mcp_ai_vehicle_cleaning_estimate_required_capability', 'upload_files' );

		if ( ! $user_id || ! user_can( $user_id, $required_cap ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to generate vehicle cleaning estimates.', 'mcp-ai-wpoos' ),
				array( 'status' => 403 )
			);
		}

		// --- Validate package ---
		$package = isset( $arguments['package'] ) ? sanitize_text_field( $arguments['package'] ) : '';
		if ( ! in_array( $package, self::PACKAGE_TIERS, true ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_package',
				sprintf(
					/* translators: %s: comma-separated list of valid packages */
					__( 'Invalid package. Choose one of: %s', 'mcp-ai-wpoos' ),
					implode( ', ', self::PACKAGE_TIERS )
				),
				array( 'status' => 400 )
			);
		}

		// --- Load menu config ---
		$menu_config_id = isset( $arguments['menu_config_id'] ) ? absint( $arguments['menu_config_id'] ) : 0;
		$menu           = $this->load_menu_config( $menu_config_id );

		// --- Determine vehicle size tier ---
		$size_result = $this->determine_size_tier( $arguments, $context );

		$size_tier       = $size_result['tier'];
		$size_confidence = $size_result['confidence'];
		$size_source     = $size_result['source'];
		$size_warnings   = $size_result['warnings'] ?? array();

		// --- Validate add-ons ---
		$raw_add_ons   = $arguments['add_ons'] ?? array();
		$valid_add_ons = $this->validate_add_ons( $raw_add_ons, $menu );

		// --- Build line items ---
		$line_items = array();

		// Package line item.
		$pkg_config = $menu['packages'][ $package ] ?? null;
		if ( $pkg_config ) {
			$pkg_price    = $this->get_size_price( $pkg_config['prices'] ?? array(), $size_tier );
			$line_items[] = array(
				'type'              => 'package',
				'code'              => $package,
				'description'       => $pkg_config['name'] ?? ucwords( str_replace( '_', ' ', $package ) ),
				'size_tier'         => $size_tier,
				'unit_price'        => $pkg_price,
				'quantity'          => 1,
				'extended'          => $pkg_price,
				'included_services' => $pkg_config['included_services'] ?? array(),
			);
		}

		// Add-on line items.
		foreach ( $valid_add_ons as $add_on ) {
			$addon_config = $menu['add_ons'][ $add_on['code'] ] ?? null;
			if ( ! $addon_config ) {
				continue;
			}

			$addon_price  = $this->calculate_addon_price( $addon_config, $size_tier, $add_on['severity'] ?? '' );
			$line_items[] = array(
				'type'        => 'add_on',
				'code'        => $add_on['code'],
				'description' => $addon_config['name'] ?? ucwords( str_replace( '_', ' ', $add_on['code'] ) ),
				'size_tier'   => $addon_config['size_based'] ? $size_tier : 'flat',
				'severity'    => $add_on['severity'] ?? '',
				'unit_price'  => $addon_price,
				'quantity'    => 1,
				'extended'    => $addon_price,
			);
		}

		// --- Calculate totals ---
		$tax_rate = isset( $arguments['tax_rate'] ) ? (float) $arguments['tax_rate'] : 0.0;
		$tax_rate = max( 0.0, min( 1.0, $tax_rate ) );
		$currency = sanitize_text_field( $arguments['currency'] ?? 'CAD' );
		$totals   = $this->calculate_totals( $line_items, $tax_rate );

		// --- Build estimate ---
		$estimate_id = wp_generate_uuid4();

		$estimate = array(
			'estimate_id'  => $estimate_id,
			'vehicle_size' => array(
				'tier'       => $size_tier,
				'label'      => self::SIZE_TIER_LABELS[ $size_tier ] ?? $size_tier,
				'confidence' => $size_confidence,
				'source'     => $size_source,
			),
			'package'      => array(
				'code' => $package,
				'name' => $pkg_config['name'] ?? ucwords( str_replace( '_', ' ', $package ) ),
			),
			'line_items'   => $line_items,
			'totals'       => array_merge( $totals, array( 'currency' => $currency ) ),
			'metadata'     => array(
				'generated_at' => gmdate( 'Y-m-d\TH:i:s\Z' ),
				'tool_version' => '2.2.0',
				'menu_source'  => $menu_config_id > 0 ? 'custom' : 'default',
				'image_count'  => count( $arguments['image_attachment_ids'] ?? array() ),
			),
		);

		if ( ! empty( $size_warnings ) ) {
			$estimate['warnings'] = $size_warnings;
		}

		$message = $this->build_estimate_message( $estimate );

		return $this->format_success_response( $message, $estimate );
	}

	/**
	 * Determine the vehicle size tier from images or manual override.
	 *
	 * @since 2.2.0
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array Size result with tier, confidence, source, and optional warnings.
	 */
	protected function determine_size_tier( $arguments, $context ) {
		// Path 1: Manual override.
		if ( ! empty( $arguments['size_override'] ) && in_array( $arguments['size_override'], self::SIZE_TIERS, true ) ) {
			return array(
				'tier'       => $arguments['size_override'],
				'confidence' => 1.0,
				'source'     => 'manual_override',
			);
		}

		// Path 2: Visual classification from images.
		$image_ids = $arguments['image_attachment_ids'] ?? array();
		if ( ! empty( $image_ids ) && is_array( $image_ids ) ) {
			$visual = $this->classify_vehicle_size( $image_ids, $context );
			if ( ! is_wp_error( $visual ) ) {
				$result = array(
					'tier'       => $visual['tier'],
					'confidence' => $visual['confidence'],
					'source'     => 'visual_classification',
				);

				if ( $visual['confidence'] < self::SIZE_CONFIDENCE_THRESHOLD ) {
					$result['warnings'] = array(
						sprintf(
							/* translators: 1: confidence percentage, 2: detected size tier */
							__( 'Vehicle size classification confidence is %1$s%% (detected as "%2$s"). For a more accurate quote, please upload a full-side photo showing the entire vehicle, or select your vehicle size manually.', 'mcp-ai-wpoos' ),
							round( $visual['confidence'] * 100 ),
							self::SIZE_TIER_LABELS[ $visual['tier'] ] ?? $visual['tier']
						),
					);
				}

				return $result;
			}
		}

		// Path 3: Default to "car" with a warning.
		return array(
			'tier'       => 'car',
			'confidence' => 0.0,
			'source'     => 'default',
			'warnings'   => array(
				__( 'No vehicle photo provided and no size override specified. Defaulting to "Car" size tier. Upload a vehicle photo or specify size_override for accurate pricing.', 'mcp-ai-wpoos' ),
			),
		);
	}

	/**
	 * Classify vehicle size from images using LLM vision.
	 *
	 * @since 2.2.0
	 *
	 * @param int[] $image_ids Array of attachment IDs.
	 * @param array $context   Execution context.
	 * @return array|WP_Error  Classification result or error.
	 */
	protected function classify_vehicle_size( $image_ids, $context ) {
		if ( ! function_exists( 'wp_mcp_ai_get_tool_registry' ) ) {
			return new WP_Error( 'wp_mcp_ai_no_registry', __( 'Tool registry not available.', 'mcp-ai-wpoos' ) );
		}

		$registry = wp_mcp_ai_get_tool_registry();
		if ( ! $registry || ! $registry->is_tool_registered( 'analyze_image' ) ) {
			return new WP_Error( 'wp_mcp_ai_vision_unavailable', __( 'Image analysis tool not available.', 'mcp-ai-wpoos' ) );
		}

		// Use first image for classification.
		$attachment_id = absint( $image_ids[0] );
		if ( ! wp_attachment_is_image( $attachment_id ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_image', __( 'First attachment is not a valid image.', 'mcp-ai-wpoos' ) );
		}

		$prompt = 'Classify this vehicle into exactly ONE of these size categories: '
			. '"car" (sedans, coupes, hatchbacks, compact cars, convertibles, wagons), '
			. '"small_truck_suv" (crossovers, small/mid-size SUVs, small pickup trucks, minivans), '
			. '"oversize_truck_suv" (full-size SUVs like Suburban/Expedition, full-size pickup trucks like F-150/Silverado, large vans, extended-cab trucks). '
			. 'Return ONLY a JSON object with keys: "size_tier" (string: one of car/small_truck_suv/oversize_truck_suv), '
			. '"confidence" (float 0-1), "vehicle_description" (string: brief description e.g. "Honda Civic sedan"). '
			. 'Do not include any other text.';

		$result = $registry->execute_tool(
			'analyze_image',
			array(
				'attachment_id' => $attachment_id,
				'prompt'        => $prompt,
				'max_tokens'    => 150,
			),
			$context
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$text = '';
		if ( is_array( $result ) && isset( $result['text'] ) ) {
			$text = $result['text'];
		} elseif ( is_array( $result ) && isset( $result['data']['text'] ) ) {
			$text = $result['data']['text'];
		} elseif ( is_string( $result ) ) {
			$text = $result;
		}

		$parsed = $this->parse_json_from_text( $text );
		if ( empty( $parsed ) || empty( $parsed['size_tier'] ) ) {
			return new WP_Error( 'wp_mcp_ai_classify_failed', __( 'Could not classify vehicle size from image.', 'mcp-ai-wpoos' ) );
		}

		$tier = sanitize_text_field( $parsed['size_tier'] );
		if ( ! in_array( $tier, self::SIZE_TIERS, true ) ) {
			// Attempt fuzzy mapping.
			$tier = $this->fuzzy_map_size_tier( $tier );
		}

		return array(
			'tier'                => $tier,
			'confidence'          => isset( $parsed['confidence'] ) ? (float) $parsed['confidence'] : 0.5,
			'vehicle_description' => sanitize_text_field( $parsed['vehicle_description'] ?? '' ),
		);
	}

	/**
	 * Fuzzy-map an unrecognized size tier string to a known tier.
	 *
	 * @since 2.2.0
	 *
	 * @param string $raw Raw size tier string from LLM.
	 * @return string Mapped tier (defaults to 'car' if unrecognized).
	 */
	protected function fuzzy_map_size_tier( $raw ) {
		$raw = strtolower( trim( $raw ) );

		$map = array(
			'car'                => 'car',
			'sedan'              => 'car',
			'coupe'              => 'car',
			'hatchback'          => 'car',
			'compact'            => 'car',
			'convertible'        => 'car',
			'wagon'              => 'car',
			'small_truck_suv'    => 'small_truck_suv',
			'small_suv'          => 'small_truck_suv',
			'suv'                => 'small_truck_suv',
			'crossover'          => 'small_truck_suv',
			'mid_size_suv'       => 'small_truck_suv',
			'small_truck'        => 'small_truck_suv',
			'oversize_truck_suv' => 'oversize_truck_suv',
			'oversize'           => 'oversize_truck_suv',
			'full_size_suv'      => 'oversize_truck_suv',
			'full_size_truck'    => 'oversize_truck_suv',
			'truck'              => 'oversize_truck_suv',
			'large_suv'          => 'oversize_truck_suv',
			'van'                => 'oversize_truck_suv',
			'large_van'          => 'oversize_truck_suv',
			'minivan'            => 'oversize_truck_suv',
			'pickup'             => 'oversize_truck_suv',
			'third_row_suv'      => 'oversize_truck_suv',
		);

		return $map[ $raw ] ?? 'car';
	}

	/**
	 * Validate add-on selections against the menu.
	 *
	 * @since 2.2.0
	 *
	 * @param array $raw_add_ons Raw add-on selections from input.
	 * @param array $menu        Loaded menu config.
	 * @return array Valid add-on selections.
	 */
	protected function validate_add_ons( $raw_add_ons, $menu ) {
		if ( ! is_array( $raw_add_ons ) ) {
			return array();
		}

		$valid = array();
		$seen  = array();
		$known = array_keys( $menu['add_ons'] ?? array() );

		foreach ( $raw_add_ons as $addon ) {
			if ( ! is_array( $addon ) || empty( $addon['code'] ) ) {
				continue;
			}

			$code = sanitize_text_field( $addon['code'] );
			if ( ! in_array( $code, $known, true ) ) {
				continue;
			}

			// Deduplicate.
			if ( isset( $seen[ $code ] ) ) {
				continue;
			}
			$seen[ $code ] = true;

			$severity = '';
			if ( ! empty( $addon['severity'] ) ) {
				$severity = sanitize_text_field( $addon['severity'] );
				if ( ! in_array( $severity, array( 'light', 'moderate', 'severe' ), true ) ) {
					$severity = '';
				}
			}

			$valid[] = array(
				'code'     => $code,
				'severity' => $severity,
			);
		}

		return $valid;
	}

	/**
	 * Get price for a specific size tier from a prices array.
	 *
	 * @since 2.2.0
	 *
	 * @param array  $prices    Associative array of tier => price.
	 * @param string $size_tier Vehicle size tier.
	 * @return float Price for the tier.
	 */
	protected function get_size_price( $prices, $size_tier ) {
		if ( isset( $prices[ $size_tier ] ) ) {
			return (float) $prices[ $size_tier ];
		}
		// Fallback to 'car' price if tier not found.
		return (float) ( $prices['car'] ?? 0.0 );
	}

	/**
	 * Calculate the price of an add-on, considering size and severity.
	 *
	 * Supports three pricing modes:
	 *  1. Direct severity prices (e.g., 'severity_prices' => ['light' => 45.00, ...])
	 *  2. Size-based pricing with tier-keyed prices array
	 *  3. Flat price with optional severity multipliers
	 *
	 * @since 2.2.0
	 *
	 * @param array  $addon_config Add-on configuration from menu.
	 * @param string $size_tier    Vehicle size tier.
	 * @param string $severity     Optional severity level.
	 * @return float Calculated price.
	 */
	protected function calculate_addon_price( $addon_config, $size_tier, $severity = '' ) {
		// Mode 1: Direct severity-based prices (no multiplier — each severity has its own price).
		if ( '' !== $severity && ! empty( $addon_config['severity_prices'] ) ) {
			if ( isset( $addon_config['severity_prices'][ $severity ] ) ) {
				return (float) $addon_config['severity_prices'][ $severity ];
			}
			// Fallback to moderate if severity not found.
			return (float) ( $addon_config['severity_prices']['moderate'] ?? 0.0 );
		}

		$base_price = 0.0;

		// Mode 2: Size-based pricing.
		if ( ! empty( $addon_config['size_based'] ) && isset( $addon_config['prices'] ) ) {
			$base_price = $this->get_size_price( $addon_config['prices'], $size_tier );
		} elseif ( isset( $addon_config['price'] ) ) {
			$base_price = (float) $addon_config['price'];
		}

		// Mode 3: Severity multipliers applied to base price.
		if ( '' !== $severity && ! empty( $addon_config['severity_multipliers'] ) ) {
			$multiplier = (float) ( $addon_config['severity_multipliers'][ $severity ] ?? 1.0 );
			$base_price = round( $base_price * $multiplier, 2 );
		}

		return $base_price;
	}

	/**
	 * Calculate totals from line items.
	 *
	 * @since 2.2.0
	 *
	 * @param array $line_items Array of line items.
	 * @param float $tax_rate   Tax rate as decimal.
	 * @return array Totals breakdown.
	 */
	protected function calculate_totals( $line_items, $tax_rate ) {
		$package_total = 0.0;
		$addons_total  = 0.0;

		foreach ( $line_items as $item ) {
			if ( 'package' === $item['type'] ) {
				$package_total += $item['extended'];
			} else {
				$addons_total += $item['extended'];
			}
		}

		$subtotal = round( $package_total + $addons_total, 2 );
		$tax      = round( $subtotal * $tax_rate, 2 );
		$total    = round( $subtotal + $tax, 2 );

		return array(
			'package_total'   => round( $package_total, 2 ),
			'addons_total'    => round( $addons_total, 2 ),
			'subtotal'        => $subtotal,
			'tax_rate'        => $tax_rate,
			'tax'             => $tax,
			'total'           => $total,
			'line_item_count' => count( $line_items ),
		);
	}

	/**
	 * Load menu configuration from a custom attachment or use the built-in default.
	 *
	 * @since 2.2.0
	 *
	 * @param int $attachment_id Optional menu config attachment ID.
	 * @return array Menu config with packages and add_ons.
	 */
	protected function load_menu_config( $attachment_id = 0 ) {
		if ( $attachment_id > 0 ) {
			$custom = $this->load_custom_menu( $attachment_id );
			if ( ! empty( $custom['packages'] ) ) {
				return $custom;
			}
		}

		/**
		 * Filter the default cleaning menu configuration.
		 *
		 * @since 2.2.0
		 *
		 * @param array $menu Default menu configuration.
		 */
		return apply_filters( 'wp_mcp_ai_vehicle_cleaning_menu', $this->get_default_menu() );
	}

	/**
	 * Load a custom menu config from a JSON attachment.
	 *
	 * @since 2.2.0
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array Parsed menu config or empty array.
	 */
	protected function load_custom_menu( $attachment_id ) {
		$file_path = get_attached_file( $attachment_id );
		if ( ! $file_path || ! file_exists( $file_path ) ) {
			return array();
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$contents = file_get_contents( $file_path );
		if ( false === $contents ) {
			return array();
		}

		$data = json_decode( $contents, true );
		if ( ! is_array( $data ) || empty( $data['packages'] ) ) {
			return array();
		}

		return $data;
	}

	/**
	 * Get the built-in default menu configuration.
	 *
	 * Pricing and services match London Prestige Car Wash's current menu
	 * structure: four express-service package tiers with size-based pricing
	 * and specialty add-ons with severity-based or flat pricing.
	 *
	 * Oversize pricing applies to large trucks, minivans, and third-row
	 * seating SUVs.
	 *
	 * @since 2.2.0
	 * @return array Menu config with packages and add_ons.
	 */
	protected function get_default_menu() {
		return array(
			'packages' => array(
				'premium_exterior_express'   => array(
					'name'              => __( 'Premium Exterior Express', 'mcp-ai-wpoos' ),
					'prices'            => array(
						'car'                => 29.99,
						'small_truck_suv'    => 35.99,
						'oversize_truck_suv' => 39.99,
					),
					'included_services' => array(
						__( 'Cotton towel hand dry', 'mcp-ai-wpoos' ),
						__( 'Spot free exterior clean', 'mcp-ai-wpoos' ),
						__( 'Under car self wash', 'mcp-ai-wpoos' ),
						__( 'Driver\'s rubber mat washing', 'mcp-ai-wpoos' ),
						__( 'Insect and dirt removal', 'mcp-ai-wpoos' ),
						__( 'Spray foam soap', 'mcp-ai-wpoos' ),
					),
				),
				'practical_interior_express' => array(
					'name'              => __( 'Practical Interior Express', 'mcp-ai-wpoos' ),
					'prices'            => array(
						'car'                => 59.99,
						'small_truck_suv'    => 69.99,
						'oversize_truck_suv' => 79.99,
					),
					'included_services' => array(
						__( 'Includes Premium Exterior Express', 'mcp-ai-wpoos' ),
						__( 'Quick floor and seat vacuuming', 'mcp-ai-wpoos' ),
						__( 'Interior window cleaning', 'mcp-ai-wpoos' ),
						__( 'Dashboard and cupholder cleaning', 'mcp-ai-wpoos' ),
						__( 'Door panel cleaning', 'mcp-ai-wpoos' ),
						__( 'Rubber mat washing', 'mcp-ai-wpoos' ),
					),
				),
				'popular_interior_express'   => array(
					'name'              => __( 'Popular Interior Express', 'mcp-ai-wpoos' ),
					'prices'            => array(
						'car'                => 79.99,
						'small_truck_suv'    => 89.99,
						'oversize_truck_suv' => 99.99,
					),
					'included_services' => array(
						__( 'Includes Practical Interior Express', 'mcp-ai-wpoos' ),
						__( 'Quick trunk vacuuming', 'mcp-ai-wpoos' ),
						__( 'Rim cleaning', 'mcp-ai-wpoos' ),
						__( 'Tire dressing', 'mcp-ai-wpoos' ),
						__( 'Triple foam polisher', 'mcp-ai-wpoos' ),
						__( 'Clear coat protectant', 'mcp-ai-wpoos' ),
						__( 'Rust inhibitor', 'mcp-ai-wpoos' ),
					),
				),
				'prestige_interior_express'  => array(
					'name'              => __( 'Prestige Interior Express', 'mcp-ai-wpoos' ),
					'prices'            => array(
						'car'                => 129.99,
						'small_truck_suv'    => 139.99,
						'oversize_truck_suv' => 159.99,
					),
					'included_services' => array(
						__( 'Includes Popular Interior Express', 'mcp-ai-wpoos' ),
						__( 'Dashboard conditioning', 'mcp-ai-wpoos' ),
						__( 'Door panel conditioning', 'mcp-ai-wpoos' ),
						__( 'Leather/vinyl seat conditioning', 'mcp-ai-wpoos' ),
						__( 'Carpet mat shampooing', 'mcp-ai-wpoos' ),
					),
				),
			),
			'add_ons'  => array(
				'soil_mud_sap_oil'          => array(
					'name'            => __( 'Soil / Mud / Sap / Oil', 'mcp-ai-wpoos' ),
					'size_based'      => false,
					'severity_prices' => array(
						'light'    => 25.00,
						'moderate' => 50.00,
						'severe'   => 75.00,
					),
				),
				'pet_hair_removal'          => array(
					'name'            => __( 'Pet Hair Removal', 'mcp-ai-wpoos' ),
					'size_based'      => false,
					'severity_prices' => array(
						'light'    => 45.00,
						'moderate' => 75.00,
						'severe'   => 100.00,
					),
				),
				'additional_interior_clean' => array(
					'name'            => __( 'Additional Interior Clean', 'mcp-ai-wpoos' ),
					'size_based'      => false,
					'severity_prices' => array(
						'light'    => 45.00,
						'moderate' => 75.00,
						'severe'   => 100.00,
					),
				),
				'premium_hand_wash_upgrade' => array(
					'name'       => __( 'Premium Hand Wash Upgrade', 'mcp-ai-wpoos' ),
					'size_based' => true,
					'prices'     => array(
						'car'                => 15.00,
						'small_truck_suv'    => 20.00,
						'oversize_truck_suv' => 25.00,
					),
				),
				'rims_tire_dressing'        => array(
					'name'       => __( 'Rims / Tire Dressing', 'mcp-ai-wpoos' ),
					'price'      => 20.00,
					'size_based' => false,
				),
				'trunk_bed_shampoo'         => array(
					'name'       => __( 'Trunk / Bed Shampoo', 'mcp-ai-wpoos' ),
					'price'      => 15.00,
					'size_based' => false,
				),
				'carpet_seat_deodorizer'    => array(
					'name'       => __( 'Carpet / Seat Deodorizer', 'mcp-ai-wpoos' ),
					'price'      => 30.00,
					'size_based' => false,
				),
			),
		);
	}

	/**
	 * Build a user-facing summary message.
	 *
	 * @since 2.2.0
	 *
	 * @param array $estimate Full estimate data.
	 * @return string Formatted message.
	 */
	protected function build_estimate_message( $estimate ) {
		$size   = $estimate['vehicle_size'] ?? array();
		$pkg    = $estimate['package'] ?? array();
		$totals = $estimate['totals'] ?? array();

		$parts   = array();
		$parts[] = sprintf(
			/* translators: 1: package name, 2: vehicle size label */
			__( 'Vehicle Cleaning Estimate: %1$s — %2$s', 'mcp-ai-wpoos' ),
			$pkg['name'] ?? '',
			$size['label'] ?? ''
		);

		if ( ! empty( $totals['total'] ) ) {
			$parts[] = sprintf(
				/* translators: 1: total amount, 2: currency code */
				__( 'Total: $%1$s %2$s', 'mcp-ai-wpoos' ),
				number_format( $totals['total'], 2 ),
				$totals['currency'] ?? 'CAD'
			);
		}

		$addon_count = ( $totals['line_item_count'] ?? 1 ) - 1;
		if ( $addon_count > 0 ) {
			$parts[] = sprintf(
				/* translators: %d: number of add-on services */
				__( '%d add-on service(s) included', 'mcp-ai-wpoos' ),
				$addon_count
			);
		}

		if ( $totals['tax'] > 0 ) {
			$parts[] = sprintf(
				/* translators: 1: tax percentage, 2: tax amount */
				__( 'Tax (%1$s%%): $%2$s', 'mcp-ai-wpoos' ),
				round( $totals['tax_rate'] * 100, 1 ),
				number_format( $totals['tax'], 2 )
			);
		}

		// Warnings.
		if ( ! empty( $estimate['warnings'] ) ) {
			foreach ( $estimate['warnings'] as $warning ) {
				$parts[] = $warning;
			}
		}

		return implode( "\n", $parts );
	}

	/**
	 * Safely parse JSON from text that may contain surrounding prose.
	 *
	 * @since 2.2.0
	 *
	 * @param string $text Raw text that may contain JSON.
	 * @return array|null Parsed data or null.
	 */
	protected function parse_json_from_text( $text ) {
		if ( empty( $text ) ) {
			return null;
		}

		$decoded = json_decode( $text, true );
		if ( is_array( $decoded ) ) {
			return $decoded;
		}

		// Try extracting JSON from markdown code blocks.
		if ( preg_match( '/```( ? ( :json)?\s*\n?([\s\S]*?)\n?```/', $text, $matches ) ) {
			$decoded = json_decode( $matches[1], true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}

		// Try extracting JSON object from text.
		if ( preg_match( '/\{[\s\S]*\}/', $text, $matches ) ) {
			$decoded = json_decode( $matches[0], true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}

		return null;
	}
}
