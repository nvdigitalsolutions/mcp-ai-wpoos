<?php
/**
 * Import F&B Blueprint — installs a curated F&B assistant blueprint.
 *
 * Delegates to the shared WP_MCP_AI_Blueprint_Installer for file loading,
 * JSON parsing, duplicate detection, post insertion, and meta population.
 * Mirrors the CRM / Healthcare blueprint import tools.
 *
 * @package   WP_MCP_AI_Pro
 * @subpackage Food_Beverage_Toolkit
 * @since     1.6.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Imports a curated F&B assistant blueprint into the mcp_ai_assistant CPT.
 *
 * @since 1.6.0
 */
class WP_MCP_AI_Tool_Import_Fnb_Blueprint implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * Directory containing F&B blueprint JSON files.
	 *
	 * @since 1.6.0
	 * @var string
	 */
	const BLUEPRINTS_DIR = WP_MCP_AI_PRO_PATH . 'includes/tools/food-beverage/examples';

	/**
	 * Available F&B blueprint slugs.
	 *
	 * Must stay synchronised with the .json files in the examples/ directory.
	 *
	 * @since 1.6.0
	 * @var string[]
	 */
	const BLUEPRINT_SLUGS = array(
		'fnb-a1-manager',
		'fnb-a2-kitchen-bar-stock',
		'fnb-a3-financial',
		'fnb-a4-content',
	);

	/**
	 * {@inheritdoc}
	 */
	public static function is_available() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_fnb_toolkit'] );
	}

	/**
	 * {@inheritdoc}
	 */
	public static function get_unavailable_reason() {
		return __( 'The Import F&B Blueprint tool requires the Food & Beverage Toolkit to be enabled in plugin settings.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'import_fnb_blueprint';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Import F&B Blueprint', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Install one of the four F&B assistant blueprints (A1 Manager, A2 Kitchen & Bar Stock, A3 Financial, A4 Content) as an assistant. A2 may draft purchase requests (fnb_pr_create) and read the register and audit trail; the ACT-tier approval tools are never granted.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get usage guidance for the tool.
	 *
	 * @return array
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Installing one of the four F&B assistant blueprints by slug — the admin Blueprints page one-click alternative to the WP-CLI seeder.', 'mcp-ai-wpoos-pro' ),
			'when_not_to_use' => __( 'Importing the richer nvoos-assistant v1 bundles (A4 image-tool opt-in, full guardrail structure); use wp mcp-ai pro fnb seed-assistants for those.', 'mcp-ai-wpoos-pro' ),
			'related_tools'   => array( 'fnb_read_table', 'fnb_pr_create', 'fnb_audit_log' ),
			'notes'           => __( 'Blueprint slugs are fixed in BLUEPRINT_SLUGS; set overwrite=true to replace an existing assistant of the same name. ACT-tier tools stay off by default.', 'mcp-ai-wpoos-pro' ),
		);
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
	 * @since 1.6.0
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

		// Render venue/currency tokens ({{venue_name}}, {{currency}}, …) in the
		// blueprint's name, description and instructions before install, so the
		// operator's tenant profile is applied identically to the WP-CLI seeder.
		if ( class_exists( 'WP_MCP_AI_Fnb_Tenant_Profile' ) ) {
			foreach ( array( 'name', 'description' ) as $field ) {
				if ( isset( $data[ $field ] ) && is_string( $data[ $field ] ) ) {
					$data[ $field ] = WP_MCP_AI_Fnb_Tenant_Profile::render( $data[ $field ] );
				}
			}
			if ( isset( $data['meta']['instructions'] ) && is_string( $data['meta']['instructions'] ) ) {
				$data['meta']['instructions'] = WP_MCP_AI_Fnb_Tenant_Profile::render( $data['meta']['instructions'] );
			}
		}

		// Install as an mcp_ai_assistant post.
		return WP_MCP_AI_Blueprint_Installer::install( $data, $bp, $overwrite );
	}
}
