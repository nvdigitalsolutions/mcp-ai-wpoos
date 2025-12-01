<?php
/**
 * Tests for Tool Queue Profiles.
 *
 * @package WP_MCP_AI
 */

/**
 * Test Tool Queue Profiles functionality.
 *
 * @group rabbitmq
 */
class Test_Tool_Queue_Profiles extends WP_UnitTestCase {

	/**
	 * Test QUICK_READ profile has correct values.
	 */
	public function test_quick_read_profile() {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Queue_Profiles' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Tool_Queue_Profiles class not loaded.' );
		}

		$profile = WP_MCP_AI_Tool_Queue_Profiles::QUICK_READ;

		$this->assertEquals( 'high', $profile['priority'] );
		$this->assertEquals( 5, $profile['timeout'] );
		$this->assertEquals( 0, $profile['max_retries'] );
		$this->assertTrue( $profile['idempotent'] );
		$this->assertTrue( $profile['parallelizable'] );
	}

	/**
	 * Test STANDARD_READ profile has correct values.
	 */
	public function test_standard_read_profile() {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Queue_Profiles' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Tool_Queue_Profiles class not loaded.' );
		}

		$profile = WP_MCP_AI_Tool_Queue_Profiles::STANDARD_READ;

		$this->assertEquals( 'normal', $profile['priority'] );
		$this->assertEquals( 30, $profile['timeout'] );
		$this->assertEquals( 2, $profile['max_retries'] );
		$this->assertTrue( $profile['idempotent'] );
		$this->assertTrue( $profile['parallelizable'] );
	}

	/**
	 * Test WRITE_OPERATION profile has correct values.
	 */
	public function test_write_operation_profile() {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Queue_Profiles' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Tool_Queue_Profiles class not loaded.' );
		}

		$profile = WP_MCP_AI_Tool_Queue_Profiles::WRITE_OPERATION;

		$this->assertEquals( 'normal', $profile['priority'] );
		$this->assertEquals( 30, $profile['timeout'] );
		$this->assertEquals( 1, $profile['max_retries'] );
		$this->assertFalse( $profile['idempotent'] );
		$this->assertFalse( $profile['parallelizable'] );
	}

	/**
	 * Test VIDEO_GENERATION profile requires queue.
	 */
	public function test_video_generation_requires_queue() {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Queue_Profiles' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Tool_Queue_Profiles class not loaded.' );
		}

		$profile = WP_MCP_AI_Tool_Queue_Profiles::VIDEO_GENERATION;

		$this->assertEquals( 'tool.execution.async', $profile['queue'] );
		$this->assertEquals( 'low', $profile['priority'] );
		$this->assertTrue( $profile['requires_queue'] );
		$this->assertEquals( 600, $profile['timeout'] );
	}

	/**
	 * Test get() returns correct profile.
	 */
	public function test_get_returns_correct_profile() {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Queue_Profiles' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Tool_Queue_Profiles class not loaded.' );
		}

		$profile = WP_MCP_AI_Tool_Queue_Profiles::get( 'quick_read' );

		$this->assertEquals( WP_MCP_AI_Tool_Queue_Profiles::QUICK_READ, $profile );
	}

	/**
	 * Test get() returns null for unknown profile.
	 */
	public function test_get_returns_null_for_unknown() {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Queue_Profiles' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Tool_Queue_Profiles class not loaded.' );
		}

		$profile = WP_MCP_AI_Tool_Queue_Profiles::get( 'nonexistent_profile' );

		$this->assertNull( $profile );
	}

	/**
	 * Test all profiles are accessible via get().
	 */
	public function test_all_profiles_accessible() {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Queue_Profiles' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Tool_Queue_Profiles class not loaded.' );
		}

		$profile_names = array(
			'quick_read',
			'standard_read',
			'write_operation',
			'external_api',
			'image_generation',
			'video_generation',
			'web_crawl',
			'audio_processing',
			'realtime',
		);

		foreach ( $profile_names as $name ) {
			$profile = WP_MCP_AI_Tool_Queue_Profiles::get( $name );
			$this->assertNotNull( $profile, "Profile '$name' should be accessible via get()." );
			$this->assertIsArray( $profile, "Profile '$name' should be an array." );
		}
	}
}
