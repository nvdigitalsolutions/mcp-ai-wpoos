<?php
/**
 * WP_MCP_AI_Tool_Create_CTA_Section (ecosystem port - Wave F2, site-creator tool batch).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/site-creator-toolkit/` directory for the
 * standalone `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the
 * class in monolith installs - the addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined (see
 * the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`;
 * the base-owned interface/Logger seams resolve from the addon's D8-compat `src/` copies (the monorepo root classmap serves the base copies monolith).
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Site_Creator_Toolkit
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);



if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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
 * Create CTA Section Tool
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_Create_CTA_Section implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Check if this tool is available.
	 *
	 * @since 1.2.0
	 *
	 * @return bool True if tool is available.
	 */
	public static function is_available() {
		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'create_cta_section';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Create CTA Section', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Generates call-to-action sections with compelling copy, urgency elements, and conversion-optimized buttons. Includes multiple CTA styles.', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'headline'    => array(
					'type'        => 'string',
					'description' => __( 'CTA headline', 'nvoos-content-graph-pro' ),
				),
				'description' => array(
					'type'        => 'string',
					'description' => __( 'Supporting description text', 'nvoos-content-graph-pro' ),
				),
				'button_text' => array(
					'type'        => 'string',
					'description' => __( 'Button text', 'nvoos-content-graph-pro' ),
					'default'     => 'Get Started Now',
				),
				'style'       => array(
					'type'        => 'string',
					'description' => __( 'CTA style', 'nvoos-content-graph-pro' ),
					'enum'        => array( 'bold', 'subtle', 'gradient', 'minimal' ),
					'default'     => 'bold',
				),
				'urgency'     => array(
					'type'        => 'boolean',
					'description' => __( 'Include urgency elements', 'nvoos-content-graph-pro' ),
					'default'     => false,
				),
			),
			'required'             => array( 'headline' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Execute the tool.
	 *
	 * @since 1.2.0
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error CTA section data or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Check if site creator toolkit is enabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_site_creator_toolkit'] ) ) {
			return new WP_Error( 'wp_mcp_ai_feature_disabled', __( 'The Site Creator Toolkit is disabled.', 'nvoos-content-graph-pro' ) );
		}

		// Check permissions.
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $user_id || ! user_can( $user_id, 'edit_pages' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission.', 'nvoos-content-graph-pro' ) );
		}

		// Sanitize arguments.
		$headline    = isset( $arguments['headline'] ) ? sanitize_text_field( $arguments['headline'] ) : '';
		$description = isset( $arguments['description'] ) ? sanitize_textarea_field( $arguments['description'] ) : '';
		$button_text = isset( $arguments['button_text'] ) ? sanitize_text_field( $arguments['button_text'] ) : 'Get Started Now';
		$style       = isset( $arguments['style'] ) ? sanitize_text_field( $arguments['style'] ) : 'bold';
		$urgency     = isset( $arguments['urgency'] ) ? (bool) $arguments['urgency'] : false;

		if ( empty( $headline ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_required', __( 'Headline is required.', 'nvoos-content-graph-pro' ) );
		}

		$cta_section = array(
			'type'    => 'cta',
			'style'   => $style,
			'content' => array(
				'headline'    => $headline,
				'description' => ! empty( $description ) ? $description : 'Take action today and transform your experience',
				'button'      => array(
					'text'  => $button_text,
					'style' => $style,
				),
			),
		);

		if ( $urgency ) {
			$cta_section['content']['urgency'] = array(
				'text' => 'Limited time offer - Act now!',
				'type' => 'countdown',
			);
		}

		return array(
			'success'     => true,
			'cta_section' => $cta_section,
			/* translators: %s: CTA style */
			'summary'     => sprintf( __( 'Generated %s CTA section with headline and button.', 'nvoos-content-graph-pro' ), $style ),
			'timestamp'   => current_time( 'mysql' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'write', 'requires-capability', 'non-deterministic' );
	}
}
