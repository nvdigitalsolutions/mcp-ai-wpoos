<?php
/**
 * Tool_Manage_Rfi_Log (ecosystem port - Wave F2, architectural-design tool batch).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/architectural-design/` directory for the
 * standalone `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the
 * class in monolith installs - the addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined (see
 * the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`;
 * per-file seams — the base-owned interface/Logger/media-url-utils requires gain exists-check seams
 * resolving from the addon's D8-compat `src/` copies, the response/subprocess traits are
 * wave-proof-guarded, the openai/gemini client requires stay monolith-gated, and the
 * `WP_MCP_AI_PRO_PATH` refs swap to `NVOOS_CONTENT_GRAPH_PRO_PATH` with the `src/` root.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Architectural_Design
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);




if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Standalone seam (documented deviation): the base-owned interface require gains an
// exists-check seam resolving from the addon's D8-compat copy.
if ( ! interface_exists( 'WP_MCP_AI_Tool_Interface' ) ) {
	$nvoos_content_graph_pro_tool_interface = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/interfaces/interface-wp-mcp-ai-tool.php';
	if ( file_exists( $nvoos_content_graph_pro_tool_interface ) ) {
		require_once $nvoos_content_graph_pro_tool_interface;
	}
}


/**
 * Manage RFI log.
 */
