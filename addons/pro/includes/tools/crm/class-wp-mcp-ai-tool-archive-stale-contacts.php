<?php
/**
 * Tool for archiving stale CRM contacts with no recent activity.
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
 * Archives CRM contacts that have had no activity for a specified period.
 *
 * Queries contacts (leads and/or customers) that have no associated CRM activity
 * records within the given inactivity window and archives them. Supports dry_run
 * mode for safe preview.
 *
 * @since 2.9.0
 */
class WP_MCP_AI_Tool_Archive_Stale_Contacts implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'archive_stale_contacts';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Archive Stale Contacts', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Archives CRM contacts (leads and/or customers) that have had no activity for a specified period. Supports dry_run mode to preview which contacts would be archived.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'days_inactive' => array(
					'type'        => 'integer',
					'description' => __( 'Number of days without activity before a contact is considered stale. Default: 365.', 'mcp-ai-wpoos-pro' ),
					'default'     => 365,
					'minimum'     => 30,
					'maximum'     => 3650,
				),
				'contact_type'  => array(
					'type'        => 'string',
					'description' => __( 'Type of contacts to archive. Default: all.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'lead', 'customer', 'all' ),
					'default'     => 'all',
				),
				'dry_run'       => array(
					'type'        => 'boolean',
					'description' => __( 'If true, preview which contacts would be archived without making changes. Default: true.', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
			),
			'required'   => array(),
		);
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
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'crm',
			'post_type'             => 'mcp_ai_lead',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'profession_tags'       => array( 'administrator', 'sales_manager', 'sales_ops' ),
			'risk_level'            => 'caution',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'write',
			'state-changing',
			'local-only',
			'requires-capability',
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
		return __( 'The Archive Stale Contacts tool requires the CRM Toolkit to be enabled in plugin settings.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array Archive result.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_crm_toolkit'] ) ) {
			return array(
				'success' => false,
				'error'   => __( 'CRM Toolkit is not enabled. Please enable it in Settings → NV oOS → Tools & Features.', 'mcp-ai-wpoos-pro' ),
			);
		}

		$days_inactive = isset( $arguments['days_inactive'] ) ? absint( $arguments['days_inactive'] ) : 365;
		$days_inactive = max( 30, min( $days_inactive, 3650 ) );
		$contact_type  = isset( $arguments['contact_type'] ) ? sanitize_text_field( $arguments['contact_type'] ) : 'all';
		$dry_run       = isset( $arguments['dry_run'] ) ? (bool) $arguments['dry_run'] : true;

		$cutoff_date = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days_inactive} days" ) );

		// Determine which post types to scan.
		$post_types = array();
		if ( 'all' === $contact_type || 'lead' === $contact_type ) {
			$post_types[] = 'mcp_ai_lead';
		}
		if ( 'all' === $contact_type || 'customer' === $contact_type ) {
			$post_types[] = 'mcp_ai_customer';
		}

		$archived  = array();
		$skipped   = array();

		foreach ( $post_types as $post_type ) {
			// Find contacts with no activity since the cutoff date.
			$query_args = array(
				'post_type'      => $post_type,
				'posts_per_page' => -1,
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'date_query'     => array(
					array(
						'before'    => $cutoff_date,
						'inclusive' => true,
					),
				),
			);

			$contact_ids = get_posts( $query_args );

			foreach ( $contact_ids as $contact_id ) {
				// Check for recent activity.
				$recent_activity = $this->get_latest_activity_date( $contact_id );
				$is_stale        = empty( $recent_activity ) || $recent_activity < $cutoff_date;

				if ( ! $is_stale ) {
					$skipped[] = array(
						'id'            => $contact_id,
						'type'          => $post_type,
						'title'         => get_the_title( $contact_id ),
						'last_activity' => $recent_activity,
						'reason'        => __( 'Has recent activity.', 'mcp-ai-wpoos-pro' ),
					);
					continue;
				}

				$result_item = array(
					'id'            => $contact_id,
					'type'          => $post_type,
					'title'         => get_the_title( $contact_id ),
					'last_activity' => $recent_activity ? $recent_activity : __( 'Never', 'mcp-ai-wpoos-pro' ),
				);

				if ( ! $dry_run ) {
					$updated = wp_update_post(
						array(
							'ID'          => $contact_id,
							'post_status' => 'draft',
						),
						true
					);

					if ( is_wp_error( $updated ) ) {
						$skipped[] = array_merge( $result_item, array( 'reason' => $updated->get_error_message() ) );
						continue;
					}

					update_post_meta( $contact_id, '_archived_date', gmdate( 'Y-m-d H:i:s' ) );
					update_post_meta( $contact_id, '_archive_reason', __( 'Stale contact (no recent activity).', 'mcp-ai-wpoos-pro' ) );
					update_post_meta( $contact_id, '_archived_by', get_current_user_id() );
					$result_item['new_status'] = 'draft';
				}

				$archived[] = $result_item;
			}
		}

		return array(
			'success'        => true,
			'dry_run'        => $dry_run,
			'days_inactive'  => $days_inactive,
			'cutoff_date'    => $cutoff_date,
			'action'         => $dry_run
				? __( 'Dry run completed. No contacts were modified.', 'mcp-ai-wpoos-pro' )
				: __( 'Stale contacts archived successfully.', 'mcp-ai-wpoos-pro' ),
			'archived_count' => count( $archived ),
			'skipped_count'  => count( $skipped ),
			'contacts'       => array(
				'archived' => $archived,
				'skipped'  => $skipped,
			),
		);
	}

	/**
	 * Get the date of the latest CRM activity for a contact.
	 *
	 * @since 2.9.0
	 * @param int $contact_id Contact post ID.
	 * @return string|false Latest activity date or false if none found.
	 */
	private function get_latest_activity_date( $contact_id ) {
		$query = new WP_Query(
			array(
				'post_type'      => 'mcp_ai_crm_activity',
				'posts_per_page' => 1,
				'post_status'    => 'publish',
				'orderby'        => 'date',
				'order'          => 'DESC',
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'   => '_activity_contact_id',
						'value' => $contact_id,
						'type'  => 'NUMERIC',
					),
				),
			)
		);

		if ( $query->have_posts() ) {
			$post = get_post( $query->posts[0] );
			wp_reset_postdata();
			return $post ? $post->post_date : false;
		}

		wp_reset_postdata();
		return false;
	}
}
