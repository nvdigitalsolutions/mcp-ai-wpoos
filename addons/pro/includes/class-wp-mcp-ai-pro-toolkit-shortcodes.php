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
		$script_path   = WP_MCP_AI_PRO_URL . 'assets/js/toolkit-widgets.min.js';
		$style_path    = WP_MCP_AI_PRO_URL . 'assets/css/toolkit-widgets.css';
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
				'restUrl'   => esc_url_raw( rest_url( 'mcp-ai-pro/v1' ) ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'i18n'      => array(
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
			$title     = isset( $post['post_title'] ) ? $post['post_title'] : '';
			$platform  = isset( $post['platform'] ) ? $post['platform'] : '';
			$date      = isset( $post['scheduled_date'] ) ? $post['scheduled_date'] : '';
			$status    = isset( $post['status'] ) ? $post['status'] : '';

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
}
