<?php
/**
 * Import CRM Blueprint — installs a curated CRM assistant blueprint.
 *
 * Delegates to the shared WP_MCP_AI_Blueprint_Installer for file loading,
 * JSON parsing, duplicate detection, post insertion, and meta population.
 *
 * @package   WP_MCP_AI_Pro
 * @subpackage CRM_Toolkit
 * @since     2.3.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Imports a curated CRM assistant blueprint into the mcp_ai_assistant CPT.
 *
 * @since 2.3.0
 */
class WP_MCP_AI_Tool_Import_CRM_Blueprint implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * Directory containing CRM blueprint JSON files.
	 *
	 * @since 2.3.0
	 * @var string
	 */
	const BLUEPRINTS_DIR = WP_MCP_AI_PRO_PATH . 'includes/tools/crm/examples';

	/**
	 * Available CRM blueprint slugs.
	 *
	 * Must stay synchronised with the .json files in the examples/ directory.
	 *
	 * @since 2.3.0
	 * @var string[]
	 */
	const BLUEPRINT_SLUGS = array(
		'b2b-saas-sdr',
		'agency-account-manager',
		'real-estate-buyer-agent',
		'wholesale-distributor',
		'bespoke-concierge',
		'luxeseek-sourcing-agent',
		'business-advisory',
		'career-coach',
	);

	/**
	 * {@inheritdoc}
	 */
	public static function is_available() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_crm_toolkit'] );
	}

	/**
	 * {@inheritdoc}
	 */
	public static function get_unavailable_reason() {
		return __( 'The Import CRM Blueprint tool requires the CRM Toolkit to be enabled in plugin settings.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'import_crm_blueprint';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Import CRM Blueprint', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Install a curated CRM assistant blueprint for B2B SaaS SDR, agency account management, real estate buyer agent, wholesale distribution, bespoke concierge, luxury sourcing, business advisory, or career coaching workflows.', 'mcp-ai-wpoos-pro' );
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
	 * @since 2.3.0
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
			$installer_path = WP_MCP_AI_PRO_PATH . 'includes/tools/orchestration/class-wp-mcp-ai-blueprint-installer.php';
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
