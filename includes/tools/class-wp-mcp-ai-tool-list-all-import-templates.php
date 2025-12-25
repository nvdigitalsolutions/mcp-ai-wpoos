<?php
/**
 * Tool for listing WP All Import templates.
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
 * Lists WP All Import templates configured on the site.
 */
class WP_MCP_AI_Tool_List_All_Import_Templates implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * Determine whether WP All Import is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return class_exists( 'PMXI_Plugin' ) || defined( 'PMXI_VERSION' );
	}

	/**
	 * Message explaining why the tool is unavailable.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason() {
		return __( 'The WP All Import tool is disabled because WP All Import plugin is not active.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'list_all_import_templates';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'List WP All Import Templates', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Returns a list of WP All Import templates configured on the site. Requires WP All Import plugin.', 'wp-mcp-ai' );
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
					'description' => __( 'Maximum number of import templates to retrieve.', 'wp-mcp-ai' ),
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
			return new WP_Error( 'wp_mcp_ai_all_import_missing', __( 'WP All Import is not active on this site.', 'wp-mcp-ai' ) );
		}

		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You must be logged in to view import templates.', 'wp-mcp-ai' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
		}

		if ( ! user_can( $user_id, 'manage_options' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to view import templates.', 'wp-mcp-ai' ) );
		}

		$limit = isset( $arguments['limit'] ) ? absint( $arguments['limit'] ) : 20;
		$limit = $limit > 0 ? min( $limit, 50 ) : 20;

		// WP All Import stores imports as custom post type 'import'.
		$imports = get_posts(
			array(
				'post_type'      => 'import',
				'posts_per_page' => $limit,
				'post_status'    => 'any',
				'orderby'        => 'modified',
				'order'          => 'DESC',
			)
		);

		$results = array();

		foreach ( $imports as $import ) {
			$import_meta = get_post_meta( $import->ID );

			$results[] = array(
				'id'          => $import->ID,
				'name'        => $import->post_title,
				'status'      => $import->post_status,
				'created_at'  => $import->post_date,
				'modified_at' => $import->post_modified,
				'import_type' => isset( $import_meta['custom_type'][0] ) ? $import_meta['custom_type'][0] : '',
				'scheduled'   => isset( $import_meta['is_scheduled'][0] ) ? (bool) $import_meta['is_scheduled'][0] : false,
				'processing'  => isset( $import_meta['processing'][0] ) ? (bool) $import_meta['processing'][0] : false,
			);
		}

		return array(
			'summary' => sprintf(
				/* translators: %d: number of import templates */
				__( 'Found %d import template(s)', 'wp-mcp-ai' ),
				count( $results )
			),
			'imports' => $results,
			'count'   => count( $results ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'requires-plugin',     // Requires WP All Import plugin.
			'read-only',           // Only reads data, does not modify state.
			'local-only',          // No external API calls.
			'cacheable',           // Results can be cached.
			'requires-capability', // Requires 'manage_options' capability.
		);
	}
}
