<?php
/**
 * Import Regulatory Registration Blueprint — installs a curated regulatory registration assistant blueprint.
 *
 * Delegates to the shared WP_MCP_AI_Blueprint_Installer for file loading,
 * JSON parsing, duplicate detection, post insertion, and meta population.
 *
 * @package   WP_MCP_AI_Pro
 * @subpackage Regulatory_Registration_Toolkit
 * @since     2.3.1
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Imports a curated regulatory registration assistant blueprint into the mcp_ai_assistant CPT.
 *
 * @since 2.3.1
 */
class WP_MCP_AI_Tool_Import_Regulatory_Registration_Blueprint implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	const BLUEPRINTS_DIR = WP_MCP_AI_PRO_PATH . 'includes/tools/regulatory-registration/examples';

	const BLUEPRINT_SLUGS = array(
		'regulatory-affairs-manager',
		'product-registration-specialist',
	);

	/**
	 * {@inheritdoc}
	 */
	public static function is_available() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_regulatory_registration_toolkit'] );
	}

	/**
	 * {@inheritdoc}
	 */
	public static function get_unavailable_reason() {
		return __( 'The Import Regulatory Registration Blueprint tool requires the Regulatory Registration Toolkit to be enabled in plugin settings.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'import_regulatory_registration_blueprint'; }
	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Import Regulatory Registration Blueprint', 'mcp-ai-wpoos-pro' ); }
	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Install a curated regulatory registration assistant blueprint for regulatory affairs management or product registration specialist workflows.', 'mcp-ai-wpoos-pro' ); }

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
					'description' => __( 'Blueprint slug to import.', 'mcp-ai-wpoos-pro' ),
				),
				'overwrite' => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to overwrite an existing assistant with the same name.', 'mcp-ai-wpoos-pro' ),
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
			$installer_path = WP_MCP_AI_PRO_PATH . 'includes/tools/orchestration/class-wp-mcp-ai-blueprint-installer.php';
			if ( file_exists( $installer_path ) ) {
				require_once $installer_path;
			}
		}

		$data = WP_MCP_AI_Blueprint_Installer::load_blueprint( self::BLUEPRINTS_DIR, $bp );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		return WP_MCP_AI_Blueprint_Installer::install( $data, $bp, $overwrite );
	}
}
