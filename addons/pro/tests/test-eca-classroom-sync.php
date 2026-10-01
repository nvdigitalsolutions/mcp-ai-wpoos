<?php
/**
 * Tests for the Google Classroom ECA sync engine.
 *
 * Covers roster upsert idempotency, identity matching, MIS-wins behaviour,
 * push-notification deltas, and reconcile scheduling gates — with a mocked
 * HTTP transport, no live Google calls.
 *
 * @package WP_MCP_AI
 */

/**
 * Classroom ECA sync engine test case.
 */
class Test_ECA_Classroom_Sync extends WP_UnitTestCase {

	/**
	 * Set up before each test.
	 */
	public function setUp(): void {
		parent::setUp();

		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_eca_management'            => true,
				'enable_eca_classroom_integration' => true,
			)
		);

		if ( ! class_exists( 'WP_MCP_AI_ECA_CPT' ) ) {
			require_once dirname( __DIR__ ) . '/includes/class-wp-mcp-ai-eca-cpt.php';
		}

		WP_MCP_AI_ECA_CPT::register_post_types();

		require_once dirname( __DIR__ ) . '/includes/eca/class-wp-mcp-ai-eca-enrollments-db.php';
		require_once dirname( __DIR__ ) . '/includes/eca/class-wp-mcp-ai-eca-classroom-helper.php';
		require_once WP_MCP_AI_PATH . 'includes/google/class-wp-mcp-ai-google-classroom-scopes.php';
		require_once WP_MCP_AI_PATH . 'includes/google/class-wp-mcp-ai-google-oauth-service.php';
		require_once WP_MCP_AI_PATH . 'includes/google/class-wp-mcp-ai-google-classroom-client.php';
		require_once WP_MCP_AI_PATH . 'includes/google/class-wp-mcp-ai-google-classroom-credentials.php';
		require_once WP_MCP_AI_PATH . 'includes/google/class-wp-mcp-ai-google-classroom-push.php';
		require_once dirname( __DIR__ ) . '/includes/eca/class-wp-mcp-ai-eca-classroom-sync.php';

		// PHPUnit never runs activation hooks — install the custom tables the
		// notification delta path writes to.
		WP_MCP_AI_ECA_Enrollments_DB::create_tables();
	}

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		delete_option( 'wp_mcp_ai_settings' );
		parent::tearDown();
	}

	/**
	 * A representative Classroom roster member.
	 *
	 * @return array<string,mixed>
	 */
	private function member_fixture() {
		return array(
			'userId'  => '100000000000000000001',
			'profile' => array(
				'name'         => array( 'fullName' => 'Ada Lovelace' ),
				'emailAddress' => 'ada@example.com',
			),
		);
	}

	/**
	 * Upserting the same member twice must create exactly one student record.
	 */
	public function test_upsert_is_idempotent() {
		WP_MCP_AI_ECA_Classroom_Sync::upsert_student_from_member( $this->member_fixture(), false );
		WP_MCP_AI_ECA_Classroom_Sync::upsert_student_from_member( $this->member_fixture(), false );

		$students = get_posts(
			array(
				'post_type'   => 'mcp_ai_student',
				'post_status' => 'any',
				'numberposts' => -1,
			)
		);

		$this->assertCount( 1, $students );
		$this->assertSame( 'Ada Lovelace', $students[0]->post_title );
	}

	/**
	 * A member matching an existing student by email must link to that record
	 * instead of creating a duplicate.
	 */
	public function test_upsert_matches_by_email_fallback() {
		$student_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_student',
				'post_status' => 'publish',
				'post_title'  => 'Ada Lovelace',
			)
		);
		update_post_meta( $student_id, '_student_email', 'ada@example.com' );

		WP_MCP_AI_ECA_Classroom_Sync::upsert_student_from_member( $this->member_fixture(), false );

		$students = get_posts(
			array(
				'post_type'   => 'mcp_ai_student',
				'post_status' => 'any',
				'numberposts' => -1,
			)
		);

		$this->assertCount( 1, $students );
		$this->assertSame( '100000000000000000001', get_post_meta( $student_id, '_student_google_id', true ) );
	}

	/**
	 * MIS-owned records keep their data: the Google ID links but the names are
	 * untouched when MIS wins.
	 */
	public function test_mis_wins_protects_existing_fields() {
		$student_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_student',
				'post_status' => 'publish',
				'post_title'  => 'Ada Lovelace',
			)
		);
		update_post_meta( $student_id, '_student_isams_id', 'IS-42' );
		update_post_meta( $student_id, '_student_first_name', 'MIS First' );
		update_post_meta( $student_id, '_student_email', 'ada@example.com' );

		$touched = WP_MCP_AI_ECA_Classroom_Sync::upsert_student_from_member( $this->member_fixture(), true );

		$this->assertSame( 0, $touched );
		$this->assertSame( '100000000000000000001', get_post_meta( $student_id, '_student_google_id', true ) );
		$this->assertSame( 'MIS First', get_post_meta( $student_id, '_student_first_name', true ) );
	}

	/**
	 * A CREATED roster notification must upsert the student and enrol them in
	 * the linked ECA.
	 */
	public function test_notification_created_enrols_linked_student() {
		$eca_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_eca',
				'post_status' => 'publish',
				'post_title'  => 'Chess Club',
			)
		);
		update_post_meta( $eca_id, '_eca_google_course_id', '12345' );

		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			require_once dirname( __DIR__ ) . '/includes/class-wp-mcp-ai-pro-remote-site-manager.php';
		}

		$connection_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection(
			array(
				'name'            => 'Classroom Test',
				'url'             => 'https://classroom.googleapis.com/v1',
				'connection_type' => 'google_classroom',
				'auth_type'       => 'none',
				'client_id'       => 'test-client-id.apps.googleusercontent.com',
				'client_secret'   => 'test-client-secret',
				'refresh_token'   => 'test-refresh-token',
				'enabled'         => true,
			)
		);

		if ( is_wp_error( $connection_id ) ) {
			$this->markTestSkipped( 'Could not seed a Remote Sites connection.' );
		}

		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) {
				if ( false !== strpos( $url, 'oauth2.googleapis.com/token' ) ) {
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => wp_json_encode(
							array(
								'access_token' => 'tok',
								'expires_in'   => 3600,
							)
						),
					);
				}

				if ( false !== strpos( $url, 'classroom.googleapis.com' ) ) {
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => wp_json_encode(
							array(
								'userId'  => '100000000000000000001',
								'profile' => array(
									'name'         => array( 'fullName' => 'Ada Lovelace' ),
									'emailAddress' => 'ada@example.com',
								),
							)
						),
					);
				}

				return false;
			},
			10,
			3
		);

		WP_MCP_AI_ECA_Classroom_Sync::handle_notification_event(
			array(
				'connection_id' => $connection_id,
				'collection'    => 'courses.students',
				'event_type'    => 'CREATED',
				'resource_id'   => array(
					'courseId' => '12345',
					'userId'   => '100000000000000000001',
				),
			)
		);

		remove_all_filters( 'pre_http_request' );

		$student_id = WP_MCP_AI_ECA_Classroom_Helper::find_student_by_google_id( '100000000000000000001' );

		$this->assertGreaterThan( 0, $student_id );
		$this->assertTrue( WP_MCP_AI_ECA_Enrollments_DB::is_enrolled( $student_id, $eca_id, 'school', 0 ) );

		delete_option( 'wp_mcp_ai_pro_remote_sites' );
	}

	/**
	 * No sync targets exist without a sync-enabled Classroom connection.
	 */
	public function test_no_sync_targets_without_connection() {
		delete_option( 'wp_mcp_ai_pro_remote_sites' );

		$this->assertFalse( WP_MCP_AI_ECA_Classroom_Sync::has_sync_targets() );
	}

	/**
	 * The jittered interval never falls below the minimum.
	 */
	public function test_jittered_interval_floor() {
		add_filter( 'wp_mcp_ai_eca_classroom_sync_interval', '__return_zero' );

		$interval = WP_MCP_AI_ECA_Classroom_Sync::jittered_interval();

		remove_filter( 'wp_mcp_ai_eca_classroom_sync_interval', '__return_zero' );

		$this->assertGreaterThanOrEqual( WP_MCP_AI_ECA_Classroom_Sync::MIN_INTERVAL, $interval );
	}
}
