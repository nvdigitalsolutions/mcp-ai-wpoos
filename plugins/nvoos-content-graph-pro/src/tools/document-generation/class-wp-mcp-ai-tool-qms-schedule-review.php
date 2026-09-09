<?php
/**
 * WP_MCP_AI_Tool_QMS_Schedule_Review (ecosystem port - Wave F2, document-generation tool batch).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/document-generation/` directory for the
 * standalone `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the
 * class in monolith installs - the addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined (see
 * the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`;
 * the base-owned interface/Logger/trait requires gain exists-check seams resolving from the addon's D8-compat `src/` copies; the monolith-gated openai/gemini/ollama client requires stay monolith-gated (architectural-design precedent).
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Document_Generation
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);





if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Standalone seam (documented deviation): the base-owned interface and
// Logger requires gain exists-check seams resolving from the addon's
// D8-compat copies (the monorepo root classmap serves the base copies
// monolith).
if ( ! interface_exists( 'WP_MCP_AI_Tool_Interface' ) ) {
	$nvoos_content_graph_pro_tool_interface = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/interfaces/interface-wp-mcp-ai-tool.php';
	if ( file_exists( $nvoos_content_graph_pro_tool_interface ) ) {
		require_once $nvoos_content_graph_pro_tool_interface;
	}
}
if ( ! class_exists( 'WP_MCP_AI_Logger' ) ) {
	$nvoos_content_graph_pro_logger = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-logger.php';
	if ( file_exists( $nvoos_content_graph_pro_logger ) ) {
		require_once $nvoos_content_graph_pro_logger;
	}
}


/**
 * WP_MCP_AI_Tool_QMS_Schedule_Review tool.
 */
class WP_MCP_AI_Tool_QMS_Schedule_Review implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {


	/**

	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'qms_schedule_review';
	}
	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'QMS: Schedule Review', 'nvoos-content-graph-pro' );
	}
	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Schedule a periodic review of a controlled document. Creates a PM Task assigned to the document owner with the requested due date and updates the record\'s next_review_date.', 'nvoos-content-graph-pro' );
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
				'post_id'  => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'due_date' => array(
					'type'    => 'string',
					'pattern' => '^\d{4}-\d{2}-\d{2}$',
				),
				'notes'    => array(
					'type'      => 'string',
					'maxLength' => 2000,
				),
			),
			'required'             => array( 'post_id', 'due_date' ),
			'additionalProperties' => false,
		);
	}
		/**
		 * Get capability flags for this tool.
		 *
		 * @return array
		 */
	public function get_capability_flags() {
		return array( 'pro', 'write', 'state-changing' );
	}
	/**
	 * Check if tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return class_exists( 'WP_MCP_AI_QMS_Capabilities' ) && WP_MCP_AI_QMS_Capabilities::is_enabled();
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
		if ( ! $user_id || ! user_can( $user_id, WP_MCP_AI_QMS_Capabilities::CAP ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission.', 'nvoos-content-graph-pro' ) );
		}
		$post_id  = isset( $arguments['post_id'] ) ? absint( $arguments['post_id'] ) : 0;
		$due_date = isset( $arguments['due_date'] ) ? sanitize_text_field( $arguments['due_date'] ) : '';
		if ( ! $post_id || ! $due_date ) {
			return new WP_Error( 'wp_mcp_ai_invalid', __( 'post_id and due_date are required.', 'nvoos-content-graph-pro' ) );
		}
		$record = WP_MCP_AI_QMS_Doc_Record_CPT::get_record( $post_id );
		if ( ! $record ) {
			return new WP_Error( 'wp_mcp_ai_qms_invalid_record', __( 'Controlled document not found.', 'nvoos-content-graph-pro' ) );
		}

		// Update next_review_date on the record.
		update_post_meta( $post_id, '_qms_next_review_date', $due_date );

		$owner = $record['owner_id'] ? $record['owner_id'] : $user_id;
		$title = sprintf(
			/* translators: 1: doc id, 2: revision, 3: title */
			__( 'Review: %1$s rev %2$s — %3$s', 'nvoos-content-graph-pro' ),
			$record['document_id'],
			$record['revision'] ? $record['revision'] : '—',
			$record['title']
		);
		$content = isset( $arguments['notes'] ) ? wp_kses_post( $arguments['notes'] ) : '';

		$task_id = wp_insert_post(
			array(
				'post_type'    => 'mcp_ai_task',
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_content' => $content,
				'post_author'  => $user_id,
			),
			true
		);
		if ( is_wp_error( $task_id ) ) {
			return $task_id;
		}

		update_post_meta( $task_id, '_task_status', 'pending' );
		update_post_meta( $task_id, '_task_due_date', $due_date );
		update_post_meta( $task_id, '_task_assignee_id', $owner );
		update_post_meta( $task_id, '_task_qms_record_id', $post_id );

		// Link to area if applicable.
		$area_id = (int) get_post_meta( $post_id, '_qms_linked_area_id', true );
		if ( $area_id ) {
			update_post_meta( $task_id, '_task_area_id', $area_id );
		}

		WP_MCP_AI_QMS_Audit_Log::record(
			array(
				'event'    => 'review_scheduled',
				'post_id'  => $post_id,
				'doc_id'   => $record['document_id'],
				'revision' => $record['revision'],
				'meta'     => array(
					'task_id'  => $task_id,
					'due_date' => $due_date,
				),
			)
		);

		return array(
			'success'  => true,
			'post_id'  => $post_id,
			'task_id'  => (int) $task_id,
			'due_date' => $due_date,
			'message'  => __( 'Review scheduled.', 'nvoos-content-graph-pro' ),
		);
	}
}
