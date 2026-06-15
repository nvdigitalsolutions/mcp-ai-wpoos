<?php
/**
 * Rotate Leads Tool — bulk reassignment across the routing pool.
 *
 * @package WP_MCP_AI_Pro
 * @since 2.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rotate Leads — bulk reassignment across the routing pool.
 *
 * @since 2.3.0
 */
class WP_MCP_AI_Tool_Rotate_Leads implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public static function is_available() {
		$s = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $s['enable_crm_toolkit'] );
	}

	/**
	 * {@inheritdoc}
	 */
	public static function get_unavailable_reason() {
		return __( 'CRM Toolkit required.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'rotate_leads';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Rotate Leads', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Bulk-reassign unowned or stale leads across the routing pool using the configured strategy.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'lead_status'     => array(
					'type'        => 'string',
					'default'     => 'new',
					'description' => __( 'Reassign only leads with this status.', 'mcp-ai-wpoos-pro' ),
				),
				'lifecycle_stage' => array(
					'type'        => 'string',
					'default'     => '',
					'description' => __( 'Optional filter by lifecycle stage.', 'mcp-ai-wpoos-pro' ),
				),
				'max_leads'       => array(
					'type'        => 'integer',
					'default'     => 100,
					'minimum'     => 1,
					'maximum'     => 1000,
					'description' => __( 'Maximum number of leads to reassign.', 'mcp-ai-wpoos-pro' ),
				),
				'dry_run'         => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'If true, returns the list of leads that WOULD be reassigned without making changes.', 'mcp-ai-wpoos-pro' ),
				),
			),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'manage_options';
	}

	/**
	 * {@inheritdoc}
	 */
	public function requires_base_pro() {
		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'database-write', 'requires-capability' );
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		if ( ! self::is_available() ) {
			return new WP_Error( 'unavailable', self::get_unavailable_reason() );
		}
		$uid = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $uid || ! user_can( $uid, 'manage_options' ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}

		$max_leads   = min( 1000, max( 1, absint( $arguments['max_leads'] ?? 100 ) ) );
		$dry_run     = ! empty( $arguments['dry_run'] );
		$lead_status = sanitize_key( $arguments['lead_status'] ?? 'new' );

		$meta_q   = array( 'relation' => 'AND' );
		$meta_q[] = array(
			'key'   => 'lead_status',
			'value' => $lead_status,
		);
		if ( ! empty( $arguments['lifecycle_stage'] ) ) {
			$meta_q[] = array(
				'key'   => 'lifecycle_stage',
				'value' => sanitize_key( $arguments['lifecycle_stage'] ),
			);
		}

		$q = new WP_Query(
			array(
				'post_type'      => array( 'mcp_ai_lead', 'mcp_crm_contacts' ),
				'post_status'    => 'publish',
				'posts_per_page' => $max_leads,
				'fields'         => 'ids',
				'meta_query'     => $meta_q, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'no_found_rows'  => true,
			)
		);

		$reassigned = array();
		foreach ( $q->posts as $lead_id ) {
			$current_owner = get_post_meta( $lead_id, 'contact_owner', true );
			$new_owner     = class_exists( 'WP_MCP_AI_CRM_Engine' ) ? WP_MCP_AI_CRM_Engine::get_next_owner() : 0;

			if ( ! $new_owner ) {
				continue;
			}

			if ( ! $dry_run ) {
				update_post_meta( $lead_id, 'contact_owner', $new_owner );
				if ( class_exists( 'WP_MCP_AI_CRM_Audit' ) ) {
					WP_MCP_AI_CRM_Audit::record(
						'lead_rotated',
						'lead',
						$lead_id,
						array(
							'previous_owner' => $current_owner,
							'new_owner'      => $new_owner,
						)
					);
				}
			}

			$reassigned[] = array(
				'lead_id'        => $lead_id,
				'title'          => get_the_title( $lead_id ),
				'previous_owner' => $current_owner,
				'new_owner'      => $new_owner,
			);
		}

		return array(
			'success'          => true,
			'dry_run'          => $dry_run,
			'reassigned_count' => count( $reassigned ),
			'total_found'      => $q->found_posts,
			'reassigned'       => $reassigned,
			'message'          => $dry_run
				/* translators: %d: number of leads */
				? sprintf( __( 'Dry run: %d leads would be reassigned.', 'mcp-ai-wpoos-pro' ), count( $reassigned ) )
				/* translators: %d: number of leads */
				: sprintf( __( '%d leads reassigned.', 'mcp-ai-wpoos-pro' ), count( $reassigned ) ),
		);
	}
}
