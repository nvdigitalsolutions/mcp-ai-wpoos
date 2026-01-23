<?php
/**
 * Shortcode registration and rendering for pro toolkit widgets.
 *
 * Provides base functionality for toolkit shortcodes following the pattern
 * established by the main [mcp_ai_chat] shortcode.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and handles all pro toolkit shortcodes.
 *
 * Shortcodes provide the core rendering logic that is used by both
 * Elementor widgets and Gutenberg blocks.
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Pro_Toolkit_Shortcodes {

	/**
	 * Script handle for toolkit widgets.
	 */
	const SCRIPT_HANDLE = 'wp-mcp-ai-pro-toolkit-widgets';

	/**
	 * Style handle for toolkit widgets.
	 */
	const STYLE_HANDLE = 'wp-mcp-ai-pro-toolkit-widgets';

	/**
	 * Initialize shortcodes.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'register_assets' ) );
		add_action( 'init', array( $this, 'register_shortcodes' ) );
	}

	/**
	 * Register assets for toolkit widgets.
	 */
	public function register_assets() {
		$script_path    = WP_MCP_AI_PRO_URL . 'assets/js/toolkit-widgets.min.js';
		$style_path     = WP_MCP_AI_PRO_URL . 'assets/css/toolkit-widgets.css';
		$script_version = WP_MCP_AI_PRO_VERSION;
		$style_version  = WP_MCP_AI_PRO_VERSION;

		// Register stylesheet.
		wp_register_style(
			self::STYLE_HANDLE,
			$style_path,
			array(),
			$style_version
		);

		// Register script.
		wp_register_script(
			self::SCRIPT_HANDLE,
			$script_path,
			array( 'jquery' ),
			$script_version,
			true
		);

		// Localize script with common data.
		wp_localize_script(
			self::SCRIPT_HANDLE,
			'wpMcpAiToolkitWidgets',
			array(
				'restUrl' => esc_url_raw( rest_url( 'mcp-ai-pro/v1' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'i18n'    => array(
					'loading'     => __( 'Loading...', 'mcp-ai-wpoos-pro' ),
					'noResults'   => __( 'No results found.', 'mcp-ai-wpoos-pro' ),
					'error'       => __( 'An error occurred. Please try again.', 'mcp-ai-wpoos-pro' ),
					'prevPage'    => __( 'Previous Page', 'mcp-ai-wpoos-pro' ),
					'nextPage'    => __( 'Next Page', 'mcp-ai-wpoos-pro' ),
					'searchLabel' => __( 'Search', 'mcp-ai-wpoos-pro' ),
				),
			)
		);
	}

	/**
	 * Register all toolkit shortcodes.
	 */
	public function register_shortcodes() {
		// E-commerce toolkit shortcodes.
		add_shortcode( 'mcp_ecommerce_products', array( $this, 'render_ecommerce_products' ) );
		add_shortcode( 'mcp_ecommerce_product_search', array( $this, 'render_ecommerce_product_search' ) );
		add_shortcode( 'mcp_ecommerce_orders', array( $this, 'render_ecommerce_orders' ) );

		// Social Media toolkit shortcodes.
		add_shortcode( 'mcp_social_media_calendar', array( $this, 'render_social_media_calendar' ) );
		add_shortcode( 'mcp_social_media_templates', array( $this, 'render_social_media_templates' ) );

		// Calendar Booking toolkit shortcodes.
		add_shortcode( 'mcp_calendar_booking_form', array( $this, 'render_calendar_booking_form' ) );
		add_shortcode( 'mcp_calendar_services', array( $this, 'render_calendar_services' ) );
		add_shortcode( 'mcp_calendar_staff', array( $this, 'render_calendar_staff' ) );

		// DJ Management toolkit shortcodes.
		add_shortcode( 'mcp_dj_equipment', array( $this, 'render_dj_equipment' ) );
		add_shortcode( 'mcp_dj_packages', array( $this, 'render_dj_packages' ) );

		// Financial Planner toolkit shortcodes.
		add_shortcode( 'mcp_financial_budget', array( $this, 'render_financial_budget' ) );
		add_shortcode( 'mcp_financial_goals', array( $this, 'render_financial_goals' ) );

		// Multilingual toolkit shortcodes.
		add_shortcode( 'mcp_multilingual_translation_memory', array( $this, 'render_multilingual_translation_memory' ) );
		add_shortcode( 'mcp_multilingual_glossaries', array( $this, 'render_multilingual_glossaries' ) );

		// AI Tool Builder toolkit shortcodes.
		add_shortcode( 'mcp_ai_tool_builder_templates', array( $this, 'render_ai_tool_builder_templates' ) );
		add_shortcode( 'mcp_ai_tool_builder_schemas', array( $this, 'render_ai_tool_builder_schemas' ) );

		// Media toolkit shortcodes.
		add_shortcode( 'mcp_media_templates', array( $this, 'media_templates_shortcode' ) );
		add_shortcode( 'mcp_media_collections', array( $this, 'media_collections_shortcode' ) );
	}

	/**
	 * Render E-commerce products list.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string Rendered HTML.
	 */
	public function render_ecommerce_products( $atts ) {
		$atts = shortcode_atts(
			array(
				'display'  => 'grid',
				'columns'  => 3,
				'limit'    => 9,
				'orderby'  => 'date',
				'order'    => 'DESC',
				'category' => '',
			),
			$atts,
			'mcp_ecommerce_products'
		);

		wp_enqueue_style( self::STYLE_HANDLE );
		wp_enqueue_script( self::SCRIPT_HANDLE );

		$store = $this->get_data_store( 'ecommerce', 'products' );
		if ( ! $store ) {
			return $this->render_error( __( 'E-commerce toolkit is not available.', 'mcp-ai-wpoos-pro' ) );
		}

		$query_args = array(
			'per_page' => absint( $atts['limit'] ),
			'orderby'  => sanitize_key( $atts['orderby'] ),
			'order'    => sanitize_key( $atts['order'] ),
		);

		if ( ! empty( $atts['category'] ) ) {
			$query_args['category'] = sanitize_text_field( $atts['category'] );
		}

		$products = $store->query_items( $query_args );

		if ( empty( $products ) ) {
			return $this->render_empty( __( 'No products found.', 'mcp-ai-wpoos-pro' ) );
		}

		ob_start();
		$this->render_products_grid( $products, $atts );
		return ob_get_clean();
	}

	/**
	 * Render E-commerce product search.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string Rendered HTML.
	 */
	public function render_ecommerce_product_search( $atts ) {
		$atts = shortcode_atts(
			array(
				'placeholder' => __( 'Search products...', 'mcp-ai-wpoos-pro' ),
				'display'     => 'grid',
				'columns'     => 3,
			),
			$atts,
			'mcp_ecommerce_product_search'
		);

		wp_enqueue_style( self::STYLE_HANDLE );
		wp_enqueue_script( self::SCRIPT_HANDLE );

		ob_start();
		?>
		<div class="mcp-ai-product-search" data-display="<?php echo esc_attr( $atts['display'] ); ?>" data-columns="<?php echo esc_attr( $atts['columns'] ); ?>">
			<form class="mcp-ai-product-search-form" role="search">
				<input 
					type="search" 
					class="mcp-ai-product-search-input" 
					placeholder="<?php echo esc_attr( $atts['placeholder'] ); ?>"
					aria-label="<?php esc_attr_e( 'Search products', 'mcp-ai-wpoos-pro' ); ?>"
				/>
				<button type="submit" class="mcp-ai-product-search-button">
					<?php esc_html_e( 'Search', 'mcp-ai-wpoos-pro' ); ?>
				</button>
			</form>
			<div class="mcp-ai-product-search-results"></div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render E-commerce orders history.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string Rendered HTML.
	 */
	public function render_ecommerce_orders( $atts ) {
		$atts = shortcode_atts(
			array(
				'limit'   => 10,
				'status'  => '',
				'orderby' => 'date',
				'order'   => 'DESC',
			),
			$atts,
			'mcp_ecommerce_orders'
		);

		// Check if user is logged in.
		if ( ! is_user_logged_in() ) {
			return $this->render_error( __( 'You must be logged in to view orders.', 'mcp-ai-wpoos-pro' ) );
		}

		wp_enqueue_style( self::STYLE_HANDLE );
		wp_enqueue_script( self::SCRIPT_HANDLE );

		$store = $this->get_data_store( 'ecommerce', 'orders' );
		if ( ! $store ) {
			return $this->render_error( __( 'E-commerce toolkit is not available.', 'mcp-ai-wpoos-pro' ) );
		}

		$query_args = array(
			'per_page' => absint( $atts['limit'] ),
			'orderby'  => sanitize_key( $atts['orderby'] ),
			'order'    => sanitize_key( $atts['order'] ),
		);

		if ( ! empty( $atts['status'] ) ) {
			$query_args['status'] = sanitize_text_field( $atts['status'] );
		}

		$orders = $store->query_items( $query_args );

		if ( empty( $orders ) ) {
			return $this->render_empty( __( 'No orders found.', 'mcp-ai-wpoos-pro' ) );
		}

		ob_start();
		$this->render_orders_table( $orders );
		return ob_get_clean();
	}

	/**
	 * Render Social Media content calendar.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string Rendered HTML.
	 */
	public function render_social_media_calendar( $atts ) {
		$atts = shortcode_atts(
			array(
				'view'     => 'list',
				'limit'    => 20,
				'platform' => '',
				'status'   => 'scheduled',
			),
			$atts,
			'mcp_social_media_calendar'
		);

		wp_enqueue_style( self::STYLE_HANDLE );
		wp_enqueue_script( self::SCRIPT_HANDLE );

		$store = $this->get_data_store( 'social-media', 'content-calendar' );
		if ( ! $store ) {
			return $this->render_error( __( 'Social Media toolkit is not available.', 'mcp-ai-wpoos-pro' ) );
		}

		$query_args = array(
			'per_page' => absint( $atts['limit'] ),
			'orderby'  => 'scheduled_date',
			'order'    => 'ASC',
		);

		if ( ! empty( $atts['platform'] ) ) {
			$query_args['platform'] = sanitize_text_field( $atts['platform'] );
		}

		if ( ! empty( $atts['status'] ) ) {
			$query_args['status'] = sanitize_text_field( $atts['status'] );
		}

		$posts = $store->query_items( $query_args );

		if ( empty( $posts ) ) {
			return $this->render_empty( __( 'No scheduled posts found.', 'mcp-ai-wpoos-pro' ) );
		}

		ob_start();
		$this->render_calendar_view( $posts, $atts );
		return ob_get_clean();
	}

	/**
	 * Render Social Media post templates.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string Rendered HTML.
	 */
	public function render_social_media_templates( $atts ) {
		$atts = shortcode_atts(
			array(
				'platform' => '',
				'category' => '',
				'columns'  => 3,
			),
			$atts,
			'mcp_social_media_templates'
		);

		wp_enqueue_style( self::STYLE_HANDLE );
		wp_enqueue_script( self::SCRIPT_HANDLE );

		$store = $this->get_data_store( 'social-media', 'post-templates' );
		if ( ! $store ) {
			return $this->render_error( __( 'Social Media toolkit is not available.', 'mcp-ai-wpoos-pro' ) );
		}

		$query_args = array(
			'per_page' => 50,
			'orderby'  => 'title',
			'order'    => 'ASC',
		);

		if ( ! empty( $atts['platform'] ) ) {
			$query_args['platform'] = sanitize_text_field( $atts['platform'] );
		}

		if ( ! empty( $atts['category'] ) ) {
			$query_args['category'] = sanitize_text_field( $atts['category'] );
		}

		$templates = $store->query_items( $query_args );

		if ( empty( $templates ) ) {
			return $this->render_empty( __( 'No templates found.', 'mcp-ai-wpoos-pro' ) );
		}

		ob_start();
		$this->render_templates_grid( $templates, $atts );
		return ob_get_clean();
	}

	/**
	 * Render Calendar Booking form.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string Rendered HTML.
	 */
	public function render_calendar_booking_form( $atts ) {
		$atts = shortcode_atts(
			array(
				'service' => '',
			),
			$atts,
			'mcp_calendar_booking_form'
		);

		wp_enqueue_style( self::STYLE_HANDLE );
		wp_enqueue_script( self::SCRIPT_HANDLE );

		ob_start();
		?>
		<div class="mcp-ai-booking-form" data-service="<?php echo esc_attr( $atts['service'] ); ?>">
			<form class="mcp-ai-booking-form-inner" method="post">
				<?php wp_nonce_field( 'mcp_ai_booking', 'mcp_ai_booking_nonce' ); ?>
				
				<div class="mcp-ai-booking-field">
					<label for="booking-service"><?php esc_html_e( 'Service', 'mcp-ai-wpoos-pro' ); ?></label>
					<select id="booking-service" name="service" required>
						<option value=""><?php esc_html_e( 'Select a service...', 'mcp-ai-wpoos-pro' ); ?></option>
					</select>
				</div>

				<div class="mcp-ai-booking-field">
					<label for="booking-date"><?php esc_html_e( 'Date', 'mcp-ai-wpoos-pro' ); ?></label>
					<input type="date" id="booking-date" name="date" required />
				</div>

				<div class="mcp-ai-booking-field">
					<label for="booking-time"><?php esc_html_e( 'Time', 'mcp-ai-wpoos-pro' ); ?></label>
					<select id="booking-time" name="time" required>
						<option value=""><?php esc_html_e( 'Select a time...', 'mcp-ai-wpoos-pro' ); ?></option>
					</select>
				</div>

				<button type="submit" class="mcp-ai-booking-submit">
					<?php esc_html_e( 'Book Now', 'mcp-ai-wpoos-pro' ); ?>
				</button>
			</form>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render Calendar services list.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string Rendered HTML.
	 */
	public function render_calendar_services( $atts ) {
		$atts = shortcode_atts(
			array(
				'display'  => 'grid',
				'columns'  => 3,
				'category' => '',
			),
			$atts,
			'mcp_calendar_services'
		);

		wp_enqueue_style( self::STYLE_HANDLE );
		wp_enqueue_script( self::SCRIPT_HANDLE );

		$store = $this->get_data_store( 'calendar-booking', 'services' );
		if ( ! $store ) {
			return $this->render_error( __( 'Calendar Booking toolkit is not available.', 'mcp-ai-wpoos-pro' ) );
		}

		$query_args = array(
			'per_page' => 50,
			'orderby'  => 'title',
			'order'    => 'ASC',
		);

		if ( ! empty( $atts['category'] ) ) {
			$query_args['category'] = sanitize_text_field( $atts['category'] );
		}

		$services = $store->query_items( $query_args );

		if ( empty( $services ) ) {
			return $this->render_empty( __( 'No services found.', 'mcp-ai-wpoos-pro' ) );
		}

		ob_start();
		$this->render_services_grid( $services, $atts );
		return ob_get_clean();
	}

	/**
	 * Render Calendar staff directory.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string Rendered HTML.
	 */
	public function render_calendar_staff( $atts ) {
		$atts = shortcode_atts(
			array(
				'display' => 'grid',
				'columns' => 3,
			),
			$atts,
			'mcp_calendar_staff'
		);

		wp_enqueue_style( self::STYLE_HANDLE );
		wp_enqueue_script( self::SCRIPT_HANDLE );

		$store = $this->get_data_store( 'calendar-booking', 'staff' );
		if ( ! $store ) {
			return $this->render_error( __( 'Calendar Booking toolkit is not available.', 'mcp-ai-wpoos-pro' ) );
		}

		$staff = $store->query_items( array( 'per_page' => 50 ) );

		if ( empty( $staff ) ) {
			return $this->render_empty( __( 'No staff members found.', 'mcp-ai-wpoos-pro' ) );
		}

		ob_start();
		$this->render_staff_grid( $staff, $atts );
		return ob_get_clean();
	}

	/**
	 * Render DJ equipment list.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string Rendered HTML.
	 */
	public function render_dj_equipment( $atts ) {
		$atts = shortcode_atts(
			array(
				'type'    => '',
				'display' => 'list',
			),
			$atts,
			'mcp_dj_equipment'
		);

		wp_enqueue_style( self::STYLE_HANDLE );
		wp_enqueue_script( self::SCRIPT_HANDLE );

		$store = $this->get_data_store( 'dj-management', 'equipment' );
		if ( ! $store ) {
			return $this->render_error( __( 'DJ Management toolkit is not available.', 'mcp-ai-wpoos-pro' ) );
		}

		$query_args = array(
			'per_page' => 50,
			'orderby'  => 'title',
			'order'    => 'ASC',
		);

		if ( ! empty( $atts['type'] ) ) {
			$query_args['equipment_type'] = sanitize_text_field( $atts['type'] );
		}

		$equipment = $store->query_items( $query_args );

		if ( empty( $equipment ) ) {
			return $this->render_empty( __( 'No equipment found.', 'mcp-ai-wpoos-pro' ) );
		}

		ob_start();
		$this->render_equipment_list( $equipment );
		return ob_get_clean();
	}

	/**
	 * Render DJ packages.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string Rendered HTML.
	 */
	public function render_dj_packages( $atts ) {
		$atts = shortcode_atts(
			array(
				'columns' => 3,
			),
			$atts,
			'mcp_dj_packages'
		);

		wp_enqueue_style( self::STYLE_HANDLE );
		wp_enqueue_script( self::SCRIPT_HANDLE );

		$store = $this->get_data_store( 'dj-management', 'packages' );
		if ( ! $store ) {
			return $this->render_error( __( 'DJ Management toolkit is not available.', 'mcp-ai-wpoos-pro' ) );
		}

		$packages = $store->query_items( array( 'per_page' => 50 ) );

		if ( empty( $packages ) ) {
			return $this->render_empty( __( 'No packages found.', 'mcp-ai-wpoos-pro' ) );
		}

		ob_start();
		$this->render_packages_grid( $packages, $atts );
		return ob_get_clean();
	}

	/**
	 * Render Financial Planner budget overview.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string Rendered HTML.
	 */
	public function render_financial_budget( $atts ) {
		$atts = shortcode_atts(
			array(
				'type' => '',
			),
			$atts,
			'mcp_financial_budget'
		);

		wp_enqueue_style( self::STYLE_HANDLE );
		wp_enqueue_script( self::SCRIPT_HANDLE );

		$store = $this->get_data_store( 'financial-planner', 'budget-categories' );
		if ( ! $store ) {
			return $this->render_error( __( 'Financial Planner toolkit is not available.', 'mcp-ai-wpoos-pro' ) );
		}

		$query_args = array(
			'per_page' => 50,
			'orderby'  => 'title',
			'order'    => 'ASC',
		);

		if ( ! empty( $atts['type'] ) ) {
			$query_args['category_type'] = sanitize_text_field( $atts['type'] );
		}

		$categories = $store->query_items( $query_args );

		if ( empty( $categories ) ) {
			return $this->render_empty( __( 'No budget categories found.', 'mcp-ai-wpoos-pro' ) );
		}

		ob_start();
		$this->render_budget_table( $categories );
		return ob_get_clean();
	}

	/**
	 * Render Financial Planner goals tracker.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string Rendered HTML.
	 */
	public function render_financial_goals( $atts ) {
		$atts = shortcode_atts(
			array(
				'type'    => '',
				'display' => 'list',
			),
			$atts,
			'mcp_financial_goals'
		);

		wp_enqueue_style( self::STYLE_HANDLE );
		wp_enqueue_script( self::SCRIPT_HANDLE );

		$store = $this->get_data_store( 'financial-planner', 'goal-templates' );
		if ( ! $store ) {
			return $this->render_error( __( 'Financial Planner toolkit is not available.', 'mcp-ai-wpoos-pro' ) );
		}

		$query_args = array(
			'per_page' => 50,
			'orderby'  => 'title',
			'order'    => 'ASC',
		);

		if ( ! empty( $atts['type'] ) ) {
			$query_args['goal_type'] = sanitize_text_field( $atts['type'] );
		}

		$goals = $store->query_items( $query_args );

		if ( empty( $goals ) ) {
			return $this->render_empty( __( 'No financial goals found.', 'mcp-ai-wpoos-pro' ) );
		}

		ob_start();
		$this->render_goals_list( $goals );
		return ob_get_clean();
	}

	/**
	 * Render Multilingual Translation Memory.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string Rendered HTML.
	 */
	public function render_multilingual_translation_memory( $atts ) {
		$atts = shortcode_atts(
			array(
				'display'           => 'list',
				'source_language'   => '',
				'target_language'   => '',
				'limit'             => 20,
				'quality_score_min' => 0,
			),
			$atts,
			'mcp_multilingual_translation_memory'
		);

		wp_enqueue_style( self::STYLE_HANDLE );
		wp_enqueue_script( self::SCRIPT_HANDLE );

		$store = $this->get_data_store( 'multilingual', 'translation-memory' );
		if ( ! $store ) {
			return $this->render_error( __( 'Multilingual toolkit is not available.', 'mcp-ai-wpoos-pro' ) );
		}

		$query_args = array(
			'per_page' => absint( $atts['limit'] ),
			'orderby'  => 'quality_score',
			'order'    => 'DESC',
		);

		if ( ! empty( $atts['source_language'] ) ) {
			$query_args['source_language'] = sanitize_text_field( $atts['source_language'] );
		}

		if ( ! empty( $atts['target_language'] ) ) {
			$query_args['target_language'] = sanitize_text_field( $atts['target_language'] );
		}

		if ( ! empty( $atts['quality_score_min'] ) ) {
			$query_args['quality_score_min'] = floatval( $atts['quality_score_min'] );
		}

		$translations = $store->query_items( $query_args );

		if ( empty( $translations ) ) {
			return $this->render_empty( __( 'No translations found.', 'mcp-ai-wpoos-pro' ) );
		}

		ob_start();
		$this->render_translation_memory_view( $translations, $atts );
		return ob_get_clean();
	}

	/**
	 * Render Multilingual Glossaries.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string Rendered HTML.
	 */
	public function render_multilingual_glossaries( $atts ) {
		$atts = shortcode_atts(
			array(
				'display'  => 'list',
				'industry' => '',
				'limit'    => 50,
			),
			$atts,
			'mcp_multilingual_glossaries'
		);

		wp_enqueue_style( self::STYLE_HANDLE );
		wp_enqueue_script( self::SCRIPT_HANDLE );

		$store = $this->get_data_store( 'multilingual', 'glossaries' );
		if ( ! $store ) {
			return $this->render_error( __( 'Multilingual toolkit is not available.', 'mcp-ai-wpoos-pro' ) );
		}

		$query_args = array(
			'per_page' => absint( $atts['limit'] ),
			'orderby'  => 'term',
			'order'    => 'ASC',
		);

		if ( ! empty( $atts['industry'] ) ) {
			$query_args['industry'] = sanitize_text_field( $atts['industry'] );
		}

		$glossaries = $store->query_items( $query_args );

		if ( empty( $glossaries ) ) {
			return $this->render_empty( __( 'No glossary terms found.', 'mcp-ai-wpoos-pro' ) );
		}

		ob_start();
		$this->render_glossaries_view( $glossaries, $atts );
		return ob_get_clean();
	}

	/**
	 * Render AI Tool Builder Templates Showcase.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string Rendered HTML.
	 */
	public function render_ai_tool_builder_templates( $atts ) {
		$atts = shortcode_atts(
			array(
				'display'  => 'grid',
				'category' => '',
				'limit'    => 12,
			),
			$atts,
			'mcp_ai_tool_builder_templates'
		);

		wp_enqueue_style( self::STYLE_HANDLE );
		wp_enqueue_script( self::SCRIPT_HANDLE );

		$store = $this->get_data_store( 'ai-tool-builder', 'tool-templates' );
		if ( ! $store ) {
			return $this->render_error( __( 'AI Tool Builder toolkit is not available.', 'mcp-ai-wpoos-pro' ) );
		}

		$query_args = array(
			'per_page' => absint( $atts['limit'] ),
			'orderby'  => 'tool_name',
			'order'    => 'ASC',
		);

		if ( ! empty( $atts['category'] ) ) {
			$query_args['category'] = sanitize_text_field( $atts['category'] );
		}

		$templates = $store->query_items( $query_args );

		if ( empty( $templates ) ) {
			return $this->render_empty( __( 'No tool templates found.', 'mcp-ai-wpoos-pro' ) );
		}

		ob_start();
		$this->render_tool_templates_view( $templates, $atts );
		return ob_get_clean();
	}

	/**
	 * Render AI Tool Builder Parameter Schemas.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string Rendered HTML.
	 */
	public function render_ai_tool_builder_schemas( $atts ) {
		$atts = shortcode_atts(
			array(
				'display' => 'list',
				'limit'   => 30,
			),
			$atts,
			'mcp_ai_tool_builder_schemas'
		);

		wp_enqueue_style( self::STYLE_HANDLE );
		wp_enqueue_script( self::SCRIPT_HANDLE );

		$store = $this->get_data_store( 'ai-tool-builder', 'parameter-schemas' );
		if ( ! $store ) {
			return $this->render_error( __( 'AI Tool Builder toolkit is not available.', 'mcp-ai-wpoos-pro' ) );
		}

		$query_args = array(
			'per_page' => absint( $atts['limit'] ),
			'orderby'  => 'schema_name',
			'order'    => 'ASC',
		);

		$schemas = $store->query_items( $query_args );

		if ( empty( $schemas ) ) {
			return $this->render_empty( __( 'No parameter schemas found.', 'mcp-ai-wpoos-pro' ) );
		}

		ob_start();
		$this->render_schemas_view( $schemas, $atts );
		return ob_get_clean();
	}

	/**
	 * Get data store instance.
	 *
	 * @param string $toolkit Toolkit slug.
	 * @param string $entity  Entity type.
	 * @return WP_MCP_AI_Toolkit_Data_Store|null Data store instance or null.
	 */
	protected function get_data_store( $toolkit, $entity ) {
		if ( ! class_exists( 'WP_MCP_AI_Toolkit_Data_Store_Factory' ) ) {
			return null;
		}

		return WP_MCP_AI_Toolkit_Data_Store_Factory::get_store( $toolkit, $entity );
	}

	/**
	 * Render error message.
	 *
	 * @param string $message Error message.
	 * @return string Rendered HTML.
	 */
	protected function render_error( $message ) {
		return '<div class="mcp-ai-widget-error">' . esc_html( $message ) . '</div>';
	}

	/**
	 * Render empty state message.
	 *
	 * @param string $message Empty state message.
	 * @return string Rendered HTML.
	 */
	protected function render_empty( $message ) {
		return '<div class="mcp-ai-widget-empty">' . esc_html( $message ) . '</div>';
	}

	/**
	 * Render products grid.
	 *
	 * @param array $products Products data.
	 * @param array $atts     Shortcode attributes.
	 */
	protected function render_products_grid( $products, $atts ) {
		$display = sanitize_html_class( $atts['display'] );
		$columns = absint( $atts['columns'] );

		echo '<div class="mcp-ai-products mcp-ai-display-' . esc_attr( $display ) . ' mcp-ai-columns-' . esc_attr( $columns ) . '">';

		foreach ( $products as $product ) {
			$name  = isset( $product['product_name'] ) ? $product['product_name'] : '';
			$price = isset( $product['price'] ) ? $product['price'] : '';
			$sku   = isset( $product['sku'] ) ? $product['sku'] : '';
			$image = isset( $product['image_url'] ) ? $product['image_url'] : '';

			echo '<div class="mcp-ai-product">';

			if ( $image ) {
				echo '<div class="mcp-ai-product-image">';
				echo '<img src="' . esc_url( $image ) . '" alt="' . esc_attr( $name ) . '" />';
				echo '</div>';
			}

			echo '<div class="mcp-ai-product-content">';
			echo '<h3 class="mcp-ai-product-name">' . esc_html( $name ) . '</h3>';

			if ( $sku ) {
				echo '<div class="mcp-ai-product-sku">' . esc_html__( 'SKU:', 'mcp-ai-wpoos-pro' ) . ' ' . esc_html( $sku ) . '</div>';
			}

			if ( $price ) {
				echo '<div class="mcp-ai-product-price">$' . esc_html( number_format( (float) $price, 2 ) ) . '</div>';
			}
			echo '</div>';

			echo '</div>';
		}

		echo '</div>';
	}

	/**
	 * Render orders table.
	 *
	 * @param array $orders Orders data.
	 */
	protected function render_orders_table( $orders ) {
		echo '<div class="mcp-ai-orders-table-wrap">';
		echo '<table class="mcp-ai-orders-table">';
		echo '<thead>';
		echo '<tr>';
		echo '<th>' . esc_html__( 'Order #', 'mcp-ai-wpoos-pro' ) . '</th>';
		echo '<th>' . esc_html__( 'Date', 'mcp-ai-wpoos-pro' ) . '</th>';
		echo '<th>' . esc_html__( 'Total', 'mcp-ai-wpoos-pro' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'mcp-ai-wpoos-pro' ) . '</th>';
		echo '</tr>';
		echo '</thead>';
		echo '<tbody>';

		foreach ( $orders as $order ) {
			$order_number = isset( $order['order_number'] ) ? $order['order_number'] : '';
			$date         = isset( $order['created_at'] ) ? $order['created_at'] : '';
			$total        = isset( $order['total_amount'] ) ? $order['total_amount'] : '';
			$status       = isset( $order['status'] ) ? $order['status'] : '';

			echo '<tr>';
			echo '<td>' . esc_html( $order_number ) . '</td>';
			echo '<td>' . esc_html( date_i18n( get_option( 'date_format' ), strtotime( $date ) ) ) . '</td>';
			echo '<td>$' . esc_html( number_format( (float) $total, 2 ) ) . '</td>';
			echo '<td class="mcp-ai-order-status mcp-ai-status-' . esc_attr( sanitize_html_class( $status ) ) . '">' . esc_html( ucfirst( $status ) ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody>';
		echo '</table>';
		echo '</div>';
	}

	/**
	 * Render calendar view.
	 *
	 * @param array $posts Calendar posts.
	 * @param array $atts  Shortcode attributes.
	 */
	protected function render_calendar_view( $posts, $atts ) {
		echo '<div class="mcp-ai-social-calendar">';

		foreach ( $posts as $post ) {
			$title    = isset( $post['post_title'] ) ? $post['post_title'] : '';
			$platform = isset( $post['platform'] ) ? $post['platform'] : '';
			$date     = isset( $post['scheduled_date'] ) ? $post['scheduled_date'] : '';
			$status   = isset( $post['status'] ) ? $post['status'] : '';

			echo '<div class="mcp-ai-calendar-post mcp-ai-status-' . esc_attr( sanitize_html_class( $status ) ) . '">';
			echo '<div class="mcp-ai-calendar-post-header">';
			echo '<span class="mcp-ai-calendar-platform">' . esc_html( ucfirst( $platform ) ) . '</span>';
			echo '<span class="mcp-ai-calendar-date">' . esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $date ) ) ) . '</span>';
			echo '</div>';
			echo '<h4 class="mcp-ai-calendar-title">' . esc_html( $title ) . '</h4>';
			echo '</div>';
		}

		echo '</div>';
	}

	/**
	 * Render templates grid.
	 *
	 * @param array $templates Template data.
	 * @param array $atts      Shortcode attributes.
	 */
	protected function render_templates_grid( $templates, $atts ) {
		$columns = absint( $atts['columns'] );

		echo '<div class="mcp-ai-templates mcp-ai-columns-' . esc_attr( $columns ) . '">';

		foreach ( $templates as $template ) {
			$name     = isset( $template['template_name'] ) ? $template['template_name'] : '';
			$platform = isset( $template['platform'] ) ? $template['platform'] : '';
			$content  = isset( $template['template_content'] ) ? $template['template_content'] : '';

			echo '<div class="mcp-ai-template">';
			echo '<h4 class="mcp-ai-template-name">' . esc_html( $name ) . '</h4>';
			echo '<div class="mcp-ai-template-platform">' . esc_html( ucfirst( $platform ) ) . '</div>';
			echo '<div class="mcp-ai-template-preview">' . esc_html( wp_trim_words( $content, 20 ) ) . '</div>';
			echo '</div>';
		}

		echo '</div>';
	}

	/**
	 * Render services grid.
	 *
	 * @param array $services Services data.
	 * @param array $atts     Shortcode attributes.
	 */
	protected function render_services_grid( $services, $atts ) {
		$columns = absint( $atts['columns'] );

		echo '<div class="mcp-ai-services mcp-ai-columns-' . esc_attr( $columns ) . '">';

		foreach ( $services as $service ) {
			$name        = isset( $service['service_name'] ) ? $service['service_name'] : '';
			$description = isset( $service['description'] ) ? $service['description'] : '';
			$duration    = isset( $service['duration'] ) ? $service['duration'] : '';
			$price       = isset( $service['price'] ) ? $service['price'] : '';

			echo '<div class="mcp-ai-service">';
			echo '<h4 class="mcp-ai-service-name">' . esc_html( $name ) . '</h4>';
			echo '<div class="mcp-ai-service-description">' . esc_html( $description ) . '</div>';
			echo '<div class="mcp-ai-service-meta">';
			if ( $duration ) {
				echo '<span class="mcp-ai-service-duration">' . esc_html( $duration ) . ' ' . esc_html__( 'minutes', 'mcp-ai-wpoos-pro' ) . '</span>';
			}
			if ( $price ) {
				echo '<span class="mcp-ai-service-price">$' . esc_html( number_format( (float) $price, 2 ) ) . '</span>';
			}
			echo '</div>';
			echo '</div>';
		}

		echo '</div>';
	}

	/**
	 * Render staff grid.
	 *
	 * @param array $staff Staff data.
	 * @param array $atts  Shortcode attributes.
	 */
	protected function render_staff_grid( $staff, $atts ) {
		$columns = absint( $atts['columns'] );

		echo '<div class="mcp-ai-staff mcp-ai-columns-' . esc_attr( $columns ) . '">';

		foreach ( $staff as $member ) {
			$name  = isset( $member['staff_name'] ) ? $member['staff_name'] : '';
			$email = isset( $member['email'] ) ? $member['email'] : '';
			$bio   = isset( $member['bio'] ) ? $member['bio'] : '';

			echo '<div class="mcp-ai-staff-member">';
			echo '<h4 class="mcp-ai-staff-name">' . esc_html( $name ) . '</h4>';
			if ( $email ) {
				echo '<a href="' . esc_url( 'mailto:' . $email ) . '" class="mcp-ai-staff-email">' . esc_html( $email ) . '</a>';
			}
			if ( $bio ) {
				echo '<div class="mcp-ai-staff-bio">' . esc_html( wp_trim_words( $bio, 30 ) ) . '</div>';
			}
			echo '</div>';
		}

		echo '</div>';
	}

	/**
	 * Render equipment list.
	 *
	 * @param array $equipment Equipment data.
	 */
	protected function render_equipment_list( $equipment ) {
		echo '<div class="mcp-ai-equipment-list">';

		foreach ( $equipment as $item ) {
			$name      = isset( $item['equipment_name'] ) ? $item['equipment_name'] : '';
			$type      = isset( $item['equipment_type'] ) ? $item['equipment_type'] : '';
			$quantity  = isset( $item['quantity'] ) ? $item['quantity'] : '';
			$condition = isset( $item['condition'] ) ? $item['condition'] : '';
			$price     = isset( $item['rental_price'] ) ? $item['rental_price'] : '';

			echo '<div class="mcp-ai-equipment-item">';
			echo '<h4 class="mcp-ai-equipment-name">' . esc_html( $name ) . '</h4>';
			echo '<div class="mcp-ai-equipment-meta">';
			echo '<span class="mcp-ai-equipment-type">' . esc_html( ucfirst( $type ) ) . '</span>';
			if ( $quantity ) {
				echo '<span class="mcp-ai-equipment-quantity">' . esc_html__( 'Qty:', 'mcp-ai-wpoos-pro' ) . ' ' . absint( $quantity ) . '</span>';
			}
			if ( $condition ) {
				echo '<span class="mcp-ai-equipment-condition">' . esc_html( ucfirst( $condition ) ) . '</span>';
			}
			if ( $price ) {
				echo '<span class="mcp-ai-equipment-price">$' . esc_html( number_format( (float) $price, 2 ) ) . '/day</span>';
			}
			echo '</div>';
			echo '</div>';
		}

		echo '</div>';
	}

	/**
	 * Render packages grid.
	 *
	 * @param array $packages Packages data.
	 * @param array $atts     Shortcode attributes.
	 */
	protected function render_packages_grid( $packages, $atts ) {
		$columns = absint( $atts['columns'] );

		echo '<div class="mcp-ai-packages mcp-ai-columns-' . esc_attr( $columns ) . '">';

		foreach ( $packages as $package ) {
			$name        = isset( $package['package_name'] ) ? $package['package_name'] : '';
			$description = isset( $package['description'] ) ? $package['description'] : '';
			$price       = isset( $package['price'] ) ? $package['price'] : '';
			$duration    = isset( $package['duration'] ) ? $package['duration'] : '';

			echo '<div class="mcp-ai-package">';
			echo '<h4 class="mcp-ai-package-name">' . esc_html( $name ) . '</h4>';
			echo '<div class="mcp-ai-package-description">' . esc_html( $description ) . '</div>';
			echo '<div class="mcp-ai-package-meta">';
			if ( $duration ) {
				echo '<span class="mcp-ai-package-duration">' . absint( $duration ) . ' ' . esc_html__( 'hours', 'mcp-ai-wpoos-pro' ) . '</span>';
			}
			if ( $price ) {
				echo '<span class="mcp-ai-package-price">$' . esc_html( number_format( (float) $price, 2 ) ) . '</span>';
			}
			echo '</div>';
			echo '</div>';
		}

		echo '</div>';
	}

	/**
	 * Render budget table.
	 *
	 * @param array $categories Budget categories.
	 */
	protected function render_budget_table( $categories ) {
		echo '<div class="mcp-ai-budget-table-wrap">';
		echo '<table class="mcp-ai-budget-table">';
		echo '<thead>';
		echo '<tr>';
		echo '<th>' . esc_html__( 'Category', 'mcp-ai-wpoos-pro' ) . '</th>';
		echo '<th>' . esc_html__( 'Type', 'mcp-ai-wpoos-pro' ) . '</th>';
		echo '<th>' . esc_html__( 'Amount', 'mcp-ai-wpoos-pro' ) . '</th>';
		echo '<th>' . esc_html__( 'Frequency', 'mcp-ai-wpoos-pro' ) . '</th>';
		echo '</tr>';
		echo '</thead>';
		echo '<tbody>';

		foreach ( $categories as $category ) {
			$name      = isset( $category['category_name'] ) ? $category['category_name'] : '';
			$type      = isset( $category['category_type'] ) ? $category['category_type'] : '';
			$amount    = isset( $category['amount'] ) ? $category['amount'] : '';
			$frequency = isset( $category['frequency'] ) ? $category['frequency'] : '';

			echo '<tr>';
			echo '<td>' . esc_html( $name ) . '</td>';
			echo '<td>' . esc_html( ucfirst( $type ) ) . '</td>';
			echo '<td>$' . esc_html( number_format( (float) $amount, 2 ) ) . '</td>';
			echo '<td>' . esc_html( ucfirst( $frequency ) ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody>';
		echo '</table>';
		echo '</div>';
	}

	/**
	 * Render goals list.
	 *
	 * @param array $goals Goals data.
	 */
	protected function render_goals_list( $goals ) {
		echo '<div class="mcp-ai-goals-list">';

		foreach ( $goals as $goal ) {
			$name     = isset( $goal['goal_name'] ) ? $goal['goal_name'] : '';
			$type     = isset( $goal['goal_type'] ) ? $goal['goal_type'] : '';
			$target   = isset( $goal['target_amount'] ) ? $goal['target_amount'] : '';
			$deadline = isset( $goal['deadline'] ) ? $goal['deadline'] : '';
			$priority = isset( $goal['priority'] ) ? $goal['priority'] : '';

			echo '<div class="mcp-ai-goal mcp-ai-priority-' . esc_attr( sanitize_html_class( $priority ) ) . '">';
			echo '<h4 class="mcp-ai-goal-name">' . esc_html( $name ) . '</h4>';
			echo '<div class="mcp-ai-goal-meta">';
			echo '<span class="mcp-ai-goal-type">' . esc_html( ucfirst( str_replace( '_', ' ', $type ) ) ) . '</span>';
			if ( $target ) {
				echo '<span class="mcp-ai-goal-target">$' . esc_html( number_format( (float) $target, 2 ) ) . '</span>';
			}
			if ( $deadline ) {
				echo '<span class="mcp-ai-goal-deadline">' . esc_html( date_i18n( get_option( 'date_format' ), strtotime( $deadline ) ) ) . '</span>';
			}
			echo '</div>';
			echo '</div>';
		}

		echo '</div>';
	}

	/**
	 * Render translation memory view.
	 *
	 * @param array $translations Translation memory data.
	 * @param array $atts         Shortcode attributes.
	 */
	protected function render_translation_memory_view( $translations, $atts ) {
		$display = sanitize_html_class( $atts['display'] );

		if ( 'table' === $display ) {
			echo '<div class="mcp-ai-translation-memory-table-wrap">';
			echo '<table class="mcp-ai-translation-memory-table">';
			echo '<thead>';
			echo '<tr>';
			echo '<th>' . esc_html__( 'Source', 'mcp-ai-wpoos-pro' ) . '</th>';
			echo '<th>' . esc_html__( 'Translation', 'mcp-ai-wpoos-pro' ) . '</th>';
			echo '<th>' . esc_html__( 'Languages', 'mcp-ai-wpoos-pro' ) . '</th>';
			echo '<th>' . esc_html__( 'Quality', 'mcp-ai-wpoos-pro' ) . '</th>';
			echo '</tr>';
			echo '</thead>';
			echo '<tbody>';

			foreach ( $translations as $translation ) {
				$source_text   = isset( $translation['source_text'] ) ? $translation['source_text'] : '';
				$translated    = isset( $translation['translated_text'] ) ? $translation['translated_text'] : '';
				$source_lang   = isset( $translation['source_language'] ) ? $translation['source_language'] : '';
				$target_lang   = isset( $translation['target_language'] ) ? $translation['target_language'] : '';
				$quality_score = isset( $translation['quality_score'] ) ? $translation['quality_score'] : 0;

				echo '<tr>';
				echo '<td>' . esc_html( $source_text ) . '</td>';
				echo '<td>' . esc_html( $translated ) . '</td>';
				echo '<td>' . esc_html( strtoupper( $source_lang ) . ' → ' . strtoupper( $target_lang ) ) . '</td>';
				echo '<td>' . esc_html( number_format( (float) $quality_score, 2 ) ) . '</td>';
				echo '</tr>';
			}

			echo '</tbody>';
			echo '</table>';
			echo '</div>';
		} else {
			echo '<div class="mcp-ai-translation-memory mcp-ai-display-' . esc_attr( $display ) . '">';

			foreach ( $translations as $translation ) {
				$source_text   = isset( $translation['source_text'] ) ? $translation['source_text'] : '';
				$translated    = isset( $translation['translated_text'] ) ? $translation['translated_text'] : '';
				$source_lang   = isset( $translation['source_language'] ) ? $translation['source_language'] : '';
				$target_lang   = isset( $translation['target_language'] ) ? $translation['target_language'] : '';
				$quality_score = isset( $translation['quality_score'] ) ? $translation['quality_score'] : 0;
				$context       = isset( $translation['context'] ) ? $translation['context'] : '';

				echo '<div class="mcp-ai-translation-item">';
				echo '<div class="mcp-ai-translation-source">';
				echo '<span class="mcp-ai-translation-lang">' . esc_html( strtoupper( $source_lang ) ) . '</span>';
				echo '<p>' . esc_html( $source_text ) . '</p>';
				echo '</div>';
				echo '<div class="mcp-ai-translation-arrow">→</div>';
				echo '<div class="mcp-ai-translation-target">';
				echo '<span class="mcp-ai-translation-lang">' . esc_html( strtoupper( $target_lang ) ) . '</span>';
				echo '<p>' . esc_html( $translated ) . '</p>';
				echo '</div>';
				echo '<div class="mcp-ai-translation-meta">';
				echo '<span class="mcp-ai-translation-quality">' . esc_html__( 'Quality:', 'mcp-ai-wpoos-pro' ) . ' ' . esc_html( number_format( (float) $quality_score, 2 ) ) . '</span>';
				if ( $context ) {
					echo '<span class="mcp-ai-translation-context">' . esc_html( $context ) . '</span>';
				}
				echo '</div>';
				echo '</div>';
			}

			echo '</div>';
		}
	}

	/**
	 * Render glossaries view.
	 *
	 * @param array $glossaries Glossary data.
	 * @param array $atts       Shortcode attributes.
	 */
	protected function render_glossaries_view( $glossaries, $atts ) {
		$display = sanitize_html_class( $atts['display'] );

		if ( 'table' === $display ) {
			echo '<div class="mcp-ai-glossaries-table-wrap">';
			echo '<table class="mcp-ai-glossaries-table">';
			echo '<thead>';
			echo '<tr>';
			echo '<th>' . esc_html__( 'Term', 'mcp-ai-wpoos-pro' ) . '</th>';
			echo '<th>' . esc_html__( 'Definition', 'mcp-ai-wpoos-pro' ) . '</th>';
			echo '<th>' . esc_html__( 'Industry', 'mcp-ai-wpoos-pro' ) . '</th>';
			echo '<th>' . esc_html__( 'Context', 'mcp-ai-wpoos-pro' ) . '</th>';
			echo '</tr>';
			echo '</thead>';
			echo '<tbody>';

			foreach ( $glossaries as $glossary ) {
				$term       = isset( $glossary['term'] ) ? $glossary['term'] : '';
				$definition = isset( $glossary['definition'] ) ? $glossary['definition'] : '';
				$industry   = isset( $glossary['industry'] ) ? $glossary['industry'] : '';
				$context    = isset( $glossary['context'] ) ? $glossary['context'] : '';

				echo '<tr>';
				echo '<td><strong>' . esc_html( $term ) . '</strong></td>';
				echo '<td>' . esc_html( $definition ) . '</td>';
				echo '<td>' . esc_html( ucfirst( $industry ) ) . '</td>';
				echo '<td>' . esc_html( $context ) . '</td>';
				echo '</tr>';
			}

			echo '</tbody>';
			echo '</table>';
			echo '</div>';
		} else {
			echo '<div class="mcp-ai-glossaries mcp-ai-display-' . esc_attr( $display ) . '">';

			foreach ( $glossaries as $glossary ) {
				$term             = isset( $glossary['term'] ) ? $glossary['term'] : '';
				$definition       = isset( $glossary['definition'] ) ? $glossary['definition'] : '';
				$industry         = isset( $glossary['industry'] ) ? $glossary['industry'] : '';
				$context          = isset( $glossary['context'] ) ? $glossary['context'] : '';
				$translations_raw = isset( $glossary['translations_json'] ) ? $glossary['translations_json'] : '';
				$tags             = isset( $glossary['tags'] ) ? $glossary['tags'] : '';

				echo '<div class="mcp-ai-glossary-item">';
				echo '<h4 class="mcp-ai-glossary-term">' . esc_html( $term ) . '</h4>';
				echo '<div class="mcp-ai-glossary-definition">' . esc_html( $definition ) . '</div>';

				if ( $context ) {
					echo '<div class="mcp-ai-glossary-context"><em>' . esc_html( $context ) . '</em></div>';
				}

				echo '<div class="mcp-ai-glossary-meta">';
				if ( $industry ) {
					echo '<span class="mcp-ai-glossary-industry">' . esc_html__( 'Industry:', 'mcp-ai-wpoos-pro' ) . ' ' . esc_html( ucfirst( $industry ) ) . '</span>';
				}
				if ( $tags ) {
					echo '<span class="mcp-ai-glossary-tags">' . esc_html( $tags ) . '</span>';
				}
				echo '</div>';

				if ( $translations_raw ) {
					$translations = json_decode( $translations_raw, true );
					if ( is_array( $translations ) && ! empty( $translations ) ) {
						echo '<div class="mcp-ai-glossary-translations">';
						echo '<strong>' . esc_html__( 'Translations:', 'mcp-ai-wpoos-pro' ) . '</strong> ';
						$trans_items = array();
						foreach ( $translations as $lang => $trans ) {
							$trans_items[] = esc_html( strtoupper( $lang ) . ': ' . $trans );
						}
						echo esc_html( implode( ', ', $trans_items ) );
						echo '</div>';
					}
				}

				echo '</div>';
			}

			echo '</div>';
		}
	}

	/**
	 * Render tool templates view.
	 *
	 * @param array $templates Tool template data.
	 * @param array $atts      Shortcode attributes.
	 */
	protected function render_tool_templates_view( $templates, $atts ) {
		$display = sanitize_html_class( $atts['display'] );
		$columns = ( 'grid' === $display ) ? 3 : 1;

		echo '<div class="mcp-ai-tool-templates mcp-ai-display-' . esc_attr( $display ) . ' mcp-ai-columns-' . esc_attr( $columns ) . '">';

		foreach ( $templates as $template ) {
			$tool_name     = isset( $template['tool_name'] ) ? $template['tool_name'] : '';
			$tool_slug     = isset( $template['tool_slug'] ) ? $template['tool_slug'] : '';
			$description   = isset( $template['description'] ) ? $template['description'] : '';
			$category      = isset( $template['category'] ) ? $template['category'] : '';
			$required_cap  = isset( $template['required_capability'] ) ? $template['required_capability'] : '';
			$code_template = isset( $template['code_template'] ) ? $template['code_template'] : '';

			echo '<div class="mcp-ai-tool-template">';
			echo '<h4 class="mcp-ai-tool-template-name">' . esc_html( $tool_name ) . '</h4>';
			if ( $tool_slug ) {
				echo '<div class="mcp-ai-tool-template-slug"><code>' . esc_html( $tool_slug ) . '</code></div>';
			}
			echo '<div class="mcp-ai-tool-template-description">' . esc_html( $description ) . '</div>';

			echo '<div class="mcp-ai-tool-template-meta">';
			if ( $category ) {
				echo '<span class="mcp-ai-tool-template-category">' . esc_html__( 'Category:', 'mcp-ai-wpoos-pro' ) . ' ' . esc_html( ucfirst( $category ) ) . '</span>';
			}
			if ( $required_cap ) {
				echo '<span class="mcp-ai-tool-template-capability">' . esc_html__( 'Required:', 'mcp-ai-wpoos-pro' ) . ' ' . esc_html( $required_cap ) . '</span>';
			}
			echo '</div>';

			if ( 'list' === $display && $code_template ) {
				echo '<details class="mcp-ai-tool-template-code">';
				echo '<summary>' . esc_html__( 'View Code Template', 'mcp-ai-wpoos-pro' ) . '</summary>';
				echo '<pre><code>' . esc_html( $code_template ) . '</code></pre>';
				echo '</details>';
			}

			echo '</div>';
		}

		echo '</div>';
	}

	/**
	 * Render parameter schemas view.
	 *
	 * @param array $schemas Schema data.
	 * @param array $atts    Shortcode attributes.
	 */
	protected function render_schemas_view( $schemas, $atts ) {
		$display = sanitize_html_class( $atts['display'] );

		if ( 'table' === $display ) {
			echo '<div class="mcp-ai-schemas-table-wrap">';
			echo '<table class="mcp-ai-schemas-table">';
			echo '<thead>';
			echo '<tr>';
			echo '<th>' . esc_html__( 'Schema Name', 'mcp-ai-wpoos-pro' ) . '</th>';
			echo '<th>' . esc_html__( 'Description', 'mcp-ai-wpoos-pro' ) . '</th>';
			echo '<th>' . esc_html__( 'Validation Rules', 'mcp-ai-wpoos-pro' ) . '</th>';
			echo '</tr>';
			echo '</thead>';
			echo '<tbody>';

			foreach ( $schemas as $schema ) {
				$schema_name      = isset( $schema['schema_name'] ) ? $schema['schema_name'] : '';
				$description      = isset( $schema['description'] ) ? $schema['description'] : '';
				$validation_rules = isset( $schema['validation_rules'] ) ? $schema['validation_rules'] : '';

				echo '<tr>';
				echo '<td><strong>' . esc_html( $schema_name ) . '</strong></td>';
				echo '<td>' . esc_html( $description ) . '</td>';
				echo '<td>' . esc_html( $validation_rules ) . '</td>';
				echo '</tr>';
			}

			echo '</tbody>';
			echo '</table>';
			echo '</div>';
		} else {
			echo '<div class="mcp-ai-schemas mcp-ai-display-' . esc_attr( $display ) . '">';

			foreach ( $schemas as $schema ) {
				$schema_name      = isset( $schema['schema_name'] ) ? $schema['schema_name'] : '';
				$description      = isset( $schema['description'] ) ? $schema['description'] : '';
				$json_schema      = isset( $schema['json_schema'] ) ? $schema['json_schema'] : '';
				$validation_rules = isset( $schema['validation_rules'] ) ? $schema['validation_rules'] : '';
				$example_usage    = isset( $schema['example_usage'] ) ? $schema['example_usage'] : '';
				$tags             = isset( $schema['tags'] ) ? $schema['tags'] : '';

				echo '<div class="mcp-ai-schema-item">';
				echo '<h4 class="mcp-ai-schema-name">' . esc_html( $schema_name ) . '</h4>';
				echo '<div class="mcp-ai-schema-description">' . esc_html( $description ) . '</div>';

				if ( $validation_rules ) {
					echo '<div class="mcp-ai-schema-validation"><strong>' . esc_html__( 'Validation:', 'mcp-ai-wpoos-pro' ) . '</strong> ' . esc_html( $validation_rules ) . '</div>';
				}

				if ( $tags ) {
					echo '<div class="mcp-ai-schema-tags">' . esc_html( $tags ) . '</div>';
				}

				if ( $json_schema ) {
					echo '<details class="mcp-ai-schema-json">';
					echo '<summary>' . esc_html__( 'View JSON Schema', 'mcp-ai-wpoos-pro' ) . '</summary>';
					echo '<pre><code>' . esc_html( $json_schema ) . '</code></pre>';
					echo '</details>';
				}

				if ( $example_usage ) {
					echo '<details class="mcp-ai-schema-example">';
					echo '<summary>' . esc_html__( 'View Example', 'mcp-ai-wpoos-pro' ) . '</summary>';
					echo '<pre><code>' . esc_html( $example_usage ) . '</code></pre>';
					echo '</details>';
				}

				echo '</div>';
			}

			echo '</div>';
		}
	}

	/**
	 * Render Media Templates gallery.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string Rendered HTML.
	 */
	public function media_templates_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'display'  => 'grid',
				'columns'  => 3,
				'limit'    => 9,
				'category' => '',
				'orderby'  => 'date',
				'order'    => 'desc',
			),
			$atts,
			'mcp_media_templates'
		);

		wp_enqueue_style( self::STYLE_HANDLE );
		wp_enqueue_script( self::SCRIPT_HANDLE );

		// Build query args for media templates CPT.
		$query_args = array(
			'post_type'      => 'mcp_ai_media_tpl',
			'posts_per_page' => absint( $atts['limit'] ),
			'orderby'        => sanitize_key( $atts['orderby'] ),
			'order'          => sanitize_key( $atts['order'] ),
			'post_status'    => 'publish',
		);

		// Add category filter if specified.
		if ( ! empty( $atts['category'] ) ) {
			$query_args['tax_query'] = array(
				array(
					'taxonomy' => 'mcp_ai_tpl_category',
					'field'    => 'slug',
					'terms'    => sanitize_text_field( $atts['category'] ),
				),
			);
		}

		$templates = get_posts( $query_args );

		if ( empty( $templates ) ) {
			return $this->render_empty( __( 'No media templates found.', 'mcp-ai-wpoos-pro' ) );
		}

		ob_start();
		$this->render_media_templates_view( $templates, $atts );
		return ob_get_clean();
	}

	/**
	 * Render Media Collections gallery.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string Rendered HTML.
	 */
	public function media_collections_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'display' => 'grid',
				'columns' => 3,
				'limit'   => 9,
				'orderby' => 'date',
				'order'   => 'desc',
			),
			$atts,
			'mcp_media_collections'
		);

		wp_enqueue_style( self::STYLE_HANDLE );
		wp_enqueue_script( self::SCRIPT_HANDLE );

		// Build query args for media collections CPT.
		$query_args = array(
			'post_type'      => 'mcp_ai_media_coll',
			'posts_per_page' => absint( $atts['limit'] ),
			'orderby'        => sanitize_key( $atts['orderby'] ),
			'order'          => sanitize_key( $atts['order'] ),
			'post_status'    => 'publish',
		);

		$collections = get_posts( $query_args );

		if ( empty( $collections ) ) {
			return $this->render_empty( __( 'No media collections found.', 'mcp-ai-wpoos-pro' ) );
		}

		ob_start();
		$this->render_media_collections_view( $collections, $atts );
		return ob_get_clean();
	}

	/**
	 * Render media templates view.
	 *
	 * @param array $templates Array of template post objects.
	 * @param array $atts      Shortcode attributes.
	 */
	protected function render_media_templates_view( $templates, $atts ) {
		$display = sanitize_key( $atts['display'] );
		$columns = absint( $atts['columns'] );

		echo '<div class="mcp-ai-media-templates mcp-ai-display-' . esc_attr( $display ) . '" data-columns="' . esc_attr( $columns ) . '">';

		foreach ( $templates as $template ) {
			$template_id   = $template->ID;
			$template_url  = get_permalink( $template_id );
			$edit_url      = get_edit_post_link( $template_id );
			$thumbnail_id  = get_post_thumbnail_id( $template_id );
			$thumbnail_url = $thumbnail_id ? wp_get_attachment_image_url( $thumbnail_id, 'medium' ) : '';

			echo '<div class="mcp-ai-template-item">';

			if ( $thumbnail_url ) {
				echo '<div class="mcp-ai-template-thumbnail">';
				echo '<a href="' . esc_url( $template_url ) . '">';
				echo '<img src="' . esc_url( $thumbnail_url ) . '" alt="' . esc_attr( $template->post_title ) . '" />';
				echo '</a>';
				echo '</div>';
			}

			echo '<div class="mcp-ai-template-content">';
			echo '<h3 class="mcp-ai-template-title">';
			echo '<a href="' . esc_url( $template_url ) . '">' . esc_html( $template->post_title ) . '</a>';
			echo '</h3>';

			if ( $template->post_excerpt ) {
				echo '<div class="mcp-ai-template-excerpt">' . esc_html( $template->post_excerpt ) . '</div>';
			}

			echo '<div class="mcp-ai-template-actions">';
			echo '<a href="' . esc_url( $template_url ) . '" class="button">' . esc_html__( 'View', 'mcp-ai-wpoos-pro' ) . '</a>';

			if ( current_user_can( 'edit_post', $template_id ) ) {
				echo ' <a href="' . esc_url( $edit_url ) . '" class="button">' . esc_html__( 'Edit', 'mcp-ai-wpoos-pro' ) . '</a>';
			}

			echo '</div>';
			echo '</div>';
			echo '</div>';
		}

		echo '</div>';
	}

	/**
	 * Render media collections view.
	 *
	 * @param array $collections Array of collection post objects.
	 * @param array $atts        Shortcode attributes.
	 */
	protected function render_media_collections_view( $collections, $atts ) {
		$display = sanitize_key( $atts['display'] );
		$columns = absint( $atts['columns'] );

		echo '<div class="mcp-ai-media-collections mcp-ai-display-' . esc_attr( $display ) . '" data-columns="' . esc_attr( $columns ) . '">';

		foreach ( $collections as $collection ) {
			$collection_id  = $collection->ID;
			$collection_url = get_permalink( $collection_id );
			$edit_url       = get_edit_post_link( $collection_id );
			$thumbnail_id   = get_post_thumbnail_id( $collection_id );
			$thumbnail_url  = $thumbnail_id ? wp_get_attachment_image_url( $thumbnail_id, 'medium' ) : '';

			echo '<div class="mcp-ai-collection-item">';

			if ( $thumbnail_url ) {
				echo '<div class="mcp-ai-collection-thumbnail">';
				echo '<a href="' . esc_url( $collection_url ) . '">';
				echo '<img src="' . esc_url( $thumbnail_url ) . '" alt="' . esc_attr( $collection->post_title ) . '" />';
				echo '</a>';
				echo '</div>';
			}

			echo '<div class="mcp-ai-collection-content">';
			echo '<h3 class="mcp-ai-collection-title">';
			echo '<a href="' . esc_url( $collection_url ) . '">' . esc_html( $collection->post_title ) . '</a>';
			echo '</h3>';

			if ( $collection->post_excerpt ) {
				echo '<div class="mcp-ai-collection-excerpt">' . esc_html( $collection->post_excerpt ) . '</div>';
			}

			echo '<div class="mcp-ai-collection-actions">';
			echo '<a href="' . esc_url( $collection_url ) . '" class="button">' . esc_html__( 'View', 'mcp-ai-wpoos-pro' ) . '</a>';

			if ( current_user_can( 'edit_post', $collection_id ) ) {
				echo ' <a href="' . esc_url( $edit_url ) . '" class="button">' . esc_html__( 'Edit', 'mcp-ai-wpoos-pro' ) . '</a>';
			}

			echo '</div>';
			echo '</div>';
			echo '</div>';
		}

		echo '</div>';
	}
}
