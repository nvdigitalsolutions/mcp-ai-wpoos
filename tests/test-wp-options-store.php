<?php
/**
 * Tests for WP_MCP_AI_WP_Options_Store
 *
 * Verifies that the Options Store adapter correctly wraps WordPress
 * `get_option`, `update_option`, and `delete_option` functions, returns
 * default values when options are absent, and satisfies the interface contract.
 *
 * @package WP_MCP_AI
 * @group   infrastructure
 * @group   options-store
 */

/**
 * Test case for WP_MCP_AI_WP_Options_Store.
 */
class Test_WP_MCP_AI_WP_Options_Store extends WP_UnitTestCase {

	/**
	 * Unique option key prefix to avoid collisions with plugin options.
	 *
	 * @var string
	 */
	const KEY_PREFIX = 'wp_mcp_ai_test_options_store_';

	/**
	 * SUT instance.
	 *
	 * @var WP_MCP_AI_WP_Options_Store
	 */
	private $store;

	/**
	 * Option keys created during this test run so they can be cleaned up.
	 *
	 * @var string[]
	 */
	private $created_keys = array();

	/**
	 * Set up a fresh store instance before each test.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->store        = new WP_MCP_AI_WP_Options_Store();
		$this->created_keys = array();
	}

	/**
	 * Remove any option keys written during the test.
	 */
	public function tearDown(): void {
		foreach ( $this->created_keys as $key ) {
			delete_option( $key );
		}
		parent::tearDown();
	}

	/**
	 * Helper: return a unique option key and register it for cleanup.
	 *
	 * @param string $suffix Optional suffix.
	 * @return string
	 */
	private function key( $suffix = '' ) {
		$key                  = self::KEY_PREFIX . ( $suffix ?: uniqid() );
		$this->created_keys[] = $key;
		return $key;
	}

	// -------------------------------------------------------------------------
	// Interface contract
	// -------------------------------------------------------------------------

	/**
	 * The class should implement the options-store interface.
	 */
	public function test_implements_interface() {
		$this->assertInstanceOf( Interface_WP_MCP_AI_Options_Store::class, $this->store );
	}

	// -------------------------------------------------------------------------
	// get()
	// -------------------------------------------------------------------------

	/**
	 * get() returns the stored value.
	 */
	public function test_get_returns_stored_value() {
		$key = $this->key( 'get_basic' );
		update_option( $key, 'hello' );

		$this->assertSame( 'hello', $this->store->get( $key ) );
	}

	/**
	 * get() returns an array value unchanged.
	 */
	public function test_get_returns_array_value() {
		$key  = $this->key( 'get_array' );
		$data = array( 'a' => 1, 'b' => 'two' );
		update_option( $key, $data );

		$this->assertSame( $data, $this->store->get( $key ) );
	}

	/**
	 * get() returns null when the option does not exist and no default is given.
	 */
	public function test_get_returns_null_for_missing_option_no_default() {
		$key = $this->key( 'get_missing' );

		$this->assertNull( $this->store->get( $key ) );
	}

	/**
	 * get() returns the caller-supplied default when the option does not exist.
	 */
	public function test_get_returns_caller_default_for_missing_option() {
		$key = $this->key( 'get_missing_default' );

		$this->assertSame( 'my_default', $this->store->get( $key, 'my_default' ) );
	}

	/**
	 * get() returns a false default without treating it as "no default".
	 */
	public function test_get_returns_false_default() {
		$key = $this->key( 'get_false_default' );

		$this->assertFalse( $this->store->get( $key, false ) );
	}

	/**
	 * get() returns a 0 (integer) default correctly.
	 */
	public function test_get_returns_zero_default() {
		$key = $this->key( 'get_zero_default' );

		$this->assertSame( 0, $this->store->get( $key, 0 ) );
	}

	/**
	 * get() returns the actual stored value even when a default is supplied.
	 */
	public function test_get_prefers_stored_value_over_default() {
		$key = $this->key( 'get_prefer_stored' );
		update_option( $key, 'actual' );

		$this->assertSame( 'actual', $this->store->get( $key, 'default_ignored' ) );
	}

	// -------------------------------------------------------------------------
	// update()
	// -------------------------------------------------------------------------

