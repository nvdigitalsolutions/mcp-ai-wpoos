<?php
/**
 * Tests for JetEngine AI Chat Transcripts Custom Content Type registration.
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for JetEngine chat transcripts CCT.
 */
class WP_MCP_AI_JetEngine_Chat_Transcripts_CCT_Test extends WP_UnitTestCase {

	/**
	 * Test that the CCT slug is returned correctly.
	 */
	public function test_get_slug_returns_correct_value() {
		$this->assertSame( 'ai_chat_transcripts', WP_MCP_AI_JetEngine_CCT::get_slug() );
	}

	/**
	 * Test that registration request is properly structured.
	 */
	public function test_registration_request_structure() {
		$reflection = new ReflectionMethod( WP_MCP_AI_JetEngine_CCT::class, 'get_registration_request' );
		$reflection->setAccessible( true );
		$request = $reflection->invoke( null );

		$this->assertIsArray( $request );
		$this->assertArrayHasKey( 'name', $request );
		$this->assertArrayHasKey( 'slug', $request );
		$this->assertArrayHasKey( 'args', $request );
		$this->assertArrayHasKey( 'meta_fields', $request );
		$this->assertSame( 'ai_chat_transcripts', $request['slug'] );
	}

	/**
	 * Test that CCT args are properly configured.
	 *
	 * This test verifies that REST API endpoints are enabled for the
	 * chat transcripts CCT, which is critical for save→retrieve functionality.
	 */
	public function test_cct_args_configuration() {
		$reflection = new ReflectionMethod( WP_MCP_AI_JetEngine_CCT::class, 'get_cct_args' );
		$reflection->setAccessible( true );
		$args = $reflection->invoke( null, 'AI Chat Transcripts' );

		$this->assertIsArray( $args );
		$this->assertArrayHasKey( 'slug', $args );
		$this->assertArrayHasKey( 'icon', $args );
		$this->assertArrayHasKey( 'capability', $args );
		$this->assertArrayHasKey( 'rest_get_enabled', $args );
		$this->assertArrayHasKey( 'rest_post_enabled', $args );
		$this->assertArrayHasKey( 'rest_put_enabled', $args );
		$this->assertArrayHasKey( 'rest_delete_enabled', $args );

		$this->assertSame( 'ai_chat_transcripts', $args['slug'] );
		$this->assertSame( 'dashicons-format-chat', $args['icon'] );
		$this->assertSame( 'manage_options', $args['capability'] );

		// These assertions are critical - REST endpoints must be enabled
		// for the save→retrieve cycle to work properly.
		$this->assertTrue( $args['rest_get_enabled'], 'REST GET must be enabled to retrieve transcripts' );
		$this->assertTrue( $args['rest_post_enabled'], 'REST POST must be enabled to create transcripts' );
		$this->assertTrue( $args['rest_put_enabled'], 'REST PUT must be enabled to update transcripts' );
		$this->assertFalse( $args['rest_delete_enabled'], 'REST DELETE should remain disabled for safety' );
	}

	/**
	 * Test that REST access permissions are properly configured.
	 */
	public function test_rest_access_permissions() {
		$reflection = new ReflectionMethod( WP_MCP_AI_JetEngine_CCT::class, 'get_cct_args' );
		$reflection->setAccessible( true );
		$args = $reflection->invoke( null, 'AI Chat Transcripts' );

		$this->assertArrayHasKey( 'rest_get_access', $args );
		$this->assertArrayHasKey( 'rest_post_access', $args );
		$this->assertArrayHasKey( 'rest_put_access', $args );

		$this->assertSame( 'manage_options', $args['rest_get_access'] );
		$this->assertSame( 'edit_posts', $args['rest_post_access'] );
		$this->assertSame( 'edit_posts', $args['rest_put_access'] );
	}

	/**
	 * Test that meta fields are properly defined.
	 */
	public function test_meta_fields_configuration() {
		$reflection = new ReflectionMethod( WP_MCP_AI_JetEngine_CCT::class, 'get_meta_fields' );
		$reflection->setAccessible( true );
		$fields = $reflection->invoke( null );

		$this->assertIsArray( $fields );
		$this->assertNotEmpty( $fields );

		// Check that essential fields are present.
		$field_names = array_column( $fields, 'name' );
		$this->assertContains( 'session_key', $field_names );
		$this->assertContains( 'user_id', $field_names );
		$this->assertContains( 'assistant_id', $field_names );
		$this->assertContains( 'assistant_model', $field_names );
		$this->assertContains( 'request_payload', $field_names );
		$this->assertContains( 'response_payload', $field_names );
	}

	/**
	 * Test that session_key field is marked as required.
	 */
	public function test_session_key_field_is_required() {
		$reflection = new ReflectionMethod( WP_MCP_AI_JetEngine_CCT::class, 'get_meta_fields' );
		$reflection->setAccessible( true );
		$fields = $reflection->invoke( null );

		$session_key_field = null;
		foreach ( $fields as $field ) {
			if ( 'session_key' === $field['name'] ) {
				$session_key_field = $field;
				break;
			}
		}

		$this->assertNotNull( $session_key_field, 'session_key field should exist' );
		$this->assertArrayHasKey( 'is_required', $session_key_field );
		$this->assertTrue( $session_key_field['is_required'], 'session_key should be required' );
	}

	/**
	 * Test that create_index is enabled for better query performance.
	 */
	public function test_create_index_is_enabled() {
		$reflection = new ReflectionMethod( WP_MCP_AI_JetEngine_CCT::class, 'get_cct_args' );
		$reflection->setAccessible( true );
		$args = $reflection->invoke( null, 'AI Chat Transcripts' );

		$this->assertArrayHasKey( 'create_index', $args );
		$this->assertTrue( $args['create_index'], 'Database indexing should be enabled for performance' );
	}
}
