<?php
/**
 * Merge Support Tickets Tool
 *
 * Merges duplicate support tickets by copying activities from the
 * source ticket to the target, then closing the source as duplicate.
 *
 * @package WP_MCP_AI_Pro
 * @since 2.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * {@inheritdoc}
 */
class WP_MCP_AI_Tool_Merge_Support_Tickets implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	use WP_MCP_AI_Tool_Envelope;

	/**
	 * {@inheritdoc}
	 */
	public static function is_available() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_crm_toolkit'] );
	}

	/**
	 * {@inheritdoc}
	 */
	public static function get_unavailable_reason() {
		return __( 'The Merge Support Tickets tool requires the CRM Toolkit to be enabled.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'merge_support_tickets';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Merge Support Tickets', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Merge duplicate support tickets by copying activities to the parent and closing duplicates.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'target_ticket_id' => array(
					'type'        => 'integer',
					'description' => __( 'ID of the ticket to keep (target/parent).', 'mcp-ai-wpoos-pro' ),
				),
				'source_ticket_id' => array(
					'type'        => 'integer',
					'description' => __( 'ID of the ticket to merge (will be closed as duplicate).', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'target_ticket_id', 'source_ticket_id' ),
		);
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
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'database-write', 'requires-capability' );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$target_id = absint( $arguments['target_ticket_id'] ?? 0 );
		$source_id = absint( $arguments['source_ticket_id'] ?? 0 );

		if ( ! $target_id || ! $source_id ) {
			return new WP_Error( 'invalid_tickets', __( 'Both target and source ticket IDs are required.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( $target_id === $source_id ) {
			return new WP_Error( 'same_ticket', __( 'Cannot merge a ticket into itself.', 'mcp-ai-wpoos-pro' ) );
		}

		$target = get_post( $target_id );
		$source = get_post( $source_id );
		if ( ! $target || 'mcp_ai_support_ticket' !== $target->post_type ) {
			return new WP_Error( 'target_not_found', __( 'Target support ticket not found.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( ! $source || 'mcp_ai_support_ticket' !== $source->post_type ) {
			return new WP_Error( 'source_not_found', __( 'Source support ticket not found.', 'mcp-ai-wpoos-pro' ) );
		}

		$source_status = get_post_meta( $source_id, '_ticket_status', true );
		if ( 'closed' === $source_status ) {
			return new WP_Error( 'already_closed', __( 'Source ticket is already closed.', 'mcp-ai-wpoos-pro' ) );
		}

		// Reassign activities from source to target.
		$activities = get_posts(
			array(
				'post_type'      => 'mcp_ai_crm_activity',
				'post_status'    => 'publish',
				'posts_per_page' => 100,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => 'related_id',
						'value' => $source_id,
						'type'  => 'NUMERIC',
					),
					array(
						'key'   => 'related_type',
						'value' => 'ticket',
					),
				),
			)
		);

		$moved_count = 0;
		foreach ( $activities as $activity ) {
			update_post_meta( $activity->ID, 'related_id', $target_id );
			$old_content = $activity->post_content;
			wp_update_post(
				array(
					'ID'           => $activity->ID,
					'post_content' => sprintf(
						/* translators: %d: source ticket ID */
						__( '[Merged from Ticket #%d] ', 'mcp-ai-wpoos-pro' ),
						$source_id
					) . $old_content,
				)
			);
			++$moved_count;
		}

		// Close the source ticket as duplicate.
		update_post_meta( $source_id, '_ticket_status', 'closed' );
		update_post_meta( $source_id, '_ticket_resolution_type', 'duplicate' );
		update_post_meta(
			$source_id,
			'_ticket_resolution_note',
			sprintf(
			/* translators: %d: target ticket ID */
				__( 'Merged into Ticket #%d.', 'mcp-ai-wpoos-pro' ),
				$target_id
			)
		);
		update_post_meta( $source_id, '_ticket_parent_id', $target_id );
		update_post_meta( $source_id, '_ticket_closed_at', current_time( 'mysql' ) );
		update_post_meta( $source_id, '_ticket_closed_by', get_current_user_id() );

		// Add a note on the target about the merge.
		$activity_id = wp_insert_post(
			array(
				'post_type'    => 'mcp_ai_crm_activity',
				'post_title'   => sprintf(
					/* translators: 1: source ticket ID, 2: source subject */
					__( 'Ticket #%1$d merged: %2$s', 'mcp-ai-wpoos-pro' ),
					$source_id,
					$source->post_title
				),
				'post_content' => sprintf(
					/* translators: %d: count of moved activities */
					__( 'Merged %d activities from the duplicate ticket.', 'mcp-ai-wpoos-pro' ),
					$moved_count
				),
				'post_status'  => 'publish',
			)
		);
		if ( ! is_wp_error( $activity_id ) ) {
			update_post_meta( $activity_id, 'activity_type', 'note' );
			update_post_meta( $activity_id, 'related_type', 'ticket' );
			update_post_meta( $activity_id, 'related_id', $target_id );
		}

		return $this->format_success_response(
			__( 'Support tickets merged successfully.', 'mcp-ai-wpoos-pro' ),
			array(
				'target_ticket_id' => $target_id,
				'source_ticket_id' => $source_id,
				'moved_activities' => $moved_count,
				'edit_url'         => get_edit_post_link( $target_id, 'raw' ),
			)
		);
	}
}
