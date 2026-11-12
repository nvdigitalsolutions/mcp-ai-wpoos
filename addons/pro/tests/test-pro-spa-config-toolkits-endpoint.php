<?php
/**
 * Test the toolkit endpoint emitted by the Pro SPA runtime config.
 *
 * The shared console reads `endpoints.toolkits` to decide whether the
 * Toolkits surface exists on this runtime. It must be the real REST base for
 * admins and empty for everyone else, so the nav entry disappears rather than
 * firing a request that would 403.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Tests
 */

/**
 * Pro SPA config — toolkit endpoint tests.
 */
class Test_Pro_Spa_Config_Toolkits_Endpoint extends WP_UnitTestCase {

	/**
	 * Admin user id.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Set up an administrator session.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'WP_MCP_AI_Pro_SPA_Config' ) ) {
			$this->markTestSkipped( 'The Pro SPA config is not loaded.' );
		}

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );
	}

	/**
	 * Administrators receive the toolkit REST base.
	 */
	public function test_toolkit_endpoint_for_admin(): void {
		$runtime = WP_MCP_AI_Pro_SPA_Config::build();

		$this->assertSame(
			rest_url( 'mcp-ai-pro/v1/toolkits' ),
			$runtime['endpoints']['toolkits']
		);
	}

	/**
	 * Non-admins never receive the toolkit endpoint.
	 */
	public function test_toolkit_endpoint_empty_for_non_admin(): void {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		$runtime = WP_MCP_AI_Pro_SPA_Config::build();

		$this->assertSame( '', $runtime['endpoints']['toolkits'] );
	}
}
