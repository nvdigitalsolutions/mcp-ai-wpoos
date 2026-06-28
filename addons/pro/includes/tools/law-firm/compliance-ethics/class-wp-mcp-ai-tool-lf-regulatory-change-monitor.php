<?php
/**
 * Regulatory Change Monitor Tool
 *
 * Monitors regulatory and legal changes across practice areas and jurisdictions.
 *
 * @package WP_MCP_AI_Pro
 * @since 2.0.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Monitors regulatory and legal changes for specified practice areas and jurisdictions.
 */
class WP_MCP_AI_Tool_LF_Regulatory_Change_Monitor implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	const DISCLAIMER = 'This is not legal advice. Consult a licensed attorney for specific legal matters.';

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Practice area regulatory sources.
	 *
	 * @var array
	 */
	private static $regulatory_sources = array(
		'corporate'             => array(
			'SEC Rulemaking and Guidance',
			'FINRA Regulatory Notices',
			'State Corporate Law Amendments',
			'UCC Article Revisions',
		),
		'employment'            => array(
			'DOL Final Rules and Guidance',
			'EEOC Policy Updates',
			'NLRB Decisions and Rules',
			'State Wage and Hour Law Changes',
			'OSHA Standards Updates',
		),
		'healthcare'            => array(
			'CMS Final Rules',
			'HHS OIG Advisory Opinions',
			'FDA Guidance Documents',
			'State Health Privacy Law Changes',
			'HIPAA Enforcement Updates',
		),
		'real_estate'           => array(
			'CFPB Rulemaking',
			'HUD Regulatory Changes',
			'State Property Law Amendments',
			'Zoning and Land Use Updates',
		),
		'intellectual_property' => array(
			'USPTO Rule Changes',
			'Copyright Office Rulemakings',
			'ITC Procedural Updates',
			'International IP Treaty Developments',
		),
		'tax'                   => array(
			'IRS Revenue Rulings and Procedures',
			'Treasury Regulations',
			'State Tax Law Changes',
			'International Tax Treaty Updates',
		),
		'immigration'           => array(
			'USCIS Policy Manual Updates',
			'DOS Visa Bulletin Changes',
			'DOL PERM Regulation Updates',
			'Executive Orders Impacting Immigration',
		),
		'environmental'         => array(
			'EPA Final Rules',
			'State Environmental Regulation Changes',
			'International Climate Agreements',
			'Clean Water Act / Clean Air Act Updates',
		),
		'data_privacy'          => array(
			'FTC Privacy Enforcement Actions',
			'State Consumer Privacy Law Enactments',
			'International Data Transfer Frameworks',
			'AI Regulation Developments',
		),
	);

	/**
	 * Check if the tool is available.
	 *
	 * @return bool
	 */
	public static function is_available(): bool {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_law_firm_toolkit'] );
	}

	/**
	 * Get the reason the tool is unavailable.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason(): string {
		return __( 'Law Firm toolkit is not enabled.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_slug() {
		return 'lf_regulatory_change_monitor';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return __( 'Regulatory Change Monitor', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return __( 'Monitors regulatory and legal changes across specified practice areas and jurisdictions, returning a structured monitoring framework with applicable sources and tracked updates.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'practice_areas' => array(
					'type'        => 'array',
					'description' => __( 'Practice areas to monitor for regulatory changes.', 'mcp-ai-wpoos-pro' ),
					'items'       => array( 'type' => 'string' ),
				),
				'jurisdictions'  => array(
					'type'        => 'array',
					'description' => __( 'Jurisdictions to monitor (e.g., "Federal", "California", "EU").', 'mcp-ai-wpoos-pro' ),
					'items'       => array( 'type' => 'string' ),
				),
				'time_period'    => array(
					'type'        => 'string',
					'description' => __( 'Time period to look back for changes.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'week', 'month', 'quarter' ),
					'default'     => 'month',
				),
			),
			'required'   => array(),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_capability_flags(): array {
		return array( 'pro', 'read-only', 'external-api' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$uid = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $uid || ! user_can( $uid, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( ! self::is_available() ) {
			return new WP_Error( 'tool_not_available', self::get_unavailable_reason() );
		}

		$practice_areas = array();
		if ( ! empty( $arguments['practice_areas'] ) && is_array( $arguments['practice_areas'] ) ) {
			$practice_areas = array_map( 'sanitize_text_field', $arguments['practice_areas'] );
		}

		$jurisdictions = array();
		if ( ! empty( $arguments['jurisdictions'] ) && is_array( $arguments['jurisdictions'] ) ) {
			$jurisdictions = array_map( 'sanitize_text_field', $arguments['jurisdictions'] );
		}

		$time_period = isset( $arguments['time_period'] ) ? sanitize_text_field( $arguments['time_period'] ) : 'month';
		if ( ! in_array( $time_period, array( 'week', 'month', 'quarter' ), true ) ) {
			$time_period = 'month';
		}

		// Determine date range.
		switch ( $time_period ) {
			case 'week':
				$since_date = gmdate( 'Y-m-d', strtotime( '-1 week' ) );
				$label      = __( 'past week', 'mcp-ai-wpoos-pro' );
				break;
			case 'quarter':
				$since_date = gmdate( 'Y-m-d', strtotime( '-3 months' ) );
				$label      = __( 'past quarter', 'mcp-ai-wpoos-pro' );
				break;
			default:
				$since_date = gmdate( 'Y-m-d', strtotime( '-1 month' ) );
				$label      = __( 'past month', 'mcp-ai-wpoos-pro' );
		}

		// Build monitoring framework.
		$monitoring_framework = array();
		$total_sources        = 0;

		// If no practice areas given, use all configured.
		if ( empty( $practice_areas ) ) {
			$practice_areas = array_keys( self::$regulatory_sources );
		}

		foreach ( $practice_areas as $area ) {
			$area_key = sanitize_title( str_replace( ' ', '_', strtolower( $area ) ) );
			$sources  = isset( self::$regulatory_sources[ $area_key ] ) ? self::$regulatory_sources[ $area_key ] : array();

			// Query any stored regulatory updates from WP posts.
			$query_args = array(
				'post_type'      => 'mcp_ai_lf_reg_update',
				'post_status'    => 'publish',
				'posts_per_page' => 20,
				'date_query'     => array(
					array(
						'after' => $since_date,
					),
				),
				'meta_query'     => array(
					array(
						'key'     => '_lf_practice_area',
						'value'   => $area_key,
						'compare' => '=',
					),
				),
			);

			// Add jurisdiction filter if specified.
			if ( ! empty( $jurisdictions ) ) {
				$query_args['meta_query'][] = array(
					'key'     => '_lf_jurisdiction',
					'value'   => $jurisdictions,
					'compare' => 'IN',
				);
			}

			$updates_query   = new WP_Query( $query_args );
			$tracked_updates = array();

			if ( $updates_query->have_posts() ) {
				foreach ( $updates_query->posts as $update_post ) {
					$tracked_updates[] = array(
						'id'           => $update_post->ID,
						'title'        => $update_post->post_title,
						'summary'      => wp_trim_words( $update_post->post_content, 50 ),
						'date'         => get_the_date( 'Y-m-d', $update_post ),
						'jurisdiction' => get_post_meta( $update_post->ID, '_lf_jurisdiction', true ),
						'impact_level' => get_post_meta( $update_post->ID, '_lf_impact_level', true ),
						'source'       => get_post_meta( $update_post->ID, '_lf_source', true ),
					);
				}
			}
			wp_reset_postdata();

			$total_sources += count( $sources );

			$monitoring_framework[] = array(
				'practice_area'      => $area,
				'regulatory_sources' => $sources,
				'tracked_updates'    => $tracked_updates,
				'update_count'       => count( $tracked_updates ),
			);
		}

		// Check for jurisdiction-specific stored monitors.
		$active_monitors = array();
		if ( ! empty( $jurisdictions ) ) {
			foreach ( $jurisdictions as $jurisdiction ) {
				$monitor_key = 'wp_mcp_ai_lf_monitor_' . sanitize_key( $jurisdiction );
				$monitor     = get_option( $monitor_key, array() );
				if ( ! empty( $monitor ) ) {
					$active_monitors[] = array(
						'jurisdiction' => $jurisdiction,
						'last_checked' => isset( $monitor['last_checked'] ) ? $monitor['last_checked'] : __( 'Never', 'mcp-ai-wpoos-pro' ),
						'alerts_count' => isset( $monitor['alerts_count'] ) ? absint( $monitor['alerts_count'] ) : 0,
					);
				}
			}
		}

		$total_updates = 0;
		foreach ( $monitoring_framework as $entry ) {
			$total_updates += $entry['update_count'];
		}

		return array(
			'success'    => true,
			'message'    => sprintf(
				/* translators: 1: areas count, 2: updates count, 3: time period label */
				__( 'Monitoring %1$d practice areas: %2$d tracked updates in the %3$s.', 'mcp-ai-wpoos-pro' ),
				count( $monitoring_framework ),
				$total_updates,
				$label
			) . ' ' . self::DISCLAIMER,
			'data'       => array(
				'time_period'          => $time_period,
				'since_date'           => $since_date,
				'practice_areas'       => $practice_areas,
				'jurisdictions'        => $jurisdictions,
				'monitoring_framework' => $monitoring_framework,
				'active_monitors'      => $active_monitors,
				'total_sources'        => $total_sources,
				'total_updates'        => $total_updates,
			),
			'disclaimer' => self::DISCLAIMER,
		);
	}
}
