<?php
/**
 * Gutenberg blocks integration for WP oOS Dashboard widgets.
 *
 * Registers dashboard-related blocks for use in the block editor.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles Gutenberg block registration for dashboard widgets.
 */
class WP_MCP_AI_Dashboard_Blocks {

	/**
	 * Initialize the blocks integration.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_blocks' ) );
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'enqueue_block_editor_assets' ) );
	}

	/**
	 * Register dashboard blocks.
	 */
	public static function register_blocks() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		// Dashboard Tool Matrix Block.
		register_block_type(
			'wp-mcp-ai/dashboard-tool-matrix',
			array(
				'render_callback' => array( __CLASS__, 'render_tool_matrix_block' ),
				'attributes'      => array(
					'title' => array(
						'type'    => 'string',
						'default' => __( 'Tool Matrix', 'wp-mcp-ai' ),
					),
				),
			)
		);

		// Dashboard User Capability Block.
		register_block_type(
			'wp-mcp-ai/dashboard-user-capability',
			array(
				'render_callback' => array( __CLASS__, 'render_user_capability_block' ),
				'attributes'      => array(
					'title' => array(
						'type'    => 'string',
						'default' => __( 'User Capabilities', 'wp-mcp-ai' ),
					),
				),
			)
		);

		// Dashboard User Files Block.
		register_block_type(
			'wp-mcp-ai/dashboard-user-files',
			array(
				'render_callback' => array( __CLASS__, 'render_user_files_block' ),
				'attributes'      => array(
					'title' => array(
						'type'    => 'string',
						'default' => __( 'User Files', 'wp-mcp-ai' ),
					),
					'limit' => array(
						'type'    => 'number',
						'default' => 10,
					),
				),
			)
		);

		// Dashboard User Chats Block.
		register_block_type(
			'wp-mcp-ai/dashboard-user-chats',
			array(
				'render_callback' => array( __CLASS__, 'render_user_chats_block' ),
				'attributes'      => array(
					'title' => array(
						'type'    => 'string',
						'default' => __( 'Recent Chats', 'wp-mcp-ai' ),
					),
					'limit' => array(
						'type'    => 'number',
						'default' => 5,
					),
				),
			)
		);

		// Dashboard Theme Preview Block.
		register_block_type(
			'wp-mcp-ai/dashboard-theme-preview',
			array(
				'render_callback' => array( __CLASS__, 'render_theme_preview_block' ),
				'attributes'      => array(
					'title' => array(
						'type'    => 'string',
						'default' => __( 'Theme Preview', 'wp-mcp-ai' ),
					),
				),
			)
		);

		// Dashboard Provider Links Block.
		register_block_type(
			'wp-mcp-ai/dashboard-provider-links',
			array(
				'render_callback' => array( __CLASS__, 'render_provider_links_block' ),
				'attributes'      => array(
					'title' => array(
						'type'    => 'string',
						'default' => __( 'AI Provider Links', 'wp-mcp-ai' ),
					),
				),
			)
		);

