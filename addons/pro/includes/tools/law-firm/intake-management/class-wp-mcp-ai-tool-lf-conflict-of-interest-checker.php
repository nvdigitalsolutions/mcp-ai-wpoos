<?php
/**
 * Conflict of Interest Checker Tool
 *
 * Checks for potential conflicts of interest across existing clients and matters.
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
 * Checks for conflicts of interest across clients and matters.
 */
class WP_MCP_AI_Tool_LF_Conflict_Of_Interest_Checker implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	const DISCLAIMER = 'This is not legal advice. Consult a licensed attorney for specific legal matters.';

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

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
		return 'lf_conflict_of_interest_checker';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return __( 'Conflict of Interest Checker', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return __( 'Checks for potential conflicts of interest by searching existing client records and matter data for matching party names and related entities.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'party_name'         => array(
					'type'        => 'string',
					'description' => __( 'Name of the party to check for conflicts.', 'mcp-ai-wpoos-pro' ),
					'minLength'   => 1,
				),
				'party_type'         => array(
					'type'        => 'string',
					'description' => __( 'Type of party being checked.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'individual', 'corporation', 'entity' ),
				),
				'related_entities'   => array(
					'type'        => 'array',
					'description' => __( 'Related entities to also check for conflicts.', 'mcp-ai-wpoos-pro' ),
					'items'       => array( 'type' => 'string' ),
				),
				'matter_description' => array(
					'type'        => 'string',
					'description' => __( 'Brief description of the matter for context.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'party_name' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_capability_flags(): array {
		return array( 'pro', 'read-only', 'cacheable' );
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

		$party_name       = isset( $arguments['party_name'] ) ? sanitize_text_field( $arguments['party_name'] ) : '';
		$party_type       = isset( $arguments['party_type'] ) ? sanitize_text_field( $arguments['party_type'] ) : 'individual';
		$related_entities = array();
		if ( ! empty( $arguments['related_entities'] ) && is_array( $arguments['related_entities'] ) ) {
			$related_entities = array_map( 'sanitize_text_field', $arguments['related_entities'] );
		}

		if ( empty( $party_name ) ) {
			return new WP_Error( 'missing_required', __( 'Party name is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$search_terms        = array_merge( array( $party_name ), $related_entities );
		$potential_conflicts = array();

		foreach ( $search_terms as $term ) {
			$client_query = new WP_Query(
				array(
					'post_type'      => 'mcp_ai_lf_client',
					'post_status'    => 'publish',
					's'              => $term,
					'posts_per_page' => 50,
				)
			);

			if ( $client_query->have_posts() ) {
				foreach ( $client_query->posts as $client_post ) {
					$potential_conflicts[] = array(
						'type'          => 'client',
						'id'            => $client_post->ID,
						'name'          => $client_post->post_title,
						'matched_term'  => $term,
						'practice_area' => get_post_meta( $client_post->ID, '_lf_practice_area', true ),
						'intake_date'   => get_post_meta( $client_post->ID, '_lf_intake_date', true ),
					);
				}
			}
			wp_reset_postdata();

			$matter_query = new WP_Query(
				array(
					'post_type'      => 'mcp_ai_lf_matter',
					'post_status'    => 'publish',
					's'              => $term,
					'posts_per_page' => 50,
				)
			);

			if ( $matter_query->have_posts() ) {
				foreach ( $matter_query->posts as $matter_post ) {
					$opposing              = get_post_meta( $matter_post->ID, '_lf_opposing_counsel', true );
					$potential_conflicts[] = array(
						'type'          => 'matter',
						'id'            => $matter_post->ID,
						'title'         => $matter_post->post_title,
						'matched_term'  => $term,
						'status'        => get_post_meta( $matter_post->ID, '_lf_status', true ),
						'practice_area' => get_post_meta( $matter_post->ID, '_lf_practice_area', true ),
					);
				}
			}
			wp_reset_postdata();
		}

		$conflicts_found = count( $potential_conflicts ) > 0;
		$recommendation  = $conflicts_found
			? __( 'Potential conflicts detected. A thorough manual review is recommended before proceeding.', 'mcp-ai-wpoos-pro' )
			: __( 'No conflicts found in existing records. Proceed with standard intake procedures.', 'mcp-ai-wpoos-pro' );

		return array(
			'success'    => true,
			'message'    => $recommendation . ' ' . self::DISCLAIMER,
			'data'       => array(
				'conflicts_found'     => $conflicts_found,
				'conflict_count'      => count( $potential_conflicts ),
				'potential_conflicts' => $potential_conflicts,
				'search_terms'        => $search_terms,
				'party_type'          => $party_type,
				'recommendation'      => $recommendation,
			),
			'disclaimer' => self::DISCLAIMER,
		);
	}
}
