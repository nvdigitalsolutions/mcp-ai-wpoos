<?php
/**
 * Generate Mermaid Diagram Tool
 *
 * Creates diagrams using Mermaid.js syntax for flowcharts, sequence diagrams,
 * gantt charts, and more.
 *
 * @package WP_MCP_AI
 * @since 1.2.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP_MCP_AI_Tool_Generate_Mermaid class.
 *
 * Generates diagrams using Mermaid.js library.
 * Supports flowchart, sequence, gantt, and class diagrams.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_Generate_Mermaid implements WP_MCP_AI_Tool_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Get tool slug.
	 *
	 * @return string Tool slug.
	 */
	public function get_slug() {
		return 'generate_mermaid';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Generate Mermaid Diagram', 'mcp-ai-wpoos' );
	}

	/**
	 * Get tool description.
	 *
	 * @return string Tool description.
	 */
	public function get_description() {
		return 'Generate diagrams using Mermaid.js (flowchart, sequence, gantt, class diagrams)';
	}

	/**
	 * Get parameters schema.
	 *
	 * @return array JSON Schema for parameters.
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'type'  => array(
					'type'        => 'string',
					'description' => 'Diagram type',
					'enum'        => array( 'flowchart', 'sequence', 'gantt', 'class' ),
				),
				'code'  => array(
					'type'        => 'string',
					'description' => 'Mermaid diagram code',
				),
				'theme' => array(
					'type'        => 'string',
					'description' => 'Diagram theme (optional)',
					'enum'        => array( 'default', 'forest', 'dark', 'neutral' ),
					'default'     => 'default',
				),
			),
			'required'   => array( 'type', 'code' ),
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array Tool result.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Validate diagram type.
		$valid_types = array( 'flowchart', 'sequence', 'gantt', 'class' );
		if ( ! isset( $arguments['type'] ) || ! in_array( $arguments['type'], $valid_types, true ) ) {
			return new WP_Error(
				'wp_mcp_ai_error',
				'Invalid diagram type. Must be one of: ' . implode( ', ', $valid_types )
			);
		}

		// Validate code.
		if ( empty( $arguments['code'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_error',
				'Mermaid diagram code is required'
			);
		}

		// Generate unique ID for this diagram.
		$diagram_id = 'wp-mcp-ai-mermaid-' . wp_generate_uuid4();

		// Get theme (default to 'default').
		$theme        = isset( $arguments['theme'] ) ? $arguments['theme'] : 'default';
		$valid_themes = array( 'default', 'forest', 'dark', 'neutral' );
		if ( ! in_array( $theme, $valid_themes, true ) ) {
			$theme = 'default';
		}

		// Sanitize the Mermaid code.
		// Note: We use wp_kses_post to allow basic HTML but strip dangerous tags.
		$mermaid_code = wp_kses_post( $arguments['code'] );

		// Generate HTML with Mermaid.js code via wp_print_inline_script_tag().
		$script_js = sprintf(
			'(function() {
				if (typeof mermaid === "undefined") {
					console.error("Mermaid.js not loaded");
					return;
				}
				mermaid.initialize({ 
					startOnLoad: true,
					theme: "%s",
					securityLevel: "strict"
				});
			})();',
			esc_js( $theme )
		);

		ob_start();
		wp_print_inline_script_tag( $script_js );
		$script_tag = ob_get_clean();

		$html = sprintf(
			'<div class="wp-mcp-ai-mermaid-container">
				<div class="mermaid" id="%s" data-theme="%s">
%s
				</div>
				%s
			</div>',
			esc_attr( $diagram_id ),
			esc_attr( $theme ),
			$mermaid_code,
			$script_tag
		);

		return array(
			'success'    => true,
			'diagram_id' => $diagram_id,
			'type'       => $arguments['type'],
			'theme'      => $theme,
			'html'       => $html,
			'message'    => sprintf( 'Generated %s diagram with ID: %s', $arguments['type'], $diagram_id ),
		);
	}

	/**
	 * Get extended tool definition including toolkit metadata.
	 *
	 * @since 1.1.0
	 *
	 * @return array Tool definition with metadata.
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'data_analytics',
			'pattern_compatibility' => array( 'sequential' ),
			'profession_tags'       => array( 'software_developer', 'technical_writer' ),
			'risk_level'            => 'info',
		);
	}
}
