<?php
/**
 * WP_MCP_AI_Tool_Create_Footer_Widget (ecosystem port - Wave F2, site-creator tool batch).
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
 * Create Footer Widget Tool
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_Create_Footer_Widget implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'create_footer_widget';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Create Footer Widget', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Generates footer widgets and sections including copyright, menus, social links, and multi-column layouts.', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'layout'             => array(
					'type'        => 'string',
					'description' => __( 'Footer layout', 'nvoos-content-graph-pro' ),
					'enum'        => array( 'single', '2-column', '3-column', '4-column' ),
					'default'     => '3-column',
				),
				'include_social'     => array(
					'type'        => 'boolean',
					'description' => __( 'Include social media links', 'nvoos-content-graph-pro' ),
					'default'     => true,
				),
				'include_newsletter' => array(
					'type'        => 'boolean',
					'description' => __( 'Include newsletter signup', 'nvoos-content-graph-pro' ),
					'default'     => false,
				),
				'copyright_text'     => array(
					'type'        => 'string',
					'description' => __( 'Copyright text', 'nvoos-content-graph-pro' ),
				),
			),
			'required'             => array(),
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
	 * @return array|WP_Error Footer widget data or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Check if site creator toolkit is enabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_site_creator_toolkit'] ) ) {
			return new WP_Error( 'wp_mcp_ai_feature_disabled', __( 'The Site Creator Toolkit is disabled.', 'nvoos-content-graph-pro' ) );
		}

		// Check permissions.
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $user_id || ! user_can( $user_id, 'manage_options' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission.', 'nvoos-content-graph-pro' ) );
		}

		// Sanitize arguments.
		$layout             = isset( $arguments['layout'] ) ? sanitize_text_field( $arguments['layout'] ) : '3-column';
		$include_social     = isset( $arguments['include_social'] ) ? (bool) $arguments['include_social'] : true;
		$include_newsletter = isset( $arguments['include_newsletter'] ) ? (bool) $arguments['include_newsletter'] : false;
		$copyright_text     = isset( $arguments['copyright_text'] ) ? sanitize_text_field( $arguments['copyright_text'] ) : '';

		// Generate footer widget.
		$footer_widget = array(
			'layout'  => $layout,
			'columns' => $this->generate_footer_columns( $layout, $include_social, $include_newsletter ),
			'bottom'  => array(
				'copyright' => ! empty( $copyright_text ) ? $copyright_text : '© ' . current_time( 'Y' ) . ' All Rights Reserved',
			),
		);

		if ( $include_social ) {
			$footer_widget['bottom']['social'] = array(
				array(
					'platform' => 'facebook',
					'url'      => '#',
				),
				array(
					'platform' => 'twitter',
					'url'      => '#',
				),
				array(
					'platform' => 'linkedin',
					'url'      => '#',
				),
			);
		}

		return array(
			'success'       => true,
			'footer_widget' => $footer_widget,
			/* translators: %s: layout type */
			'summary'       => sprintf( __( 'Generated %s footer widget.', 'nvoos-content-graph-pro' ), $layout ),
			'timestamp'     => current_time( 'mysql' ),
		);
	}

	/**
	 * Generate footer columns.
	 *
	 * @since 1.2.0
	 *
	 * @param string $layout             Layout type.
	 * @param bool   $include_social     Include social.
	 * @param bool   $include_newsletter Include newsletter.
	 * @return array Columns.
	 */
	private function generate_footer_columns( $layout, $include_social, $include_newsletter ) {
		$columns = array();

		$column_count = (int) str_replace( '-column', '', $layout );
		if ( 'single' === $layout ) {
			$column_count = 1;
		}

		for ( $i = 1; $i <= $column_count; $i++ ) {
			$column = array(
				'title'   => 'Column ' . $i,
				'content' => array(),
			);

			if ( 1 === $i ) {
				$column['content'][] = array(
					'type' => 'text',
					'text' => 'About Us',
				);
			} elseif ( 2 === $i && $include_newsletter ) {
				$column['content'][] = array(
					'type'   => 'newsletter',
					'title'  => 'Newsletter',
					'button' => 'Subscribe',
				);
			} else {
				$column['content'][] = array(
					'type'  => 'menu',
					'items' => array( 'Link 1', 'Link 2', 'Link 3' ),
				);
			}

			$columns[] = $column;
		}

		return $columns;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'write', 'requires-capability', 'non-deterministic' );
	}
}
