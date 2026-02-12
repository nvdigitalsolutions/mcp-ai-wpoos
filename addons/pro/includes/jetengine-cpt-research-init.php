<?php
/**
 * JetEngine CPT Research & Add Initializer
 *
 * Automatically registers Research & Add pages for all JetEngine custom post types.
 *
 * @package WP_MCP_AI_Pro
 * @since 2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Initialize JetEngine CPT Research & Add pages.
 */
class WP_MCP_AI_JetEngine_CPT_Research_Init {

	/**
	 * Singleton instance.
	 *
	 * @var WP_MCP_AI_JetEngine_CPT_Research_Init|null
	 */
	private static $instance = null;

	/**
	 * Registered Research & Add instances.
	 *
	 * @var array
	 */
	private $research_instances = array();

	/**
	 * Get singleton instance.
	 *
	 * @return WP_MCP_AI_JetEngine_CPT_Research_Init
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		// Only initialize if Pro addon is active and not base version.
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return;
		}

		// Initialize after JetEngine is loaded.
		add_action( 'jet-engine/init', array( $this, 'init_research_pages' ), 20 );
	}

	/**
	 * Initialize Research & Add pages for JetEngine CPTs and taxonomies.
	 */
	public function init_research_pages() {
		// Check if feature is enabled.
		if ( ! $this->is_feature_enabled() ) {
			return;
		}

		// Check if JetEngine is active.
		if ( ! $this->is_jetengine_active() ) {
			return;
		}

		// Include the Research & Add base class.
		if ( ! class_exists( 'WP_MCP_AI_Research_Add_Base' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-research-add-base.php';
		}

		// Initialize CPT Research & Add pages.
		$this->init_cpt_research_pages();

		// Initialize Taxonomy Research & Add pages.
		$this->init_taxonomy_research_pages();
	}

	/**
	 * Initialize Research & Add pages for JetEngine CPTs.
	 */
	private function init_cpt_research_pages() {
		// Get JetEngine CPTs.
		$jetengine_cpts = $this->get_jetengine_cpts();
		if ( empty( $jetengine_cpts ) ) {
			return;
		}

		// Include the JetEngine CPT Research & Add class.
		require_once WP_MCP_AI_PRO_PATH . 'includes/research-add/class-wp-mcp-ai-jetengine-cpt-research-add.php';

		// Create Research & Add instance for each JetEngine CPT.
		foreach ( $jetengine_cpts as $cpt_data ) {
			if ( ! isset( $cpt_data['slug'] ) ) {
				continue;
			}

			$cpt_slug = $cpt_data['slug'];

			// Skip if already registered.
			if ( isset( $this->research_instances[ 'cpt_' . $cpt_slug ] ) ) {
				continue;
			}

			// Create and register Research & Add instance.
			$this->research_instances[ 'cpt_' . $cpt_slug ] = new WP_MCP_AI_JetEngine_CPT_Research_Add( $cpt_slug );
		}
	}

	/**
	 * Initialize Research & Add pages for JetEngine Taxonomies.
	 */
	private function init_taxonomy_research_pages() {
		// Get JetEngine taxonomies.
		$jetengine_taxonomies = $this->get_jetengine_taxonomies();
		if ( empty( $jetengine_taxonomies ) ) {
			return;
		}

		// Include the JetEngine Taxonomy Research & Add class.
		require_once WP_MCP_AI_PRO_PATH . 'includes/research-add/class-wp-mcp-ai-jetengine-taxonomy-research-add.php';

		// Create Research & Add instance for each JetEngine Taxonomy.
		foreach ( $jetengine_taxonomies as $taxonomy_data ) {
			if ( ! isset( $taxonomy_data['slug'] ) ) {
				continue;
			}

			$taxonomy_slug = $taxonomy_data['slug'];

			// Skip if already registered.
			if ( isset( $this->research_instances[ 'tax_' . $taxonomy_slug ] ) ) {
				continue;
			}

			// Create and register Research & Add instance.
			$this->research_instances[ 'tax_' . $taxonomy_slug ] = new WP_MCP_AI_JetEngine_Taxonomy_Research_Add( $taxonomy_slug );
		}
	}

	/**
	 * Check if feature is enabled.
	 *
	 * @return bool
	 */
	private function is_feature_enabled() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		
		// Check if JetEngine CPT AI is enabled.
		$jetengine_cpt_ai_enabled = isset( $settings['enable_jetengine_cpt_ai'] ) ? (bool) $settings['enable_jetengine_cpt_ai'] : true;
		
		// Check if Research & Add is enabled (if there's a separate setting).
		$research_add_enabled = isset( $settings['enable_jetengine_cpt_research_add'] ) ? (bool) $settings['enable_jetengine_cpt_research_add'] : true;
		
		return $jetengine_cpt_ai_enabled && $research_add_enabled;
	}

	/**
	 * Check if JetEngine is active.
	 *
	 * @return bool
	 */
	private function is_jetengine_active() {
		return function_exists( 'jet_engine' ) && class_exists( 'Jet_Engine' );
	}

	/**
	 * Get JetEngine custom post types.
	 *
	 * @return array Array of JetEngine CPT data.
	 */
	private function get_jetengine_cpts() {
		// Use compatibility layer for version-safe access.
		if ( ! class_exists( 'WP_MCP_AI_JetEngine_Compat' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-jetengine-compat.php';
		}

		return WP_MCP_AI_JetEngine_Compat::get_jetengine_cpts();
	}

	/**
	 * Get JetEngine custom taxonomies.
	 *
	 * @return array Array of JetEngine taxonomy data.
	 */
	private function get_jetengine_taxonomies() {
		// Use compatibility layer for version-safe access.
		if ( ! class_exists( 'WP_MCP_AI_JetEngine_Compat' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-jetengine-compat.php';
		}

		return WP_MCP_AI_JetEngine_Compat::get_jetengine_taxonomies();
	}
}

// Initialize the class.
WP_MCP_AI_JetEngine_CPT_Research_Init::get_instance();
