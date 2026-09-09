<?php
/**
 * WP_MCP_AI_Tool_Import_Site_Creator_Blueprint (ecosystem port - Wave F2, site-creator tool batch).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/site-creator-toolkit/examples/` directory for the
 * standalone `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the
 * class in monolith installs - the addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined (see
 * the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`;
 * the base-owned interface/Logger seams resolve from the addon's D8-compat `src/` copies (the monorepo root classmap serves the base copies monolith); the `BLUEPRINTS_DIR` const swaps to `NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/site-creator-toolkit/examples'` and the blueprint-installer require swaps to `src/tools/orchestration/`.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Site_Creator_Toolkit
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);



if ( ! defined( 'ABSPATH' ) ) {
	exit; }

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
 * Imports a curated site creator assistant blueprint into the mcp_ai_assistant CPT.
 *
 * @since 2.3.1
 */
class WP_MCP_AI_Tool_Import_Site_Creator_Blueprint implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	const BLUEPRINTS_DIR  = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/site-creator-toolkit/examples';
	const BLUEPRINT_SLUGS = array( 'wordpress-site-builder', 'remote-site-administrator' );

	/**
	 * {@inheritdoc}
	 */
	public static function is_available() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_site_creator_toolkit'] );
	}
	/**
	 * {@inheritdoc}
	 */
	public static function get_unavailable_reason() {
		return __( 'Requires the Site Creator Toolkit to be enabled.', 'nvoos-content-graph-pro' );
	}
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'import_site_creator_blueprint'; }
	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Import Site Creator Blueprint', 'nvoos-content-graph-pro' ); }
	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Install a site creator assistant blueprint. Available blueprints: wordpress-site-builder (AI-assisted site creation) and remote-site-administrator (full remote/local WordPress/WooCommerce site management with JetEngine, JetFormBuilder, and REST API control).', 'nvoos-content-graph-pro' ); }
	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'blueprint' => array(
					'type'        => 'string',
					'enum'        => self::BLUEPRINT_SLUGS,
					'description' => __( 'Blueprint slug.', 'nvoos-content-graph-pro' ),
				),
				'overwrite' => array(
					'type'        => 'boolean',
					'description' => __( 'Overwrite existing assistant.', 'nvoos-content-graph-pro' ),
				),
			),
			'required'   => array( 'blueprint' ),
		);
	}
	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts'; }
	/**
	 * {@inheritdoc}
	 */
	public function requires_base_pro() {
		return true; }
	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'database-write', 'requires-capability' ); }
	/**
	 * Execute the blueprint import.
	 *
	 * @since 2.3.1
	 *
	 * @param  array $arguments Validated tool arguments.
	 * @param  array $context   Execution context.
	 * @return array|WP_Error   Canonical success envelope or WP_Error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$bp        = sanitize_key( $arguments['blueprint'] );
		$overwrite = ! empty( $arguments['overwrite'] );
		if ( ! class_exists( 'WP_MCP_AI_Blueprint_Installer' ) ) {
			$p = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/orchestration/class-wp-mcp-ai-blueprint-installer.php';
			if ( file_exists( $p ) ) {
				require_once $p; }
		}
		$data = WP_MCP_AI_Blueprint_Installer::load_blueprint( self::BLUEPRINTS_DIR, $bp );
		if ( is_wp_error( $data ) ) {
			return $data; }
		return WP_MCP_AI_Blueprint_Installer::install( $data, $bp, $overwrite );
	}
}
