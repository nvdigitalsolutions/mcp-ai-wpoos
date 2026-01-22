<?php
/**
 * Toolkit Blocks Registration (PoC)
 *
 * Registers Gutenberg blocks for pro toolkits.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Toolkit Blocks Class
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Pro_Toolkit_Blocks {

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'register_block_category' ) );
		add_action( 'init', array( $this, 'register_blocks' ) );
	}

	/**
	 * Register toolkit blocks category.
	 *
	 * @since 1.0.0
	 */
	public function register_block_category() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		// Register block category.
		add_filter(
			'block_categories_all',
			function( $categories ) {
				return array_merge(
					$categories,
					array(
						array(
							'slug'  => 'mcp-ai-toolkits',
							'title' => __( 'MCP AI Toolkits', 'mcp-ai-wpoos-pro' ),
							'icon'  => 'admin-tools',
						),
					)
				);
			}
		);
	}

	/**
	 * Register toolkit blocks.
	 *
	 * @since 1.0.0
	 */
	public function register_blocks() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		// Get blocks directory path.
		$blocks_dir = dirname( __FILE__ ) . '/blocks/';

		// Register E-commerce Products block (PoC).
		if ( file_exists( $blocks_dir . 'ecommerce-products/block.json' ) ) {
			register_block_type( $blocks_dir . 'ecommerce-products' );
		}
	}
}
