<?php
/**
 * WP MCP AI Blocks Registration
 *
 * Registers Gutenberg blocks following WordPress Block Editor Handbook standards.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_MCP_AI_Blocks
 *
 * Handles registration and management of all WP MCP AI Gutenberg blocks.
 */
class WP_MCP_AI_Blocks {

	/**
	 * Block namespace.
	 */
	const NAMESPACE = 'wp-mcp-ai';

	/**
	 * Initialize the blocks.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_blocks' ) );
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'enqueue_editor_assets' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_frontend_assets' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );
	}

	/**
	 * Register all blocks.
	 */
	public static function register_blocks() {
		// Define blocks directory.
		$blocks_dir = WP_MCP_AI_PATH . 'includes/blocks/';

		// List of blocks to register.
		$blocks = array(
			'assistant-builder',
			'chat',
			'assistant-selector',
			'tools-grid',
			'knowledge-base',
		);

		foreach ( $blocks as $block ) {
			$block_dir = $blocks_dir . $block;

			// Check if block.json exists.
			if ( ! file_exists( $block_dir . '/block.json' ) ) {
				continue;
			}

			// Register the block.
			register_block_type( $block_dir );
		}

		// Register block category.
		add_filter( 'block_categories_all', array( __CLASS__, 'register_block_category' ), 10, 2 );
	}

	/**
	 * Register custom block category.
	 *
	 * @param array                   $categories Block categories.
	 * @param WP_Block_Editor_Context $context    Block editor context.
	 * @return array Modified categories.
	 */
	public static function register_block_category( $categories, $context ) {
		return array_merge(
			array(
				array(
					'slug'  => 'wp-mcp-ai',
					'title' => __( 'WP oOS - AI Assistant', 'wp-mcp-ai' ),
					'icon'  => 'admin-generic',
				),
			),
			$categories
		);
	}

	/**
	 * Enqueue block editor assets.
	 */
	public static function enqueue_editor_assets() {
		// Editor script for all blocks.
		wp_enqueue_script(
			'wp-mcp-ai-blocks-editor',
			WP_MCP_AI_URL . 'assets/js/blocks/blocks-editor.js',
			array( 'wp-blocks', 'wp-element', 'wp-editor', 'wp-components', 'wp-i18n', 'wp-block-editor', 'wp-server-side-render' ),
			WP_MCP_AI_VERSION,
			true
		);

		// Localize with data for the editor.
		wp_localize_script(
			'wp-mcp-ai-blocks-editor',
			'wpMcpAiBlocks',
			array(
				'assistants' => self::get_assistants_for_editor(),
				'toolGroups' => wp_mcp_ai_get_tool_groups_for_blocks(),
				'restUrl'    => rest_url( 'mcp-ai/v1' ),
				'wpRestUrl'  => rest_url( 'wp/v2' ),
				'nonce'      => wp_create_nonce( 'wp_rest' ),
				'i18n'       => array(
					'selectAssistant' => __( '— Select an assistant —', 'wp-mcp-ai' ),
					'noAssistants'    => __( 'No assistants found.', 'wp-mcp-ai' ),
				),
			)
		);

		// Editor styles.
		wp_enqueue_style(
			'wp-mcp-ai-blocks-editor',
			WP_MCP_AI_URL . 'assets/css/blocks/blocks-editor.css',
			array(),
			WP_MCP_AI_VERSION
		);
	}

	/**
	 * Enqueue frontend assets for blocks.
	 */
	public static function enqueue_frontend_assets() {
		// Check if any of our blocks are present.
		if ( ! self::has_blocks_on_page() ) {
			return;
		}

		// Frontend script.
		wp_enqueue_script(
			'wp-mcp-ai-blocks-frontend',
			WP_MCP_AI_URL . 'assets/js/blocks/assistant-builder-blocks-frontend.js',
			array( 'jquery' ),
			WP_MCP_AI_VERSION,
			true
		);

		// Frontend styles.
		wp_enqueue_style(
			'wp-mcp-ai-blocks-frontend',
			WP_MCP_AI_URL . 'assets/css/blocks/assistant-builder-blocks.css',
			array(),
			WP_MCP_AI_VERSION
		);
	}

	/**
	 * Enqueue admin assets for blocks (for admin pages using blocks).
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 */
	public static function enqueue_admin_assets( $hook_suffix ) {
		// Only enqueue on relevant admin pages.
		if ( strpos( $hook_suffix, 'wp-mcp-ai' ) === false && strpos( $hook_suffix, 'mcp_ai_assistant' ) === false ) {
			return;
		}

		// Frontend script for admin pages.
		wp_enqueue_script(
			'wp-mcp-ai-blocks-frontend',
			WP_MCP_AI_URL . 'assets/js/blocks/assistant-builder-blocks-frontend.js',
			array( 'jquery' ),
			WP_MCP_AI_VERSION,
			true
		);

		// Frontend styles for admin pages.
		wp_enqueue_style(
			'wp-mcp-ai-blocks-frontend',
			WP_MCP_AI_URL . 'assets/css/blocks/assistant-builder-blocks.css',
			array(),
			WP_MCP_AI_VERSION
		);
	}

	/**
	 * Check if any of our blocks are present on the page.
	 *
	 * @return bool
	 */
	private static function has_blocks_on_page() {
		$blocks = array(
			'wp-mcp-ai/assistant-builder',
			'wp-mcp-ai/chat',
			'wp-mcp-ai/assistant-selector',
			'wp-mcp-ai/tools-grid',
			'wp-mcp-ai/knowledge-base',
		);

		foreach ( $blocks as $block ) {
			if ( has_block( $block ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get assistants data for the editor.
	 *
	 * @return array
	 */
	private static function get_assistants_for_editor() {
		if ( function_exists( 'wp_mcp_ai_get_assistants_for_blocks' ) ) {
			return wp_mcp_ai_get_assistants_for_blocks();
		}

		return array();
	}
}

// Initialize blocks.
WP_MCP_AI_Blocks::init();
