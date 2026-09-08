<?php
/**
 * Import Video Production Blueprint tool (ecosystem port - Wave F2, video-production blueprint slice).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/video-production/examples/` directory
 * for the standalone `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns
 * the class in monolith installs - the addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined (see
 * the plugin entry). Tree-only tool - the base registers it nowhere (the standalone init's
 * filter/ecosystem additions carry it standalone, CRM CC-extras precedent).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`;
 * `NVOOS_CONTENT_GRAPH_PRO_PATH` swaps with the `src/` root (the blueprint JSONs are copied to
 * `src/tools/video-production/examples/`; the shared blueprint installer resolves from the
 * already-ported `src/tools/orchestration/` copy).
 *
 * Import Video Production Blueprint — installs a curated video production assistant blueprint.
 *
 * Delegates to the shared WP_MCP_AI_Blueprint_Installer for file loading,
 * JSON parsing, duplicate detection, post insertion, and meta population.
 *
 * @package   WP_MCP_AI_Pro
 * @subpackage Video_Production_Toolkit
 * @since     2.3.1
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Imports a curated video production assistant blueprint into the mcp_ai_assistant CPT.
 *
 * @since 2.3.1
 */
class WP_MCP_AI_Tool_Import_Video_Production_Blueprint implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * Directory containing video production blueprint JSON files.
	 *
	 * @since 2.3.1
	 * @var string
	 */
	const BLUEPRINTS_DIR = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/video-production/examples';

	/**
	 * Available video production blueprint slugs.
	 *
	 * Must stay synchronised with the .json files in the examples/ directory.
	 *
	 * @since 2.3.1
	 * @var string[]
	 */
	const BLUEPRINT_SLUGS = array(
		'video-editor',
		'production-manager',
	);

	/**
	 * {@inheritdoc}
	 */
	public static function is_available() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_video_production_toolkit'] );
	}

	/**
	 * {@inheritdoc}
	 */
	public static function get_unavailable_reason() {
		return __( 'The Import Video Production Blueprint tool requires the Video Production Toolkit to be enabled in plugin settings.', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'import_video_production_blueprint';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Import Video Production Blueprint', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Install a curated video production assistant blueprint for video editing or production management workflows.', 'nvoos-content-graph-pro' );
	}

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
					'description' => __( 'Blueprint slug to import.', 'nvoos-content-graph-pro' ),
				),
				'overwrite' => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to overwrite an existing assistant with the same name.', 'nvoos-content-graph-pro' ),
				),
			),
			'required'   => array( 'blueprint' ),
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
		return array( 'pro', 'database-write', 'requires-capability' );
	}

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

		// Ensure shared installer is available.
		if ( ! class_exists( 'WP_MCP_AI_Blueprint_Installer' ) ) {
			$installer_path = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/orchestration/class-wp-mcp-ai-blueprint-installer.php';
			if ( file_exists( $installer_path ) ) {
				require_once $installer_path;
			}
		}

		// Load the blueprint file.
		$data = WP_MCP_AI_Blueprint_Installer::load_blueprint( self::BLUEPRINTS_DIR, $bp );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		// Install as an mcp_ai_assistant post.
		return WP_MCP_AI_Blueprint_Installer::install( $data, $bp, $overwrite );
	}
}
