<?php
/**
 * Tool: get_vaccination_schedule
 *
 * Returns the recommended vaccine doses for a subject of a given age and
 * species, classified into due / overdue / upcoming / given buckets.
 * Wraps `WP_MCP_AI_Healthcare_Vaccination_Schedules`.
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

/**
 * Get vaccination schedule tool.
 */
class WP_MCP_AI_Tool_Get_Vaccination_Schedule implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Whether the tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		if ( class_exists( 'WP_MCP_AI_Healthcare_Engine' ) ) {
			return WP_MCP_AI_Healthcare_Engine::is_subtoolkit_enabled( 'vitals' );
		}
		return false;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_vaccination_schedule';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get Vaccination Schedule', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Return CDC, WHO, AAFP feline, or AAHA canine vaccine recommendations for a given age, sorted into due/overdue/upcoming buckets.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'pack'        => array(
					'type'        => 'string',
					'description' => __( 'Schedule pack slug. Defaults are auto-selected from age + species.', 'mcp-ai-wpoos-pro' ),
				),
				'age_years'   => array(
					'type'        => 'number',
					'description' => __( 'Age in years.', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
					'maximum'     => 150,
				),
				'age_days'    => array(
					'type'        => 'integer',
					'description' => __( 'Age in days (overrides age_years).', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
				),
				'species'     => array(
					'type'        => 'string',
					'description' => __( 'Species ("human", "canine", "feline").', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'human', 'canine', 'feline' ),
					'default'     => 'human',
				),
				'given_codes' => array(
					'type'        => 'array',
					'description' => __( 'Array of vaccine codes already administered (CVX or short slug).', 'mcp-ai-wpoos-pro' ),
					'items'       => array( 'type' => 'string' ),
					'default'     => array(),
				),
			),
			'required'   => array(),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'read-only', 'local-only', 'cacheable', 'idempotent' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Execute.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		if ( ! class_exists( 'WP_MCP_AI_Healthcare_Vaccination_Schedules' ) ) {
			return new WP_Error( 'wp_mcp_ai_unavailable', __( 'Vaccination schedule registry not loaded.', 'mcp-ai-wpoos-pro' ) );
		}

		$species  = isset( $arguments['species'] ) ? sanitize_key( $arguments['species'] ) : 'human';
		$age_days = null;
		if ( isset( $arguments['age_days'] ) && '' !== $arguments['age_days'] ) {
			$age_days = max( 0, (int) $arguments['age_days'] );
		} elseif ( isset( $arguments['age_years'] ) ) {
			$age_days = max( 0, (int) round( floatval( $arguments['age_years'] ) * 365.25 ) );
		} else {
			$age_days = 0;
		}

		$pack_slug = isset( $arguments['pack'] ) ? sanitize_key( $arguments['pack'] ) : '';
		$pack      = '' !== $pack_slug ? WP_MCP_AI_Healthcare_Vaccination_Schedules::get( $pack_slug ) : null;
		if ( null === $pack ) {
			$pack_slug = self::auto_pick_pack( $species, $age_days );
			$pack      = WP_MCP_AI_Healthcare_Vaccination_Schedules::get( $pack_slug );
		}
		if ( null === $pack ) {
			return new WP_Error( 'wp_mcp_ai_no_pack', __( 'No matching vaccination schedule pack found.', 'mcp-ai-wpoos-pro' ) );
		}

		$given_codes = array();
		if ( isset( $arguments['given_codes'] ) && is_array( $arguments['given_codes'] ) ) {
			foreach ( $arguments['given_codes'] as $code ) {
				$given_codes[] = sanitize_text_field( (string) $code );
			}
		}

		$evaluated = WP_MCP_AI_Healthcare_Vaccination_Schedules::evaluate( $pack, $age_days, $given_codes );

		return array(
			'success'     => true,
			'pack_slug'   => $pack_slug,
			'pack_name'   => isset( $pack['name'] ) ? $pack['name'] : '',
			'pack_source' => isset( $pack['source'] ) ? $pack['source'] : '',
			'species'     => $species,
			'age_days'    => $age_days,
			'due'         => $evaluated['due'],
			'overdue'     => $evaluated['overdue'],
			'upcoming'    => $evaluated['upcoming'],
			'given'       => $evaluated['given'],
		);
	}

	/**
	 * Pick a sensible default pack from species + age.
	 *
	 * @param string $species  Species slug.
	 * @param int    $age_days Age in days.
	 * @return string Pack slug.
	 */
	private static function auto_pick_pack( $species, $age_days ) {
		switch ( $species ) {
			case 'canine':
				return 'aaha-canine-core';
			case 'feline':
				return 'aafp-feline-core';
			case 'human':
			default:
				return $age_days < 6570 ? 'cdc-pediatric-2025' : 'cdc-adult-2025';
		}
	}
}
