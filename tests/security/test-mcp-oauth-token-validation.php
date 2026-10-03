<?php
/**
 * Security tests for the ChatGPT plugin OAuth contract — profile identity
 * stability/opacity and audience acceptance for tokens minted against the
 * MCP resource identifier.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */
class WP_MCP_AI_MCP_OAuth_Token_Validation_Test extends WP_UnitTestCase {

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * The profile id is stable for the same identity across repeated calls.
	 */
	public function test_profile_id_stable_for_same_user() {
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );

		$tool = new WP_MCP_AI_Tool_Nvoos_Get_Profile();

		$first  = $tool->execute( array(), array( 'user_id' => $user_id ) );
		$second = $tool->execute( array(), array( 'user_id' => $user_id ) );

		$this->assertFalse( is_wp_error( $first ) );
		$this->assertFalse( is_wp_error( $second ) );
		$this->assertSame( $first['id'], $second['id'] );
	}

	/**
	 * Distinct identities get distinct profile ids.
	 */
	public function test_profile_id_differs_across_users() {
		$user_a = self::factory()->user->create( array( 'role' => 'editor' ) );
		$user_b = self::factory()->user->create( array( 'role' => 'editor' ) );

		$tool = new WP_MCP_AI_Tool_Nvoos_Get_Profile();

		$profile_a = $tool->execute( array(), array( 'user_id' => $user_a ) );
		$profile_b = $tool->execute( array(), array( 'user_id' => $user_b ) );

		$this->assertNotSame( $profile_a['id'], $profile_b['id'] );
	}

	/**
	 * The profile id is opaque — no email, name, or raw user id inside it.
	 */
	public function test_profile_id_is_opaque() {
		$user_id = self::factory()->user->create(
			array(
				'role'       => 'editor',
				'user_email' => 'bridge-user@example.com',
			)
		);

		$tool    = new WP_MCP_AI_Tool_Nvoos_Get_Profile();
		$profile = $tool->execute( array(), array( 'user_id' => $user_id ) );

		// Opacity is guaranteed by HMAC construction: the id never encodes the
		// email or name. (A hex id can coincidentally contain digit substrings
		// of a user id, so substring checks against the numeric id are void.)
		$this->assertStringStartsWith( 'prf_', $profile['id'] );
		$this->assertStringNotContainsString( 'bridge-user', $profile['id'] );
		$this->assertSame( 20, strlen( $profile['id'] ) );
	}

	/**
	 * The envelope carries the OpenAI profile contract and a JSON text message.
	 */
	public function test_profile_envelope_contract() {
		$user_id = self::factory()->user->create(
			array(
				'role'         => 'editor',
				'user_email'   => 'bridge-user@example.com',
				'display_name' => 'Bridge User',
			)
		);

		$tool    = new WP_MCP_AI_Tool_Nvoos_Get_Profile();
		$profile = $tool->execute( array(), array( 'user_id' => $user_id ) );

		$this->assertArrayHasKey( 'id', $profile );
		$this->assertArrayHasKey( 'name', $profile );
		$this->assertArrayHasKey( 'email', $profile );
		$this->assertArrayHasKey( 'nickname', $profile );
		$this->assertArrayHasKey( 'message', $profile );

		$decoded = json_decode( $profile['message'], true );
		$this->assertSame( $profile['id'], $decoded['id'] );
		$this->assertSame( 'Bridge User', $decoded['name'] );
	}

	/**
	 * Assistant-scoped connections resolve to a stable per-assistant profile.
	 */
	public function test_profile_assistant_scoped_identity() {
		$assistant_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Bridge Assistant',
			)
		);

		$tool = new WP_MCP_AI_Tool_Nvoos_Get_Profile();

		$profile = $tool->execute( array(), array( 'assistant_id' => $assistant_id ) );

		$this->assertStringStartsWith( 'prf_', $profile['id'] );
		$this->assertSame( 'Bridge Assistant', $profile['name'] );
		$this->assertArrayNotHasKey( 'email', $profile );
	}

	/**
	 * With no identity context, the tool falls back to a stable site profile.
	 */
	public function test_profile_site_scoped_fallback() {
		$tool    = new WP_MCP_AI_Tool_Nvoos_Get_Profile();
		$profile = $tool->execute( array(), array() );

		$this->assertStringStartsWith( 'prf_', $profile['id'] );
		$this->assertNotEmpty( $profile['name'] );
	}

	/**
	 * The resource identifier is the MCP endpoint URL — the audience ChatGPT
	 * tokens are minted against.
	 */
	public function test_resource_identifier_is_mcp_endpoint() {
		$this->assertSame(
			rest_url( 'mcp-ai/v1/mcp' ),
			WP_MCP_AI_OAuth_Resource_Server::get_resource_identifier()
		);
	}

	/**
	 * Audience acceptance rejects malformed and empty audiences.
	 */
	public function test_audience_acceptance_rejects_invalid_input() {
		$this->assertFalse( WP_MCP_AI_OAuth_Resource_Server::audience_matches_resource( null ) );
		$this->assertFalse( WP_MCP_AI_OAuth_Resource_Server::audience_matches_resource( 42 ) );
		$this->assertFalse( WP_MCP_AI_OAuth_Resource_Server::audience_matches_resource( array() ) );
		$this->assertFalse( WP_MCP_AI_OAuth_Resource_Server::audience_matches_resource( array( 42 ) ) );
	}
}
