<?php
/**
 * Minimal WooCommerce stubs for unit testing.
 */
if ( ! class_exists( 'WooCommerce' ) ) {
	class WooCommerce {}
}

if ( ! class_exists( 'WC_Order' ) ) {
	class WC_Order {
		protected $data = array();

		/**
		 * Constructor.
		 */
		public function __construct( array $data ) {
			$defaults   = array(
				'id'            => 0,
				'order_number'  => '',
				'status'        => 'pending',
				'total'         => '0.00',
				'currency'      => 'USD',
				'date_created'  => new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ),
				'billing_first' => '',
				'billing_last'  => '',
				'billing_email' => '',
			);
			$this->data = wp_parse_args( $data, $defaults );
		}

		public function get_id() {
			return $this->data['id'];
		}

		public function get_order_number() {
			return $this->data['order_number'];
		}

		public function get_status() {
			return $this->data['status'];
		}

		public function get_total() {
			return $this->data['total'];
		}

		public function get_currency() {
			return $this->data['currency'];
		}

		public function get_date_created() {
			return $this->data['date_created'];
		}

		public function get_billing_first_name() {
			return $this->data['billing_first'];
		}

		public function get_billing_last_name() {
			return $this->data['billing_last'];
		}

		public function get_billing_email() {
			return $this->data['billing_email'];
		}
	}
}

if ( ! class_exists( 'WC_Product' ) ) {
	class WC_Product {
		protected $data = array();

		/**
		 * Constructor.
		 */
		public function __construct( array $data ) {
			$defaults = array(
				'id'             => 0,
				'name'           => '',
				'sku'            => '',
				'type'           => 'simple',
				'status'         => 'publish',
				'price'          => '0',
				'regular_price'  => '0',
				'sale_price'     => '',
				'stock_status'   => 'instock',
				'stock_quantity' => null,
				'manage_stock'   => false,
				'permalink'      => '',
				'date_created'   => new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ),
				'date_modified'  => new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ),
				'children'       => array(),
				'attributes'     => array(),
			);

			$this->data = wp_parse_args( $data, $defaults );
		}

		public function get_id() {
			return $this->data['id'];
		}

		public function get_name() {
			return $this->data['name'];
		}

		public function get_sku() {
			return $this->data['sku'];
		}

		public function get_type() {
			return $this->data['type'];
		}

		public function is_type( $type ) {
			return $this->data['type'] === $type;
		}

		public function get_status() {
			return $this->data['status'];
		}

		public function get_price() {
			return $this->data['price'];
		}

		public function get_regular_price() {
			return $this->data['regular_price'];
		}

		public function get_sale_price() {
			return $this->data['sale_price'];
		}

		public function get_stock_status() {
			return $this->data['stock_status'];
		}

		public function get_stock_quantity() {
			return $this->data['stock_quantity'];
		}

		public function get_manage_stock() {
			return $this->data['manage_stock'];
		}

		public function get_permalink() {
			return $this->data['permalink'];
		}

		public function get_date_created() {
			return $this->data['date_created'];
		}

		public function get_date_modified() {
			return $this->data['date_modified'];
		}

		public function get_children() {
			return isset( $this->data['children'] ) ? $this->data['children'] : array();
		}

		public function get_attributes() {
			return isset( $this->data['attributes'] ) ? $this->data['attributes'] : array();
		}
	}
}

if ( ! function_exists( 'wc_clean' ) ) {
	function wc_clean( $var ) {
		if ( is_array( $var ) ) {
			return array_map( 'wc_clean', $var );
		}

		return sanitize_text_field( $var );
	}
}

if ( ! function_exists( 'wc_get_orders' ) ) {
	function wc_get_orders( $args = array() ) {
		global $wp_mcp_ai_wc_orders_args;
		$wp_mcp_ai_wc_orders_args = $args;

		return array(
			new WC_Order(
				array(
					'id'            => 101,
					'order_number'  => '101',
					'status'        => 'completed',
					'total'         => '125.50',
					'currency'      => 'USD',
					'date_created'  => new DateTimeImmutable( '2024-03-01 10:00:00', new DateTimeZone( 'UTC' ) ),
					'billing_first' => 'Alex',
					'billing_last'  => 'Rivera',
					'billing_email' => 'alex@example.com',
				)
			),
		);
	}
}

