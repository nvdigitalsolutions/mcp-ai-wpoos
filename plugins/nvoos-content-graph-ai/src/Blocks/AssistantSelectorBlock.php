<?php
/**
 * Assistant selector block for the Content Graph AI addon.
 *
 * Aligned port of the base plugin's `mcp-ai-wpoos/assistant-selector`
 * block: a server-rendered dropdown of published assistants with an
 * optional start button. Deviation (documented): the base renders
 * `data-tools` / `data-shortcuts` on each option for its chat.js bundle;
 * the CG widget resolves the assistant's tools server-side, so the
 * ported options carry only the assistant ID.
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
 * `nvoos-content-graph-ai/assistant-selector` block.
 *
 * @since 1.1.0
 */
class AssistantSelectorBlock {

	/**
	 * Block name.
	 */
	const BLOCK_NAME = 'nvoos-content-graph-ai/assistant-selector';

	/**
	 * Assistant post type slug (byte-identical to the base plugin).
	 */
	const POST_TYPE = 'mcp_ai_assistant';

	/**
	 * Block metadata (title/icon/category/attributes).
	 *
	 * @return array
	 */
	public static function metadata(): array {
		return array(
			'apiVersion'  => 3,
			'title'       => __( 'Assistant Selector', 'nvoos-content-graph-ai' ),
			'category'    => 'nvoos-content-graph-ai',
			'icon'        => 'admin-users',
			'description' => __( 'A dropdown to select from available AI assistants.', 'nvoos-content-graph-ai' ),
			'keywords'    => array( 'ai', 'assistant', 'selector', 'dropdown' ),
			'attributes'  => array(
				'defaultAssistantId' => array(
					'type'    => 'number',
					'default' => 0,
				),
				'label'              => array(
					'type'    => 'string',
					'default' => '',
				),
				'showStartButton'    => array(
					'type'    => 'boolean',
					'default' => true,
				),
				'startButtonText'    => array(
					'type'    => 'string',
					'default' => '',
				),
			),
			'supports'    => array(
				'anchor'  => true,
				'html'    => false,
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
	 * The third argument is nullable so admin pages (e.g. the Build
	 * Assistant Prompt tab) can embed the same markup outside a block
	 * context without constructing a WP_Block instance.
	 *
	 * @param array         $attributes Block attributes.
	 * @param string        $content    Inner block content (unused).
	 * @param \WP_Block|null $block     Block instance (null in admin embeds).
	 * @return string Rendered block HTML.
	 */
	public static function render( array $attributes, string $content, ?\WP_Block $block = null ): string {
		unset( $content );

		$default_id = isset( $attributes['defaultAssistantId'] ) ? absint( $attributes['defaultAssistantId'] ) : 0;
		$label      = isset( $attributes['label'] ) && '' !== $attributes['label']
			? sanitize_text_field( (string) $attributes['label'] )
			: __( 'Select an Assistant:', 'nvoos-content-graph-ai' );
		$show_start = ! isset( $attributes['showStartButton'] ) || ! empty( $attributes['showStartButton'] );
		$start_text = isset( $attributes['startButtonText'] ) && '' !== $attributes['startButtonText']
			? sanitize_text_field( (string) $attributes['startButtonText'] )
			: __( 'Start Chat', 'nvoos-content-graph-ai' );

		$assistants = self::collect_assistants();

		$unique_id = wp_unique_id( 'nvoos-cg-selector-' );

		$wrapper_attributes = self::wrapper_attributes( $block, $unique_id );

		Blocks::enqueue_assistant_assets();

		$html  = '<div ' . $wrapper_attributes . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Sanitised by get_block_wrapper_attributes() or the esc_attr() fallback.
		$html .= '<label for="' . esc_attr( $unique_id ) . '-select">' . esc_html( $label ) . '</label>';
		$html .= '<select id="' . esc_attr( $unique_id ) . '-select" class="nvoos-cg-selector__select">';
		$html .= '<option value="">' . esc_html__( '— Select an assistant —', 'nvoos-content-graph-ai' ) . '</option>';

		foreach ( $assistants as $assistant ) {
			$html .= sprintf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( (string) $assistant['id'] ),
				selected( $default_id, $assistant['id'], false ),
				esc_html( $assistant['title'] )
			);
		}

		$html .= '</select>';

		if ( $show_start ) {
			$html .= '<button type="button" class="nvoos-cg-selector__start button button-primary" disabled>' . esc_html( $start_text ) . '</button>';
		}

		$html .= '</div>';

		return $html;
	}

	/**
	 * Collect published assistants for the dropdown (title ASC).
	 *
	 * @return array<int, array{id: int, title: string}>
	 */
	public static function collect_assistants(): array {
		$posts = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		$assistants = array();
		foreach ( $posts as $post ) {
			$assistants[] = array(
				'id'    => (int) $post->ID,
				'title' => (string) $post->post_title,
			);
		}

		return $assistants;
	}

	/**
	 * Wrapper attributes, block-context aware.
	 *
	 * @param \WP_Block|null $block     Block instance or null.
	 * @param string         $unique_id Unique instance ID.
	 * @return string Sanitised attribute string.
	 */
	protected static function wrapper_attributes( ?\WP_Block $block, string $unique_id ): string {
		$classes = array( 'wp-block-nvoos-content-graph-ai-assistant-selector', 'nvoos-cg-selector' );

		if ( function_exists( 'get_block_wrapper_attributes' ) && $block instanceof \WP_Block ) {
			return get_block_wrapper_attributes(
				array(
					'class'         => implode( ' ', $classes ),
					'data-block-id' => $unique_id,
				)
			);
		}

		return sprintf(
			'class="%s" data-block-id="%s"',
			esc_attr( implode( ' ', $classes ) ),
			esc_attr( $unique_id )
		);
	}
}
