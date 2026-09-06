<?php
/**
 * Chat block for the Content Graph AI addon.
 *
 * Aligned port of the base plugin's `mcp-ai-wpoos/chat` block: a
 * server-rendered wrapper around the ecosystem chat widget
 * (`[nvoos_content_graph_chat]`, Wave D-UI-1b). Attribute vocabulary
 * maps the base's block attributes onto the CG widget's attribute set —
 * `saveTranscript` / `enableStreaming` / `allowSensitiveTools` /
 * `template` / `showBuildButton` have no CG counterpart yet and are
 * dropped (documented deviation; they belong to the base hub's full
 * chat.js surface).
 *
 * @package NvoosContentGraphAi\Blocks
 * @since   1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary (commercial license required)
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Blocks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `nvoos-content-graph-ai/chat` block.
 *
 * @since 1.1.0
 */
class ChatBlock {

	/**
	 * Block name.
	 */
	const BLOCK_NAME = 'nvoos-content-graph-ai/chat';

	/**
	 * Block metadata (title/icon/category/attributes).
	 *
	 * @return array
	 */
	public static function metadata(): array {
		return array(
			'apiVersion' => 3,
			'title'      => __( 'AI Chat', 'nvoos-content-graph-ai' ),
			'category'   => 'nvoos-content-graph-ai',
			'icon'       => 'format-chat',
			'description' => __( 'Display an AI chat interface powered by NV oOS Content Graph.', 'nvoos-content-graph-ai' ),
			'keywords'   => array( 'ai', 'chat', 'assistant', 'conversation', 'graph' ),
			'attributes' => array(
				'assistantId' => array(
					'type'    => 'number',
					'default' => 0,
				),
				'allowGuests' => array(
					'type'    => 'boolean',
					'default' => false,
				),
				'provider'    => array(
					'type'    => 'string',
					'default' => '',
				),
				'model'       => array(
					'type'    => 'string',
					'default' => '',
				),
				'height'      => array(
					'type'    => 'string',
					'default' => '500px',
				),
				'showCost'    => array(
					'type'    => 'boolean',
					'default' => true,
				),
				'placeholder' => array(
					'type'    => 'string',
					'default' => '',
				),
			),
			'supports'   => array(
				'align'  => array( 'wide', 'full' ),
				'anchor' => true,
				'html'   => false,
				'spacing' => array(
					'margin'  => true,
					'padding' => true,
				),
			),
		);
	}

	/**
	 * Server-side render callback.
	 *
	 * @param array    $attributes Block attributes.
	 * @param string   $content    Inner block content (unused).
	 * @param WP_Block $block      Block instance.
	 * @return string Rendered block HTML.
	 */
	public static function render( array $attributes, string $content, \WP_Block $block ): string {
		unset( $content, $block );

		$shortcode_atts = array();

		$assistant_id = isset( $attributes['assistantId'] ) ? absint( $attributes['assistantId'] ) : 0;
		if ( $assistant_id ) {
			$shortcode_atts[] = 'assistant="' . $assistant_id . '"';
		}

		if ( ! empty( $attributes['allowGuests'] ) ) {
			$shortcode_atts[] = 'allow_guests="true"';
		}

		if ( ! empty( $attributes['provider'] ) ) {
			$shortcode_atts[] = 'provider="' . esc_attr( sanitize_text_field( (string) $attributes['provider'] ) ) . '"';
		}

		if ( ! empty( $attributes['model'] ) ) {
			$shortcode_atts[] = 'model="' . esc_attr( sanitize_text_field( (string) $attributes['model'] ) ) . '"';
		}

		if ( ! empty( $attributes['height'] ) ) {
			$shortcode_atts[] = 'height="' . esc_attr( (string) $attributes['height'] ) . '"';
		}

		if ( isset( $attributes['showCost'] ) && ! $attributes['showCost'] ) {
			$shortcode_atts[] = 'show_cost="0"';
		}

		if ( ! empty( $attributes['placeholder'] ) ) {
			$shortcode_atts[] = 'placeholder="' . esc_attr( sanitize_text_field( (string) $attributes['placeholder'] ) ) . '"';
		}

		$shortcode = '[nvoos_content_graph_chat ' . implode( ' ', $shortcode_atts ) . ']';

		$wrapper_class = 'wp-block-nvoos-content-graph-ai-chat';

		$wrapper_attributes = get_block_wrapper_attributes(
			array(
				'class' => $wrapper_class,
			)
		);

		return sprintf(
			'<div %1$s>%2$s</div>',
			$wrapper_attributes, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Sanitised by get_block_wrapper_attributes().
			do_shortcode( $shortcode ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Widget output escapes every value internally.
		);
	}
}
