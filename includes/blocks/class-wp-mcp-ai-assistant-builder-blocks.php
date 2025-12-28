<?php
/**
 * Gutenberg blocks for WP oOS Assistant Builder.
 *
 * Registers chat and assistant builder blocks for use in the block editor.
 * These are dynamic blocks that use PHP render callbacks since they need
 * to fetch real-time data (assistants, tools) from the WordPress backend.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles Gutenberg block registration for assistant builder functionality.
 */
class WP_MCP_AI_Assistant_Builder_Blocks {

	/**
	 * Block script handle.
	 */
	const SCRIPT_HANDLE = 'wp-mcp-ai-assistant-builder-blocks';

	/**
	 * Block style handle.
	 */
	const STYLE_HANDLE = 'wp-mcp-ai-assistant-builder-blocks-style';

	/**
	 * Whether blocks have been initialized.
	 *
	 * @var bool
	 */
	private static $initialized = false;

	/**
	 * Initialize the blocks integration.
	 *
	 * Safe to call multiple times - will only initialize once.
	 */
	public static function init() {
		if ( self::$initialized ) {
			return;
		}

		self::$initialized = true;

		add_action( 'init', array( __CLASS__, 'register_blocks' ), 20 );
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'enqueue_block_editor_assets' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_frontend_assets' ) );
	}

	/**
	 * Register assistant builder blocks.
	 */
	public static function register_blocks() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		$blocks_dir = WP_MCP_AI_PATH . 'includes/blocks/';

		// Register blocks with block.json if they exist.
		$block_types = array(
			'chat',
			'professional-selector',
			'assistant-selector',
			'tools-grid',
			'knowledge-base',
			'assistant-builder',
		);

		foreach ( $block_types as $block_type ) {
			$block_path = $blocks_dir . $block_type;
			if ( file_exists( $block_path . '/block.json' ) ) {
				register_block_type( $block_path );
			}
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
	public static function enqueue_block_editor_assets() {
		// Register block editor script.
		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			WP_MCP_AI_URL . 'assets/js/blocks/assistant-builder-blocks.js',
			array( 'wp-blocks', 'wp-element', 'wp-editor', 'wp-components', 'wp-i18n', 'wp-block-editor' ),
			WP_MCP_AI_VERSION,
			true
		);

		// Get allowed file types.
		$allowed_types = self::get_allowed_knowledge_base_types();

		// Localize script with data for the editor.
		wp_localize_script(
			self::SCRIPT_HANDLE,
			'wpMcpAiBlocks',
			array(
				'assistants'       => self::get_assistants_for_editor(),
				'toolGroups'       => self::get_tool_groups_for_editor(),
				'restUrl'          => trailingslashit( rest_url( 'mcp-ai/v1' ) ),
				'wpRestUrl'        => rest_url( 'wp/v2' ),
				'nonce'            => wp_create_nonce( 'wp_rest' ),
				'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
				'createNonce'      => wp_create_nonce( 'wp_mcp_ai_create_assistant' ),
				'maxUploadSize'    => wp_max_upload_size(),
				'allowedFileTypes' => $allowed_types,
				'i18n'             => array(
					'selectAssistant'   => __( '— Select an assistant —', 'wp-mcp-ai' ),
					'noAssistants'      => __( 'No assistants found.', 'wp-mcp-ai' ),
					'startChat'         => __( 'Start Chat', 'wp-mcp-ai' ),
					'selectAll'         => __( 'Select All', 'wp-mcp-ai' ),
					'deselectAll'       => __( 'Deselect All', 'wp-mcp-ai' ),
					'toolsSelected'     => __( 'tools selected', 'wp-mcp-ai' ),
					'availableTools'    => __( 'Available Tools', 'wp-mcp-ai' ),
					'buildAssistant'    => __( 'Build', 'wp-mcp-ai' ),
					'chatPlaceholder'   => __( 'Describe the assistant you want to create...', 'wp-mcp-ai' ),
					'noPermission'      => __( 'You do not have permission to use this feature.', 'wp-mcp-ai' ),
					'assistantSelector' => __( 'Select an Assistant:', 'wp-mcp-ai' ),
					'knowledgeBase'     => __( 'Knowledge Base', 'wp-mcp-ai' ),
					'uploadFiles'       => __( 'Upload Files', 'wp-mcp-ai' ),
					'dropFilesHere'     => __( 'Drop files here or click to upload', 'wp-mcp-ai' ),
					'filesUploaded'     => __( 'files uploaded', 'wp-mcp-ai' ),
					'removeFile'        => __( 'Remove', 'wp-mcp-ai' ),
					'uploading'         => __( 'Uploading...', 'wp-mcp-ai' ),
					'uploadError'       => __( 'Upload failed', 'wp-mcp-ai' ),
					'maxFilesReached'   => __( 'Maximum number of files reached', 'wp-mcp-ai' ),
					'fileTooLarge'      => __( 'File is too large', 'wp-mcp-ai' ),
					'invalidFileType'   => __( 'Invalid file type', 'wp-mcp-ai' ),
				),
			)
		);

		// Register block editor styles.
		wp_enqueue_style(
			self::STYLE_HANDLE,
			WP_MCP_AI_URL . 'assets/css/blocks/assistant-builder-blocks.css',
			array(),
			WP_MCP_AI_VERSION
		);
	}