	/**
	 * update() persists the value so that a subsequent get() retrieves it.
	 */
	public function test_update_persists_value() {
		$key = $this->key( 'update_persist' );

		$this->store->update( $key, 'stored_value' );

		$this->assertSame( 'stored_value', get_option( $key ) );
	}

	/**
	 * update() returns true when the value is newly created.
	 */
	public function test_update_returns_true_on_create() {
		$key    = $this->key( 'update_create' );
		$result = $this->store->update( $key, 'new' );

		$this->assertTrue( $result );
	}

	/**
	 * update() can overwrite an existing option.
	 */
	public function test_update_overwrites_existing_value() {
		$key = $this->key( 'update_overwrite' );
		update_option( $key, 'old' );

		$this->store->update( $key, 'new' );

		$this->assertSame( 'new', get_option( $key ) );
	}

	/**
	 * update() handles array values.
	 */
	public function test_update_handles_array_value() {
		$key  = $this->key( 'update_array' );
		$data = array( 'x' => 42 );

		$this->store->update( $key, $data );

		$this->assertSame( $data, get_option( $key ) );
	}

	/**
	 * update() handles integer zero without treating it as empty/false.
	 */
	public function test_update_handles_zero() {
		$key = $this->key( 'update_zero' );

		$this->store->update( $key, 0 );

		$this->assertSame( 0, (int) get_option( $key ) );
	}

	/**
	 * update() chain: multiple calls store each value correctly.
	 */
	public function test_update_chain() {
		$key = $this->key( 'update_chain' );

		$this->store->update( $key, 'first' );
		$this->store->update( $key, 'second' );
		$this->store->update( $key, 'third' );

		$this->assertSame( 'third', get_option( $key ) );
	}

	// -------------------------------------------------------------------------
	// delete()
	// -------------------------------------------------------------------------

	/**
	 * delete() removes the option so that get_option returns false afterwards.
	 */
	public function test_delete_removes_option() {
		$key = $this->key( 'delete_basic' );
		update_option( $key, 'to_be_deleted' );

		$this->store->delete( $key );

		$this->assertFalse( get_option( $key ) );
	}

	/**
	 * delete() returns true on success.
	 */
	public function test_delete_returns_true_on_success() {
		$key = $this->key( 'delete_return_true' );
		update_option( $key, 'value' );

		$result = $this->store->delete( $key );

		$this->assertTrue( $result );
	}

	/**
	 * delete() on a non-existent key returns false.
	 */
	public function test_delete_returns_false_for_missing_key() {
		$key    = $this->key( 'delete_missing' );
		$result = $this->store->delete( $key );

		$this->assertFalse( $result );
	}

	/**
	 * After delete(), get() returns the default (null) for that key.
	 */
	public function test_get_after_delete_returns_default() {
		$key = $this->key( 'get_after_delete' );
		update_option( $key, 'ephemeral' );
		$this->store->delete( $key );

		$this->assertNull( $this->store->get( $key ) );
	}

	// -------------------------------------------------------------------------
	// Round-trip / integrated
	// -------------------------------------------------------------------------

	/**
	 * update() → get() round-trip returns the same value.
	 */
	public function test_update_get_roundtrip_string() {
		$key = $this->key( 'roundtrip_str' );

		$this->store->update( $key, 'round_trip_value' );

		$this->assertSame( 'round_trip_value', $this->store->get( $key ) );
	}

	/**
	 * update() → get() round-trip for arrays.
	 */
	public function test_update_get_roundtrip_array() {
		$key  = $this->key( 'roundtrip_arr' );
		$data = array( 'nested' => array( 'deep' => true ), 'count' => 99 );

		$this->store->update( $key, $data );

		$this->assertSame( $data, $this->store->get( $key ) );
	}

	/**
	 * update() → delete() → get() sequence behaves correctly.
	 */
	public function test_update_delete_get_sequence() {
		$key = $this->key( 'sequence' );

		$this->store->update( $key, 'temporary' );
		$this->assertSame( 'temporary', $this->store->get( $key ) );

		$this->store->delete( $key );
		$this->assertNull( $this->store->get( $key ) );
	}
}