if ( ! function_exists( 'wc_get_products' ) ) {
	function wc_get_products( $args = array() ) {
		global $wp_mcp_ai_wc_products_args;
		$wp_mcp_ai_wc_products_args = $args;

		$products = array(
			new WC_Product(
				array(
					'id'             => 501,
					'name'           => 'Test Product One',
					'sku'            => 'SKU-ONE',
					'price'          => '29.99',
					'regular_price'  => '34.99',
					'sale_price'     => '29.99',
					'stock_status'   => 'instock',
					'stock_quantity' => 5,
					'manage_stock'   => true,
					'permalink'      => 'https://example.com/product/one',
					'date_created'   => new DateTimeImmutable( '2024-02-01 09:00:00', new DateTimeZone( 'UTC' ) ),
					'date_modified'  => new DateTimeImmutable( '2024-02-15 09:00:00', new DateTimeZone( 'UTC' ) ),
				)
			),
			new WC_Product(
				array(
					'id'             => 502,
					'name'           => 'Test Product Two',
					'sku'            => 'SKU-TWO',
					'price'          => '19.50',
					'regular_price'  => '19.50',
					'sale_price'     => '',
					'stock_status'   => 'outofstock',
					'stock_quantity' => 0,
					'manage_stock'   => false,
					'permalink'      => 'https://example.com/product/two',
					'date_created'   => new DateTimeImmutable( '2024-01-15 12:00:00', new DateTimeZone( 'UTC' ) ),
					'date_modified'  => new DateTimeImmutable( '2024-01-20 12:00:00', new DateTimeZone( 'UTC' ) ),
				)
			),
			new WC_Product(
				array(
					'id'             => 503,
					'name'           => 'Variable T-Shirt',
					'sku'            => 'VAR-TSHIRT',
					'type'           => 'variable',
					'price'          => '25.00',
					'regular_price'  => '25.00',
					'sale_price'     => '',
					'stock_status'   => 'instock',
					'stock_quantity' => null,
					'manage_stock'   => false,
					'permalink'      => 'https://example.com/product/variable-tshirt',
					'date_created'   => new DateTimeImmutable( '2024-03-01 10:00:00', new DateTimeZone( 'UTC' ) ),
					'date_modified'  => new DateTimeImmutable( '2024-03-05 10:00:00', new DateTimeZone( 'UTC' ) ),
					'children'       => array( 5031, 5032, 5033 ),
				)
			),
		);

		if ( isset( $args['limit'] ) ) {
			return array_slice( $products, 0, absint( $args['limit'] ) );
		}

		return $products;
	}
}

if ( ! function_exists( 'wc_get_product' ) ) {
	function wc_get_product( $product_id ) {
		// Return variations for the variable product.
		$variations = array(
			5031 => new WC_Product(
				array(
					'id'             => 5031,
					'name'           => 'Variable T-Shirt - Small Blue',
					'sku'            => 'VAR-TSHIRT-S-BLUE',
					'type'           => 'variation',
					'price'          => '25.00',
					'regular_price'  => '25.00',
					'sale_price'     => '',
					'stock_status'   => 'instock',
					'stock_quantity' => 10,
					'manage_stock'   => true,
					'permalink'      => 'https://example.com/product/variable-tshirt/?attribute_size=small&attribute_color=blue',
					'date_created'   => new DateTimeImmutable( '2024-03-01 10:00:00', new DateTimeZone( 'UTC' ) ),
					'date_modified'  => new DateTimeImmutable( '2024-03-05 10:00:00', new DateTimeZone( 'UTC' ) ),
					'attributes'     => array(
						'size'  => 'Small',
						'color' => 'Blue',
					),
				)
			),
			5032 => new WC_Product(
				array(
					'id'             => 5032,
					'name'           => 'Variable T-Shirt - Medium Blue',
					'sku'            => 'VAR-TSHIRT-M-BLUE',
					'type'           => 'variation',
					'price'          => '25.00',
					'regular_price'  => '25.00',
					'sale_price'     => '',
					'stock_status'   => 'instock',
					'stock_quantity' => 5,
					'manage_stock'   => true,
					'permalink'      => 'https://example.com/product/variable-tshirt/?attribute_size=medium&attribute_color=blue',
					'date_created'   => new DateTimeImmutable( '2024-03-01 10:00:00', new DateTimeZone( 'UTC' ) ),
					'date_modified'  => new DateTimeImmutable( '2024-03-05 10:00:00', new DateTimeZone( 'UTC' ) ),
					'attributes'     => array(
						'size'  => 'Medium',
						'color' => 'Blue',
					),
				)
			),
			5033 => new WC_Product(
				array(
					'id'             => 5033,
					'name'           => 'Variable T-Shirt - Large Blue',
					'sku'            => 'VAR-TSHIRT-L-BLUE',
					'type'           => 'variation',
					'price'          => '27.00',
					'regular_price'  => '27.00',
					'sale_price'     => '',
					'stock_status'   => 'outofstock',
					'stock_quantity' => 0,
					'manage_stock'   => true,
					'permalink'      => 'https://example.com/product/variable-tshirt/?attribute_size=large&attribute_color=blue',
					'date_created'   => new DateTimeImmutable( '2024-03-01 10:00:00', new DateTimeZone( 'UTC' ) ),
					'date_modified'  => new DateTimeImmutable( '2024-03-05 10:00:00', new DateTimeZone( 'UTC' ) ),
					'attributes'     => array(
						'size'  => 'Large',
						'color' => 'Blue',
					),
				)
			),
		);

		if ( isset( $variations[ $product_id ] ) ) {
			return $variations[ $product_id ];
		}

		return false;
	}
}