	/**
	 * Enqueue frontend assets for blocks.
	 */
	public static function enqueue_frontend_assets() {
		// Only enqueue if blocks are present on the page.
		if ( ! has_block( 'wp-mcp-ai/assistant-builder' ) &&
			! has_block( 'wp-mcp-ai/knowledge-base' ) &&
			! has_block( 'wp-mcp-ai/tools-grid' ) &&
			! has_block( 'wp-mcp-ai/assistant-selector' ) &&
			! has_block( 'wp-mcp-ai/chat' ) ) {
			return;
		}

		// Enqueue frontend script.
		wp_enqueue_script(
			'wp-mcp-ai-assistant-builder-frontend',
			WP_MCP_AI_URL . 'assets/js/blocks/assistant-builder-blocks-frontend.js',
			array( 'jquery' ),
			WP_MCP_AI_VERSION,
			true
		);

		// Enqueue frontend styles.
		wp_enqueue_style(
			'wp-mcp-ai-assistant-builder-frontend',
			WP_MCP_AI_URL . 'assets/css/blocks/assistant-builder-blocks.css',
			array(),
			WP_MCP_AI_VERSION
		);
	}

	/**
	 * Get allowed file types for knowledge base uploads.
	 *
	 * @return array
	 */
	private static function get_allowed_knowledge_base_types() {
		return array(
			'pdf'  => 'application/pdf',
			'txt'  => 'text/plain',
			'md'   => 'text/markdown',
			'doc'  => 'application/msword',
			'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			'csv'  => 'text/csv',
			'json' => 'application/json',
			'xml'  => 'application/xml',
			'html' => 'text/html',
			'rtf'  => 'application/rtf',
		);
	}

	/**
	 * Get assistants data for the block editor.
	 *
	 * @return array
	 */
	private static function get_assistants_for_editor() {
		$assistants = array();

		if ( ! class_exists( 'WP_MCP_AI_Assistant_CPT' ) ) {
			return $assistants;
		}

		$posts = get_posts(
			array(
				'post_type'      => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		foreach ( $posts as $post ) {
			$tools = get_post_meta( $post->ID, WP_MCP_AI_Assistant_CPT::META_TOOLS, true );
			if ( ! is_array( $tools ) ) {
				$tools = array();
			}

			$shortcuts = array();
			if ( class_exists( 'WP_MCP_AI_Shortcode' ) && method_exists( 'WP_MCP_AI_Shortcode', 'get_assistant_tool_shortcuts' ) ) {
				$shortcuts = WP_MCP_AI_Shortcode::get_assistant_tool_shortcuts( $post->ID );
			}

			$assistants[] = array(
				'id'        => $post->ID,
				'title'     => $post->post_title,
				'tools'     => $tools,
				'shortcuts' => $shortcuts,
			);
		}

		return $assistants;
	}

	/**
	 * Get tool groups data for the block editor.
	 *
	 * @return array
	 */
	private static function get_tool_groups_for_editor() {
		$groups = array();

		if ( ! class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
			return $groups;
		}

		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tools    = $registry->get_tools();

		$group_map    = array();
		$group_labels = array();

		if ( method_exists( $registry, 'get_tool_group_map' ) ) {
			$group_map = $registry->get_tool_group_map();
		}
		if ( method_exists( $registry, 'get_tool_group_labels' ) ) {
			$group_labels = $registry->get_tool_group_labels();
		}

		if ( ! is_array( $group_map ) ) {
			$group_map = array();
		}
		if ( ! is_array( $group_labels ) ) {
			$group_labels = array();
		}
		if ( ! isset( $group_labels['other'] ) ) {
			$group_labels['other'] = __( 'Other tools', 'wp-mcp-ai' );
		}

		$grouped = array();

		foreach ( $tools as $tool ) {
			if ( ! $tool instanceof WP_MCP_AI_Tool_Interface ) {
				continue;
			}

			$slug = $tool->get_slug();
			if ( '' === $slug ) {
				continue;
			}

			$group_id = isset( $group_map[ $slug ] ) ? (string) $group_map[ $slug ] : 'other';
			if ( '' === $group_id ) {
				$group_id = 'other';
			}

			if ( ! isset( $grouped[ $group_id ] ) ) {
				$grouped[ $group_id ] = array(
					'id'    => $group_id,
					'label' => isset( $group_labels[ $group_id ] ) ? $group_labels[ $group_id ] : ucfirst( $group_id ),
					'tools' => array(),
				);
			}

			$grouped[ $group_id ]['tools'][] = array(
				'slug'        => $slug,
				'name'        => $tool->get_name() ?: $slug,
				'description' => $tool->get_description() ?: '',
			);
		}

		// Order by group labels.
		foreach ( $group_labels as $group_id => $label ) {
			if ( isset( $grouped[ $group_id ] ) ) {
				$groups[] = $grouped[ $group_id ];
				unset( $grouped[ $group_id ] );
			}
		}
		foreach ( $grouped as $group ) {
			$groups[] = $group;
		}

		return $groups;
	}
}