class WP_MCP_AI_Tool_Manage_Rfi_Log implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return __( 'Architectural Design toolkit is not enabled.', 'nvoos-content-graph-pro' );
	}


	/**

	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'manage_rfi_log';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Manage RFI Log', 'nvoos-content-graph-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'List / create / update Requests for Information on an architectural project. Stored on the mcp_ai_arch_proj CPT as JSON post-meta. Status workflow: open → in_review → answered → closed | void.', 'nvoos-content-graph-pro' );
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
				'rfi_id'          => array( 'type' => 'string' ),
				'subject'         => array( 'type' => 'string' ),
				'question'        => array( 'type' => 'string' ),
				'answer'          => array( 'type' => 'string' ),
				'status'          => array(
					'type' => 'string',
					'enum' => array( 'open', 'in_review', 'answered', 'closed', 'void' ),
				),
				'requested_by'    => array( 'type' => 'string' ),
				'assigned_to'     => array( 'type' => 'string' ),
				'due_date'        => array(
					'type'        => 'string',
					'description' => 'YYYY-MM-DD',
				),
				'discipline'      => array( 'type' => 'string' ),
				'cost_impact'     => array(
					'type' => 'string',
					'enum' => array( 'none', 'tbd', 'increase', 'decrease' ),
				),
				'schedule_impact' => array(
					'type' => 'string',
					'enum' => array( 'none', 'tbd', 'delay', 'recovery' ),
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
		$user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $user_id || ! user_can( $user_id, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to manage the RFI log.', 'nvoos-content-graph-pro' ) );
		}
		if ( ! class_exists( 'WP_MCP_AI_Architectural_Interop' ) ) {
			return new WP_Error( 'wp_mcp_ai_engine_missing', __( 'Architectural interop engine is unavailable.', 'nvoos-content-graph-pro' ) );
		}

		$action     = isset( $arguments['action'] ) ? sanitize_key( $arguments['action'] ) : '';
		$project_id = isset( $arguments['project_id'] ) ? absint( $arguments['project_id'] ) : 0;
		if ( ! $project_id || 'mcp_ai_arch_proj' !== get_post_type( $project_id ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_project', __( 'project_id must reference an mcp_ai_arch_proj post.', 'nvoos-content-graph-pro' ) );
		}
		if ( ! current_user_can( 'edit_post', $project_id ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to edit this project.', 'nvoos-content-graph-pro' ) );
		}

		$key = WP_MCP_AI_Architectural_Interop::META_RFI_LOG;
		$log = WP_MCP_AI_Architectural_Interop::read_log( $project_id, $key );

		switch ( $action ) {
			case 'list':
				return array(
					'success' => true,
					'count'   => count( $log ),
					'rfis'    => $log,
				);
			case 'get':
				$rfi_id = isset( $arguments['rfi_id'] ) ? sanitize_text_field( $arguments['rfi_id'] ) : '';
				foreach ( $log as $entry ) {
					if ( isset( $entry['id'] ) && $entry['id'] === $rfi_id ) {
						return array(
							'success' => true,
							'rfi'     => $entry,
						);
					}
				}
				return new WP_Error( 'wp_mcp_ai_not_found', __( 'RFI not found.', 'nvoos-content-graph-pro' ) );
			case 'create':
				if ( empty( $arguments['subject'] ) || empty( $arguments['question'] ) ) {
					return new WP_Error( 'wp_mcp_ai_invalid_arguments', __( 'subject and question are required.', 'nvoos-content-graph-pro' ) );
				}
				$now   = current_time( 'mysql' );
				$entry = array(
					'id'              => WP_MCP_AI_Architectural_Interop::next_log_id( $log, 'RFI' ),
					'subject'         => sanitize_text_field( $arguments['subject'] ),
					'question'        => wp_kses_post( $arguments['question'] ),
					'answer'          => isset( $arguments['answer'] ) ? wp_kses_post( $arguments['answer'] ) : '',
					'status'          => isset( $arguments['status'] ) ? $this->coerce_status( $arguments['status'] ) : 'open',
					'requested_by'    => isset( $arguments['requested_by'] ) ? sanitize_text_field( $arguments['requested_by'] ) : '',
					'assigned_to'     => isset( $arguments['assigned_to'] ) ? sanitize_text_field( $arguments['assigned_to'] ) : '',
					'due_date'        => isset( $arguments['due_date'] ) ? sanitize_text_field( $arguments['due_date'] ) : '',
					'discipline'      => isset( $arguments['discipline'] ) ? sanitize_text_field( $arguments['discipline'] ) : '',
					'cost_impact'     => isset( $arguments['cost_impact'] ) ? sanitize_key( $arguments['cost_impact'] ) : 'none',
					'schedule_impact' => isset( $arguments['schedule_impact'] ) ? sanitize_key( $arguments['schedule_impact'] ) : 'none',
					'created_at'      => $now,
					'created_by'      => $user_id,
					'updated_at'      => $now,
				);
				$log[] = $entry;
				WP_MCP_AI_Architectural_Interop::write_log( $project_id, $key, $log );
				return array(
					'success' => true,
					'rfi'     => $entry,
				);
			case 'update':
				$rfi_id = isset( $arguments['rfi_id'] ) ? sanitize_text_field( $arguments['rfi_id'] ) : '';
				if ( '' === $rfi_id ) {
					return new WP_Error( 'wp_mcp_ai_invalid_arguments', __( 'rfi_id is required for update.', 'nvoos-content-graph-pro' ) );
				}
				$found = false;
				foreach ( $log as $i => $entry ) {
					if ( isset( $entry['id'] ) && $entry['id'] === $rfi_id ) {
						$found = true;
						foreach ( array( 'subject', 'requested_by', 'assigned_to', 'due_date', 'discipline' ) as $f ) {
							if ( isset( $arguments[ $f ] ) ) {
								$log[ $i ][ $f ] = sanitize_text_field( $arguments[ $f ] );
							}
						}
						foreach ( array( 'question', 'answer' ) as $f ) {
							if ( isset( $arguments[ $f ] ) ) {
								$log[ $i ][ $f ] = wp_kses_post( $arguments[ $f ] );
							}
						}
						if ( isset( $arguments['cost_impact'] ) ) {
							$log[ $i ]['cost_impact'] = sanitize_key( $arguments['cost_impact'] );
						}
						if ( isset( $arguments['schedule_impact'] ) ) {
							$log[ $i ]['schedule_impact'] = sanitize_key( $arguments['schedule_impact'] );
						}
						if ( isset( $arguments['status'] ) ) {
							$log[ $i ]['status'] = $this->coerce_status( $arguments['status'] );
						}
						$log[ $i ]['updated_at'] = current_time( 'mysql' );
						break;
					}
				}
				if ( ! $found ) {
					return new WP_Error( 'wp_mcp_ai_not_found', __( 'RFI not found.', 'nvoos-content-graph-pro' ) );
				}
				WP_MCP_AI_Architectural_Interop::write_log( $project_id, $key, $log );
				return array(
					'success' => true,
					'rfi'     => $log[ $i ],
				);
		}

		return new WP_Error( 'wp_mcp_ai_invalid_arguments', __( 'Unknown action.', 'nvoos-content-graph-pro' ) );
	}

	/**
	 * Coerce a status string into the allowed set.
	 *
	 * @param string $status Raw.
	 * @return string
	 */
	private function coerce_status( $status ) {
		$status  = sanitize_key( (string) $status );
		$allowed = WP_MCP_AI_Architectural_Interop::rfi_statuses();
		return in_array( $status, $allowed, true ) ? $status : 'open';
	}
}
