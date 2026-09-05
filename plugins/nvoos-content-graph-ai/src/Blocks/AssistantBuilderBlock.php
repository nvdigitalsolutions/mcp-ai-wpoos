<?php
/**
 * AI assistant builder block for the Content Graph AI addon.
 *
 * Aligned port of the base plugin's `mcp-ai-wpoos/assistant-builder`
 * block: a composite surface combining the assistant selector, tools
 * grid, knowledge base upload, a build button, and a chat section.
 * Deviations (documented):
 * - The chat section embeds the ecosystem chat widget
 *   (`[nvoos_content_graph_chat]`, Wave D-UI-1b) instead of the base
 *   chat.js bundle — the builder never spins its own chat runtime.
 * - The build flow submits the manual payload (title + selected tools +
 *   knowledge-base files) to the per-install-mode create action; the
 *   base's AI-prompt-driven construction belongs to the profession
 *   runtime wave and stays deferred.
 * - Nested sections reuse the sibling block classes' static render
 *   methods rather than the base's include-with-$attributes trick.
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
 * `nvoos-content-graph-ai/assistant-builder` block.
 *
 * @since 1.1.0
 */
class AssistantBuilderBlock {

	/**
	 * Block name.
	 */
	const BLOCK_NAME = 'nvoos-content-graph-ai/assistant-builder';

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
			'title'       => __( 'AI Assistant Builder', 'nvoos-content-graph-ai' ),
			'category'    => 'nvoos-content-graph-ai',
			'icon'        => 'hammer',
			'description' => __( 'A complete interface for building new AI assistants with tools configuration, knowledge base, and build functionality.', 'nvoos-content-graph-ai' ),
			'keywords'    => array( 'ai', 'assistant', 'builder', 'create', 'tools', 'mcp' ),
			'attributes'  => array(
				'showAssistantSelector' => array(
					'type'    => 'boolean',
					'default' => true,
				),
				'showToolsGrid'         => array(
					'type'    => 'boolean',
					'default' => true,
				),
				'showKnowledgeBase'     => array(
					'type'    => 'boolean',
					'default' => true,
				),
				'showBuildButton'       => array(
					'type'    => 'boolean',
					'default' => true,
				),
				'defaultAssistantId'    => array(
					'type'    => 'number',
					'default' => 0,
				),
				'layout'                => array(
					'type'    => 'string',
					'default' => 'stacked',
					'enum'    => array( 'stacked', 'side-by-side' ),
				),
				'toolsCollapsed'        => array(
					'type'    => 'boolean',
					'default' => true,
				),
				'showToolDescriptions'  => array(
					'type'    => 'boolean',
					'default' => true,
				),
				'enableStreaming'       => array(
					'type'    => 'boolean',
					'default' => true,
				),
				'chatPlaceholder'       => array(
					'type'    => 'string',
					'default' => '',
				),
				'allowedFileTypes'      => array(
					'type'    => 'string',
					'default' => '.pdf,.txt,.md,.doc,.docx,.csv,.json',
				),
				'maxFiles'              => array(
					'type'    => 'number',
					'default' => 10,
				),
				'maxFileSizeMB'         => array(
					'type'    => 'number',
					'default' => 10,
				),
			),
			'supports'    => array(
				'align'   => array( 'wide', 'full' ),
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
	 * The third argument is nullable so admin pages can embed the same
	 * markup outside a block context without constructing a WP_Block.
	 *
	 * @param array         $attributes Block attributes.
	 * @param string        $content    Inner block content (unused).
	 * @param \WP_Block|null $block     Block instance (null in admin embeds).
	 * @return string Rendered block HTML.
	 */
	public static function render( array $attributes, string $content, ?\WP_Block $block = null ): string {
		unset( $content );

		if ( ! current_user_can( 'edit_posts' ) ) {
			return '<div class="nvoos-cg-builder__notice"><p>' . esc_html__( 'You do not have permission to use the Assistant Builder.', 'nvoos-content-graph-ai' ) . '</p></div>';
		}

		$show_selector   = ! isset( $attributes['showAssistantSelector'] ) || ! empty( $attributes['showAssistantSelector'] );
		$show_tools      = ! isset( $attributes['showToolsGrid'] ) || ! empty( $attributes['showToolsGrid'] );
		$show_kb         = ! isset( $attributes['showKnowledgeBase'] ) || ! empty( $attributes['showKnowledgeBase'] );
		$show_build      = ! isset( $attributes['showBuildButton'] ) || ! empty( $attributes['showBuildButton'] );
		$default_id      = isset( $attributes['defaultAssistantId'] ) ? absint( $attributes['defaultAssistantId'] ) : 0;
		$layout          = isset( $attributes['layout'] ) && 'side-by-side' === $attributes['layout'] ? 'side-by-side' : 'stacked';
		$tools_collapsed = ! isset( $attributes['toolsCollapsed'] ) || ! empty( $attributes['toolsCollapsed'] );
		$show_descs      = ! isset( $attributes['showToolDescriptions'] ) || ! empty( $attributes['showToolDescriptions'] );
		$allowed_types   = isset( $attributes['allowedFileTypes'] ) && '' !== $attributes['allowedFileTypes']
			? sanitize_text_field( (string) $attributes['allowedFileTypes'] )
			: '.pdf,.txt,.md,.doc,.docx,.csv,.json';
		$max_files       = isset( $attributes['maxFiles'] ) ? max( 1, absint( $attributes['maxFiles'] ) ) : 10;
		$max_size_mb     = isset( $attributes['maxFileSizeMB'] ) ? max( 1, absint( $attributes['maxFileSizeMB'] ) ) : 10;

		$unique_id = wp_unique_id( 'nvoos-cg-builder-' );

		$wrapper_classes = array(
			'wp-block-nvoos-content-graph-ai-assistant-builder',
			'nvoos-cg-builder',
			'nvoos-cg-builder--' . sanitize_html_class( $layout ),
		);

		$wrapper_attributes = self::wrapper_attributes( $block, $unique_id, $wrapper_classes );

		Blocks::enqueue_assistant_assets();

		$html = '<div ' . $wrapper_attributes . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Sanitised by get_block_wrapper_attributes() or the esc_attr() fallback.

		if ( $show_selector ) {
			$html .= '<div class="nvoos-cg-builder__selector">';
			$html .= AssistantSelectorBlock::render(
				array(
					'defaultAssistantId' => $default_id,
					'showStartButton'    => false,
				),
				'',
				$block
			);
			$html .= '</div>';
		}

		if ( $show_tools ) {
			$html .= '<div class="nvoos-cg-builder__tools" style="display: none;">';
			$html .= ToolsGridBlock::render(
				array(
					'showDescriptions' => $show_descs,
					'startCollapsed'   => $tools_collapsed,
					'showActions'      => true,
				),
				'',
				$block
			);
			$html .= '</div>';
		}

		if ( $show_kb ) {
			$html .= '<div class="nvoos-cg-builder__knowledge-base" style="display: none;">';
			$html .= KnowledgeBaseBlock::render(
				array(
					'allowedTypes'  => $allowed_types,
					'maxFiles'      => $max_files,
					'maxFileSizeMB' => $max_size_mb,
					'showPreview'   => true,
				),
				'',
				$block
			);
			$html .= '</div>';
		}

		if ( $show_build ) {
			$html .= '<div class="nvoos-cg-builder__build" style="display: none;">';
			$html .= '<label for="' . esc_attr( $unique_id ) . '-title">' . esc_html__( 'Assistant title', 'nvoos-content-graph-ai' ) . '</label>';
			$html .= '<input type="text" id="' . esc_attr( $unique_id ) . '-title" class="nvoos-cg-builder__title" placeholder="' . esc_attr__( 'e.g. Support Assistant', 'nvoos-content-graph-ai' ) . '">';
			$html .= '<button type="button" class="button button-primary nvoos-cg-builder__build-btn">' . esc_html__( 'Build Assistant', 'nvoos-content-graph-ai' ) . '</button>';
			$html .= '<span class="nvoos-cg-builder__error" style="display: none;"></span>';
			$html .= '</div>';
		}

		$html .= '<div class="nvoos-cg-builder__chat" style="display: none;">';
		$html .= do_shortcode( '[nvoos_content_graph_chat]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Widget output escapes every value internally.
		$html .= '</div>';

		$html .= '<script type="application/json" class="nvoos-cg-builder-config">' . wp_json_encode( self::config( $unique_id, $show_selector, $show_tools, $show_kb, $show_build ) ) . '</script>';

		$html .= '</div>';

		return $html;
	}

	/**
	 * Frontend configuration for the block instance.
	 *
	 * The create action seam follows the base plugin's assistant-creation
	 * flow: monolith installs reuse the base's admin-ajax action; the
	 * standalone install uses the ported `nvoos_cg_ai_create_assistant`
	 * action owned by the Build Assistant page.
	 *
	 * @param string $unique_id     Unique instance ID.
	 * @param bool   $show_selector Whether the selector section rendered.
	 * @param bool   $show_tools    Whether the tools section rendered.
	 * @param bool   $show_kb       Whether the knowledge base rendered.
	 * @param bool   $show_build    Whether the build section rendered.
	 * @return array<string,mixed>
	 */
	protected static function config( string $unique_id, bool $show_selector, bool $show_tools, bool $show_kb, bool $show_build ): array {
		$monolith = defined( 'WP_MCP_AI_PATH' );

		return array(
			'blockId'      => $unique_id,
			'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
			'createAction' => $monolith ? 'wp_mcp_ai_create_assistant' : 'nvoos_cg_ai_create_assistant',
			'createNonce'  => wp_create_nonce( $monolith ? 'wp_mcp_ai_create_assistant' : 'nvoos_cg_ai_create_assistant' ),
			'redirectUrl'  => admin_url( 'edit.php?post_type=' . self::POST_TYPE ),
			'sections'     => array(
				'selector' => $show_selector,
				'tools'    => $show_tools,
				'kb'       => $show_kb,
				'build'    => $show_build,
			),
		);
	}

	/**
	 * Wrapper attributes, block-context aware.
	 *
	 * @param \WP_Block|null $block    Block instance or null.
	 * @param string         $unique_id Unique instance ID.
	 * @param array          $classes  Wrapper classes.
	 * @return string Sanitised attribute string.
	 */
	protected static function wrapper_attributes( ?\WP_Block $block, string $unique_id, array $classes ): string {
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
