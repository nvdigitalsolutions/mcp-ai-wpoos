<?php
/**
 * Transformers.js Script Enqueue Manager
 *
 * Conditionally loads Transformers.js scripts for browser-native AI tasks.
 * Uses CDN for heavy dependencies, bundles only thin wrappers.
 *
 * Phase 2: Transformers.js Integration
 * - Summarization without server round-trip
 * - Sentiment analysis in browser
 * - Named Entity Recognition (NER)
 * - Translation capabilities
 * - Question answering
 * - Semantic search with embeddings
 *
 * @package WP_MCP_AI
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_MCP_AI_Transformers_Enqueue
 *
 * Manages conditional script loading for Transformers.js features.
 * Feature flag controls when scripts are loaded to minimize bundle size.
 */
class WP_MCP_AI_Transformers_Enqueue {

	/**
	 * Feature flag option name
	 */
	const OPTION_ENABLE_TRANSFORMERS = 'wp_mcp_ai_enable_transformers_tasks';

	/**
	 * Initialize the enqueue manager
	 */
	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_scripts' ), 5 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'maybe_enqueue_scripts' ), 20 );
		add_filter( 'wp_mcp_ai_settings_tabs', array( __CLASS__, 'add_settings_tab' ), 10 );
		add_action( 'wp_mcp_ai_settings_transformers', array( __CLASS__, 'render_settings' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
	}

	/**
	 * Register scripts (don't enqueue yet - wait until needed)
	 * Registered early so other code can enqueue them if needed
	 */
	public static function register_scripts() {
		$plugin_version = defined( 'WP_MCP_AI_VERSION' ) ? WP_MCP_AI_VERSION : '1.2.0';
		$plugin_file    = defined( 'WP_MCP_AI_FILE' ) ? WP_MCP_AI_FILE : dirname( __DIR__ ) . '/mcp-ai-wpoos.php';

		// NEW: Transformers tasks client (Phase 2 - thin wrapper, loads from CDN).
		wp_register_script(
			'wp-mcp-ai-transformers-tasks',
			plugins_url( 'assets/js/transformers-tasks-client.min.js', $plugin_file ),
			array(),
			$plugin_version,
			true
		);
	}

	/**
	 * Enqueue scripts only when transformers feature is enabled and on appropriate pages
	 */
	public static function maybe_enqueue_scripts() {
		// Only load if transformers feature is enabled.
		$transformers_enabled = get_option( self::OPTION_ENABLE_TRANSFORMERS, false );
		if ( ! $transformers_enabled ) {
			return;
		}

		// Only load on pages with chat interface.
		// The shortcode and Elementor widget already enqueue base scripts,
		// so we just add the transformers features here.
		if ( ! self::is_chat_page() ) {
			return;
		}

		// Enqueue transformers tasks client.
		wp_enqueue_script( 'wp-mcp-ai-transformers-tasks' );

		// Log for debugging.
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[NV oOS Transformers] Browser-native AI tasks scripts enqueued' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug-only logging, guarded by WP_DEBUG.
		}
	}

	/**
	 * Check if current page has chat interface
	 *
	 * @return bool
	 */
	private static function is_chat_page() {
		global $post;

		if ( ! $post ) {
			return false;
		}

		// Check for shortcode in post content.
		if ( has_shortcode( $post->post_content, 'mcp_ai_chat' ) ) {
			return true;
		}

		// Check for Elementor widget.
		if ( self::has_elementor_chat_widget() ) {
			return true;
		}

		return false;
	}

	/**
	 * Check if page has Elementor chat widget
	 *
	 * @return bool
	 */
	private static function has_elementor_chat_widget() {
		if ( ! did_action( 'elementor/loaded' ) ) {
			return false;
		}

		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return false;
		}

		$document = \Elementor\Plugin::$instance->documents->get( get_the_ID() );
		if ( ! $document ) {
			return false;
		}

		// Check if document contains our chat widget.
		$data = $document->get_elements_data();
		$json = wp_json_encode( $data );

