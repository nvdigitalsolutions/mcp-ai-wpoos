<?php
/**
 * Import Comic Creation Blueprint.
 *
 * @package   WP_MCP_AI_Pro
 * @subpackage Comic_Creation_Toolkit
 * @since     2.3.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

/**
 * Imports a curated comic creation assistant blueprint into the mcp_ai_assistant CPT.
 *
 * @since 2.3.1
 */
class WP_MCP_AI_Tool_Import_Comic_Creation_Blueprint implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	const BLUEPRINTS_DIR  = WP_MCP_AI_PRO_PATH . 'includes/tools/comic-creation/examples';
	const BLUEPRINT_SLUGS = array( 'comic-artist' );

	/**
	 * {@inheritdoc}
	 */
	public static function is_available() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_comic_creation_toolkit'] );
	}
	/**
	 * {@inheritdoc}
	 */
	public static function get_unavailable_reason() {
		return __( 'Requires the Comic Creation Toolkit to be enabled.', 'mcp-ai-wpoos-pro' );
	}
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'import_comic_creation_blueprint'; }
	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Import Comic Creation Blueprint', 'mcp-ai-wpoos-pro' ); }
	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Install the Comic Artist assistant blueprint for AI-assisted comic creation workflows.', 'mcp-ai-wpoos-pro' ); }
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
					'description' => __( 'Blueprint slug.', 'mcp-ai-wpoos-pro' ),
				),
				'overwrite' => array(
					'type'        => 'boolean',
					'description' => __( 'Overwrite existing assistant.', 'mcp-ai-wpoos-pro' ),
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
			$p = WP_MCP_AI_PRO_PATH . 'includes/tools/orchestration/class-wp-mcp-ai-blueprint-installer.php';
			if ( file_exists( $p ) ) {
				require_once $p; }
		}
		$data = WP_MCP_AI_Blueprint_Installer::load_blueprint( self::BLUEPRINTS_DIR, $bp );
		if ( is_wp_error( $data ) ) {
			return $data; }
		return WP_MCP_AI_Blueprint_Installer::install( $data, $bp, $overwrite );
	}
}
