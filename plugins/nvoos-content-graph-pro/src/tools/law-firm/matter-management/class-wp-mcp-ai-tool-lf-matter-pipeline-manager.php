<?php
/**
 * matter-management/class-wp-mcp-ai-tool-lf-matter-pipeline-manager.php (ecosystem port — Wave F4, law-firm matter-management batch).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/law-firm/matter-management/class-wp-mcp-ai-tool-lf-matter-pipeline-manager.php` for the
 * standalone `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the
 * class in monolith installs — the addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined
 * (see the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`;
 * `NVOOS_CONTENT_GRAPH_PRO_*` constant swaps; the `__DIR__` calculator require resolves from the
 * addon's already-ported `src/tools/law-firm/` copy.
 *
 * @package NvoosContentGraphPro
 * @since 1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages the matter pipeline for creating, updating, and listing matters.
 */
class WP_MCP_AI_Tool_LF_Matter_Pipeline_Manager implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return __( 'Law Firm toolkit is not enabled.', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_slug() {
		return 'lf_matter_pipeline_manager';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return __( 'Matter Pipeline Manager', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return __( 'Creates, updates, lists, and summarizes legal matters with case details including client, practice area, status, case number, court, judge, and jurisdiction.', 'nvoos-content-graph-pro' );
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
					'description' => __( 'Pipeline action to perform.', 'nvoos-content-graph-pro' ),
					'enum'        => array( 'create', 'update_status', 'list', 'get_summary' ),
				),
				'matter_title'  => array(
					'type'        => 'string',
					'description' => __( 'Title of the matter (required for create).', 'nvoos-content-graph-pro' ),
				),
				'client_id'     => array(
					'type'        => 'integer',
					'description' => __( 'Associated client ID.', 'nvoos-content-graph-pro' ),
				),
				'practice_area' => array(
					'type'        => 'string',
					'description' => __( 'Area of law for the matter.', 'nvoos-content-graph-pro' ),
				),
				'status'        => array(
					'type'        => 'string',
					'description' => __( 'Matter status (e.g., active, pending, closed, on_hold).', 'nvoos-content-graph-pro' ),
				),
				'matter_id'     => array(
					'type'        => 'integer',
					'description' => __( 'Matter ID (required for update_status and get_summary).', 'nvoos-content-graph-pro' ),
				),
				'case_number'   => array(
					'type'        => 'string',
					'description' => __( 'Court case number.', 'nvoos-content-graph-pro' ),
				),
				'court'         => array(
					'type'        => 'string',
					'description' => __( 'Court name.', 'nvoos-content-graph-pro' ),
				),
				'judge'         => array(
					'type'        => 'string',
					'description' => __( 'Assigned judge name.', 'nvoos-content-graph-pro' ),
				),
				'jurisdiction'  => array(
					'type'        => 'string',
					'description' => __( 'Jurisdiction (e.g., state, federal).', 'nvoos-content-graph-pro' ),
				),
			),
			'required'   => array( 'action' ),
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
		$uid = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $uid || ! user_can( $uid, 'manage_options' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'Permission denied.', 'nvoos-content-graph-pro' ) );
		}
		if ( ! self::is_available() ) {
			return new WP_Error( 'tool_not_available', self::get_unavailable_reason() );
		}

		$action = isset( $arguments['action'] ) ? sanitize_text_field( $arguments['action'] ) : '';

		switch ( $action ) {
			case 'create':
				return $this->create_matter( $arguments, $uid );

			case 'update_status':
				return $this->update_matter_status( $arguments );

			case 'list':
				return $this->list_matters( $arguments );

			case 'get_summary':
				return $this->get_matter_summary( $arguments );

			default:
				return new WP_Error( 'invalid_action', __( 'Invalid pipeline action.', 'nvoos-content-graph-pro' ) );
		}
	}

	/**
	 * Create a new matter.
	 *
	 * @param array $arguments Tool arguments.
	 * @param int   $uid       User ID.
	 * @return array|WP_Error
	 */
	private function create_matter( array $arguments, int $uid ) {
		$title = isset( $arguments['matter_title'] ) ? sanitize_text_field( $arguments['matter_title'] ) : '';
		if ( empty( $title ) ) {
			return new WP_Error( 'missing_required', __( 'Matter title is required for creation.', 'nvoos-content-graph-pro' ) );
		}

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_lf_matter',
				'post_title'  => $title,
				'post_status' => 'publish',
				'post_author' => $uid,
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$meta_fields = array(
			'client_id'     => '_lf_client_id',
			'practice_area' => '_lf_practice_area',
			'status'        => '_lf_status',
			'case_number'   => '_lf_case_number',
			'court'         => '_lf_court',
			'judge'         => '_lf_judge',
			'jurisdiction'  => '_lf_jurisdiction',
		);

		foreach ( $meta_fields as $arg_key => $meta_key ) {
			if ( ! empty( $arguments[ $arg_key ] ) ) {
				$value = ( 'client_id' === $arg_key ) ? absint( $arguments[ $arg_key ] ) : sanitize_text_field( $arguments[ $arg_key ] );
				update_post_meta( $post_id, $meta_key, $value );
			}
		}

		if ( empty( $arguments['status'] ) ) {
			update_post_meta( $post_id, '_lf_status', 'active' );
		}
		update_post_meta( $post_id, '_lf_created_date', current_time( 'Y-m-d H:i:s' ) );

		return array(
			'success'    => true,
			'message'    => __( 'Matter created successfully. ', 'nvoos-content-graph-pro' ) . self::DISCLAIMER,
			'data'       => array(
				'matter_id'     => $post_id,
				'matter_title'  => $title,
				'status'        => get_post_meta( $post_id, '_lf_status', true ),
				'practice_area' => get_post_meta( $post_id, '_lf_practice_area', true ),
			),
			'disclaimer' => self::DISCLAIMER,
		);
	}

	/**
	 * Update a matter status.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array|WP_Error
	 */
	private function update_matter_status( array $arguments ) {
		$matter_id = isset( $arguments['matter_id'] ) ? absint( $arguments['matter_id'] ) : 0;
		$status    = isset( $arguments['status'] ) ? sanitize_text_field( $arguments['status'] ) : '';

		if ( ! $matter_id || empty( $status ) ) {
			return new WP_Error( 'missing_required', __( 'Matter ID and status are required.', 'nvoos-content-graph-pro' ) );
		}

		$matter = get_post( $matter_id );
		if ( ! $matter || 'mcp_ai_lf_matter' !== $matter->post_type ) {
			return new WP_Error( 'not_found', __( 'Matter not found.', 'nvoos-content-graph-pro' ) );
		}

		$old_status = get_post_meta( $matter_id, '_lf_status', true );
		update_post_meta( $matter_id, '_lf_status', $status );
		update_post_meta( $matter_id, '_lf_last_status_change', current_time( 'Y-m-d H:i:s' ) );

		return array(
			'success'    => true,
			'message'    => __( 'Matter status updated. ', 'nvoos-content-graph-pro' ) . self::DISCLAIMER,
			'data'       => array(
				'matter_id'  => $matter_id,
				'title'      => $matter->post_title,
				'old_status' => $old_status,
				'new_status' => $status,
				'updated_at' => current_time( 'Y-m-d H:i:s' ),
			),
			'disclaimer' => self::DISCLAIMER,
		);
	}

	/**
	 * List matters with optional filters.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array|WP_Error
	 */
	private function list_matters( array $arguments ) {
		$query_args = array(
			'post_type'      => 'mcp_ai_lf_matter',
			'post_status'    => 'publish',
			'posts_per_page' => 50,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		$meta_query = array();
		if ( ! empty( $arguments['practice_area'] ) ) {
			$meta_query[] = array(
				'key'   => '_lf_practice_area',
				'value' => sanitize_text_field( $arguments['practice_area'] ),
			);
		}
		if ( ! empty( $arguments['status'] ) ) {
			$meta_query[] = array(
				'key'   => '_lf_status',
				'value' => sanitize_text_field( $arguments['status'] ),
			);
		}
		if ( ! empty( $arguments['client_id'] ) ) {
			$meta_query[] = array(
				'key'   => '_lf_client_id',
				'value' => absint( $arguments['client_id'] ),
			);
		}
		if ( ! empty( $meta_query ) ) {
			$query_args['meta_query'] = $meta_query;
		}

		$query   = new WP_Query( $query_args );
		$matters = array();

		foreach ( $query->posts as $post ) {
			$matters[] = array(
				'matter_id'     => $post->ID,
				'title'         => $post->post_title,
				'status'        => get_post_meta( $post->ID, '_lf_status', true ),
				'practice_area' => get_post_meta( $post->ID, '_lf_practice_area', true ),
				'case_number'   => get_post_meta( $post->ID, '_lf_case_number', true ),
				'court'         => get_post_meta( $post->ID, '_lf_court', true ),
				'created'       => $post->post_date,
			);
		}
		wp_reset_postdata();

		return array(
			'success'    => true,
			'message'    => sprintf(
				/* translators: %d: number of matters found */
				__( '%d matters found. ', 'nvoos-content-graph-pro' ),
				count( $matters )
			) . self::DISCLAIMER,
			'data'       => array(
				'matters' => $matters,
				'total'   => count( $matters ),
			),
			'disclaimer' => self::DISCLAIMER,
		);
	}

	/**
	 * Get a detailed summary of a single matter.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array|WP_Error
	 */
	private function get_matter_summary( array $arguments ) {
		$matter_id = isset( $arguments['matter_id'] ) ? absint( $arguments['matter_id'] ) : 0;
		if ( ! $matter_id ) {
			return new WP_Error( 'missing_required', __( 'Matter ID is required.', 'nvoos-content-graph-pro' ) );
		}

		$matter = get_post( $matter_id );
		if ( ! $matter || 'mcp_ai_lf_matter' !== $matter->post_type ) {
			return new WP_Error( 'not_found', __( 'Matter not found.', 'nvoos-content-graph-pro' ) );
		}

		$deadlines    = get_post_meta( $matter_id, '_lf_deadlines', true );
		$tasks        = get_post_meta( $matter_id, '_lf_tasks', true );
		$time_entries = get_post_meta( $matter_id, '_lf_time_entries', true );

		$total_hours = 0.0;
		if ( is_array( $time_entries ) ) {
			foreach ( $time_entries as $entry ) {
				$total_hours += (float) ( $entry['hours'] ?? 0 );
			}
		}

		return array(
			'success'    => true,
			'message'    => __( 'Matter summary retrieved. ', 'nvoos-content-graph-pro' ) . self::DISCLAIMER,
			'data'       => array(
				'matter_id'      => $matter_id,
				'title'          => $matter->post_title,
				'status'         => get_post_meta( $matter_id, '_lf_status', true ),
				'practice_area'  => get_post_meta( $matter_id, '_lf_practice_area', true ),
				'case_number'    => get_post_meta( $matter_id, '_lf_case_number', true ),
				'court'          => get_post_meta( $matter_id, '_lf_court', true ),
				'judge'          => get_post_meta( $matter_id, '_lf_judge', true ),
				'jurisdiction'   => get_post_meta( $matter_id, '_lf_jurisdiction', true ),
				'client_id'      => get_post_meta( $matter_id, '_lf_client_id', true ),
				'deadline_count' => is_array( $deadlines ) ? count( $deadlines ) : 0,
				'task_count'     => is_array( $tasks ) ? count( $tasks ) : 0,
				'total_hours'    => round( $total_hours, 1 ),
				'created_date'   => get_post_meta( $matter_id, '_lf_created_date', true ),
			),
			'disclaimer' => self::DISCLAIMER,
		);
	}
}
