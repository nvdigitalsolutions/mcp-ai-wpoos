<?php
/**
 * Tool — Manage Submittal Log.
 *
 * Stores construction submittals (shop drawings, product data, samples,
 * mockups) as a JSON log on the project CPT. Status workflow:
 * submitted → under_review → approved | approved_as_noted | revise_and_resubmit
 * | rejected | void.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.5.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';

/**
 * Manage submittal log.
 */
class WP_MCP_AI_Tool_Manage_Submittal_Log implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/* WP_MCP_AI_AVAILABILITY_BLOCK */

	// phpcs:ignore Squiz.Commenting.FunctionComment.WrongStyle
	/**
	 * Check if tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_architectural_design_toolkit'] );
	}

	/**
	 * Get unavailable reason.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason() {
		return __( 'Architectural Design toolkit is not enabled.', 'mcp-ai-wpoos-pro' );
	}


	/**

	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'manage_submittal_log';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Manage Submittal Log', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'List / create / update construction submittals (shop drawings, product data, samples, mockups) on an architectural project. Stored as JSON post-meta on mcp_ai_arch_proj. Status workflow follows AIA / CSI conventions.', 'mcp-ai-wpoos-pro' );
	}


	/**

	 * Get the parameters schema.
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'action'          => array(
					'type' => 'string',
					'enum' => array( 'list', 'create', 'update', 'get' ),
				),
				'project_id'      => array( 'type' => 'integer' ),
				'submittal_id'    => array( 'type' => 'string' ),
				'spec_section'    => array(
					'type'        => 'string',
					'description' => 'CSI MasterFormat or POMI section reference.',
				),
				'title'           => array( 'type' => 'string' ),
				'submittal_type'  => array(
					'type' => 'string',
					'enum' => array( 'shop_drawing', 'product_data', 'sample', 'mockup', 'test_report', 'certificate', 'other' ),
				),
				'status'          => array(
					'type' => 'string',
					'enum' => array( 'submitted', 'under_review', 'approved', 'approved_as_noted', 'revise_and_resubmit', 'rejected', 'void' ),
				),
				'submitted_by'    => array( 'type' => 'string' ),
				'reviewer'        => array( 'type' => 'string' ),
				'due_date'        => array(
					'type'        => 'string',
					'description' => 'YYYY-MM-DD',
				),
				'review_comments' => array( 'type' => 'string' ),
				'revision'        => array(
					'type'        => 'integer',
					'description' => 'Revision counter (0,1,2,...).',
				),
			),
			'required'             => array( 'action', 'project_id' ),
			'additionalProperties' => false,
		);
	}

		/**
		 * Get capability flags for this tool.
		 *
		 * @return array
		 */
	public function get_capability_flags() {
		return array( 'pro', 'requires-capability', 'write', 'state-changing' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $user_id || ! user_can( $user_id, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to manage the submittal log.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( ! class_exists( 'WP_MCP_AI_Architectural_Interop' ) ) {
			return new WP_Error( 'wp_mcp_ai_engine_missing', __( 'Architectural interop engine is unavailable.', 'mcp-ai-wpoos-pro' ) );
		}

		$action     = isset( $arguments['action'] ) ? sanitize_key( $arguments['action'] ) : '';
		$project_id = isset( $arguments['project_id'] ) ? absint( $arguments['project_id'] ) : 0;
		if ( ! $project_id || 'mcp_ai_arch_proj' !== get_post_type( $project_id ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_project', __( 'project_id must reference an mcp_ai_arch_proj post.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( ! current_user_can( 'edit_post', $project_id ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to edit this project.', 'mcp-ai-wpoos-pro' ) );
		}

		$key = WP_MCP_AI_Architectural_Interop::META_SUBMITTAL_LOG;
		$log = WP_MCP_AI_Architectural_Interop::read_log( $project_id, $key );

		switch ( $action ) {
			case 'list':
				return array(
					'success'    => true,
					'count'      => count( $log ),
					'submittals' => $log,
				);
			case 'get':
				$sid = isset( $arguments['submittal_id'] ) ? sanitize_text_field( $arguments['submittal_id'] ) : '';
				foreach ( $log as $entry ) {
					if ( isset( $entry['id'] ) && $entry['id'] === $sid ) {
						return array(
							'success'   => true,
							'submittal' => $entry,
						);
					}
				}
				return new WP_Error( 'wp_mcp_ai_not_found', __( 'Submittal not found.', 'mcp-ai-wpoos-pro' ) );
			case 'create':
				if ( empty( $arguments['title'] ) || empty( $arguments['spec_section'] ) ) {
					return new WP_Error( 'wp_mcp_ai_invalid_arguments', __( 'title and spec_section are required.', 'mcp-ai-wpoos-pro' ) );
				}
				$now   = current_time( 'mysql' );
				$entry = array(
					'id'              => WP_MCP_AI_Architectural_Interop::next_log_id( $log, 'SUB' ),
					'spec_section'    => sanitize_text_field( $arguments['spec_section'] ),
					'title'           => sanitize_text_field( $arguments['title'] ),
					'submittal_type'  => isset( $arguments['submittal_type'] ) ? sanitize_key( $arguments['submittal_type'] ) : 'shop_drawing',
					'status'          => isset( $arguments['status'] ) ? $this->coerce_status( $arguments['status'] ) : 'submitted',
					'submitted_by'    => isset( $arguments['submitted_by'] ) ? sanitize_text_field( $arguments['submitted_by'] ) : '',
					'reviewer'        => isset( $arguments['reviewer'] ) ? sanitize_text_field( $arguments['reviewer'] ) : '',
					'due_date'        => isset( $arguments['due_date'] ) ? sanitize_text_field( $arguments['due_date'] ) : '',
					'review_comments' => isset( $arguments['review_comments'] ) ? wp_kses_post( $arguments['review_comments'] ) : '',
					'revision'        => isset( $arguments['revision'] ) ? max( 0, (int) $arguments['revision'] ) : 0,
					'created_at'      => $now,
					'created_by'      => $user_id,
					'updated_at'      => $now,
				);
				$log[] = $entry;
				WP_MCP_AI_Architectural_Interop::write_log( $project_id, $key, $log );
				return array(
					'success'   => true,
					'submittal' => $entry,
				);
			case 'update':
				$sid = isset( $arguments['submittal_id'] ) ? sanitize_text_field( $arguments['submittal_id'] ) : '';
				if ( '' === $sid ) {
					return new WP_Error( 'wp_mcp_ai_invalid_arguments', __( 'submittal_id is required for update.', 'mcp-ai-wpoos-pro' ) );
				}
				$found = false;
				foreach ( $log as $i => $entry ) {
					if ( isset( $entry['id'] ) && $entry['id'] === $sid ) {
						$found = true;
						foreach ( array( 'spec_section', 'title', 'submitted_by', 'reviewer', 'due_date' ) as $f ) {
							if ( isset( $arguments[ $f ] ) ) {
								$log[ $i ][ $f ] = sanitize_text_field( $arguments[ $f ] );
							}
						}
						if ( isset( $arguments['submittal_type'] ) ) {
							$log[ $i ]['submittal_type'] = sanitize_key( $arguments['submittal_type'] );
						}
						if ( isset( $arguments['review_comments'] ) ) {
							$log[ $i ]['review_comments'] = wp_kses_post( $arguments['review_comments'] );
						}
						if ( isset( $arguments['revision'] ) ) {
							$log[ $i ]['revision'] = max( 0, (int) $arguments['revision'] );
						}
						if ( isset( $arguments['status'] ) ) {
							$log[ $i ]['status'] = $this->coerce_status( $arguments['status'] );
						}
						$log[ $i ]['updated_at'] = current_time( 'mysql' );
						break;
					}
				}
				if ( ! $found ) {
					return new WP_Error( 'wp_mcp_ai_not_found', __( 'Submittal not found.', 'mcp-ai-wpoos-pro' ) );
				}
				WP_MCP_AI_Architectural_Interop::write_log( $project_id, $key, $log );
				return array(
					'success'   => true,
					'submittal' => $log[ $i ],
				);
		}

		return new WP_Error( 'wp_mcp_ai_invalid_arguments', __( 'Unknown action.', 'mcp-ai-wpoos-pro' ) );
	}

	/**
	 * Coerce a status string into the allowed set.
	 *
	 * @param string $status Raw.
	 * @return string
	 */
	private function coerce_status( $status ) {
		$status  = sanitize_key( (string) $status );
		$allowed = WP_MCP_AI_Architectural_Interop::submittal_statuses();
		return in_array( $status, $allowed, true ) ? $status : 'submitted';
	}
}
