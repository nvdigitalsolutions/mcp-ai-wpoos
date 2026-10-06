<?php
/**
 * Tests for E-commerce toolkit hardening.
 *
 * Regression coverage for the ecommerce cluster audit:
 * - validate-image-for-product / validate-image-for-vehicle fed the OpenAI
 *   response message.content straight into trim() + json_decode(); OpenAI
 *   compatible gateways (and Gemini-shaped responses) can return that field
 *   as an array of {type,text} parts, which fatals with "array given".
 * - export_products_report / generate_invoice_pdf ran raw exec() to Node
 *   scripts at doubled/nonexistent paths; on exec-disabled hosts that is a
 *   fatal Error and on every other host a permanent generation failure.
 * - lookup_product_price fed Crawl4AI markdown/text and
 *   submit_document_prompt results into preg_match()/explode() without a
 *   string guarantee.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Tests
 * @group ecommerce
 * @group pro
 */

/**
 * E-commerce toolkit hardening test case.
 */
class Test_Ecommerce_Toolkit_Hardening extends WP_UnitTestCase {

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped( 'Pro addon is not loaded.' );
		}

		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/ecommerce/class-wp-mcp-ai-ecommerce-helpers.php';
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/ecommerce/class-wp-mcp-ai-pro-tool-validate-image-for-product.php';
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/ecommerce/class-wp-mcp-ai-pro-tool-validate-image-for-vehicle.php';
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/ecommerce/class-wp-mcp-ai-pro-tool-lookup-product-price.php';
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/ecommerce/class-wp-mcp-ai-tool-export-products-report.php';
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/ecommerce/class-wp-mcp-ai-tool-generate-woocommerce-order-invoice-pdf.php';

		// Neutralise environment-provided keys so the settings option drives
		// credential resolution in these tests.
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv
		putenv( 'OPENAI_API_KEY' );

		if ( class_exists( 'WP_MCP_AI_Credential_Resolver' ) ) {
			WP_MCP_AI_Credential_Resolver::clear_cache();
		}
		if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) && method_exists( 'WP_MCP_AI_Admin_Settings', 'reset_settings_cache' ) ) {
			WP_MCP_AI_Admin_Settings::reset_settings_cache();
		}
		delete_option( 'wp_mcp_ai_credentials' );
		update_option( 'wp_mcp_ai_settings', array( 'openai_api_key' => 'test-key' ) );
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		remove_all_filters( 'pre_http_request' );
		parent::tearDown();
	}

	/**
	 * Build a mocked OpenAI HTTP response with the given message content.
	 *
	 * Public so add_filter() can register it as a callback.
	 *
	 * @param mixed $content Message content (string or array of parts).
	 * @return array Mock response array for pre_http_request.
	 */
	public function openai_content_mock( $content ) {
		return array(
			'headers'  => array(),
			'body'     => wp_json_encode(
				array(
					'id'      => 'chatcmpl-test',
					'object'  => 'chat.completion',
					'created' => time(),
					'model'   => 'gpt-4.1',
					'choices' => array(
						array(
							'index'   => 0,
							'message' => array(
								'role'    => 'assistant',
								'content' => $content,
							),
						),
					),
				)
			),
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
		);
	}

	/**
	 * Build a minimal valid product vision analysis JSON payload.
	 *
	 * @return array Analysis payload.
	 */
	private function product_analysis_payload() {
		return array(
			'person_detected'        => true,
			'person_count'           => 1,
			'required_parts_visible' => array(
				'wrist' => true,
				'hand'  => true,
			),
			'optional_parts_visible' => array(
				'forearm' => true,
			),
			'obstructions'           => array(),
			'lighting_quality'       => 'good',
			'image_sharpness'        => 'sharp',
			'background_complexity'  => 'simple',
			'existing_accessories'   => array(),
			'pose_suitable'          => true,
			'suggestions'            => array(),
		);
	}

	/**
	 * Build a minimal valid vehicle (cleaning) analysis JSON payload.
	 *
	 * @return array Analysis payload.
	 */
	private function vehicle_analysis_payload() {
		return array(
			'vehicle_detected'     => true,
			'vehicle_count'        => 1,
			'full_vehicle_visible' => true,
			'estimated_size_tier'  => 'car',
			'size_confidence'      => 0.9,
			'condition_visible'    => true,
			'visible_conditions'   => array(),
			'lighting_quality'     => 'good',
			'image_sharpness'      => 'sharp',
			'obstructions'         => array(),
			'overall_suitable'     => true,
			'suggestions'          => array(),
		);
	}

	/**
	 * Invoke a private/protected method via reflection.
	 *
	 * @param object $instance   Object instance.
	 * @param string $method     Method name.
	 * @param array  $args       Method arguments.
	 * @return mixed Method return value.
	 */
	private function invoke_method( $instance, $method, array $args = array() ) {
		$reflection = new ReflectionMethod( $instance, $method );
		$reflection->setAccessible( true );
		return $reflection->invokeArgs( $instance, $args );
	}

	/**
	 * The flatten helpers must pass strings through and concatenate both
	 * {type,text} part arrays and arrays of plain strings, and never emit
	 * "Array" garbage.
	 */
	public function test_flatten_response_content_matrix() {
		$product_tool = new WP_MCP_AI_Pro_Tool_Validate_Image_For_Product();
		$lookup_tool  = new WP_MCP_AI_Pro_Tool_Lookup_Product_Price();

		$this->assertSame( 'plain text', $this->invoke_method( $product_tool, 'flatten_response_content', array( 'plain text' ) ) );
		$this->assertSame(
			'Part one.Part two.',
			$this->invoke_method(
				$product_tool,
				'flatten_response_content',
				array(
					array(
						array(
							'type' => 'text',
							'text' => 'Part one.',
						),
						array(
							'type' => 'text',
							'text' => 'Part two.',
						),
					),
				)
			)
		);
		$this->assertSame( 'ab', $this->invoke_method( $lookup_tool, 'flatten_response_content', array( array( 'a', 'b' ) ) ) );
		$this->assertSame( '', $this->invoke_method( $lookup_tool, 'flatten_response_content', array( array( array( 'type' => 'text' ) ) ) ) );
		$this->assertSame( '', $this->invoke_method( $lookup_tool, 'flatten_response_content', array( 42 ) ) );
	}

	/**
	 * The validate-image-for-product tool must flatten array-of-parts content
	 * into a working analysis instead of fataling in trim().
	 */
	public function test_validate_product_vision_ai_array_parts_content() {
		$content = array(
			array(
				'type' => 'text',
				'text' => wp_json_encode( $this->product_analysis_payload() ),
			),
		);

		add_filter(
			'pre_http_request',
			function () use ( $content ) {
				return $this->openai_content_mock( $content );
			},
			20,
			3
		);

		$tool   = new WP_MCP_AI_Pro_Tool_Validate_Image_For_Product();
		$result = $this->invoke_method(
			$tool,
			'validate_with_vision_ai',
			array(
				array( 'image_url' => 'https://example.com/photo.jpg' ),
				'watch',
			)
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'checks', $result );
		$this->assertContains( 'pass', wp_list_pluck( $result['checks'], 'status' ) );
	}

	/**
	 * The validate-image-for-product tool must handle plain string content the
	 * same way (no shape-specific code paths).
	 */
	public function test_validate_product_vision_ai_string_content() {
		$content = wp_json_encode( $this->product_analysis_payload() );

		add_filter(
			'pre_http_request',
			function () use ( $content ) {
				return $this->openai_content_mock( $content );
			},
			20,
			3
		);

		$tool   = new WP_MCP_AI_Pro_Tool_Validate_Image_For_Product();
		$result = $this->invoke_method(
			$tool,
			'validate_with_vision_ai',
			array(
				array( 'image_url' => 'https://example.com/photo.jpg' ),
				'watch',
			)
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'checks', $result );
	}

	/**
	 * Empty content must surface the honest invalid_response error, not a
	 * "Array" json_decode or an empty-check pass-through.
	 */
	public function test_validate_product_vision_ai_empty_content() {
		$content = array( array( 'type' => 'text' ) );

		add_filter(
			'pre_http_request',
			function () use ( $content ) {
				return $this->openai_content_mock( $content );
			},
			20,
			3
		);

		$tool   = new WP_MCP_AI_Pro_Tool_Validate_Image_For_Product();
		$result = $this->invoke_method(
			$tool,
			'validate_with_vision_ai',
			array(
				array( 'image_url' => 'https://example.com/photo.jpg' ),
				'watch',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_invalid_response', $result->get_error_code() );
	}

	/**
	 * The validate-image-for-vehicle tool must flatten array-of-parts content.
	 */
	public function test_validate_vehicle_vision_ai_array_parts_content() {
		$content = array(
			array(
				'type' => 'text',
				'text' => wp_json_encode( $this->vehicle_analysis_payload() ),
			),
		);

		add_filter(
			'pre_http_request',
			function () use ( $content ) {
				return $this->openai_content_mock( $content );
			},
			20,
			3
		);

		$tool   = new WP_MCP_AI_Pro_Tool_Validate_Image_For_Vehicle();
		$result = $this->invoke_method(
			$tool,
			'validate_with_vision_ai',
			array(
				array( array( 'image_url' => 'https://example.com/car.jpg' ) ),
				'cleaning',
			)
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'checks', $result );
	}

	/**
	 * The validate-image-for-vehicle tool must error honestly on empty content.
	 */
	public function test_validate_vehicle_vision_ai_empty_content() {
		$content = '';

		add_filter(
			'pre_http_request',
			function () use ( $content ) {
				return $this->openai_content_mock( $content );
			},
			20,
			3
		);

		$tool   = new WP_MCP_AI_Pro_Tool_Validate_Image_For_Vehicle();
		$result = $this->invoke_method(
			$tool,
			'validate_with_vision_ai',
			array(
				array( array( 'image_url' => 'https://example.com/car.jpg' ) ),
				'cleaning',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_invalid_response', $result->get_error_code() );
	}

	/**
	 * Extract_price_from_content must reject non-string content instead of
	 * feeding it into preg_match().
	 */
	public function test_extract_price_from_content_rejects_array() {
		$tool = new WP_MCP_AI_Pro_Tool_Lookup_Product_Price();

		$this->assertNull( $this->invoke_method( $tool, 'extract_price_from_content', array( array( 'text' => '$12.99' ) ) ) );

		$price = $this->invoke_method( $tool, 'extract_price_from_content', array( 'Only $12.99 USD today' ) );
		$this->assertSame( 12.99, $price['price'] );
		$this->assertSame( 'USD', $price['currency'] );
	}

	/**
	 * Parse_json_from_text must return an error for non-string content.
	 */
	public function test_parse_json_from_text_rejects_array() {
		$tool   = new WP_MCP_AI_Pro_Tool_Lookup_Product_Price();
		$result = $this->invoke_method( $tool, 'parse_json_from_text', array( array( 'text' => '[]' ) ) );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_invalid_response', $result->get_error_code() );
	}

	/**
	 * Crawl results carrying array markdown must flatten before the heading
	 * and price regexes run.
	 */
	public function test_parse_crawl_result_for_product_flattens_array_markdown() {
		$tool   = new WP_MCP_AI_Pro_Tool_Lookup_Product_Price();
		$result = $this->invoke_method(
			$tool,
			'parse_crawl_result_for_product',
			array(
				array(
					'results' => array(
						array(
							'markdown' => array(
								array(
									'type' => 'text',
									'text' => "# Titan Watch\n",
								),
								array(
									'type' => 'text',
									'text' => 'Price: $199.99 USD',
								),
							),
						),
					),
				),
				'https://example.com/product',
			)
		);

		$this->assertSame( 'Titan Watch', $result['title'] );
		$this->assertSame( 199.99, $result['price'] );
		$this->assertSame( 'USD', $result['currency'] );
	}

	/**
	 * The Node script runner must refuse to run while the shell-tools
	 * constant is absent (the F-EXEC-01 gate). Deliberately does not define
	 * WP_MCP_AI_ALLOW_SHELL_TOOLS - defining it would pollute every later
	 * suite in the shared process.
	 */
	public function test_run_node_script_shell_gate_without_constant() {
		$result = wp_mcp_ai_ecommerce_run_node_script( '/nonexistent/script.js', '/tmp/in.json', '/tmp/out.pdf' );

		$this->assertWPError( $result );
		$this->assertSame( 'shell_tools_disabled', $result->get_error_code() );
	}

	/**
	 * Excel export must degrade to the wrapped gate error (no fatal, no
	 * leftover input/output files) when shell tools are disabled.
	 */
	public function test_export_excel_file_gate_without_shell_constant() {
		$tool     = new WP_MCP_AI_Tool_Export_Products_Report();
		$temp_dir = sys_get_temp_dir();
		$data     = array(
			'headers' => array( 'Name', 'Price' ),
			'rows'    => array( array( 'Widget', '9.99' ) ),
		);

		$result = $this->invoke_method( $tool, 'generate_excel_file', array( $data, $temp_dir, 'gate-test' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'excel_generation_failed', $result->get_error_code() );
		$this->assertStringContainsString( 'Shell tools are disabled', $result->get_error_message() );

		// Both the JSON input and any partial output must be cleaned up.
		$leftovers = glob( $temp_dir . '/node-input-*.json' );
		$this->assertEmpty( $leftovers );
		$this->assertFileDoesNotExist( $temp_dir . '/gate-test.xlsx' );
	}

	/**
	 * Invoice PDF generation must degrade to the wrapped gate error when
	 * shell tools are disabled.
	 */
	public function test_invoice_generate_pdf_gate_without_shell_constant() {
		$tool = new WP_MCP_AI_Tool_Generate_WooCommerce_Order_Invoice_PDF();
		$data = array(
			'invoice_number' => 'INV-1',
			'order_id'       => 1,
			'order_number'   => '1',
			'order_date'     => '2026-10-04',
			'company'        => array(
				'name'    => 'Test Co',
				'address' => '',
				'email'   => '',
				'phone'   => '',
				'website' => '',
			),
			'customer'       => array(
				'name'            => 'Buyer',
				'billing_address' => '',
			),
			'items'          => array(),
			'shipping'       => array(),
			'taxes'          => array(),
			'totals'         => array(
				'subtotal'       => '0.00',
				'shipping_total' => '0.00',
				'tax_total'      => '0.00',
				'total'          => '0.00',
				'currency'       => 'USD',
			),
			'payment'        => array( 'method' => 'Cash' ),
			'notes'          => '',
			'terms'          => '',
		);

		$result = $this->invoke_method( $tool, 'generate_pdf', array( $data ) );

		$this->assertWPError( $result );
		$this->assertSame( 'pdf_generation_failed', $result->get_error_code() );
		$this->assertStringContainsString( 'Shell tools are disabled', $result->get_error_message() );
	}

	/**
	 * The invoice HTML builder must escape user-controlled fields.
	 */
	public function test_build_invoice_html_escapes_user_data() {
		$tool = new WP_MCP_AI_Tool_Generate_WooCommerce_Order_Invoice_PDF();
		$data = array(
			'invoice_number' => 'INV-7',
			'order_number'   => '7',
			'order_date'     => '2026-10-04',
			'company'        => array(
				'name'    => '<script>alert(1)</script>',
				'address' => '1 Main St',
				'email'   => 'a@b.co',
				'phone'   => '',
				'website' => '',
			),
			'customer'       => array(
				'name'            => 'Buyer',
				'billing_address' => '2 Side St',
			),
			'items'          => array(
				array(
					'name'     => '<b>Widget</b>',
					'sku'      => 'W-1',
					'quantity' => 2,
					'price'    => '9.99',
					'total'    => '19.98',
				),
			),
			'shipping'       => array(),
			'taxes'          => array(),
			'totals'         => array(
				'subtotal'       => '19.98',
				'shipping_total' => '0.00',
				'tax_total'      => '0.00',
				'total'          => '19.98',
				'currency'       => 'USD',
			),
			'payment'        => array( 'method' => 'Card' ),
			'notes'          => '<script>bad()</script>',
			'terms'          => 'Net 30',
		);

		$html = $this->invoke_method( $tool, 'build_invoice_html', array( $data ) );

		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
		$this->assertStringContainsString( '&lt;b&gt;Widget&lt;/b&gt;', $html );
		$this->assertStringContainsString( 'INV-7', $html );
		$this->assertStringContainsString( 'Totals', $html );
		$this->assertStringContainsString( '19.98', $html );
	}

	/**
	 * The JSON input writer must round-trip its payload to disk.
	 */
	public function test_write_node_input_file_roundtrip() {
		$tool      = new WP_MCP_AI_Tool_Export_Products_Report();
		$temp_dir  = sys_get_temp_dir();
		$payload   = array(
			'author' => 'Test',
			'sheets' => array(),
		);
		$file_path = $this->invoke_method( $tool, 'write_node_input_file', array( $payload, $temp_dir ) );

		$this->assertIsString( $file_path );
		$this->assertFileExists( $file_path );
		$this->assertSame( $payload, json_decode( file_get_contents( $file_path ), true ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test fixture read.
		wp_delete_file( $file_path );
	}
}
