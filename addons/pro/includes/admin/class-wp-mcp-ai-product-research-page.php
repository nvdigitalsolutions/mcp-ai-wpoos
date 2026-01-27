<?php
/**
 * Research & Add admin page for WooCommerce Products.
 *
 * Provides a dedicated page for researching product information before creating products,
 * with full chat interface for AI assistance.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/trait-wp-mcp-ai-research-page-featured-image.php';
require_once __DIR__ . '/trait-wp-mcp-ai-research-page-enhancements.php';

/**
 * Product Research Admin Page
 *
 * Adds a submenu page under Products menu for AI-powered product research.
 */
class WP_MCP_AI_Product_Research_Page {
	use WP_MCP_AI_Research_Page_Featured_Image;
	use WP_MCP_AI_Research_Page_Import_Handler;
	use WP_MCP_AI_Research_Page_Consolidation;
	use WP_MCP_AI_Research_Page_Data_Validation;
	use WP_MCP_AI_Research_Page_Mode_Tabs;

	/**
	 * Page slug.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'research-product';

	/**
	 * Initialize the page.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu_page' ), 20 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_ajax_wp_mcp_ai_create_product_from_research', array( __CLASS__, 'handle_create_from_research' ) );
		add_action( 'wp_ajax_wp_mcp_ai_import_product', array( __CLASS__, 'ajax_handle_import' ) );
	}

	/**
	 * Add submenu page under Products menu.
	 */
	public static function add_menu_page() {
		// Check if WooCommerce is active.
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		add_submenu_page(
			'edit.php?post_type=product',
			__( 'Research & Add Product', 'mcp-ai-wpoos-pro' ),
			__( 'Research & Add', 'mcp-ai-wpoos-pro' ),
			'edit_products',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Enqueue assets for the research page.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function enqueue_assets( $hook ) {
		// Only load on our research page.
		if ( 'product_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		// Enqueue chat assets.
		if ( class_exists( 'WP_MCP_AI_Shortcode' ) ) {
			$shortcode_instance = new WP_MCP_AI_Shortcode();
			$shortcode_instance->register_assets();
			wp_enqueue_style( WP_MCP_AI_Shortcode::STYLE_HANDLE );
			wp_enqueue_script( WP_MCP_AI_Shortcode::SCRIPT_HANDLE );
		}

		// Enqueue research page specific styles.
		wp_enqueue_style(
			'wp-mcp-ai-research-page',
			WP_MCP_AI_PRO_URL . 'assets/css/research-page.css',
			array(),
			WP_MCP_AI_PRO_VERSION
		);

		// Enqueue research page script.
		wp_enqueue_script(
			'wp-mcp-ai-research-page',
			WP_MCP_AI_PRO_URL . 'assets/js/research-page.js',
			array( 'jquery', 'wp-api', WP_MCP_AI_Shortcode::SCRIPT_HANDLE ),
			WP_MCP_AI_PRO_VERSION,
			true
		);

		// Localize script.
		wp_localize_script(
			'wp-mcp-ai-research-page',
			'wpMcpAiResearchPage',
			array(
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'nonce'        => wp_create_nonce( 'wp_mcp_ai_research_product' ),
				'addNewUrl'    => admin_url( 'post-new.php?post_type=product' ),
				'researchTool' => 'research_product',
				'strings'      => array(
					'researching'   => __( 'Researching...', 'mcp-ai-wpoos-pro' ),
					'error'         => __( 'An error occurred. Please try again.', 'mcp-ai-wpoos-pro' ),
					'creating'      => __( 'Creating product...', 'mcp-ai-wpoos-pro' ),
					'created'       => __( 'Product created successfully!', 'mcp-ai-wpoos-pro' ),
					'confirmCreate' => __( 'Create a product with the researched information?', 'mcp-ai-wpoos-pro' ),
				),
			)
		);
	}

	/**
	 * Render the research page.
	 */
	public static function render_page() {
		$mode = self::get_current_mode();

		// Get assistant from settings.
		$settings     = get_option( 'wp_mcp_ai_product_settings', array() );
		$assistant_id = isset( $settings['assistant_id'] ) ? absint( $settings['assistant_id'] ) : 0;

		// If no assistant configured or invalid, get the first available assistant.
		if ( ! $assistant_id || 'publish' !== get_post_status( $assistant_id ) ) {
			$assistants = get_posts(
				array(
					'post_type'      => 'mcp_ai_assistant',
					'post_status'    => 'publish',
					'posts_per_page' => 1,
					'orderby'        => 'date',
					'order'          => 'DESC',
				)
			);

			$assistant_id = ! empty( $assistants ) ? $assistants[0]->ID : 0;
		}

		?>
		<div class="wrap wp-mcp-ai-research-page">
			<h1 class="wp-heading-inline">
				<?php esc_html_e( 'Research & Add Product', 'mcp-ai-wpoos-pro' ); ?>
			</h1>

			<hr class="wp-header-end">

			<?php self::render_mode_tabs( $mode ); ?>

			<?php
			switch ( $mode ) {
				case 'import':
					self::render_import_section();
					break;
				case 'consolidate':
					self::render_consolidation_dashboard();
					break;
				default: // 'chat'
					self::render_chat_interface( $assistant_id );
			}
			?>
		</div>
		<?php
	}

	/**
	 * Render the chat interface.
	 *
	 * @param int $assistant_id Assistant ID.
	 */
	protected static function render_chat_interface( $assistant_id ) {
		?>

			<div class="wp-mcp-ai-research-container">
				<div class="wp-mcp-ai-research-sidebar">
					<div class="wp-mcp-ai-research-intro">
						<h2><?php esc_html_e( 'How It Works', 'mcp-ai-wpoos-pro' ); ?></h2>
						<ol>
							<li><?php esc_html_e( 'Search your catalog or the web for product ideas', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Scrape competitor products or research with AI', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Generate product images and optimize descriptions', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Create products directly in your WooCommerce store', 'mcp-ai-wpoos-pro' ); ?></li>
						</ol>
					</div>

					<div class="wp-mcp-ai-research-tips">
						<h3><?php esc_html_e( 'Research Tips', 'mcp-ai-wpoos-pro' ); ?></h3>
						<ul>
							<li><strong><?php esc_html_e( 'Search catalog:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Check existing products before adding duplicates', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><strong><?php esc_html_e( 'Scrape competitors:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Import product data from competitor URLs', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><strong><?php esc_html_e( 'Generate images:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Create product images with AI if needed', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><strong><?php esc_html_e( 'Optimize content:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Generate alt text for accessibility and SEO', 'mcp-ai-wpoos-pro' ); ?></li>
						</ul>
					</div>

					<div class="wp-mcp-ai-research-examples">
						<h3><?php esc_html_e( 'Example Queries', 'mcp-ai-wpoos-pro' ); ?></h3>
						<ul class="wp-mcp-ai-example-list">
							<li><button type="button" class="button button-secondary wp-mcp-ai-example-query" data-query="List all products in my catalog">
								<?php esc_html_e( '"List all products..."', 'mcp-ai-wpoos-pro' ); ?>
							</button></li>
							<li><button type="button" class="button button-secondary wp-mcp-ai-example-query" data-query="Scrape product from https://example.com/product-page">
								<?php esc_html_e( '"Scrape product from URL..."', 'mcp-ai-wpoos-pro' ); ?>
							</button></li>
							<li><button type="button" class="button button-secondary wp-mcp-ai-example-query" data-query="Research Nike Air Max 270 shoes with pricing">
								<?php esc_html_e( '"Research Nike shoes..."', 'mcp-ai-wpoos-pro' ); ?>
							</button></li>
							<li><button type="button" class="button button-secondary wp-mcp-ai-example-query" data-query="Generate a product image of wireless headphones">
								<?php esc_html_e( '"Generate product image..."', 'mcp-ai-wpoos-pro' ); ?>
							</button></li>
						</ul>
					</div>

					<div class="wp-mcp-ai-research-preview" id="wp-mcp-ai-product-preview" style="display: none;">
						<h3><?php esc_html_e( 'Product Preview', 'mcp-ai-wpoos-pro' ); ?></h3>
						<div class="wp-mcp-ai-preview-content">
							<div class="wp-mcp-ai-preview-loading">
								<span class="spinner is-active"></span>
								<p><?php esc_html_e( 'Building product preview...', 'mcp-ai-wpoos-pro' ); ?></p>
							</div>
							<div class="wp-mcp-ai-preview-data" style="display: none;">
								<div class="wp-mcp-ai-preview-header">
									<h4 class="wp-mcp-ai-preview-title"></h4>
									<p class="wp-mcp-ai-preview-meta"></p>
								</div>
								<div class="wp-mcp-ai-preview-details"></div>
								<div class="wp-mcp-ai-preview-images" style="display: none;">
									<h5><?php esc_html_e( 'Product Images', 'mcp-ai-wpoos-pro' ); ?></h5>
									<div class="wp-mcp-ai-preview-image-list"></div>
								</div>
							</div>
						</div>
					</div>

					<div class="wp-mcp-ai-research-actions">
						<h3><?php esc_html_e( 'Quick Actions', 'mcp-ai-wpoos-pro' ); ?></h3>
						<p>
							<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=product' ) ); ?>" class="button">
								<?php esc_html_e( 'View All Products', 'mcp-ai-wpoos-pro' ); ?>
							</a>
						</p>
						<p>
							<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=product' ) ); ?>" class="button">
								<?php esc_html_e( 'Add Product Manually', 'mcp-ai-wpoos-pro' ); ?>
							</a>
						</p>
					</div>
				</div>

				<div class="wp-mcp-ai-research-main">
					<?php if ( $assistant_id > 0 ) : ?>
						<div class="wp-mcp-ai-research-chat">
							<?php
							// Render chat interface with comprehensive product tools.
							// Includes research, creation, competitor analysis, and catalog management.
							echo do_shortcode(
								'[mcp_ai_chat assistant="' . absint( $assistant_id ) . '" additional_tools="research_product,create_woo_product,scrape_product,get_woo_products,get_woo_recent_orders,web_search,search_content,generate_openai_image,generate_image_alt_text"]'
							);
							?>
						</div>

					<?php else : ?>
						<div class="notice notice-error">
							<p>
								<?php
								echo wp_kses_post(
									sprintf(
										/* translators: %s: Link to create assistant */
										__( 'No AI assistant found. Please <a href="%s">create an assistant</a> first.', 'mcp-ai-wpoos-pro' ),
										admin_url( 'post-new.php?post_type=mcp_ai_assistant' )
									)
								);
								?>
							</p>
						</div>
					<?php endif; ?>
				</div>
			</div>
		<?php
	}

	/**
	 * Handle AJAX request to create product from research.
	 */
	public static function handle_create_from_research() {
		// Verify nonce.
		check_ajax_referer( 'wp_mcp_ai_research_product', 'nonce' );

		// Check user capability.
		if ( ! current_user_can( 'edit_products' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to create products.', 'mcp-ai-wpoos-pro' ) ) );
		}

		// Get research data from request.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Data is sanitized below per field.
		$research_data = isset( $_POST['research_data'] ) ? json_decode( wp_unslash( $_POST['research_data'] ), true ) : array();

		if ( empty( $research_data ) || empty( $research_data['reference'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid research data. Product reference is required.', 'mcp-ai-wpoos-pro' ) ) );
		}

		// Process featured image generation request.
		$title         = isset( $research_data['title'] ) ? $research_data['title'] : $research_data['reference'];
		$research_data = self::process_featured_image_request( $research_data, $title, 'a product' );

		// Use the create_woo_product tool to create the product.
		if ( ! class_exists( 'WP_MCP_AI_Tool_Create_Woo_Product' ) ) {
			wp_send_json_error( array( 'message' => __( 'Create product tool not available.', 'mcp-ai-wpoos-pro' ) ) );
		}

		$tool   = new WP_MCP_AI_Tool_Create_Woo_Product();
		$result = $tool->execute(
			$research_data,
			array( 'user_id' => get_current_user_id() )
		);

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		// Return success with product ID and edit URL.
		$product_id = isset( $result['product_id'] ) ? $result['product_id'] : 0;
		$edit_url   = $product_id > 0 ? admin_url( 'post.php?post=' . $product_id . '&action=edit' ) : '';

		wp_send_json_success(
			array(
				'message'    => __( 'Product created successfully!', 'mcp-ai-wpoos-pro' ),
				'product_id' => $product_id,
				'edit_url'   => $edit_url,
			)
		);
	}

	/**
	 * Get supported import formats.
	 *
	 * @return array Import formats.
	 */
	protected static function get_import_formats() {
		return array(
			'csv'  => 'CSV',
			'xml'  => 'XML',
			'json' => 'JSON',
		);
	}

	/**
	 * Process imported data.
	 *
	 * @param string $data   Import data.
	 * @param string $format Data format.
	 * @return array|WP_Error Result or error.
	 */
	protected static function process_import_data( $data, $format ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed,Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Required by trait interface.
		return new WP_Error( 'not_implemented', __( 'Product import processing coming soon', 'mcp-ai-wpoos-pro' ) );
	}

	/**
	 * Get validation schema.
	 *
	 * @return array Validation schema.
	 */
	protected static function get_validation_schema() {
		return array(
			'required_fields'    => array(
				'sku'   => __( 'SKU', 'mcp-ai-wpoos-pro' ),
				'name'  => __( 'Product Name', 'mcp-ai-wpoos-pro' ),
				'price' => __( 'Price', 'mcp-ai-wpoos-pro' ),
			),
			'recommended_fields' => array(
				'description' => __( 'Description', 'mcp-ai-wpoos-pro' ),
				'category'    => __( 'Category', 'mcp-ai-wpoos-pro' ),
				'image'       => __( 'Product Image', 'mcp-ai-wpoos-pro' ),
				'stock'       => __( 'Stock Quantity', 'mcp-ai-wpoos-pro' ),
			),
			'validation_rules'   => array(
				'price' => array(
					'type'      => 'numeric',
					'min_value' => 0,
				),
				'stock' => array(
					'type'      => 'numeric',
					'min_value' => 0,
				),
				'sku'   => array( 'max_length' => 100 ),
			),
			'quality_dimensions' => array(
				'accuracy',
				'completeness',
				'consistency',
				'uniqueness',
			),
		);
	}

	/**
	 * Calculate completeness.
	 *
	 * @return array Completeness data.
	 */
	protected static function calculate_completeness() {
		$products = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => 'any',
				'posts_per_page' => -1,
			)
		);

		$total    = count( $products );
		$complete = 0;

		foreach ( $products as $product ) {
			$product_obj = wc_get_product( $product->ID );
			if ( $product_obj && $product_obj->get_sku() && $product_obj->get_price() && ! empty( $product->post_content ) ) {
				++$complete;
			}
		}

		$percentage = $total > 0 ? round( ( $complete / $total ) * 100 ) : 0;

		return array(
			'percentage'  => $percentage,
			'missing'     => array(),
			'suggestions' => array(
				__( 'Ensure all products have unique SKUs', 'mcp-ai-wpoos-pro' ),
				__( 'Add detailed descriptions and images', 'mcp-ai-wpoos-pro' ),
				__( 'Set prices and stock levels', 'mcp-ai-wpoos-pro' ),
			),
		);
	}

	/**
	 * Get items for review.
	 *
	 * @return array Items.
	 */
	protected static function get_items_for_review() {
		$products = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => 'any',
				'posts_per_page' => 20,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		$items = array();
		foreach ( $products as $product ) {
			$product_obj = wc_get_product( $product->ID );
			$items[]     = array(
				'id'    => $product->ID,
				'title' => $product->post_title,
				'meta'  => array(
					'sku'   => $product_obj ? $product_obj->get_sku() : '',
					'price' => $product_obj ? $product_obj->get_price() : '',
					'stock' => $product_obj ? $product_obj->get_stock_quantity() : '',
				),
			);
		}

		return $items;
	}

	/**
	 * Calculate quality score for item.
	 *
	 * @param array $item Item data.
	 * @return array Quality data.
	 */
	protected static function calculate_quality_score( $item ) {
		$score  = 0;
		$issues = array();

		if ( ! empty( $item['meta']['sku'] ) ) {
			$score += 25;
		} else {
			$issues[] = __( 'Missing SKU', 'mcp-ai-wpoos-pro' );
		}

		if ( ! empty( $item['meta']['price'] ) ) {
			$score += 25;
		} else {
			$issues[] = __( 'Missing price', 'mcp-ai-wpoos-pro' );
		}

		if ( ! empty( $item['meta']['stock'] ) ) {
			$score += 25;
		} else {
			$issues[] = __( 'Missing stock level', 'mcp-ai-wpoos-pro' );
		}

		if ( ! empty( $item['title'] ) && strlen( $item['title'] ) > 10 ) {
			$score += 25;
		} else {
			$issues[] = __( 'Title needs improvement', 'mcp-ai-wpoos-pro' );
		}

		$level = $score >= 80 ? 'high' : ( $score >= 50 ? 'medium' : 'low' );

		return array(
			'score'  => $score,
			'level'  => $level,
			'status' => 'high' === $level ? __( 'Complete', 'mcp-ai-wpoos-pro' ) : __( 'Needs Work', 'mcp-ai-wpoos-pro' ),
			'issues' => $issues,
		);
	}
}

// Initialize.
WP_MCP_AI_Product_Research_Page::init();