		return strpos( $json, 'mcp-ai-chat' ) !== false;
	}

	/**
	 * Check if transformers feature is enabled
	 *
	 * @return bool
	 */
	public static function is_transformers_enabled() {
		return (bool) get_option( self::OPTION_ENABLE_TRANSFORMERS, false );
	}

	/**
	 * Add settings tab for Transformers.js
	 *
	 * @param array $tabs Existing tabs.
	 * @return array Modified tabs.
	 */
	public static function add_settings_tab( $tabs ) {
		$tabs['transformers'] = __( 'Browser AI Tasks', 'mcp-ai-wpoos' );
		return $tabs;
	}

	/**
	 * Register settings
	 */
	public static function register_settings() {
		register_setting(
			'wp_mcp_ai_settings',
			self::OPTION_ENABLE_TRANSFORMERS,
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => false,
			)
		);
	}

	/**
	 * Render settings page
	 */
	public static function render_settings() {
		$transformers_enabled = get_option( self::OPTION_ENABLE_TRANSFORMERS, false );
		?>
		<div class="wrap">
			<h2><?php esc_html_e( 'Browser-Native AI Tasks (Transformers.js)', 'mcp-ai-wpoos' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Enable instant AI tasks in the browser without server round-trips. Includes summarization, sentiment analysis, entity extraction, translation, Q&A, and semantic search.', 'mcp-ai-wpoos' ); ?>
			</p>

			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row">
							<label for="wp_mcp_ai_enable_transformers_tasks">
								<?php esc_html_e( 'Enable Browser AI Tasks', 'mcp-ai-wpoos' ); ?>
							</label>
						</th>
						<td>
							<label for="wp_mcp_ai_enable_transformers_tasks">
								<input 
									type="checkbox" 
									id="wp_mcp_ai_enable_transformers_tasks" 
									name="wp_mcp_ai_enable_transformers_tasks" 
									value="1" 
									<?php checked( $transformers_enabled, true ); ?>
								/>
								<?php esc_html_e( 'Enable Transformers.js browser-native AI tasks', 'mcp-ai-wpoos' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'When enabled, users can perform instant AI tasks in their browser without server processing. Models are downloaded from HuggingFace CDN and cached locally.', 'mcp-ai-wpoos' ); ?>
							</p>
						</td>
					</tr>
				</tbody>
			</table>

			<h3><?php esc_html_e( 'Available Browser-Native Tasks', 'mcp-ai-wpoos' ); ?></h3>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Task', 'mcp-ai-wpoos' ); ?></th>
						<th><?php esc_html_e( 'Description', 'mcp-ai-wpoos' ); ?></th>
						<th><?php esc_html_e( 'Model Size', 'mcp-ai-wpoos' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<tr>
						<td><strong><?php esc_html_e( 'Summarization', 'mcp-ai-wpoos' ); ?></strong></td>
						<td><?php esc_html_e( 'Generate concise summaries of long text', 'mcp-ai-wpoos' ); ?></td>
						<td><?php esc_html_e( '~120MB', 'mcp-ai-wpoos' ); ?></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Sentiment Analysis', 'mcp-ai-wpoos' ); ?></strong></td>
						<td><?php esc_html_e( 'Detect positive/negative sentiment in text', 'mcp-ai-wpoos' ); ?></td>
						<td><?php esc_html_e( '~80MB', 'mcp-ai-wpoos' ); ?></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Entity Extraction', 'mcp-ai-wpoos' ); ?></strong></td>
						<td><?php esc_html_e( 'Extract named entities (people, places, organizations)', 'mcp-ai-wpoos' ); ?></td>
						<td><?php esc_html_e( '~110MB', 'mcp-ai-wpoos' ); ?></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Translation', 'mcp-ai-wpoos' ); ?></strong></td>
						<td><?php esc_html_e( 'Translate text between 200+ languages', 'mcp-ai-wpoos' ); ?></td>
						<td><?php esc_html_e( '~300MB', 'mcp-ai-wpoos' ); ?></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Question Answering', 'mcp-ai-wpoos' ); ?></strong></td>
						<td><?php esc_html_e( 'Extract answers from context documents', 'mcp-ai-wpoos' ); ?></td>
						<td><?php esc_html_e( '~80MB', 'mcp-ai-wpoos' ); ?></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Semantic Search', 'mcp-ai-wpoos' ); ?></strong></td>
						<td><?php esc_html_e( 'Generate embeddings for vector search', 'mcp-ai-wpoos' ); ?></td>
						<td><?php esc_html_e( '~22MB', 'mcp-ai-wpoos' ); ?></td>
					</tr>
				</tbody>
			</table>

			<div class="notice notice-info inline" style="margin-top: 20px;">
				<p>
					<strong><?php esc_html_e( 'Note:', 'mcp-ai-wpoos' ); ?></strong>
					<?php esc_html_e( 'Models are downloaded from HuggingFace CDN on first use and cached in the browser. Initial load may take 10-30 seconds depending on network speed.', 'mcp-ai-wpoos' ); ?>
				</p>
			</div>

			<div class="notice notice-warning inline" style="margin-top: 20px;">
				<p>
					<strong><?php esc_html_e( 'Browser Requirements:', 'mcp-ai-wpoos' ); ?></strong>
					<?php esc_html_e( 'Modern browsers with Web Workers support (Chrome 80+, Firefox 80+, Safari 14+, Edge 80+)', 'mcp-ai-wpoos' ); ?>
				</p>
			</div>
		</div>
		<?php
	}
}

// Initialize.
WP_MCP_AI_Transformers_Enqueue::init();
