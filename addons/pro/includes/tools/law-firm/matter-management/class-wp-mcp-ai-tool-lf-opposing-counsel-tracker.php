<?php
/**
 * Opposing Counsel Tracker Tool
 *
 * Tracks opposing counsel information and history across matters.
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
 * Tracks opposing counsel information on legal matters.
 */
class WP_MCP_AI_Tool_LF_Opposing_Counsel_Tracker implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'lf_opposing_counsel_tracker';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return __( 'Opposing Counsel Tracker', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return __( 'Tracks opposing counsel information on matters including attorney name, firm, bar number, contact details, and notes. Supports adding, listing, and viewing history across matters.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'action'        => array(
					'type'        => 'string',
					'description' => __( 'Opposing counsel action.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'add', 'list', 'get_history' ),
				),
				'matter_id'     => array(
					'type'        => 'integer',
					'description' => __( 'The matter ID.', 'mcp-ai-wpoos-pro' ),
				),
				'attorney_name' => array(
					'type'        => 'string',
					'description' => __( 'Name of the opposing attorney.', 'mcp-ai-wpoos-pro' ),
				),
				'firm_name'     => array(
					'type'        => 'string',
					'description' => __( 'Name of the opposing firm.', 'mcp-ai-wpoos-pro' ),
				),
				'bar_number'    => array(
					'type'        => 'string',
					'description' => __( 'Bar number of the opposing attorney.', 'mcp-ai-wpoos-pro' ),
				),
				'email'         => array(
					'type'        => 'string',
					'description' => __( 'Email address of the opposing attorney.', 'mcp-ai-wpoos-pro' ),
				),
				'phone'         => array(
					'type'        => 'string',
					'description' => __( 'Phone number of the opposing attorney.', 'mcp-ai-wpoos-pro' ),
				),
				'notes'         => array(
					'type'        => 'string',
					'description' => __( 'Additional notes about opposing counsel.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'action', 'matter_id' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_capability_flags(): array {
		return array( 'pro', 'write', 'state-changing' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$uid = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $uid || ! user_can( $uid, 'manage_options' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( ! self::is_available() ) {
			return new WP_Error( 'tool_not_available', self::get_unavailable_reason() );
		}

		$action    = isset( $arguments['action'] ) ? sanitize_text_field( $arguments['action'] ) : '';
		$matter_id = isset( $arguments['matter_id'] ) ? absint( $arguments['matter_id'] ) : 0;

		if ( empty( $action ) || ! $matter_id ) {
			return new WP_Error( 'missing_required', __( 'Action and matter ID are required.', 'mcp-ai-wpoos-pro' ) );
		}

		$matter = get_post( $matter_id );
		if ( ! $matter || 'mcp_ai_lf_matter' !== $matter->post_type ) {
			return new WP_Error( 'not_found', __( 'Matter not found.', 'mcp-ai-wpoos-pro' ) );
		}

		$counsel_list = get_post_meta( $matter_id, '_lf_opposing_counsel', true );
		if ( ! is_array( $counsel_list ) ) {
			$counsel_list = array();
		}

		switch ( $action ) {
			case 'add':
				$attorney_name = isset( $arguments['attorney_name'] ) ? sanitize_text_field( $arguments['attorney_name'] ) : '';
				if ( empty( $attorney_name ) ) {
					return new WP_Error( 'missing_required', __( 'Attorney name is required.', 'mcp-ai-wpoos-pro' ) );
				}

				$entry = array(
					'id'            => wp_generate_uuid4(),
					'attorney_name' => $attorney_name,
					'firm_name'     => isset( $arguments['firm_name'] ) ? sanitize_text_field( $arguments['firm_name'] ) : '',
					'bar_number'    => isset( $arguments['bar_number'] ) ? sanitize_text_field( $arguments['bar_number'] ) : '',
					'email'         => isset( $arguments['email'] ) ? sanitize_email( $arguments['email'] ) : '',
					'phone'         => isset( $arguments['phone'] ) ? sanitize_text_field( $arguments['phone'] ) : '',
					'notes'         => isset( $arguments['notes'] ) ? sanitize_textarea_field( $arguments['notes'] ) : '',
					'added_by'      => $uid,
					'added_at'      => current_time( 'Y-m-d H:i:s' ),
				);

				$counsel_list[] = $entry;
				update_post_meta( $matter_id, '_lf_opposing_counsel', $counsel_list );

				return array(
					'success'    => true,
					'message'    => __( 'Opposing counsel added. ', 'mcp-ai-wpoos-pro' ) . self::DISCLAIMER,
					'data'       => array(
						'counsel_id'    => $entry['id'],
						'matter_id'     => $matter_id,
						'attorney_name' => $attorney_name,
						'firm_name'     => $entry['firm_name'],
					),
					'disclaimer' => self::DISCLAIMER,
				);

			case 'list':
				return array(
					'success'    => true,
					'message'    => sprintf(
						/* translators: %d: number of opposing counsel */
						__( '%d opposing counsel records found. ', 'mcp-ai-wpoos-pro' ),
						count( $counsel_list )
					) . self::DISCLAIMER,
					'data'       => array(
						'matter_id'        => $matter_id,
						'opposing_counsel' => $counsel_list,
						'total'            => count( $counsel_list ),
					),
					'disclaimer' => self::DISCLAIMER,
				);

			case 'get_history':
				$attorney_name = isset( $arguments['attorney_name'] ) ? sanitize_text_field( $arguments['attorney_name'] ) : '';
				if ( empty( $attorney_name ) ) {
					return new WP_Error( 'missing_required', __( 'Attorney name is required for history lookup.', 'mcp-ai-wpoos-pro' ) );
				}

				// Search across all matters for this attorney.
				$all_matters = new WP_Query(
					array(
						'post_type'      => 'mcp_ai_lf_matter',
						'post_status'    => 'publish',
						'posts_per_page' => class_exists( 'WP_MCP_AI_Tool_Artifact_Helper' ) ? WP_MCP_AI_Tool_Artifact_Helper::resolve_max_items( 'lf_opposing_counsel_tracker', 0, 1000 ) : 1000,
						'fields'         => 'ids',
					)
				);

				$history = array();
				foreach ( $all_matters->posts as $mid ) {
					$oc = get_post_meta( $mid, '_lf_opposing_counsel', true );
					if ( ! is_array( $oc ) ) {
						continue;
					}
					foreach ( $oc as $entry ) {
						if ( stripos( $entry['attorney_name'] ?? '', $attorney_name ) !== false ) {
							$history[] = array(
								'matter_id'    => $mid,
								'matter_title' => get_the_title( $mid ),
								'firm_name'    => $entry['firm_name'] ?? '',
								'added_at'     => $entry['added_at'] ?? '',
								'notes'        => $entry['notes'] ?? '',
							);
						}
					}
				}
				wp_reset_postdata();

				return array(
					'success'    => true,
					'message'    => sprintf(
						/* translators: 1: count, 2: attorney name */
						__( 'Found %1$d matters involving %2$s. ', 'mcp-ai-wpoos-pro' ),
						count( $history ),
						$attorney_name
					) . self::DISCLAIMER,
					'data'       => array(
						'attorney_name' => $attorney_name,
						'history'       => $history,
						'matter_count'  => count( $history ),
					),
					'disclaimer' => self::DISCLAIMER,
				);

			default:
				return new WP_Error( 'invalid_action', __( 'Invalid opposing counsel action.', 'mcp-ai-wpoos-pro' ) );
		}
	}
}
