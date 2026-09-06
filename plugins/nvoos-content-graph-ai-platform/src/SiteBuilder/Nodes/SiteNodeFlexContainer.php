<?php
/**
 * Site Node: Flex Container (Wave E4, sub-cluster 5).
 *
 * Aligned port of the base plugin's `WP_MCP_AI_Site_Node_Flex_Container`:
 * byte-identical slug/metadata, the six input port definitions
 * (children, direction, gap, align, justify, padding), the `html`
 * output, and the execution contract — the three flex-property
 * whitelists with their fallbacks, the assembled `display:flex` inline
 * style, kses'd child HTML, and the `nvoos-flex-container` wrapper.
 *
 * Documented deviations:
 *  - Text domain `nvoos-content-graph-ai-platform`.
 *  - Class name/namespace — the platform's PSR-4 tree.
 *
 * @since 2.1.0
 * @package NvoosContentGraphAiPlatform\SiteBuilder\Nodes
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\SiteBuilder\Nodes;

use NvoosContentGraphAiPlatform\SiteBuilder\SiteNodeInterface;

/**
 * Layout node that arranges its child HTML blocks using CSS flexbox.
 *
 * Category: layout
 * Inputs:  children (html array), direction, gap, alignment, padding
 * Outputs: html
 *
 * @since 2.1.0
 */
class SiteNodeFlexContainer implements SiteNodeInterface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug(): string {
		return 'flex_container';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name(): string {
		return __( 'Flex Container', 'nvoos-content-graph-ai-platform' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description(): string {
		return __( 'Arrange child blocks using CSS flexbox. Connect text/image nodes to the children input.', 'nvoos-content-graph-ai-platform' );
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
				'name'     => 'children',
				'type'     => 'html',
				'label'    => __( 'Children', 'nvoos-content-graph-ai-platform' ),
				'required' => false,
				'default'  => array(),
			),
			array(
				'name'     => 'direction',
				'type'     => 'string',
				'label'    => __( 'Direction', 'nvoos-content-graph-ai-platform' ),
				'required' => false,
				'default'  => 'row',
			),
			array(
				'name'     => 'gap',
				'type'     => 'string',
				'label'    => __( 'Gap', 'nvoos-content-graph-ai-platform' ),
				'required' => false,
				'default'  => '16px',
			),
			array(
				'name'     => 'align',
				'type'     => 'string',
				'label'    => __( 'Align Items', 'nvoos-content-graph-ai-platform' ),
				'required' => false,
				'default'  => 'stretch',
			),
			array(
				'name'     => 'justify',
				'type'     => 'string',
				'label'    => __( 'Justify Content', 'nvoos-content-graph-ai-platform' ),
				'required' => false,
				'default'  => 'flex-start',
			),
			array(
				'name'     => 'padding',
				'type'     => 'string',
				'label'    => __( 'Padding', 'nvoos-content-graph-ai-platform' ),
				'required' => false,
				'default'  => '0',
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
	 * Execute: build a flexbox wrapper around children.
	 *
	 * {@inheritdoc}
	 *
	 * @param array $inputs Node input values keyed by input name.
	 */
	public function execute( array $inputs ) {
		$direction = isset( $inputs['direction'] ) ? sanitize_text_field( $inputs['direction'] ) : 'row';
		$gap       = isset( $inputs['gap'] ) ? sanitize_text_field( $inputs['gap'] ) : '16px';
		$align     = isset( $inputs['align'] ) ? sanitize_text_field( $inputs['align'] ) : 'stretch';
		$justify   = isset( $inputs['justify'] ) ? sanitize_text_field( $inputs['justify'] ) : 'flex-start';
		$padding   = isset( $inputs['padding'] ) ? sanitize_text_field( $inputs['padding'] ) : '0';
		$children  = isset( $inputs['children'] ) ? (array) $inputs['children'] : array();

		// Whitelist flex properties.
		$allowed_directions = array( 'row', 'row-reverse', 'column', 'column-reverse' );
		$allowed_align      = array( 'stretch', 'flex-start', 'flex-end', 'center', 'baseline' );
		$allowed_justify    = array( 'flex-start', 'flex-end', 'center', 'space-between', 'space-around', 'space-evenly' );

		if ( ! in_array( $direction, $allowed_directions, true ) ) {
			$direction = 'row';
		}
		if ( ! in_array( $align, $allowed_align, true ) ) {
			$align = 'stretch';
		}
		if ( ! in_array( $justify, $allowed_justify, true ) ) {
			$justify = 'flex-start';
		}

		$style = sprintf(
			'display:flex;flex-direction:%s;gap:%s;align-items:%s;justify-content:%s;padding:%s;',
			esc_attr( $direction ),
			esc_attr( $gap ),
			esc_attr( $align ),
			esc_attr( $justify ),
			esc_attr( $padding )
		);

		// Each child is already expected to be safe HTML (escaped by its producing node).
		$children_html = '';
		if ( ! empty( $children ) ) {
			foreach ( $children as $child ) {
				$children_html .= wp_kses_post( (string) $child );
			}
		}

		$html  = '<div style="' . $style . '" class="nvoos-flex-container">';
		$html .= $children_html;
		$html .= '</div>';

		return array(
			'html' => $html,
		);
	}
}
