<?php
/**
 * Tests for MCP App discovery cache freshness (proposal 066).
 *
 * Covers the ttlMs-capped discovery TTL, the client-side ttlMs capture,
 * server-pushed `notifications/tools/list_changed` dispatch, and the
 * registry invalidation sweep.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

// phpcs:disable WordPress.Files.FileName,Generic.Files.OneObjectStructurePerFile.MultipleFound -- Test fixture exposing a protected client method, co-located with its suite.

/**
 * Client subclass exposing the SSE notification dispatcher for tests.
 */
class Testable_MCP_App_Client extends WP_MCP_AI_MCP_App_Client {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( array( 'server_url' => 'https://example.com/mcp' ) );
	}

	/**
	 * Expose the protected SSE notification dispatcher.
	 *
	 * @param string $body Raw SSE stream body.
	 * @return void
	 */
	public function exposed_dispatch_sse_notifications( $body ) {
		$this->dispatch_sse_notifications( $body );
	}
}

/**
 * MCP App discovery cache freshness suite.
 */
class Test_MCP_App_Cache_Freshness extends WP_UnitTestCase {

	/**
	 * Assistant post ID.
	 *
	 * @var int
	 */
	protected $assistant_id;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		if ( ! class_exists( 'WP_MCP_AI_MCP_App_Registry' ) ) {
			require_once WP_MCP_AI_PATH . 'addons/pro/includes/mcp-apps/class-wp-mcp-ai-mcp-app-registry.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_MCP_App_Client' ) ) {
			require_once WP_MCP_AI_PATH . 'addons/pro/includes/mcp-apps/class-wp-mcp-ai-mcp-app-client.php';
		}

		$this->assistant_id = self::factory()->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_status' => 'publish',
				'post_title'  => 'Cache Freshness Assistant',
			)
		);
	}

	/**
	 * Zero/absent ttlMs keeps the default discovery TTL.
	 */
	public function test_resolve_cache_ttl_zero_keeps_default() {
		$this->assertSame( 300, WP_MCP_AI_MCP_App_Registry::resolve_cache_ttl( 0 ) );
		$this->assertSame( 300, WP_MCP_AI_MCP_App_Registry::resolve_cache_ttl( 'nonsense' ) );
	}

	/**
	 * A positive ttlMs caps the default discovery TTL.
	 */
	public function test_resolve_cache_ttl_positive_caps_default() {
		$this->assertSame( 60, WP_MCP_AI_MCP_App_Registry::resolve_cache_ttl( 60000 ) );
		$this->assertSame( 1, WP_MCP_AI_MCP_App_Registry::resolve_cache_ttl( 500 ) );
		$this->assertSame( 300, WP_MCP_AI_MCP_App_Registry::resolve_cache_ttl( 999999999 ) );
	}

	/**
	 * The client captures the server-declared ttlMs from tools/list.
	 */
	public function test_list_tools_captures_server_ttl() {
		$client = $this->getMockBuilder( WP_MCP_AI_MCP_App_Client::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'send_request' ) )
			->getMock();

		$client->method( 'send_request' )->willReturn(
			array(
				'tools' => array( array( 'name' => 'remote_tool' ) ),
				'ttlMs' => 60000,
			)
		);

		$tools = $client->list_tools();

		$this->assertSame( array( array( 'name' => 'remote_tool' ) ), $tools );
		$this->assertSame( 60000, $client->get_last_tools_ttl_ms() );
	}

	/**
	 * Tools/list_changed notifications invalidate the matching discovery cache.
	 */
	public function test_list_changed_notification_invalidates_matching_url() {
		$registry = WP_MCP_AI_MCP_App_Registry::get_instance();

		$registry->save_apps(
			$this->assistant_id,
			array(
				array(
					'label'      => 'Elementor',
					'server_url' => 'https://example.com/mcp',
					'auth_type'  => 'none',
				),
			)
		);

		$apps      = $registry->get_apps( $this->assistant_id );
		$cache_key = WP_MCP_AI_MCP_App_Registry::CACHE_PREFIX . md5( wp_json_encode( $apps[0] ) );

		set_transient( $cache_key, array( 'stale' => true ), 300 );

		$registry->handle_remote_notification( 'notifications/tools/list_changed', array(), 'https://example.com/mcp' );

		$this->assertFalse( get_transient( $cache_key ) );
	}

	/**
	 * Other methods and other URLs leave the discovery cache alone.
	 */
	public function test_other_notifications_do_not_invalidate() {
		$registry = WP_MCP_AI_MCP_App_Registry::get_instance();

		$apps      = $registry->get_apps( $this->assistant_id );
		$cache_key = WP_MCP_AI_MCP_App_Registry::CACHE_PREFIX . md5( wp_json_encode( $apps[0] ) );

		set_transient( $cache_key, array( 'fresh' => true ), 300 );

		$registry->handle_remote_notification( 'notifications/resources/list_changed', array(), 'https://example.com/mcp' );
		$this->assertNotFalse( get_transient( $cache_key ) );

		$registry->handle_remote_notification( 'notifications/tools/list_changed', array(), 'https://other.example/mcp' );
		$this->assertNotFalse( get_transient( $cache_key ) );

		delete_transient( $cache_key );
	}

	/**
	 * The client dispatches notification SSE events and ignores responses.
	 */
	public function test_sse_notification_dispatch_fires_hook() {
		$received = array();

		$collector = function ( $method, $params, $server_url ) use ( &$received ) {
			$received[] = array( $method, $params, $server_url );
		};
		add_action( 'wp_mcp_ai_remote_mcp_notification', $collector, 10, 3 );

		$body  = 'event: message' . "\n";
		$body .= 'data: {"jsonrpc":"2.0","id":7,"result":{"tools":[]}}' . "\n\n";
		$body .= 'event: message' . "\n";
		$body .= 'data: {"jsonrpc":"2.0","method":"notifications/tools/list_changed","params":{}}' . "\n\n";
		$body .= ': keepalive' . "\n\n";

		$client = new Testable_MCP_App_Client();
		$client->exposed_dispatch_sse_notifications( $body );

		remove_action( 'wp_mcp_ai_remote_mcp_notification', $collector );

		$this->assertCount( 1, $received, 'Only the notification event should dispatch; the response has an id.' );
		$this->assertSame( 'notifications/tools/list_changed', $received[0][0] );
		$this->assertSame( array(), $received[0][1] );
		$this->assertSame( 'https://example.com/mcp', $received[0][2] );
	}
}
