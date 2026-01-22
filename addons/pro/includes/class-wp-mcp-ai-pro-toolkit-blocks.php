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

		// Register all blocks by scanning the blocks directory.
		$block_dirs = array(
			'ecommerce-products',
			'ecommerce-search',
			'ecommerce-orders',
			'social-calendar',
			'social-templates',
			'calendar-booking',
			'calendar-services',
			'calendar-staff',
			'dj-equipment',
			'dj-packages',
			'financial-budget',
			'financial-goals',
			'multilingual-translation-memory',
			'multilingual-glossaries',
			'ai-tool-builder-templates',
			'ai-tool-builder-schemas',
			'media-templates',
			'media-collections',
		);

		foreach ( $block_dirs as $block_dir ) {
			$block_path = $blocks_dir . $block_dir;
			if ( file_exists( $block_path . '/block.json' ) ) {
				register_block_type( $block_path );
			}
		}
	}
}
