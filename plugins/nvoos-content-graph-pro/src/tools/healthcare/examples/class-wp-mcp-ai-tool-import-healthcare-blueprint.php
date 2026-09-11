<?php
/**
 * examples/class-wp-mcp-ai-tool-import-healthcare-blueprint.php (ecosystem port — Wave F4, healthcare interop + OpenMed batch).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/healthcare/examples/class-wp-mcp-ai-tool-import-healthcare-blueprint.php` for the
 * standalone `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the
 * class in monolith installs — the addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined
 * (see the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`;
 * `NVOOS_CONTENT_GRAPH_PRO_*` constant swaps (the blueprint installer resolves from the addon's
 * already-ported `src/tools/orchestration/` copy).
 *
 * @package NvoosContentGraphPro
 * @since 1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Imports a curated healthcare assistant blueprint into the mcp_ai_assistant CPT.
 *
 * @since 2.3.0
 */
class WP_MCP_AI_Tool_Import_Healthcare_Blueprint implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * Directory containing healthcare blueprint JSON files.
	 *
	 * @since 2.3.0
	 * @var string
	 */
	const BLUEPRINTS_DIR = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/examples';

	/**
	 * Available healthcare blueprint slugs.
	 *
	 * Must stay synchronised with the .json files in the examples/ directory.
	 *
	 * @since 2.3.0
	 * @var string[]
	 */
	const BLUEPRINT_SLUGS = array(
		'general-clinic',
		'veterinary-practice',
		'personal-health-tracker',
		'radiology-review',
	);

	/**
	 * {@inheritdoc}
	 */
	public static function is_available() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return (
			! empty( $settings['enable_health_wellness_management'] ) ||
			! empty( $settings['enable_healthcare_imaging'] ) ||
			! empty( $settings['enable_medical_vitals'] )
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public static function get_unavailable_reason() {
		return __( 'The Import Healthcare Blueprint tool requires at least one healthcare sub-toolkit (Health & Wellness, Healthcare Imaging, or Medical Vitals) to be enabled in plugin settings.', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'import_healthcare_blueprint';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Import Healthcare Blueprint', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Install a curated healthcare assistant blueprint for general clinic front desk, veterinary practice, personal health tracking, or radiology review workflows. Blueprints include pre-configured PHI audit logging, FHIR/CCDA/HL7v2 tool sets, and capability-gated access.', 'nvoos-content-graph-pro' );
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
		return array( 'pro', 'database-write', 'pii-access', 'requires-capability' );
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

		// Healthcare blueprints carry an extra PHI-audit marker in meta_input.
		// Ensure it's present for the audit system to gate PHI access.
		if ( isset( $data['meta_input'] ) && ! empty( $data['meta_input']['_wp_mcp_ai_audit_phi'] ) ) {
			/**
			 * Fires before a healthcare blueprint with PHI audit enabled
			 * is installed. Use this to enforce BAA acknowledgement gates.
			 *
			 * @since 2.3.0
			 *
			 * @param string $blueprint_slug The blueprint slug being installed.
			 */
			do_action( 'wp_mcp_ai_healthcare_before_blueprint_install', $bp );
		}

		// Install as an mcp_ai_assistant post.
		$result = WP_MCP_AI_Blueprint_Installer::install( $data, $bp, $overwrite );

		if ( ! is_wp_error( $result ) && isset( $result['assistant_id'] ) ) {
			/**
			 * Fires after a healthcare blueprint has been successfully installed.
			 *
			 * @since 2.3.0
			 *
			 * @param int    $assistant_id   The assistant post ID.
			 * @param string $blueprint_slug The blueprint slug.
			 */
			do_action( 'wp_mcp_ai_healthcare_after_blueprint_install', $result['assistant_id'], $bp );
		}

		return $result;
	}
}
