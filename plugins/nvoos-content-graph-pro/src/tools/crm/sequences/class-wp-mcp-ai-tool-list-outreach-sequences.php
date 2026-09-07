<?php
/**
 * List Outreach Sequences Tool (ecosystem port — Wave F2, CRM sequences
 * batch).
 *
 * Ported from the base Pro addon's
 * `addons/pro/includes/tools/crm/sequences/class-wp-mcp-ai-tool-list-outreach-sequences.php`
 * for the standalone `nvoos-content-graph-pro` addon. Kept
 * byte-identical. The base Pro addon owns the class in monolith
 * installs — the addon's autoloader skips its copy when
 * `WP_MCP_AI_PRO_PATH` is defined (see the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain
 * `nvoos-content-graph-pro`.
 *
 * @package NvoosContentGraphPro
 * @since 2.3.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * List Outreach Sequences tool.
 *
 * @since 2.3.0
 */
class WP_MCP_AI_Tool_List_Outreach_Sequences implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return __( 'CRM Toolkit required.', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'list_outreach_sequences';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'List Outreach Sequences', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'List all outreach sequence definitions.', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'per_page' => array(
					'type'    => 'integer',
					'default' => 20,
				),
				'page'     => array(
					'type'    => 'integer',
					'default' => 1,
				),
			),
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
	public function requires_base_pro() {
		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'database-read', 'requires-capability' );
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$q    = new WP_Query(
			array(
				'post_type'      => 'mcp_ai_sequence',
				'post_status'    => 'publish',
				'posts_per_page' => min( 100, absint( $arguments['per_page'] ?? 20 ) ),
				'paged'          => max( 1, absint( $arguments['page'] ?? 1 ) ),
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);
		$seqs = array();
		foreach ( $q->posts as $p ) {
			$seqs[] = array(
				'id'          => $p->ID,
				'name'        => $p->post_title,
				'description' => $p->post_content,
				'step_count'  => (int) get_post_meta( $p->ID, 'step_count', true ),
				'steps'       => get_post_meta( $p->ID, 'steps', true ),
			);
		}
		return array(
			'success'   => true,
			'sequences' => $seqs,
			'total'     => $q->found_posts,
			'per_page'  => min( 100, absint( $arguments['per_page'] ?? 20 ) ),
			'page'      => max( 1, absint( $arguments['page'] ?? 1 ) ),
			'pages'     => max( 1, $q->max_num_pages ),
		);
	}
}