		// Dashboard Activity Feed Block.
		register_block_type(
			'wp-mcp-ai/dashboard-activity-feed',
			array(
				'render_callback' => array( __CLASS__, 'render_activity_feed_block' ),
				'attributes'      => array(
					'title' => array(
						'type'    => 'string',
						'default' => __( 'Recent Activity', 'wp-mcp-ai' ),
					),
					'limit' => array(
						'type'    => 'number',
						'default' => 10,
					),
				),
			)
		);
	}

	/**
	 * Enqueue block editor assets.
	 */
	public static function enqueue_block_editor_assets() {
		$asset_file = WP_MCP_AI_PATH . 'assets/js/dashboard-blocks.asset.php';
		$asset_data = file_exists( $asset_file ) ? include $asset_file : array( 'dependencies' => array( 'wp-blocks', 'wp-element', 'wp-editor', 'wp-components' ), 'version' => WP_MCP_AI_VERSION );

		wp_enqueue_script(
			'wp-mcp-ai-dashboard-blocks',
			WP_MCP_AI_URL . 'assets/js/dashboard-blocks.js',
			$asset_data['dependencies'],
			$asset_data['version'],
			true
		);

		wp_localize_script(
			'wp-mcp-ai-dashboard-blocks',
			'wpMcpAiDashboardBlocks',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'wp_mcp_ai_dashboard' ),
			)
		);
	}

	/**
	 * Render the tool matrix block.
	 *
	 * @param array $attributes Block attributes.
	 * @return string Block HTML.
	 */
	public static function render_tool_matrix_block( $attributes ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return '<p>' . esc_html__( 'You do not have permission to view the tool matrix.', 'wp-mcp-ai' ) . '</p>';
		}

		$title = isset( $attributes['title'] ) ? $attributes['title'] : __( 'Tool Matrix', 'wp-mcp-ai' );

		ob_start();
		?>
		<div class="wp-block-wp-mcp-ai-dashboard-tool-matrix">
			<h3><?php echo esc_html( $title ); ?></h3>
			<p><?php esc_html_e( 'Tool matrix displayed here. Use Elementor widget for full interactive matrix.', 'wp-mcp-ai' ); ?></p>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render the user capability block.
	 *
	 * @param array $attributes Block attributes.
	 * @return string Block HTML.
	 */
	public static function render_user_capability_block( $attributes ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return '<p>' . esc_html__( 'You do not have permission to view user capabilities.', 'wp-mcp-ai' ) . '</p>';
		}

		$title = isset( $attributes['title'] ) ? $attributes['title'] : __( 'User Capabilities', 'wp-mcp-ai' );

		ob_start();
		?>
		<div class="wp-block-wp-mcp-ai-dashboard-user-capability">
			<h3><?php echo esc_html( $title ); ?></h3>
			<p><?php esc_html_e( 'User capability information displayed here. Use Elementor widget for detailed view.', 'wp-mcp-ai' ); ?></p>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render the user files block.
	 *
	 * @param array $attributes Block attributes.
	 * @return string Block HTML.
	 */
	public static function render_user_files_block( $attributes ) {
		if ( ! is_user_logged_in() ) {
			return '<p>' . esc_html__( 'Please log in to view your files.', 'wp-mcp-ai' ) . '</p>';
		}

		$title = isset( $attributes['title'] ) ? $attributes['title'] : __( 'User Files', 'wp-mcp-ai' );
		$limit = isset( $attributes['limit'] ) ? absint( $attributes['limit'] ) : 10;

		ob_start();
		?>
		<div class="wp-block-wp-mcp-ai-dashboard-user-files">
			<h3><?php echo esc_html( $title ); ?></h3>
			<p><?php printf( esc_html__( 'Displaying up to %d recent files. Use Elementor widget for full file management.', 'wp-mcp-ai' ), $limit ); ?></p>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render the user chats block.
	 *
	 * @param array $attributes Block attributes.
	 * @return string Block HTML.
	 */
	public static function render_user_chats_block( $attributes ) {
		if ( ! is_user_logged_in() ) {
			return '<p>' . esc_html__( 'Please log in to view your chats.', 'wp-mcp-ai' ) . '</p>';
		}

		$title = isset( $attributes['title'] ) ? $attributes['title'] : __( 'Recent Chats', 'wp-mcp-ai' );
		$limit = isset( $attributes['limit'] ) ? absint( $attributes['limit'] ) : 5;

		ob_start();
		?>
		<div class="wp-block-wp-mcp-ai-dashboard-user-chats">
			<h3><?php echo esc_html( $title ); ?></h3>
			<p><?php printf( esc_html__( 'Displaying up to %d recent chat transcripts. Use Elementor widget for full chat history.', 'wp-mcp-ai' ), $limit ); ?></p>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render the theme preview block.
	 *
	 * @param array $attributes Block attributes.
	 * @return string Block HTML.
	 */
	public static function render_theme_preview_block( $attributes ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return '<p>' . esc_html__( 'You do not have permission to view theme settings.', 'wp-mcp-ai' ) . '</p>';
		}

		$title = isset( $attributes['title'] ) ? $attributes['title'] : __( 'Theme Preview', 'wp-mcp-ai' );

		ob_start();
		?>
		<div class="wp-block-wp-mcp-ai-dashboard-theme-preview">
			<h3><?php echo esc_html( $title ); ?></h3>
			<p><?php esc_html_e( 'Theme color preview displayed here. Use Elementor widget for interactive preview.', 'wp-mcp-ai' ); ?></p>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render the provider links block.
	 *
	 * @param array $attributes Block attributes.
	 * @return string Block HTML.
	 */
	public static function render_provider_links_block( $attributes ) {
		$title = isset( $attributes['title'] ) ? $attributes['title'] : __( 'AI Provider Links', 'wp-mcp-ai' );

		ob_start();
		?>
		<div class="wp-block-wp-mcp-ai-dashboard-provider-links">
			<h3><?php echo esc_html( $title ); ?></h3>
			<ul class="wp-mcp-ai-provider-links">
				<li><a href="https://platform.openai.com/" target="_blank" rel="noopener"><?php esc_html_e( 'OpenAI Platform', 'wp-mcp-ai' ); ?></a></li>
				<li><a href="https://console.cloud.google.com/" target="_blank" rel="noopener"><?php esc_html_e( 'Google Cloud Console', 'wp-mcp-ai' ); ?></a></li>
				<li><a href="https://ollama.com/" target="_blank" rel="noopener"><?php esc_html_e( 'Ollama', 'wp-mcp-ai' ); ?></a></li>
			</ul>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render the activity feed block.
	 *
	 * @param array $attributes Block attributes.
	 * @return string Block HTML.
	 */
	public static function render_activity_feed_block( $attributes ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return '<p>' . esc_html__( 'You do not have permission to view activity feed.', 'wp-mcp-ai' ) . '</p>';
		}

		$title = isset( $attributes['title'] ) ? $attributes['title'] : __( 'Recent Activity', 'wp-mcp-ai' );
		$limit = isset( $attributes['limit'] ) ? absint( $attributes['limit'] ) : 10;

		ob_start();
		?>
		<div class="wp-block-wp-mcp-ai-dashboard-activity-feed">
			<h3><?php echo esc_html( $title ); ?></h3>
			<p><?php printf( esc_html__( 'Displaying up to %d recent activities. Use Elementor widget for full activity log.', 'wp-mcp-ai' ), $limit ); ?></p>
		</div>
		<?php
		return ob_get_clean();
	}
}

// Initialize the blocks.
WP_MCP_AI_Dashboard_Blocks::init();
