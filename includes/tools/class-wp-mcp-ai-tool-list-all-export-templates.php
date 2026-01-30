<?php
/**
 * Tool for listing WP All Export templates.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Prevent parse errors on PHP < 7.4 by exiting before class definition.
if ( version_compare( PHP_VERSION, '7.4.0', '<' ) ) {
	return;
}

/**
 * Lists WP All Export templates configured on the site.
 */
class WP_MCP_AI_Tool_List_All_Export_Templates implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * Determine whether WP All Export is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return class_exists( 'PMXE_Plugin' ) || defined( 'PMXE_VERSION' );
	}

	/**
	 * Message explaining why the tool is unavailable.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason() {
		return __( 'The WP All Export tool is disabled because WP All Export plugin is not active.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'list_all_export_templates';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'List WP All Export Templates', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Returns a list of WP All Export templates configured on the site. Requires WP All Export plugin.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'limit' => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of export templates to retrieve.', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
					'maximum'     => 50,
					'default'     => 20,
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		if ( ! self::is_available() ) {
			return new WP_Error( 'wp_mcp_ai_all_export_missing', __( 'WP All Export is not active on this site.', 'mcp-ai-wpoos' ) );
		}

		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You must be logged in to view export templates.', 'mcp-ai-wpoos' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos' ) );
		}

		if ( ! user_can( $user_id, 'manage_options' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to view export templates.', 'mcp-ai-wpoos' ) );
		}

		$limit = isset( $arguments['limit'] ) ? absint( $arguments['limit'] ) : 20;
		$limit = $limit > 0 ? min( $limit, 50 ) : 20;

		// WP All Export stores exports as custom post type 'pmxe_exports'.
		$exports = get_posts(
			array(
				'post_type'      => 'pmxe_exports',
				'posts_per_page' => $limit,
				'post_status'    => 'any',
				'orderby'        => 'modified',
				'order'          => 'DESC',
			)
		);

		$results = array();

		foreach ( $exports as $export ) {
			$export_meta = get_post_meta( $export->ID );

			$results[] = array(
				'id'          => $export->ID,
				'name'        => $export->post_title,
				'status'      => $export->post_status,
				'created_at'  => $export->post_date,
				'modified_at' => $export->post_modified,
				'export_type' => isset( $export_meta['export_post_type'][0] ) ? $export_meta['export_post_type'][0] : '',
				'scheduled'   => isset( $export_meta['scheduled'][0] ) ? (bool) $export_meta['scheduled'][0] : false,
			);
		}

		$summary_text = sprintf(
			/* translators: %d: number of export templates */
			__( 'Found %d export template(s)', 'mcp-ai-wpoos' ),
			count( $results )
		);

		return array(
			'message' => $summary_text,
			'summary' => $summary_text,
			'exports' => $results,
			'count'   => count( $results ),
		);
	}


	/**

	 * Get extended tool definition including toolkit metadata.

	 *

	 * @since 1.1.0

	 *

	 * @return array Tool definition with metadata.

	 */

	public function get_definition() {

		return array(

			'name'                  => $this->get_name(),

			'description'           => $this->get_description(),

			'toolkit'               => 'workflow_automation',

			'pattern_compatibility' => array( 'hierarchical' ),

			'profession_tags'       => array( 'systems_administrator', 'data_engineer' ),

			'risk_level'            => 'info',

		);

	}


	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'requires-plugin',     // Requires WP All Export plugin.
			'read-only',           // Only reads data, does not modify state.
			'local-only',          // No external API calls.
			'cacheable',           // Results can be cached.
			'requires-capability', // Requires 'manage_options' capability.
		);
	}
}
