<?php
/**
 * Tests for the Get Elementor Form Submissions tool.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test the Get Elementor Form Submissions tool.
 */
class WP_MCP_AI_Get_Elementor_Form_Submissions_Test extends WP_UnitTestCase {

	/**
	 * Set up the test.
	 */
	public function setUp(): void {
		parent::setUp();

		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-elementor-form-submissions.php';
		require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';

		// Create the e_submissions table mock.
		$this->create_submissions_tables();
	}

	/**
	 * Tear down the test.
	 */
	public function tearDown(): void {
		$this->drop_submissions_tables();
		parent::tearDown();
	}

	/**
	 * Create mock Elementor submissions tables.
	 */
	private function create_submissions_tables() {
		global $wpdb;

		$submissions_table = $wpdb->prefix . 'e_submissions';
		$values_table      = $wpdb->prefix . 'e_submissions_values';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$submissions_table} (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				type VARCHAR(20) NOT NULL DEFAULT 'form',
				hash_id VARCHAR(40) NOT NULL DEFAULT '',
				main_meta_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
				post_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
				referer TEXT NOT NULL DEFAULT '',
				element_id VARCHAR(40) NOT NULL DEFAULT '',
				form_name VARCHAR(255) NOT NULL DEFAULT '',
				campaign_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
				user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
				user_ip VARCHAR(46) NOT NULL DEFAULT '',
				user_agent TEXT NOT NULL DEFAULT '',
				status VARCHAR(20) NOT NULL DEFAULT 'success',
				is_read TINYINT(1) NOT NULL DEFAULT 0,
				created_at_gmt DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
				updated_at_gmt DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
				created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
				updated_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
				PRIMARY KEY (id),
				KEY post_id (post_id),
				KEY element_id (element_id),
				KEY status (status)
			) {$wpdb->get_charset_collate()}"
		);

		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$values_table} (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				submission_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
				`key` VARCHAR(255) NOT NULL DEFAULT '',
				`value` LONGTEXT NOT NULL DEFAULT '',
				PRIMARY KEY (id),
				KEY submission_id (submission_id),
				KEY `key` (`key`)
			) {$wpdb->get_charset_collate()}"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
	}

		/**
		 * Drop mock submissions tables.
		 */
	private function drop_submissions_tables() {
		global $wpdb;
		$submissions_table = $wpdb->prefix . 'e_submissions';
			$values_table  = $wpdb->prefix . 'e_submissions_values';
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( "DROP TABLE IF EXISTS {$values_table}" );
			$wpdb->query( "DROP TABLE IF EXISTS {$submissions_table}" );
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
	}

	/**
	 * Seed mock submission data.
	 *
	 * @param int    $post_id      Post ID of the form page.
	 * @param string $form_name    Form widget name.
	 * @param string $element_id   Elementor widget ID.
	 * @param int    $count        Number of submissions to create.
	 * @param array  $field_values Key-value pairs for submission fields.
	 * @return int[] Submission IDs.
	 */
	private function seed_submissions( $post_id, $form_name = 'Test Form', $element_id = 'abc123', $count = 3, $field_values = array() ) {
		global $wpdb;
		$submissions_table = $wpdb->prefix . 'e_submissions';
		$values_table      = $wpdb->prefix . 'e_submissions_values';

		if ( empty( $field_values ) ) {
			$field_values = array(
				'name'    => 'John Doe',
				'email'   => 'john@example.com',
				'message' => 'Hello world',
			);
		}

		$ids = array();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		for ( $i = 0; $i < $count; $i++ ) {
			$now_gmt = gmdate( 'Y-m-d H:i:s' );
			$wpdb->insert(
				$submissions_table,
				array(
					'type'           => 'form',
					'post_id'        => $post_id,
					'element_id'     => $element_id,
					'form_name'      => $form_name,
					'user_id'        => 1,
					'user_ip'        => '127.0.0.1',
					'status'         => 'success',
					'created_at_gmt' => $now_gmt,
					'updated_at_gmt' => $now_gmt,
					'created_at'     => $now_gmt,
					'updated_at'     => $now_gmt,
				),
				array( '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
			);

			$submission_id = $wpdb->insert_id;
			$ids[]         = $submission_id;

			foreach ( $field_values as $key => $value ) {
				$wpdb->insert(
					$values_table,
					array(
						'submission_id' => $submission_id,
						'key'           => $key,
						'value'         => $value,
					),
					array( '%d', '%s', '%s' )
				);
			}
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return $ids;
	}

	/**
	 * Test that the tool class exists.
	 */
	public function test_tool_class_exists() {
		$this->assertTrue( class_exists( 'WP_MCP_AI_Tool_Get_Elementor_Form_Submissions' ), 'Tool class should exist.' );
	}

	/**
	 * Test that the tool implements required interfaces.
	 */
	public function test_tool_implements_interfaces() {
		$tool = new WP_MCP_AI_Tool_Get_Elementor_Form_Submissions();

		$this->assertInstanceOf( WP_MCP_AI_Tool_Interface::class, $tool, 'Tool should implement WP_MCP_AI_Tool_Interface.' );
		$this->assertInstanceOf( WP_MCP_AI_Tool_Capability_Flags_Interface::class, $tool, 'Tool should implement WP_MCP_AI_Tool_Capability_Flags_Interface.' );
	}

	/**
	 * Test tool slug.
	 */
	public function test_get_slug() {
		$tool = new WP_MCP_AI_Tool_Get_Elementor_Form_Submissions();

		$this->assertSame( 'get_elementor_form_submissions', $tool->get_slug() );
	}

	/**
	 * Test tool name is non-empty.
	 */
	public function test_get_name() {
		$tool = new WP_MCP_AI_Tool_Get_Elementor_Form_Submissions();

		$this->assertNotEmpty( $tool->get_name() );
	}

	/**
	 * Test tool description is non-empty.
	 */
	public function test_get_description() {
		$tool = new WP_MCP_AI_Tool_Get_Elementor_Form_Submissions();

		$this->assertNotEmpty( $tool->get_description() );
	}

	/**
	 * Test parameters schema.
	 */
	public function test_get_parameters_schema() {
		$tool   = new WP_MCP_AI_Tool_Get_Elementor_Form_Submissions();
		$schema = $tool->get_parameters_schema();

		$this->assertIsArray( $schema );
		$this->assertSame( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'required', $schema );

		$this->assertContains( 'form_post_id', $schema['required'] );

		$properties = $schema['properties'];
		$this->assertArrayHasKey( 'form_post_id', $properties );
		$this->assertArrayHasKey( 'element_id', $properties );
		$this->assertArrayHasKey( 'status', $properties );
		$this->assertArrayHasKey( 'limit', $properties );
		$this->assertArrayHasKey( 'transport', $properties );
		$this->assertArrayHasKey( 'connection_id', $properties );
	}

	/**
	 * Test capability flags.
	 */
	public function test_get_capability_flags() {
		$tool  = new WP_MCP_AI_Tool_Get_Elementor_Form_Submissions();
		$flags = $tool->get_capability_flags();

		$this->assertIsArray( $flags );
		$this->assertNotEmpty( $flags );
		$this->assertContains( 'read-only', $flags );
		$this->assertContains( 'local-only', $flags );
		$this->assertContains( 'requires-capability', $flags );
		$this->assertContains( 'requires-plugin', $flags );
	}

	/**
	 * Test tool definition metadata.
	 */
	public function test_get_definition() {
		$tool       = new WP_MCP_AI_Tool_Get_Elementor_Form_Submissions();
		$definition = $tool->get_definition();

		$this->assertIsArray( $definition );
		$this->assertArrayHasKey( 'name', $definition );
		$this->assertArrayHasKey( 'description', $definition );
		$this->assertArrayHasKey( 'toolkit', $definition );
		$this->assertArrayHasKey( 'risk_level', $definition );
	}

	/**
	 * Test that is_available returns true when tables exist.
	 */
	public function test_is_available_with_tables() {
		$tool = new WP_MCP_AI_Tool_Get_Elementor_Form_Submissions();

		$this->assertTrue( $tool->is_available(), 'Tool should be available when e_submissions table exists.' );
	}

	/**
	 * Test that execute returns submissions for a valid form.
	 */
	public function test_execute_returns_submissions() {
		$post_id = 42;
		$this->seed_submissions( $post_id, 'Contact Form', 'elem_abc', 3 );

		// Create and set an admin user.
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool = new WP_MCP_AI_Tool_Get_Elementor_Form_Submissions();

		$result = $tool->execute(
			array( 'form_post_id' => $post_id ),
			array( 'user_id' => $user_id )
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'local', $result['transport'] );
		$this->assertSame( $post_id, $result['form_post_id'] );
		$this->assertCount( 3, $result['submissions'] );
		$this->assertSame( 3, $result['total'] );

		// Verify submission shape.
		$first = $result['submissions'][0];
		$this->assertArrayHasKey( 'id', $first );
		$this->assertArrayHasKey( 'form_name', $first );
		$this->assertArrayHasKey( 'element_id', $first );
		$this->assertArrayHasKey( 'status', $first );
		$this->assertArrayHasKey( 'created_at', $first );
		$this->assertArrayHasKey( 'fields', $first );
		$this->assertSame( 'Contact Form', $first['form_name'] );

		// Verify fields are present.
		$this->assertNotEmpty( $first['fields'] );
		$this->assertArrayHasKey( 'name', $first['fields'][0] );
		$this->assertArrayHasKey( 'label', $first['fields'][0] );
		$this->assertArrayHasKey( 'value', $first['fields'][0] );
	}

	/**
	 * Test that execute respects the limit parameter.
	 */
	public function test_execute_respects_limit() {
		$post_id = 99;
		$this->seed_submissions( $post_id, 'Newsletter', 'elem_xyz', 10 );

		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool = new WP_MCP_AI_Tool_Get_Elementor_Form_Submissions();

		$result = $tool->execute(
			array(
				'form_post_id' => $post_id,
				'limit'        => 5,
			),
			array( 'user_id' => $user_id )
		);

		$this->assertCount( 5, $result['submissions'] );
		$this->assertSame( 10, $result['total'], 'Total count should reflect all submissions, not just the page.' );
	}

	/**
	 * Test that execute filters by status.
	 */
	public function test_execute_filters_by_status() {
		global $wpdb;
		$post_id = 77;
		$this->seed_submissions( $post_id, 'Signup', 'elem_signup', 3 );

		// Mark one submission as failed.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->prefix . 'e_submissions',
			array( 'status' => 'failed' ),
			array( 'post_id' => $post_id ),
			array( '%s' ),
			array( '%d' )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool = new WP_MCP_AI_Tool_Get_Elementor_Form_Submissions();

		$result_success = $tool->execute(
			array(
				'form_post_id' => $post_id,
				'status'       => 'success',
			),
			array( 'user_id' => $user_id )
		);

		$this->assertCount( 2, $result_success['submissions'], 'Should return only success submissions.' );

		$result_failed = $tool->execute(
			array(
				'form_post_id' => $post_id,
				'status'       => 'failed',
			),
			array( 'user_id' => $user_id )
		);

		$this->assertCount( 1, $result_failed['submissions'], 'Should return only failed submissions.' );
	}

	/**
	 * Test that execute filters by element_id.
	 */
	public function test_execute_filters_by_element_id() {
		$post_id = 55;
		$this->seed_submissions( $post_id, 'Form A', 'widget_aaa', 2 );
		$this->seed_submissions( $post_id, 'Form B', 'widget_bbb', 3 );

		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool = new WP_MCP_AI_Tool_Get_Elementor_Form_Submissions();

		$result = $tool->execute(
			array(
				'form_post_id' => $post_id,
				'element_id'   => 'widget_aaa',
			),
			array( 'user_id' => $user_id )
		);

		$this->assertCount( 2, $result['submissions'] );
		$this->assertSame( 'Form A', $result['submissions'][0]['form_name'] );
	}

	/**
	 * Test that execute returns error for missing form_post_id.
	 */
	public function test_execute_requires_form_post_id() {
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Get_Elementor_Form_Submissions();
		$result = $tool->execute( array(), array( 'user_id' => $user_id ) );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_missing_form_id', $result->get_error_code() );
	}

	/**
	 * Test that execute returns error for unauthorized users.
	 */
	public function test_execute_requires_capability() {
		$post_id = 66;
		$this->seed_submissions( $post_id, 'Private Form', 'elem_private', 2 );

		// Create a subscriber with no edit_posts capability.
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Get_Elementor_Form_Submissions();
		$result = $tool->execute(
			array( 'form_post_id' => $post_id ),
			array( 'user_id' => $user_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Test that execute returns empty list when no submissions exist.
	 */
	public function test_execute_returns_empty_for_no_submissions() {
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Get_Elementor_Form_Submissions();
		$result = $tool->execute(
			array( 'form_post_id' => 99999 ),
			array( 'user_id' => $user_id )
		);

		$this->assertIsArray( $result );
		$this->assertCount( 0, $result['submissions'] );
		$this->assertSame( 0, $result['total'] );
	}

	/**
	 * Test field normalization truncates long values.
	 */
	public function test_field_normalization_truncates_long_values() {
		$post_id      = 88;
		$long_message = str_repeat( 'A', 500 );
		$this->seed_submissions(
			$post_id,
			'Feedback',
			'elem_fb',
			1,
			array(
				'message' => $long_message,
			)
		);

		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Get_Elementor_Form_Submissions();
		$result = $tool->execute(
			array( 'form_post_id' => $post_id ),
			array( 'user_id' => $user_id )
		);

		$this->assertCount( 1, $result['submissions'] );
		$field_value = $result['submissions'][0]['fields'][0]['value'];
		$this->assertLessThanOrEqual( 210, strlen( $field_value ), 'Field value should be truncated to ~200 chars.' );
	}

	/**
	 * Test get_unavailable_reason returns a string.
	 */
	public function test_get_unavailable_reason() {
		$reason = WP_MCP_AI_Tool_Get_Elementor_Form_Submissions::get_unavailable_reason();
		$this->assertIsString( $reason );
		$this->assertNotEmpty( $reason );
	}

	/**
	 * Test get_required_capability.
	 */
	public function test_get_required_capability() {
		$tool = new WP_MCP_AI_Tool_Get_Elementor_Form_Submissions();
		$this->assertSame( 'edit_posts', $tool->get_required_capability() );
	}

	/**
	 * Test that fields are capped at 8 per submission.
	 */
	public function test_fields_capped_at_eight() {
		$post_id     = 44;
		$many_fields = array();
		for ( $i = 1; $i <= 15; $i++ ) {
			$many_fields[ 'field_' . $i ] = 'value_' . $i;
		}
		$this->seed_submissions( $post_id, 'Long Form', 'elem_long', 1, $many_fields );

		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Get_Elementor_Form_Submissions();
		$result = $tool->execute(
			array( 'form_post_id' => $post_id ),
			array( 'user_id' => $user_id )
		);

		$this->assertCount( 1, $result['submissions'] );
		$this->assertLessThanOrEqual( 8, count( $result['submissions'][0]['fields'] ), 'Should cap fields at 8.' );
	}
}
