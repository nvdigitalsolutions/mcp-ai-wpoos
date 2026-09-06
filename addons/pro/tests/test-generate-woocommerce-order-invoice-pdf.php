<?php
/**
 * Tests for the WooCommerce order invoice PDF tool identity.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

/**
 * Test the WooCommerce-specific invoice implementation.
 */
class Test_WP_MCP_AI_Generate_WooCommerce_Order_Invoice_PDF extends WP_UnitTestCase {

	/**
	 * Ensure the WooCommerce tool has a unique PHP identity while preserving
	 * the existing public tool slug and order-based schema.
	 */
	public function test_tool_identity_and_schema() {
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/ecommerce/class-wp-mcp-ai-tool-generate-woocommerce-order-invoice-pdf.php';

		$tool   = new WP_MCP_AI_Tool_Generate_WooCommerce_Order_Invoice_PDF();
		$schema = $tool->get_parameters_schema();

		$this->assertSame( 'generate_invoice_pdf', $tool->get_slug() );
		$this->assertArrayHasKey( 'order_id', $schema['properties'] );
		$this->assertContains( 'order_id', $schema['required'] );
	}
}
