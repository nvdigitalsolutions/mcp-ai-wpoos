<?php
/**
 * Markup subsystem test.
 *
 * Markup store tests (TTL, replay, cap).
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test case for Test_Markup_Store.
 *
 * @group markup
 */
class Test_Markup_Store extends WP_UnitTestCase {

	/**
	 * Test case.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		delete_option( WP_MCP_AI_Markup_Store::INDEX_OPTION );
	}

	/**
	 * Test fixture builder.
	 *
	 * @param int $assistant_id Assistant ID.
	 * @param int $ttl          TTL in seconds.
	 * @return WP_MCP_AI_Markup_Request
	 */
	private function make_request( $assistant_id = 1, $ttl = 600 ) {
		return new WP_MCP_AI_Markup_Request(
			array(
				'tool_slug'    => 'image_inpainting',
				'target'       => array( 'attachment_id' => 1 ),
				'target_type'  => WP_MCP_AI_Markup_Request::TARGET_TYPE_IMAGE,
				'mode'         => WP_MCP_AI_Markup_Request::MODE_MASK,
				'assistant_id' => $assistant_id,
				'ttl'          => $ttl,
			)
		);
	}

	/**
	 * Test case.
	 *
	 * @return void
	 */
	public function test_save_and_get() {
		$store   = new WP_MCP_AI_Markup_Store();
		$request = $this->make_request();
		$saved   = $store->save( $request );

		$this->assertTrue( $saved );
		$this->assertNotNull( $store->get( $request->get_request_id() ) );
	}

	/**
	 * Test case.
	 *
	 * @return void
	 */
	public function test_consume_deletes_on_read() {
		$store   = new WP_MCP_AI_Markup_Store();
		$request = $this->make_request();
		$store->save( $request );

		$consumed = $store->consume( $request->get_request_id() );
		$this->assertNotNull( $consumed );
		$this->assertNull( $store->consume( $request->get_request_id() ), 'replay must be impossible' );
	}

	/**
	 * Test case.
	 *
	 * @return void
	 */
	public function test_per_assistant_cap() {
		$store = new WP_MCP_AI_Markup_Store();
		for ( $i = 0; $i < WP_MCP_AI_Markup_Store::MAX_PER_ASSISTANT; $i++ ) {
			$store->save( $this->make_request( 7 ) );
		}
		$over = $store->save( $this->make_request( 7 ) );
		$this->assertWPError( $over );
		$this->assertSame( 'wp_mcp_ai_markup_too_many_requests', $over->get_error_code() );
	}

	/**
	 * Test case.
	 *
	 * @return void
	 */
	public function test_expired_entries_returned_as_null() {
		$store   = new WP_MCP_AI_Markup_Store();
		$request = $this->make_request( 1, 60 );
		$store->save( $request );
		// Manually overwrite the transient with an expired record to simulate
		// passage of time without sleeping.
		$arr               = $request->to_array();
		$arr['expires_at'] = time() - 10;
		set_transient( WP_MCP_AI_Markup_Store::TRANSIENT_PREFIX . $request->get_request_id(), $arr, 60 );

		$this->assertNull( $store->get( $request->get_request_id() ) );
	}

	/**
	 * Test case.
	 *
	 * @return void
	 */
	public function test_cleanup_removes_expired_index_entries() {
		$store   = new WP_MCP_AI_Markup_Store();
		$request = $this->make_request( 11, 60 );
		$store->save( $request );

		$arr               = $request->to_array();
		$arr['expires_at'] = time() - 10;
		set_transient( WP_MCP_AI_Markup_Store::TRANSIENT_PREFIX . $request->get_request_id(), $arr, 60 );
		// Force the index to record the expired entry.
		update_option(
			WP_MCP_AI_Markup_Store::INDEX_OPTION,
			array(
				11 => array(
					$request->get_request_id() => array(
						'expires_at' => time() - 10,
						'tool_slug'  => 'image_inpainting',
					),
				),
			),
			false
		);

		$purged = $store->cleanup_expired();
		$this->assertGreaterThanOrEqual( 1, $purged );
		$this->assertSame( array(), get_option( WP_MCP_AI_Markup_Store::INDEX_OPTION, array() ) );
	}
}
