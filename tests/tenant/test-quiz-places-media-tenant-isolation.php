<?php
/**
 * Quiz, Places, Media, and QMS Tenant Isolation Tests
 *
 * Verifies centralized save_post hook correctly stamps tenant meta on
 * quiz, places, media collections, and QMS document CPTs and that
 * cross-tenant access is denied across all four scopes.
 *
 * @package WP_MCP_AI
 * @since   3.1.0
 */

/**
 * Test quiz, places, media, and QMS tenant isolation.
 */
class Test_Quiz_Places_Media_Tenant_Isolation extends WP_UnitTestCase {

	/**
	 * Tenant A ID.
	 *
	 * @var int
	 */
	private $tenant_a_id;

	/**
	 * Tenant B ID.
	 *
	 * @var int
	 */
	private $tenant_b_id;

	/**
	 * Set up test tenants.
	 */
	public function set_up() {
		parent::set_up();
		global $wpdb;
		$table = $wpdb->prefix . 'mcp_ai_tenants';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			$table,
			array(
				'tenant_type' => 'school',
				'tenant_name' => 'School A',
			),
			array( '%s', '%s' )
		);
		$this->tenant_a_id = (int) $wpdb->insert_id;
		$wpdb->insert(
			$table,
			array(
				'tenant_type' => 'school',
				'tenant_name' => 'School B',
			),
			array( '%s', '%s' )
		);
		$this->tenant_b_id = (int) $wpdb->insert_id;
		// phpcs:enable
		update_option( 'wp_mcp_ai_tenant_isolation_enabled', true );
	}

	/**
	 * Tear down test data.
	 */
	public function tear_down() {
		WP_MCP_AI_Tenant_Context::reset();
		delete_option( 'wp_mcp_ai_tenant_isolation_enabled' );
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mcp_ai_tenants WHERE tenant_name LIKE 'School %'" );
		// phpcs:enable
		parent::tear_down();
	}

	/**
	 * Test: Quiz created as Tenant A is invisible to Tenant B.
	 */
	public function test_quiz_cross_tenant_isolation() {
		WP_MCP_AI_Tenant_Context::instance()->set( 'school', $this->tenant_a_id );

		$quiz_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_quiz',
				'post_title'  => 'Math Final - School A',
				'post_status' => 'publish',
			)
		);
		$this->assertGreaterThan( 0, $quiz_id );
		$this->assertEquals( $this->tenant_a_id, (int) get_post_meta( $quiz_id, '_tenant_id', true ) );

		WP_MCP_AI_Tenant_Context::reset();
		WP_MCP_AI_Tenant_Context::instance()->set( 'school', $this->tenant_b_id );

		$query = new WP_Query(
			array(
				'post_type'      => 'mcp_ai_quiz',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
		$this->assertNotContains( $quiz_id, $query->posts );
	}

	/**
	 * Test: Place created as Tenant A is invisible to Tenant B.
	 */
	public function test_place_cross_tenant_isolation() {
		WP_MCP_AI_Tenant_Context::instance()->set( 'school', $this->tenant_a_id );

		$place_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_place',
				'post_title'  => 'Campus Library - School A',
				'post_status' => 'publish',
			)
		);
		$this->assertGreaterThan( 0, $place_id );

		WP_MCP_AI_Tenant_Context::reset();
		WP_MCP_AI_Tenant_Context::instance()->set( 'school', $this->tenant_b_id );

		$query = new WP_Query(
			array(
				'post_type'      => 'mcp_ai_place',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
		$this->assertNotContains( $place_id, $query->posts );
	}

	/**
	 * Test: Media collection created as Tenant A is invisible to Tenant B.
	 */
	public function test_media_collection_cross_tenant_isolation() {
		WP_MCP_AI_Tenant_Context::instance()->set( 'school', $this->tenant_a_id );

		$media_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_media_coll',
				'post_title'  => 'Brand Assets - School A',
				'post_status' => 'publish',
			)
		);
		$this->assertGreaterThan( 0, $media_id );

		WP_MCP_AI_Tenant_Context::reset();
		WP_MCP_AI_Tenant_Context::instance()->set( 'school', $this->tenant_b_id );

		$query = new WP_Query(
			array(
				'post_type'      => 'mcp_ai_media_coll',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
		$this->assertNotContains( $media_id, $query->posts );
	}

	/**
	 * Test: QMS document created as Tenant A is invisible to Tenant B.
	 */
	public function test_qms_doc_cross_tenant_isolation() {
		WP_MCP_AI_Tenant_Context::instance()->set( 'school', $this->tenant_a_id );

		$doc_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_doc_record',
				'post_title'  => 'SOP-QMS-001 - School A',
				'post_status' => 'publish',
			)
		);
		$this->assertGreaterThan( 0, $doc_id );

		WP_MCP_AI_Tenant_Context::reset();
		WP_MCP_AI_Tenant_Context::instance()->set( 'school', $this->tenant_b_id );

		$query = new WP_Query(
			array(
				'post_type'      => 'mcp_ai_doc_record',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
		$this->assertNotContains( $doc_id, $query->posts );
	}
}
