<?php
/**
 * Transformers.js Script Enqueue Manager
 *
 * Conditionally loads Transformers.js scripts only when the embedded provider is active
 * and Transformers.js features are enabled.
 *
 * Features:
 * - Lazy loading (only when needed)
 * - Feature flag support
 * - Browser-native AI tasks
 * - Semantic search integration
 *
 * @package WP_MCP_AI
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Transformers.js Enqueue Manager
 */
class WP_MCP_AI_Transformers_Enqueue {

	/**
	 * Instance of this class
	 *
	 * @var WP_MCP_AI_Transformers_Enqueue
	 */
	private static $instance = null;

	/**
	 * Get singleton instance
	 *
	 * @return WP_MCP_AI_Transformers_Enqueue
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor
	 */
	private function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'register_scripts' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_scripts' ), 20 );
		add_action( 'admin_enqueue_scripts', array( $this, 'register_scripts' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'maybe_enqueue_admin_scripts' ), 20 );
	}

	/**
	 * Register Transformers.js scripts
	 */
	public function register_scripts() {
		// Transformers Tasks Client
		wp_register_script(
			'wp-mcp-ai-transformers-tasks',
			plugins_url( 'assets/js/transformers-tasks-client.min.js', WP_MCP_AI_FILE ),
			array(),
			WP_MCP_AI_VERSION,
			true
		);

		// Client Vector Store
		wp_register_script(
			'wp-mcp-ai-client-vector-store',
			plugins_url( 'assets/js/client-vector-store.min.js', WP_MCP_AI_FILE ),
			array( 'wp-mcp-ai-transformers-tasks' ),
			WP_MCP_AI_VERSION,
			true
		);
	}

	/**
	 * Maybe enqueue scripts on frontend
	 */
	public function maybe_enqueue_scripts() {
		// Check if Transformers.js is enabled
		if ( ! $this->is_transformers_enabled() ) {
			return;
		}

		// Only load on pages with chat interface
		if ( ! $this->is_chat_page() ) {
			return;
		}

		// Enqueue scripts
		$this->enqueue_transformers_scripts();
	}

	/**
	 * Maybe enqueue scripts in admin
	 */
	public function maybe_enqueue_admin_scripts() {
		// Check if we're on a relevant admin page
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		// Only on specific admin pages
		$allowed_pages = array(
			'toplevel_page_wp-mcp-ai-settings',
			'nv-oos_page_wp-mcp-ai-test-page',
			'nv-oos_page_wp-mcp-ai-diagnostics',
		);

		if ( ! in_array( $screen->id, $allowed_pages, true ) ) {
			return;
		}

		// Check if Transformers.js is enabled
		if ( ! $this->is_transformers_enabled() ) {
			return;
		}

		// Enqueue scripts
		$this->enqueue_transformers_scripts();
	}

	/**
	 * Enqueue Transformers.js scripts
	 */
	private function enqueue_transformers_scripts() {
		// Enqueue tasks client
		wp_enqueue_script( 'wp-mcp-ai-transformers-tasks' );

		// Enqueue vector store if semantic search is enabled
		if ( $this->is_semantic_search_enabled() ) {
			wp_enqueue_script( 'wp-mcp-ai-client-vector-store' );
		}

		// Localize script with configuration
		wp_localize_script(
			'wp-mcp-ai-transformers-tasks',
			'wpMcpAiTransformers',
			array(
				'enabled'              => true,
				'autoInit'             => apply_filters( 'wp_mcp_ai_transformers_auto_init', true ),
				'autoInitVectorStore'  => $this->is_semantic_search_enabled(),
				'debug'                => defined( 'WP_MCP_AI_DEBUG' ) && WP_MCP_AI_DEBUG,
				'semanticSearchEnabled' => $this->is_semantic_search_enabled(),
				'features'             => array(
					'summarization'      => true,
					'sentiment'          => true,
					'ner'                => true,
					'embedding'          => $this->is_semantic_search_enabled(),
					'translation'        => $this->is_translation_enabled(),
					'questionAnswering'  => true,
					'zeroShot'           => true,
				),
			)
		);
	}

	/**
	 * Check if Transformers.js is enabled
	 *
	 * @return bool
	 */
	private function is_transformers_enabled() {
		// Check constant
		if ( defined( 'WP_MCP_AI_TRANSFORMERS_ENABLED' ) ) {
			return WP_MCP_AI_TRANSFORMERS_ENABLED;
		}

		// Check option
		return (bool) get_option( 'wp_mcp_ai_enable_transformers', false );
	}

	/**
	 * Check if semantic search is enabled
	 *
	 * @return bool
	 */
	private function is_semantic_search_enabled() {
		if ( ! $this->is_transformers_enabled() ) {
			return false;
		}

		// Check constant
		if ( defined( 'WP_MCP_AI_SEMANTIC_SEARCH_ENABLED' ) ) {
			return WP_MCP_AI_SEMANTIC_SEARCH_ENABLED;
		}

		// Check option
		return (bool) get_option( 'wp_mcp_ai_enable_semantic_search', true );
	}

	/**
	 * Check if translation is enabled
	 *
	 * @return bool
	 */
	private function is_translation_enabled() {
		if ( ! $this->is_transformers_enabled() ) {
			return false;
		}

		// Check constant
		if ( defined( 'WP_MCP_AI_TRANSLATION_ENABLED' ) ) {
			return WP_MCP_AI_TRANSLATION_ENABLED;
		}

		// Check option
		return (bool) get_option( 'wp_mcp_ai_enable_translation', true );
	}

	/**
	 * Check if current page has chat interface
	 *
	 * @return bool
	 */
	private function is_chat_page() {
		// Check for shortcode
		if ( has_shortcode( get_post_field( 'post_content', get_the_ID() ), 'mcp_ai_chat' ) ) {
			return true;
		}

		// Check for Elementor widget
		if ( $this->has_elementor_chat_widget() ) {
			return true;
		}

		// Allow filtering
		return apply_filters( 'wp_mcp_ai_is_chat_page', false );
	}

	/**
	 * Check if page has Elementor chat widget
	 *
	 * @return bool
	 */
	private function has_elementor_chat_widget() {
		// Check if Elementor is active
		if ( ! did_action( 'elementor/loaded' ) ) {
			return false;
		}

		// Get Elementor document
		$document = \Elementor\Plugin::$instance->documents->get( get_the_ID() );
		if ( ! $document ) {
			return false;
		}

		// Check if document has chat widget
		$elements_data = $document->get_elements_data();
		return strpos( wp_json_encode( $elements_data ), 'mcp-ai-chat' ) !== false;
	}

	/**
	 * Get available Transformers.js features
	 *
	 * @return array
	 */
	public static function get_available_features() {
		return array(
			'summarization'     => array(
				'name'        => __( 'Text Summarization', 'mcp-ai-wpoos' ),
				'description' => __( 'Summarize long text into concise summaries', 'mcp-ai-wpoos' ),
				'model_size'  => '~60MB',
			),
			'sentiment'         => array(
				'name'        => __( 'Sentiment Analysis', 'mcp-ai-wpoos' ),
				'description' => __( 'Analyze text sentiment (positive/negative)', 'mcp-ai-wpoos' ),
				'model_size'  => '~30MB',
			),
			'ner'               => array(
				'name'        => __( 'Named Entity Recognition', 'mcp-ai-wpoos' ),
				'description' => __( 'Extract people, places, organizations from text', 'mcp-ai-wpoos' ),
				'model_size'  => '~40MB',
			),
			'embedding'         => array(
				'name'        => __( 'Text Embeddings', 'mcp-ai-wpoos' ),
				'description' => __( 'Generate embeddings for semantic search', 'mcp-ai-wpoos' ),
				'model_size'  => '~23MB',
			),
			'translation'       => array(
				'name'        => __( 'Translation', 'mcp-ai-wpoos' ),
				'description' => __( 'Translate text between languages', 'mcp-ai-wpoos' ),
				'model_size'  => '~60MB',
			),
			'questionAnswering' => array(
				'name'        => __( 'Question Answering', 'mcp-ai-wpoos' ),
				'description' => __( 'Answer questions based on context', 'mcp-ai-wpoos' ),
				'model_size'  => '~30MB',
			),
			'zeroShot'          => array(
				'name'        => __( 'Zero-Shot Classification', 'mcp-ai-wpoos' ),
				'description' => __( 'Classify text without training', 'mcp-ai-wpoos' ),
				'model_size'  => '~30MB',
			),
		);
	}
}

// Initialize
WP_MCP_AI_Transformers_Enqueue::get_instance();
