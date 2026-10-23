<?php
/**
 * Tests for the server-side transcript storage aggregate.
 *
 * The Pro SPA status bar reports "Sessions: X KB" from the chat-transcripts
 * list envelope's `storage_bytes` field. This suite pins:
 *   - the repository aggregate returning 0 when the CCT table is unavailable
 *   - the list envelope carrying the figure reported by the repository
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test chat transcript storage bytes.
 */
class Test_Chat_Transcript_Storage_Bytes extends WP_UnitTestCase {
	/**
	 * Administrator user ID.
	 *
	 * @var int
	 */
	protected $admin_id;

	/**
	 * Chat controller instance.
	 *
	 * @var WP_MCP_AI_REST_Chat_Controller
	 */
	protected $controller;

	/**
	 * Main REST controller instance.
	 *
	 * @var WP_MCP_AI_REST
	 */
	protected $main_controller;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// WP 6.9 may re-register breadcrumbs block during rest_api_init.
		$this->setExpectedIncorrectUsage( 'WP_Block_Type_Registry::register' );
		// WooCommerce Blocks hooks non-idempotent init callbacks (payment
		// method integrations) — re-firing init in the harness re-registers
		// them and raises a _doing_it_wrong notice from Woo's own code.
		$this->setExpectedIncorrectUsage( 'Automattic\WooCommerce\Blocks\Integrations\IntegrationRegistry::register' );

		if ( function_exists( 'wp_mcp_ai_bootstrap' ) ) {
			wp_mcp_ai_bootstrap();
		}

		// Create admin user.
		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );

		// Initialize controllers.
		$this->main_controller = WP_MCP_AI_REST::get_instance();
		$this->controller      = new WP_MCP_AI_REST_Chat_Controller( $this->main_controller );

		// Register routes.
		rest_get_server();
		do_action( 'init' );
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		$this->set_transcript_repository_mock( null );
		wp_set_current_user( 0 );

		parent::tearDown();
	}

	/**
	 * Inject a mock transcript repository into the main controller.
	 *
	 * WP_MCP_AI_REST caches its repository in a protected property with no
	 * public setter, so reflection is used. Pass null to reset to the real
	 * repository resolution on the next access.
	 *
	 * @param object|null $mock_repository Mock repository or null to reset.
	 */
	private function set_transcript_repository_mock( $mock_repository ) {
		if ( ! $this->main_controller ) {
			return;
		}

		$reflection = new ReflectionClass( $this->main_controller );
		$property   = $reflection->getProperty( 'transcript_repository' );
		$property->setAccessible( true );
		$property->setValue( $this->main_controller, $mock_repository );
	}

	/**
	 * Test that the repository aggregate returns 0 when the CCT table is
	 * unavailable (JetEngine CCT inactive in the unit environment).
	 */
	public function test_repository_returns_zero_when_table_unavailable() {
		$repository = wp_mcp_ai_get_transcript_repository();

		$this->assertSame( 0, $repository->get_storage_bytes( $this->admin_id ) );
	}

	/**
	 * Test that the list envelope carries the repository-reported figure.
	 */
	public function test_list_envelope_includes_storage_bytes() {
		$mock_repository = new class() {
			/**
			 * Mock session listing.
			 *
			 * @param int $user_id      User identifier.
			 * @param int $per_page     Sessions per page.
			 * @param int $page         Page number.
			 * @param int $assistant_id Optional assistant ID.
			 * @return array Empty session set.
			 */
			public function get_sessions( $user_id, $per_page, $page, $assistant_id = 0 ) {
				return array(
					'items' => array(),
					'total' => 0,
				);
			}

			/**
			 * Mock storage aggregate.
			 *
			 * @param int $user_id      User identifier.
			 * @param int $assistant_id Optional assistant ID.
			 * @return int Fixed byte figure.
			 */
			public function get_storage_bytes( $user_id, $assistant_id = 0 ) {
				return 4711;
			}
		};

		$this->set_transcript_repository_mock( $mock_repository );

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/chat-transcripts' );

		$response = $this->controller->handle_chat_transcripts( $request );

		$this->assertNotInstanceOf( WP_Error::class, $response );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'storage_bytes', $data, 'List envelope should include storage_bytes' );
		$this->assertSame( 4711, $data['storage_bytes'], 'storage_bytes should match the repository figure' );
	}

	/**
	 * Test that the envelope falls back to 0 when the repository has no
	 * storage aggregate method (defensive for addon/base version mixes).
	 */
	public function test_list_envelope_defaults_to_zero_when_repository_lacks_method() {
		$mock_repository = new class() {
			/**
			 * Mock session listing.
			 *
			 * @param int $user_id      User identifier.
			 * @param int $per_page     Sessions per page.
			 * @param int $page         Page number.
			 * @param int $assistant_id Optional assistant ID.
			 * @return array Empty session set.
			 */
			public function get_sessions( $user_id, $per_page, $page, $assistant_id = 0 ) {
				return array(
					'items' => array(),
					'total' => 0,
				);
			}
		};

		$this->set_transcript_repository_mock( $mock_repository );

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/chat-transcripts' );

		$response = $this->controller->handle_chat_transcripts( $request );

		$this->assertNotInstanceOf( WP_Error::class, $response );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'storage_bytes', $data, 'List envelope should always include storage_bytes' );
		$this->assertSame( 0, $data['storage_bytes'], 'storage_bytes should default to 0' );
	}
}
