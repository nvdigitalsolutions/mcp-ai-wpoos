<?php
/**
 * Base+pro matrix sanity pins.
 *
 * Verifies the phpunit-basepro.xml.dist environment actually boots the
 * base+pro deployment shape: the base-version constant is true while the
 * Pro addon is loaded, in an admin context.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

require_once __DIR__ . '/helpers/trait-basepro-test-case.php';

/**
 * Sanity checks for the base+pro test matrix.
 */
class Test_BasePro_Matrix extends WP_UnitTestCase {
	use WP_MCP_AI_BasePro_Test_Case;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		$this->wp_mcp_ai_basepro_require_matrix();
	}

	/**
	 * The matrix must boot as a base build with the Pro addon active.
	 */
	public function test_matrix_is_base_version_with_pro_active(): void {
		$this->assertTrue( wp_mcp_ai_is_base_version(), 'Base-version helper must return true in this matrix.' );
		$this->assertTrue( defined( 'WP_MCP_AI_BASE_VERSION' ) && WP_MCP_AI_BASE_VERSION, 'WP_MCP_AI_BASE_VERSION must be true.' );
		$this->assertTrue( defined( 'WP_MCP_AI_PRO_VERSION' ), 'WP_MCP_AI_PRO_VERSION must be defined (Pro addon loaded).' );
		$this->assertTrue( defined( 'WP_MCP_AI_PRO_PATH' ), 'WP_MCP_AI_PRO_PATH must be defined.' );
	}

	/**
	 * The matrix must run in admin context so the is_admin() init branches
	 * (admin menus, settings pages) are exercised like a dashboard request.
	 */
	public function test_matrix_is_admin_context(): void {
		$this->assertTrue( is_admin(), 'The base+pro matrix must boot in admin context.' );
	}
}
