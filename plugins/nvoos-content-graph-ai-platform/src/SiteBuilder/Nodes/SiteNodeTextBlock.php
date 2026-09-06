<?php
/**
 * Site Node: Text Block (Wave E4, sub-cluster 5).
 *
 * Aligned port of the base plugin's `WP_MCP_AI_Site_Node_Text_Block`:
 * byte-identical slug/metadata, the four input port definitions
 * (content, tag, className, style), the `html` output, and the
 * execution contract — kses'd content, the 15-tag block-element
 * whitelist with the div fallback, sanitized class/style attributes,
 * and the assembled `<tag>` wrapper.
 *
 * Documented deviations:
 *  - Text domain `nvoos-content-graph-ai-platform`.
 *  - Class name/namespace — the platform's PSR-4 tree.
 *  - `className` is cast to string before sanitization — the base's
 *    implicit coercion would TypeError under this file's
 *    `strict_types=1`; behavior is identical for string inputs
 *    (documented hardening).
 *
 * @since 2.1.0
 * @package NvoosContentGraphAiPlatform\SiteBuilder\Nodes
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\SiteBuilder\Nodes;

use NvoosContentGraphAiPlatform\SiteBuilder\SiteNodeInterface;

/**
 * Layout node that wraps a text string (or HTML) in a semantic block.
 *
 * Category: layout
 * Inputs:  content (html), tag (string), className (string)
 * Outputs: html
 *
 * @since 2.1.0
 */
class SiteNodeTextBlock implements SiteNodeInterface {

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
		return __( 'Text Block', 'nvoos-content-graph-ai-platform' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description(): string {
		return __( 'Render a text or HTML content block with a configurable tag, class, and inline style.', 'nvoos-content-graph-ai-platform' );
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
				'label'    => __( 'Content', 'nvoos-content-graph-ai-platform' ),
				'required' => false,
				'default'  => '',
			),
			array(
				'name'     => 'tag',
				'type'     => 'string',
				'label'    => __( 'HTML Tag', 'nvoos-content-graph-ai-platform' ),
				'required' => false,
				'default'  => 'div',
			),
			array(
				'name'     => 'className',
				'type'     => 'string',
				'label'    => __( 'CSS Class', 'nvoos-content-graph-ai-platform' ),
				'required' => false,
				'default'  => '',
			),
			array(
				'name'     => 'style',
				'type'     => 'css',
				'label'    => __( 'Inline Style', 'nvoos-content-graph-ai-platform' ),
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
				'label' => __( 'HTML', 'nvoos-content-graph-ai-platform' ),
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
		$class_attr = isset( $inputs['className'] ) ? sanitize_html_class( (string) $inputs['className'] ) : '';
		$style      = isset( $inputs['style'] ) ? esc_attr( (string) $inputs['style'] ) : '';

		// Allow only safe block-level tags.
		$allowed_tags = array( 'div', 'section', 'article', 'aside', 'header', 'footer', 'p', 'blockquote', 'pre', 'figure', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' );
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
