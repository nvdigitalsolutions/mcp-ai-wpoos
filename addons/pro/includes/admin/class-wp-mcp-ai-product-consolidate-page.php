<?php
/**
 * Product Consolidate & Add Page
 *
 * Enhanced product import with CSV/XML support, SKU validation, and e-commerce data quality standards.
 * Implements industry best practices for product data management.
 *
 * @package WP_MCP_AI_Pro
 * @since 2.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-wp-mcp-ai-consolidate-add-base.php';

/**
 * Product Consolidation Admin Page
 */
class WP_MCP_AI_Product_Consolidate_Page extends WP_MCP_AI_Consolidate_Add_Base {

	/**
	 * Page slug.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'product-consolidate';

	/**
	 * Initialize the page.
	 */
	public static function init() {
		$instance = new self( 'ecommerce' );
		add_action( 'admin_menu', array( __CLASS__, 'add_menu_page' ), 25 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/**
	 * Add submenu page under E-Commerce Toolkit menu.
	 */
	public static function add_menu_page() {
		// Check if WooCommerce is active.
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		add_submenu_page(
			'wp-mcp-ai-ecommerce-toolkit',
			__( 'Consolidate & Add Products', 'mcp-ai-wpoos-pro' ),
			__( 'Consolidate & Add', 'mcp-ai-wpoos-pro' ),
			'edit_products',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Render the page.
	 */
	public static function render_page() {
		$instance = new self( 'ecommerce' );
		$instance->render();
	}

	/**
	 * Enqueue assets for the consolidation page.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function enqueue_assets( $hook ) {
		// Only load on our consolidation page.
		if ( 'product_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		// Enqueue chat assets if available.
		if ( class_exists( 'WP_MCP_AI_Shortcode' ) ) {
			$shortcode_instance = new WP_MCP_AI_Shortcode();
			$shortcode_instance->register_assets();
			wp_enqueue_style( WP_MCP_AI_Shortcode::STYLE_HANDLE );
			wp_enqueue_script( WP_MCP_AI_Shortcode::SCRIPT_HANDLE );
		}

		// Enqueue consolidation page specific script.
		wp_enqueue_script(
			'wp-mcp-ai-product-consolidate',
			WP_MCP_AI_PRO_URL . 'assets/js/product-consolidate.js',
			array( 'jquery' ),
			WP_MCP_AI_PRO_VERSION,
			true
		);

		wp_localize_script(
			'wp-mcp-ai-product-consolidate',
			'wpMcpAiProductConsolidate',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonces'  => array(
					'bulk_import'        => wp_create_nonce( 'wp_mcp_ai_bulk_import' ),
					'upload_document'    => wp_create_nonce( 'wp_mcp_ai_upload_document' ),
					'validate_data'      => wp_create_nonce( 'wp_mcp_ai_validate_data' ),
					'check_completeness' => wp_create_nonce( 'wp_mcp_ai_check_completeness' ),
				),
			)
		);
	}

	/**
	 * Get entity types for product toolkit.
	 *
	 * @return array Entity types.
	 */
	protected function get_entity_types() {
		return array(
			'products' => __( 'Products', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Get import formats supported for products.
	 *
	 * @return array Import formats.
	 */
	protected function get_import_formats() {
		return array(
			'csv'  => 'CSV',
			'xml'  => 'XML',
			'json' => 'JSON',
			'xlsx' => 'Excel (XLSX)',
		);
	}

	/**
	 * Get validation schema for products based on e-commerce standards.
	 *
	 * @return array Validation rules.
	 */
	protected function get_validation_schema() {
		return array(
			'required_fields'    => array(
				'sku'         => __( 'SKU (Stock Keeping Unit)', 'mcp-ai-wpoos-pro' ),
				'title'       => __( 'Product Name', 'mcp-ai-wpoos-pro' ),
				'price'       => __( 'Price', 'mcp-ai-wpoos-pro' ),
				'description' => __( 'Description', 'mcp-ai-wpoos-pro' ),
				'image'       => __( 'Product Image', 'mcp-ai-wpoos-pro' ),
			),
			'recommended_fields' => array(
				'category'       => __( 'Category', 'mcp-ai-wpoos-pro' ),
				'tags'           => __( 'Tags', 'mcp-ai-wpoos-pro' ),
				'stock_quantity' => __( 'Stock Quantity', 'mcp-ai-wpoos-pro' ),
				'weight'         => __( 'Weight', 'mcp-ai-wpoos-pro' ),
				'dimensions'     => __( 'Dimensions', 'mcp-ai-wpoos-pro' ),
			),
			'validation_rules'   => array(
				'sku'            => array(
					'type'       => 'string',
					'unique'     => true,
					'max_length' => 100,
				),
				'price'          => array(
					'type'      => 'numeric',
					'min_value' => 0,
				),
				'stock_quantity' => array(
					'type'      => 'integer',
					'min_value' => 0,
				),
			),
			'quality_dimensions' => array(
				'accuracy'     => __( 'Data matches reality (prices, specs, etc.)', 'mcp-ai-wpoos-pro' ),
				'completeness' => __( '97-99% of attributes filled (industry standard)', 'mcp-ai-wpoos-pro' ),
				'consistency'  => __( 'Uniform data across all channels', 'mcp-ai-wpoos-pro' ),
				'uniqueness'   => __( 'No duplicate SKUs or identifiers', 'mcp-ai-wpoos-pro' ),
				'conformance'  => __( 'Attributes follow standard taxonomy', 'mcp-ai-wpoos-pro' ),
			),
		);
	}

	/**
	 * Parse imported product data.
	 *
	 * @param string $data   Raw import data.
	 * @param string $format Import format.
	 * @return array|WP_Error Parsed data or error.
	 */
	protected function parse_import_data( $data, $format ) {
		switch ( $format ) {
			case 'csv':
				return $this->parse_csv_data( $data );
			case 'xml':
				return $this->parse_xml_data( $data );
			case 'json':
				return $this->parse_json_data( $data );
			default:
				return new WP_Error( 'unsupported_format', __( 'Unsupported import format', 'mcp-ai-wpoos-pro' ) );
		}
	}

	/**
	 * Parse CSV product data.
	 *
	 * @param string $data CSV data.
	 * @return array|WP_Error Parsed products or error.
	 */
	protected function parse_csv_data( $data ) {
		$lines = str_getcsv( $data, "\n" );
		if ( empty( $lines ) ) {
			return new WP_Error( 'empty_csv', __( 'CSV file is empty', 'mcp-ai-wpoos-pro' ) );
		}

		// First line should be headers.
		$headers  = str_getcsv( array_shift( $lines ) );
		$products = array();

		foreach ( $lines as $line ) {
			if ( empty( trim( $line ) ) ) {
				continue;
			}

			$values = str_getcsv( $line );
			if ( count( $values ) !== count( $headers ) ) {
				continue; // Skip malformed rows.
			}

			$product    = array_combine( $headers, $values );
			$products[] = $this->normalize_product_data( $product );
		}

		return $products;
	}

	/**
	 * Parse XML product data.
	 *
	 * @param string $data XML data.
	 * @return array|WP_Error Parsed products or error.
	 */
	protected function parse_xml_data( $data ) {
		libxml_use_internal_errors( true );
		$xml = simplexml_load_string( $data );

		if ( false === $xml ) {
			$errors = libxml_get_errors();
			libxml_clear_errors();
			return new WP_Error( 'invalid_xml', __( 'Invalid XML format', 'mcp-ai-wpoos-pro' ) );
		}

		$products = array();
		foreach ( $xml->product as $product_xml ) {
			$product = array();
			foreach ( $product_xml as $key => $value ) {
				$product[ (string) $key ] = (string) $value;
			}
			$products[] = $this->normalize_product_data( $product );
		}

		return $products;
	}

	/**
	 * Parse JSON product data.
	 *
	 * @param string $data JSON data.
	 * @return array|WP_Error Parsed products or error.
	 */
	protected function parse_json_data( $data ) {
		$products = json_decode( $data, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return new WP_Error( 'invalid_json', __( 'Invalid JSON format', 'mcp-ai-wpoos-pro' ) );
		}

		if ( ! is_array( $products ) ) {
			return new WP_Error( 'invalid_json_structure', __( 'JSON must be an array of products', 'mcp-ai-wpoos-pro' ) );
		}

		return array_map( array( $this, 'normalize_product_data' ), $products );
	}

	/**
	 * Normalize product data to standard format.
	 *
	 * @param array $product Raw product data.
	 * @return array Normalized product data.
	 */
	protected function normalize_product_data( $product ) {
		// Map common field variations to standard fields.
		$field_map = array(
			'name'          => 'title',
			'product_name'  => 'title',
			'product_title' => 'title',
			'desc'          => 'description',
			'product_desc'  => 'description',
			'cost'          => 'price',
			'regular_price' => 'price',
			'product_id'    => 'sku',
			'id'            => 'sku',
			'stock'         => 'stock_quantity',
			'inventory'     => 'stock_quantity',
			'qty'           => 'stock_quantity',
		);

		$normalized = array();
		foreach ( $product as $key => $value ) {
			$key_lower                 = strtolower( trim( $key ) );
			$mapped_key                = isset( $field_map[ $key_lower ] ) ? $field_map[ $key_lower ] : $key_lower;
			$normalized[ $mapped_key ] = trim( $value );
		}

		return $normalized;
	}

	/**
	 * Calculate product data completeness.
	 *
	 * @return array Completeness data.
	 */
	protected function calculate_completeness() {
		// Check WooCommerce products.
		$args = array(
			'post_type'      => 'product',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		);

		$products       = get_posts( $args );
		$total_products = count( $products );

		if ( 0 === $total_products ) {
			return array(
				'percentage' => 0,
				'missing'    => array( __( 'No products found. Start by importing or adding products.', 'mcp-ai-wpoos-pro' ) ),
			);
		}

		$schema             = $this->get_validation_schema();
		$required_fields    = array_keys( $schema['required_fields'] );
		$recommended_fields = array_keys( $schema['recommended_fields'] );

		$total_fields  = count( $required_fields ) + count( $recommended_fields );
		$filled_count  = 0;
		$missing_items = array();

		// Sample first 10 products for completeness check.
		$sample_products  = array_slice( $products, 0, 10 );
		$products_checked = 0;

		foreach ( $sample_products as $product_id ) {
			$product = wc_get_product( $product_id );
			if ( ! $product ) {
				continue;
			}

			++$products_checked;
			$product_filled = 0;

			// Check required fields.
			if ( $product->get_sku() ) {
				++$product_filled;
			}
			if ( $product->get_name() ) {
				++$product_filled;
			}
			if ( $product->get_price() ) {
				++$product_filled;
			}
			if ( $product->get_description() ) {
				++$product_filled;
			}
			if ( $product->get_image_id() ) {
				++$product_filled;
			}

			// Check recommended fields.
			if ( $product->get_category_ids() ) {
				++$product_filled;
			}
			if ( $product->get_tag_ids() ) {
				++$product_filled;
			}
			if ( $product->get_stock_quantity() !== null ) {
				++$product_filled;
			}
			if ( $product->get_weight() ) {
				++$product_filled;
			}
			if ( $product->get_length() || $product->get_width() || $product->get_height() ) {
				++$product_filled;
			}

			$filled_count += $product_filled;
		}

		$average_filled = $products_checked > 0 ? $filled_count / $products_checked : 0;
		$percentage     = round( ( $average_filled / $total_fields ) * 100 );

		// Identify missing data across sampled products.
		if ( $percentage < 97 ) {
			$missing_items[] = sprintf(
				/* translators: %d: Current percentage, %d: Industry standard percentage */
				__( 'Product data completeness is %1$d%%, below industry standard of %2$d%%', 'mcp-ai-wpoos-pro' ),
				$percentage,
				97
			);
		}

		return array(
			'percentage' => $percentage,
			'missing'    => $missing_items,
		);
	}

	/**
	 * Calculate quality score for a product.
	 *
	 * @param array $item Product data.
	 * @return array Quality score data.
	 */
	protected function calculate_item_quality_score( $item ) {
		$schema = $this->get_validation_schema();
		$score  = 100;
		$issues = array();

		// Get actual product if we have an ID.
		$product = isset( $item['id'] ) ? wc_get_product( $item['id'] ) : null;
		if ( ! $product ) {
			return array(
				'score'  => 0,
				'level'  => 'low',
				'status' => __( 'Not Found', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Check required fields (20 points each).
		if ( ! $product->get_sku() ) {
			$score   -= 20;
			$issues[] = 'missing_sku';
		}
		if ( ! $product->get_name() ) {
			$score   -= 20;
			$issues[] = 'missing_name';
		}
		if ( ! $product->get_price() ) {
			$score   -= 20;
			$issues[] = 'missing_price';
		}
		if ( ! $product->get_description() ) {
			$score   -= 15;
			$issues[] = 'missing_description';
		}
		if ( ! $product->get_image_id() ) {
			$score   -= 15;
			$issues[] = 'missing_image';
		}

		// Check recommended fields (5 points each).
		if ( empty( $product->get_category_ids() ) ) {
			$score -= 5;
		}
		if ( empty( $product->get_tag_ids() ) ) {
			$score -= 2;
		}
		if ( $product->get_stock_quantity() === null ) {
			$score -= 3;
		}

		// Determine quality level.
		if ( $score >= 80 ) {
			$level  = 'high';
			$status = __( 'Excellent', 'mcp-ai-wpoos-pro' );
		} elseif ( $score >= 50 ) {
			$level  = 'medium';
			$status = __( 'Good', 'mcp-ai-wpoos-pro' );
		} else {
			$level  = 'low';
			$status = __( 'Needs Improvement', 'mcp-ai-wpoos-pro' );
		}

		return array(
			'score'  => max( 0, $score ),
			'level'  => $level,
			'status' => $status,
			'issues' => $issues,
		);
	}

	/**
	 * Validate item data before saving.
	 *
	 * @param array $item_data Item data to validate.
	 * @return true|WP_Error True if valid, WP_Error if validation fails.
	 */
	protected function validate_item_data( $item_data ) {
		$schema = $this->get_validation_schema();

		// Check required fields.
		foreach ( $schema['required_fields'] as $field => $label ) {
			if ( empty( $item_data[ $field ] ) ) {
				return new WP_Error(
					'missing_required_field',
					sprintf(
						/* translators: %s: Field label */
						__( 'Required field missing: %s', 'mcp-ai-wpoos-pro' ),
						$label
					)
				);
			}
		}

		// Validate SKU uniqueness.
		if ( ! empty( $item_data['sku'] ) ) {
			$existing_product = wc_get_product_id_by_sku( $item_data['sku'] );
			if ( $existing_product ) {
				return new WP_Error(
					'duplicate_sku',
					sprintf(
						/* translators: %s: SKU value */
						__( 'SKU already exists: %s', 'mcp-ai-wpoos-pro' ),
						$item_data['sku']
					)
				);
			}
		}

		// Validate price is numeric and non-negative.
		if ( isset( $item_data['price'] ) && ( ! is_numeric( $item_data['price'] ) || $item_data['price'] < 0 ) ) {
			return new WP_Error( 'invalid_price', __( 'Price must be a positive number', 'mcp-ai-wpoos-pro' ) );
		}

		// Validate stock quantity.
		if ( isset( $item_data['stock_quantity'] ) && ( ! is_numeric( $item_data['stock_quantity'] ) || $item_data['stock_quantity'] < 0 ) ) {
			return new WP_Error( 'invalid_stock', __( 'Stock quantity must be a non-negative integer', 'mcp-ai-wpoos-pro' ) );
		}

		return true;
	}

	/**
	 * Render product-specific form fields.
	 */
	protected function render_entity_form_fields() {
		?>
		<table class="form-table">
			<tr>
				<th scope="row">
					<label for="product_sku"><?php esc_html_e( 'SKU', 'mcp-ai-wpoos-pro' ); ?> <span class="required">*</span></label>
				</th>
				<td>
					<input type="text" id="product_sku" name="item_data[sku]" class="regular-text" required>
					<p class="description"><?php esc_html_e( 'Unique product identifier', 'mcp-ai-wpoos-pro' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="product_title"><?php esc_html_e( 'Product Name', 'mcp-ai-wpoos-pro' ); ?> <span class="required">*</span></label>
				</th>
				<td>
					<input type="text" id="product_title" name="item_data[title]" class="regular-text" required>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="product_price"><?php esc_html_e( 'Price', 'mcp-ai-wpoos-pro' ); ?> <span class="required">*</span></label>
				</th>
				<td>
					<input type="number" id="product_price" name="item_data[price]" class="regular-text" step="0.01" min="0" required>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="product_description"><?php esc_html_e( 'Description', 'mcp-ai-wpoos-pro' ); ?> <span class="required">*</span></label>
				</th>
				<td>
					<textarea id="product_description" name="item_data[description]" rows="5" class="large-text" required></textarea>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="product_stock"><?php esc_html_e( 'Stock Quantity', 'mcp-ai-wpoos-pro' ); ?></label>
				</th>
				<td>
					<input type="number" id="product_stock" name="item_data[stock_quantity]" class="regular-text" min="0">
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="product_weight"><?php esc_html_e( 'Weight', 'mcp-ai-wpoos-pro' ); ?></label>
				</th>
				<td>
					<input type="number" id="product_weight" name="item_data[weight]" class="regular-text" step="0.01" min="0">
					<p class="description"><?php esc_html_e( 'Weight in pounds or kilograms', 'mcp-ai-wpoos-pro' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}
}
