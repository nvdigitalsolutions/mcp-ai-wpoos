<?php
/**
 * Block registration hub for the Content Graph AI addon.
 *
 * Registers the ecosystem's chat-family blocks (Wave D-UI-2) —
 * `nvoos-content-graph-ai/chat` and `nvoos-content-graph-ai/chat-bubble`
 * — with the block category, the bubble frontend assets (script + style,
 * shared with any future Elementor bubble widget), and the block editor
 * script. Block names and category are ecosystem-specific and never
 * collide with the base plugin's `mcp-ai-wpoos/*` blocks in monolith
 * installs.
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
 * Registers the chat-family blocks and their assets.
 *
 * @since 1.1.0
 */
class Blocks {

	/**
	 * Block category slug.
	 */
	const CATEGORY = 'nvoos-content-graph-ai';

	/**
	 * Bubble frontend script handle.
	 */
	const BUBBLE_SCRIPT_HANDLE = 'nvoos-content-graph-ai-chat-bubble';

	/**
	 * Bubble frontend style handle.
	 */
	const BUBBLE_STYLE_HANDLE = 'nvoos-content-graph-ai-chat-bubble-style';

	/**
	 * Block editor script handle.
	 */
	const EDITOR_SCRIPT_HANDLE = 'nvoos-content-graph-ai-blocks';

	/**
	 * Register the hub (hooked to `init` by `register()`).
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_blocks' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_editor_assets' ) );

		add_filter(
			'block_categories_all',
			static function ( array $categories ): array {
				foreach ( $categories as $category ) {
					if ( isset( $category['slug'] ) && self::CATEGORY === $category['slug'] ) {
						return $categories;
					}
				}

				$categories[] = array(
					'slug'  => self::CATEGORY,
					'title' => __( 'NV oOS Content Graph AI', 'nvoos-content-graph-ai' ),
					'icon'  => 'superhero',
				);

				return $categories;
			}
		);
	}

	/**
	 * Register the chat-family blocks and bubble assets.
	 *
	 * @return void
	 */
	public function register_blocks(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		$this->register_bubble_assets();

		$registry = \WP_Block_Type_Registry::get_instance();

		if ( ! $registry->is_registered( ChatBlock::BLOCK_NAME ) ) {
			register_block_type(
				ChatBlock::BLOCK_NAME,
				array_merge(
					ChatBlock::metadata(),
					array( 'render_callback' => array( ChatBlock::class, 'render' ) )
				)
			);
		}

		if ( ! $registry->is_registered( ChatBubbleBlock::BLOCK_NAME ) ) {
			register_block_type(
				ChatBubbleBlock::BLOCK_NAME,
				array_merge(
					ChatBubbleBlock::metadata(),
					array( 'render_callback' => array( ChatBubbleBlock::class, 'render' ) )
				)
			);
		}
	}

	/**
	 * Register the bubble frontend script + style.
	 *
	 * @return void
	 */
	protected function register_bubble_assets(): void {
		if ( ! wp_script_is( self::BUBBLE_SCRIPT_HANDLE, 'registered' ) ) {
			wp_register_script(
				self::BUBBLE_SCRIPT_HANDLE,
				NVOOS_CONTENT_GRAPH_AI_URL . 'assets/js/content-graph-ai-chat-bubble.js',
				array(),
				NVOOS_CONTENT_GRAPH_AI_VERSION,
				true
			);
		}

		if ( ! wp_style_is( self::BUBBLE_STYLE_HANDLE, 'registered' ) ) {
			wp_register_style(
				self::BUBBLE_STYLE_HANDLE,
				NVOOS_CONTENT_GRAPH_AI_URL . 'assets/css/content-graph-ai-chat-bubble.css',
				array(),
				NVOOS_CONTENT_GRAPH_AI_VERSION
			);
		}
	}

	/**
	 * Enqueue the block editor script.
	 *
	 * @return void
	 */
	public function enqueue_editor_assets(): void {
		wp_enqueue_script(
			self::EDITOR_SCRIPT_HANDLE,
			NVOOS_CONTENT_GRAPH_AI_URL . 'assets/js/blocks/content-graph-ai-blocks.js',
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n' ),
			NVOOS_CONTENT_GRAPH_AI_VERSION,
			true
		);
	}
}
