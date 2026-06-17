<?php
/**
 * Tool for retrieving CRM contact interactions.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage CRM_Toolkit
 * @since 2.9.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Retrieves all interactions for a CRM contact.
 *
 * Queries CRM activity records (mcp_ai_crm_activity) associated with a
 * specific contact, optionally filtered by interaction type or date range.
 *
 * @since 2.9.0
 */
class WP_MCP_AI_Tool_Get_Contact_Interactions implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_contact_interactions';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get Contact Interactions', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Retrieves all interactions (emails, calls, meetings, notes) for a CRM contact, optionally filtered by type or date range. Returns interaction details sorted by date.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'contact_id'       => array(
					'type'        => 'integer',
					'description' => __( 'CRM contact ID. Required.', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'interaction_type' => array(
					'type'        => 'string',
					'description' => __( 'Filter by interaction type. Default: all.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'email', 'call', 'meeting', 'note', 'all' ),
					'default'     => 'all',
				),
				'date_from'        => array(
					'type'        => 'string',
					'description' => __( 'Filter interactions from this date (YYYY-MM-DD).', 'mcp-ai-wpoos-pro' ),
				),
				'date_to'          => array(
					'type'        => 'string',
					'description' => __( 'Filter interactions up to this date (YYYY-MM-DD).', 'mcp-ai-wpoos-pro' ),
				),
				'limit'            => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of interactions to return. Default: 50.', 'mcp-ai-wpoos-pro' ),
					'default'     => 50,
					'minimum'     => 1,
					'maximum'     => 500,
				),
			),
			'required'   => array( 'contact_id' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'read';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'crm',
			'post_type'             => 'mcp_ai_crm_activity',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'profession_tags'       => array( 'administrator', 'sales_manager', 'account_executive' ),
			'risk_level'            => 'info',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'read-only',
			'local-only',
			'requires-capability',
			'cacheable',
		);
	}

	/**
	 * Check if the tool is available.
	 *
	 * Requires the CRM Toolkit to be enabled in plugin settings.
	 *
	 * @since 2.9.0
	 * @return bool
	 */
	public static function is_available() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_crm_toolkit'] );
	}

	/**
	 * Message explaining why the tool is unavailable.
	 *
	 * @since 2.9.0
	 * @return string
	 */
	public static function get_unavailable_reason() {
		return __( 'The Get Contact Interactions tool requires the CRM Toolkit to be enabled in plugin settings.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array Contact interactions result.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_crm_toolkit'] ) ) {
			return array(
				'success' => false,
				'error'   => __( 'CRM Toolkit is not enabled. Please enable it in Settings → NV oOS → Tools & Features.', 'mcp-ai-wpoos-pro' ),
			);
		}

		$contact_id       = isset( $arguments['contact_id'] ) ? absint( $arguments['contact_id'] ) : 0;
		$interaction_type = isset( $arguments['interaction_type'] ) ? sanitize_text_field( $arguments['interaction_type'] ) : 'all';
		$date_from        = isset( $arguments['date_from'] ) ? sanitize_text_field( $arguments['date_from'] ) : '';
		$date_to          = isset( $arguments['date_to'] ) ? sanitize_text_field( $arguments['date_to'] ) : '';
		$limit            = isset( $arguments['limit'] ) ? absint( $arguments['limit'] ) : 50;
		$limit            = min( max( $limit, 1 ), 500 );

		if ( ! $contact_id ) {
			return array(
				'success' => false,
				'error'   => __( 'A valid contact_id is required.', 'mcp-ai-wpoos-pro' ),
			);
		}

		$query_args = array(
			'post_type'      => 'mcp_ai_crm_activity',
			'posts_per_page' => $limit,
			'post_status'    => 'publish',
			'orderby'        => 'date',
			'order'          => 'DESC',
			'meta_query'     => array(
				array(
					'key'   => '_activity_contact_id',
					'value' => $contact_id,
					'type'  => 'NUMERIC',
				),
			),
		);

		// Filter by interaction type.
		if ( 'all' !== $interaction_type ) {
			$query_args['meta_query'][] = array(
				'key'   => '_activity_type',
				'value' => $interaction_type,
			);
		}

		// Date range filter.
		if ( ! empty( $date_from ) || ! empty( $date_to ) ) {
			$date_query = array( 'inclusive' => true );
			if ( ! empty( $date_from ) ) {
				$date_query['after'] = $date_from;
			}
			if ( ! empty( $date_to ) ) {
				$date_query['before'] = $date_to;
			}
			$query_args['date_query'] = array( $date_query );
		}

		$interactions = array();
		$query        = new WP_Query( $query_args );

		if ( $query->have_posts() ) {
			foreach ( $query->posts as $post ) {
				$interactions[] = array(
					'id'               => $post->ID,
					'type'             => get_post_meta( $post->ID, '_activity_type', true ),
					'subject'          => get_the_title( $post ),
					'date'             => $post->post_date,
					'outcome'          => get_post_meta( $post->ID, '_activity_outcome', true ),
					'duration_minutes' => (int) get_post_meta( $post->ID, '_activity_duration', true ),
					'summary'          => get_the_excerpt( $post ),
				);
			}
		}

		wp_reset_postdata();

		return array(
			'success'          => true,
			'message'          => sprintf(
				/* translators: %d: number of interactions found */
				__( 'Found %1$d interactions for contact #%2$d.', 'mcp-ai-wpoos-pro' ),
				count( $interactions ),
				$contact_id
			),
			'contact_id'       => $contact_id,
			'total_count'      => count( $interactions ),
			'interaction_type' => $interaction_type,
			'interactions'     => $interactions,
		);
	}
}
