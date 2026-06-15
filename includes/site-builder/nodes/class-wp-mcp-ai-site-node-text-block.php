<?php
/**
 * Site Node: Text Block — renders a text/HTML content block.
 *
 * @package    WP_MCP_AI
 * @subpackage Site_Builder
 * @since      1.2.0
 * @author     NV Digital Solutions
 * @copyright  Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license    GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Layout node that wraps a text string (or HTML) in a semantic block.
 *
 * Category: layout
 * Inputs:  content (html), tag (string), className (string)
 * Outputs: html
 */
class WP_MCP_AI_Site_Node_Text_Block implements WP_MCP_AI_Site_Node_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug(): string {
		return 'text_block';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name(): string {
		return __( 'Text Block', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description(): string {
		return __( 'Render a text or HTML content block with a configurable tag, class, and inline style.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_category(): string {
		return 'layout';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_inputs(): array {
		return array(
			array(
				'name'     => 'content',
				'type'     => 'html',
				'label'    => __( 'Content', 'mcp-ai-wpoos' ),
				'required' => false,
				'default'  => '',
			),
			array(
				'name'     => 'tag',
				'type'     => 'string',
				'label'    => __( 'HTML Tag', 'mcp-ai-wpoos' ),
				'required' => false,
				'default'  => 'div',
			),
			array(
				'name'     => 'className',
				'type'     => 'string',
				'label'    => __( 'CSS Class', 'mcp-ai-wpoos' ),
				'required' => false,
				'default'  => '',
			),
			array(
				'name'     => 'style',
				'type'     => 'css',
				'label'    => __( 'Inline Style', 'mcp-ai-wpoos' ),
				'required' => false,
				'default'  => '',
			),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_outputs(): array {
		return array(
			array(
				'name'  => 'html',
				'type'  => 'html',
				'label' => __( 'HTML', 'mcp-ai-wpoos' ),
			),
		);
	}

	/**
	 * Execute: wrap content in the configured HTML tag with optional class/style.
	 *
	 * {@inheritdoc}
	 *
	 * @param array $inputs Node input values keyed by input name.
	 */
	public function execute( array $inputs ) {
		$tag        = isset( $inputs['tag'] ) ? sanitize_key( $inputs['tag'] ) : 'div';
		$content    = isset( $inputs['content'] ) ? wp_kses_post( (string) $inputs['content'] ) : '';
		$class_attr = isset( $inputs['className'] ) ? sanitize_html_class( $inputs['className'] ) : '';
		$style      = isset( $inputs['style'] ) ? esc_attr( (string) $inputs['style'] ) : '';

		// Allow only safe block-level tags.
		$allowed_tags = array( 'div', 'section', 'article', 'aside', 'header', 'footer', 'p', 'blockquote', 'pre', 'figure' );
		if ( ! in_array( $tag, $allowed_tags, true ) ) {
			$tag = 'div';
		}

		$attrs = '';
		if ( '' !== $class_attr ) {
			$attrs .= ' class="' . $class_attr . '"';
		}
		if ( '' !== $style ) {
			$attrs .= ' style="' . $style . '"';
		}

		$html  = '<' . $tag . $attrs . '>';
		$html .= $content;
		$html .= '</' . $tag . '>';

		return array(
			'html' => $html,
		);
	}
}
