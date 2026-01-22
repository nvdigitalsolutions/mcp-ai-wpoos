<?php
/**
 * Pro Toolkits Integration Class
 *
 * Handles registration of toolkit shortcodes, Elementor widgets, and Gutenberg blocks.
 * This class coordinates Phase 5 frontend components integration with WordPress.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pro Toolkits Integration Class
 *
 * Registers and manages frontend components for pro toolkits:
 * - Shortcodes (12 total)
 * - Elementor widgets (12 total)
 * - Gutenberg blocks (12 total)
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Pro_Toolkit_Integration {

	/**
	 * Singleton instance.
	 *
	 * @var WP_MCP_AI_Pro_Toolkit_Integration
	 */
	private static $instance = null;

	/**
	 * Shortcodes instance.
	 *
	 * @var WP_MCP_AI_Pro_Toolkit_Shortcodes
	 */
	private $shortcodes;

	/**
	 * Blocks instance.
	 *
	 * @var WP_MCP_AI_Pro_Toolkit_Blocks
	 */
	private $blocks;

	/**
	 * Get singleton instance.
	 *
	 * @since 1.0.0
	 *
	 * @return WP_MCP_AI_Pro_Toolkit_Integration
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	private function __construct() {
		$this->init();
	}

	/**
	 * Initialize integration.
	 *
	 * @since 1.0.0
	 */
	private function init() {
		// Load shortcodes class.
		require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-toolkit-shortcodes.php';
		$this->shortcodes = new WP_MCP_AI_Pro_Toolkit_Shortcodes();

		// Load blocks registration class.
		require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-toolkit-blocks.php';
		$this->blocks = new WP_MCP_AI_Pro_Toolkit_Blocks();

		// Register Elementor widgets if Elementor is active.
		if ( did_action( 'elementor/loaded' ) ) {
			add_action( 'elementor/widgets/register', array( $this, 'register_elementor_widgets' ) );
			add_action( 'elementor/elements/categories_registered', array( $this, 'register_elementor_category' ) );
		}

		// Enqueue frontend assets.
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );
	}

	/**
	 * Register Elementor category for toolkit widgets.
	 *
	 * @since 1.0.0
	 *
	 * @param \Elementor\Elements_Manager $elements_manager Elementor elements manager.
	 */
	public function register_elementor_category( $elements_manager ) {
		$elements_manager->add_category(
			'mcp-ai-toolkits',
			array(
				'title' => __( 'MCP AI Toolkits', 'mcp-ai-wpoos-pro' ),
				'icon'  => 'fa fa-plug',
			)
		);
	}

	/**
	 * Register all Elementor widgets.
	 *
	 * @since 1.0.0
	 *
	 * @param \Elementor\Widgets_Manager $widgets_manager Elementor widgets manager.
	 */
	public function register_elementor_widgets( $widgets_manager ) {
		// Widget files to register.
		$widget_files = array(
			// E-commerce widgets (3).
			'class-wp-mcp-ai-ecommerce-products-widget.php',
			'class-wp-mcp-ai-ecommerce-search-widget.php',
			'class-wp-mcp-ai-ecommerce-orders-widget.php',
			// Social Media widgets (2).
			'class-wp-mcp-ai-social-calendar-widget.php',
			'class-wp-mcp-ai-social-templates-widget.php',
			// Calendar Booking widgets (3).
			'class-wp-mcp-ai-calendar-booking-widget.php',
			'class-wp-mcp-ai-calendar-services-widget.php',
			'class-wp-mcp-ai-calendar-staff-widget.php',
			// DJ Management widgets (2).
			'class-wp-mcp-ai-dj-equipment-widget.php',
			'class-wp-mcp-ai-dj-packages-widget.php',
			// Financial Planner widgets (2).
			'class-wp-mcp-ai-financial-budget-widget.php',
			'class-wp-mcp-ai-financial-goals-widget.php',
			// Multilingual widgets (2).
			'class-wp-mcp-ai-multilingual-translation-memory-widget.php',
			'class-wp-mcp-ai-multilingual-glossaries-widget.php',
			// AI Tool Builder widgets (2).
			'class-wp-mcp-ai-ai-tool-builder-templates-widget.php',
			'class-wp-mcp-ai-ai-tool-builder-schemas-widget.php',
			// Media Toolkit widgets (2).
			'class-wp-mcp-ai-media-templates-widget.php',
			'class-wp-mcp-ai-media-collections-widget.php',
		);

		// Load and register each widget.
		foreach ( $widget_files as $widget_file ) {
			$widget_path = WP_MCP_AI_PRO_PATH . 'includes/elementor/' . $widget_file;
			if ( file_exists( $widget_path ) ) {
				require_once $widget_path;

				// Extract class name from filename.
				$class_name = $this->get_class_name_from_file( $widget_file );

				if ( class_exists( $class_name ) ) {
					$widgets_manager->register( new $class_name() );
				}
			}
		}
	}

	/**
	 * Get class name from widget filename.
	 *
	 * Converts filename to class name following WordPress conventions.
	 * Example: class-wp-mcp-ai-ecommerce-products-widget.php => WP_MCP_AI_Ecommerce_Products_Widget
	 *
	 * @since 1.0.0
	 *
	 * @param string $filename Widget filename.
	 * @return string Class name.
	 */
	private function get_class_name_from_file( $filename ) {
		// Remove .php extension.
		$filename = str_replace( '.php', '', $filename );

		// Remove 'class-' prefix.
		$filename = str_replace( 'class-', '', $filename );

		// Convert hyphens to underscores.
		$filename = str_replace( '-', '_', $filename );

		// Convert to proper case.
		$parts = explode( '_', $filename );
		$parts = array_map( 'ucfirst', $parts );

		return implode( '_', $parts );
	}

	/**
	 * Enqueue frontend assets for toolkit widgets.
	 *
	 * Only enqueues when toolkit shortcodes/widgets/blocks are present on the page.
	 *
	 * @since 1.0.0
	 */
	public function enqueue_frontend_assets() {
		// Check if any toolkit components are in use on the current page.
		global $post;

		if ( ! $post ) {
			return;
		}

		// Check if content contains any toolkit shortcodes or blocks.
		$has_toolkit_components = false;

		// Check for shortcodes.
		$shortcode_tags = array(
			'mcp_ecommerce_products',
			'mcp_ecommerce_product_search',
			'mcp_ecommerce_orders',
			'mcp_social_media_calendar',
			'mcp_social_media_templates',
			'mcp_calendar_booking_form',
			'mcp_calendar_services',
			'mcp_calendar_staff',
			'mcp_dj_equipment',
			'mcp_dj_packages',
			'mcp_financial_budget',
			'mcp_financial_goals',
			'mcp_multilingual_translation_memory',
			'mcp_multilingual_glossaries',
			'mcp_ai_tool_builder_templates',
			'mcp_ai_tool_builder_schemas',
			'mcp_media_templates',
			'mcp_media_collections',
		);

		foreach ( $shortcode_tags as $tag ) {
			if ( has_shortcode( $post->post_content, $tag ) ) {
				$has_toolkit_components = true;
				break;
			}
		}

		// Check for Gutenberg blocks.
		if ( ! $has_toolkit_components && function_exists( 'has_block' ) ) {
			$block_types = array(
				'mcp-ai-toolkits/ecommerce-products',
				'mcp-ai-toolkits/ecommerce-search',
				'mcp-ai-toolkits/ecommerce-orders',
				'mcp-ai-toolkits/social-calendar',
				'mcp-ai-toolkits/social-templates',
				'mcp-ai-toolkits/calendar-booking',
				'mcp-ai-toolkits/calendar-services',
				'mcp-ai-toolkits/calendar-staff',
				'mcp-ai-toolkits/dj-equipment',
				'mcp-ai-toolkits/dj-packages',
				'mcp-ai-toolkits/financial-budget',
				'mcp-ai-toolkits/financial-goals',
				'mcp-ai-toolkits/multilingual-translation-memory',
				'mcp-ai-toolkits/multilingual-glossaries',
				'mcp-ai-toolkits/ai-tool-builder-templates',
				'mcp-ai-toolkits/ai-tool-builder-schemas',
				'mcp-ai-toolkits/media-templates',
				'mcp-ai-toolkits/media-collections',
			);

			foreach ( $block_types as $block_type ) {
				if ( has_block( $block_type, $post ) ) {
					$has_toolkit_components = true;
					break;
				}
			}
		}

		// Only enqueue if components are in use.
		if ( ! $has_toolkit_components ) {
			return;
		}

		// Enqueue CSS (placeholder - will be created in future phase).
		// wp_enqueue_style(
		// 	'wp-mcp-ai-pro-toolkit-widgets',
		// 	WP_MCP_AI_PRO_URL . 'assets/css/toolkit-widgets.css',
		// 	array(),
		// 	WP_MCP_AI_PRO_VERSION
		// );

		// Enqueue JavaScript (placeholder - will be created in future phase).
		// wp_enqueue_script(
		// 	'wp-mcp-ai-pro-toolkit-widgets',
		// 	WP_MCP_AI_PRO_URL . 'assets/js/toolkit-widgets.min.js',
		// 	array( 'jquery' ),
		// 	WP_MCP_AI_PRO_VERSION,
		// 	true
		// );

		// Localize script with data.
		// wp_localize_script(
		// 	'wp-mcp-ai-pro-toolkit-widgets',
		// 	'mcpAiToolkits',
		// 	array(
		// 		'ajaxUrl' => admin_url( 'admin-ajax.php' ),
		// 		'nonce'   => wp_create_nonce( 'mcp_ai_toolkit_nonce' ),
		// 		'i18n'    => array(
		// 			'loading' => __( 'Loading...', 'mcp-ai-wpoos-pro' ),
		// 			'error'   => __( 'An error occurred', 'mcp-ai-wpoos-pro' ),
		// 		),
		// 	)
		// );
	}
}
