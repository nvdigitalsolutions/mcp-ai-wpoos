<?php
/**
 * WP_MCP_AI_Tool_Import_AI_Tool_Builder_Blueprint (ecosystem port - Wave F2, ai-tool-builder toolkit).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/ai-tool-builder/examples/` directory for
 * the standalone `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the
 * class in monolith installs - the addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined (see the
 * plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`;
 * `NVOOS_CONTENT_GRAPH_PRO_PATH` swaps with the `src/` root (BLUEPRINTS_DIR + installer require — the
 * installer resolves from the already-ported `src/tools/orchestration/` copy).
 *
 * Import AI Tool Builder Blueprint.
 *
 * @package   WP_MCP_AI_Pro
 * @subpackage AI_Tool_Builder
 * @since     2.3.1
 */

declare(strict_types=1);




if ( ! defined( 'ABSPATH' ) ) {
	exit; }

/**
 * Imports a curated AI tool builder assistant blueprint into the mcp_ai_assistant CPT.
 *
 * @since 2.3.1
 */
class WP_MCP_AI_Tool_Import_AI_Tool_Builder_Blueprint implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	const BLUEPRINTS_DIR  = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/ai-tool-builder/examples';
	const BLUEPRINT_SLUGS = array( 'tool-developer' );

	/**
	 * {@inheritdoc}
	 */
	public static function is_available() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_ai_tool_builder_toolkit'] );
	}
	/**
	 * {@inheritdoc}
	 */
	public static function get_unavailable_reason() {
		return __( 'Requires the AI Tool Builder Toolkit to be enabled.', 'nvoos-content-graph-pro' );
	}
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'import_ai_tool_builder_blueprint'; }
	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Import AI Tool Builder Blueprint', 'nvoos-content-graph-pro' ); }
	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Install the AI Tool Developer assistant blueprint for custom tool development workflows.', 'nvoos-content-graph-pro' ); }
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
