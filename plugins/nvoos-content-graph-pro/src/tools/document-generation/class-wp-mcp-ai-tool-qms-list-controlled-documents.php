<?php
/**
 * WP_MCP_AI_Tool_QMS_List_Controlled_Documents (ecosystem port - Wave F2, document-generation tool batch).
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
 * WP_MCP_AI_Tool_QMS_List_Controlled_Documents tool.
 */
class WP_MCP_AI_Tool_QMS_List_Controlled_Documents implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {


	/**

	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'qms_list_controlled_documents';
	}
	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'QMS: List Controlled Documents', 'nvoos-content-graph-pro' );
	}
	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Master document register. List controlled documents with filters for status (draft/in_review/approved/released/superseded/obsolete), doc type, owner, or review-due-by date.', 'nvoos-content-graph-pro' );
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
				'status'        => array(
					'type' => 'string',
					'enum' => array( 'draft', 'in_review', 'approved', 'released', 'superseded', 'obsolete' ),
				),
				'doc_type_slug' => array( 'type' => 'string' ),
				'owner_id'      => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'review_due_by' => array(
					'type'    => 'string',
					'pattern' => '^\d{4}-\d{2}-\d{2}$',
				),
				'limit'         => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 100,
					'default' => 25,
				),
			),
			'additionalProperties' => false,
		);
	}
		/**
		 * Get capability flags for this tool.
		 *
		 * @return array
		 */
	public function get_capability_flags() {
		return array( 'pro', 'read-only', 'paginated' );
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

		$args = array(
			'post_type'      => WP_MCP_AI_QMS_Doc_Record_CPT::POST_TYPE,
			'post_status'    => 'any',
			'posts_per_page' => isset( $arguments['limit'] ) ? min( 100, max( 1, absint( $arguments['limit'] ) ) ) : 25,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'fields'         => 'ids',
		);

		$meta_query = array();
		if ( ! empty( $arguments['status'] ) ) {
			$meta_query[] = array(
				'key'   => '_qms_status',
				'value' => sanitize_key( $arguments['status'] ),
			);
		}
		if ( ! empty( $arguments['owner_id'] ) ) {
			$meta_query[] = array(
				'key'   => '_qms_owner_id',
				'value' => absint( $arguments['owner_id'] ),
			);
		}
		if ( ! empty( $arguments['review_due_by'] ) ) {
			$meta_query[] = array(
				'key'     => '_qms_next_review_date',
				'value'   => sanitize_text_field( $arguments['review_due_by'] ),
				'compare' => '<=',
				'type'    => 'DATE',
			);
		}
		if ( $meta_query ) {
			$args['meta_query'] = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- bounded.
		}

		if ( ! empty( $arguments['doc_type_slug'] ) ) {
			$args['tax_query'] = array(
				array(
					'taxonomy' => WP_MCP_AI_QMS_Taxonomy::TAXONOMY,
					'field'    => 'slug',
					'terms'    => sanitize_key( $arguments['doc_type_slug'] ),
				),
			);
		}

		$query = new WP_Query( $args );
		$out   = array();
		foreach ( $query->posts as $pid ) {
			$out[] = WP_MCP_AI_QMS_Doc_Record_CPT::get_record( $pid );
		}
		return array(
			'success' => true,
			'count'   => count( $out ),
			'records' => $out,
		);
	}
}
