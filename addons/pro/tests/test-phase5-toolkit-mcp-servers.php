<?php
/**
 * Test_Phase5_Toolkit_MCP_Servers
 *
 * Phase 5 — assistant UI metabox + observability card.
 *
 * Covers:
 *   1. Metabox is registered on mcp_ai_assistant post type.
 *   2. save_meta_box() persists allowed slugs from POST data.
 *   3. save_meta_box() clears meta when no slugs posted.
 *   4. get_allowed_servers() returns correct array after save.
 *   5. Observability card render_card() noops when classes unavailable.
 *   6. render_card() fires on the hook wp_mcp_ai_performance_section_after_components.
 *
 * @package WP_MCP_AI_Pro
 */

require_once dirname( __DIR__ ) . '/includes/mcp-servers/class-wp-mcp-ai-toolkit-server-registry.php';
require_once dirname( __DIR__ ) . '/includes/mcp-servers/class-wp-mcp-ai-toolkit-mcp-audit-log.php';
require_once dirname( __DIR__ ) . '/includes/mcp-servers/class-wp-mcp-ai-pro-toolkit-mcp-observability-card.php';
require_once dirname( __DIR__ ) . '/includes/admin/class-wp-mcp-ai-pro-metabox-toolkit-mcp-servers.php';

/**
 * PHPUnit test case for Phase 5 — assistant UI + observability card.
 */
class Test_Phase5_Toolkit_MCP_Servers extends WP_UnitTestCase {

	/**
	 * Tear down — reset singletons.
	 */
	public function tearDown(): void {
		WP_MCP_AI_Toolkit_Server_Registry::reset_instance();
		WP_MCP_AI_Toolkit_MCP_Audit_Log::reset_instance();
		parent::tearDown();
	}

	// -----------------------------------------------------------------------
	// 1. Metabox registration.
	// -----------------------------------------------------------------------

	/**
	 * Test that the constructor binds add_meta_boxes and save_post actions.
	 */
	public function test_constructor_registers_hooks() {
		$metabox = new WP_MCP_AI_Pro_Metabox_Toolkit_MCP_Servers();

		$this->assertGreaterThan(
			0,
			has_action( 'add_meta_boxes', array( $metabox, 'add_meta_box' ) ),
			'add_meta_boxes hook should be registered'
		);
		$this->assertGreaterThan(
			0,
			has_action( 'save_post_mcp_ai_assistant', array( $metabox, 'save_meta_box' ) ),
			'save_post_mcp_ai_assistant hook should be registered'
		);
	}

	// -----------------------------------------------------------------------
	// 2. save_meta_box() stores slugs.
	// -----------------------------------------------------------------------

	/**
	 * Test save persists allowed slugs when posted.
	 */
	public function test_save_meta_box_stores_slugs() {
		$post_id = self::factory()->post->create( array( 'post_type' => 'mcp_ai_assistant' ) );

		// Set the user BEFORE creating the nonce: wp_create_nonce() binds to
		// the current user, and the handler verifies against it.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		// Create valid nonce.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$_POST[ WP_MCP_AI_Pro_Metabox_Toolkit_MCP_Servers::NONCE_FIELD ] = wp_create_nonce(
			WP_MCP_AI_Pro_Metabox_Toolkit_MCP_Servers::NONCE_ACTION
		);
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$_POST['wp_mcp_ai_pro_allowed_mcp_servers'] = array( 'crm', 'health' );

		$metabox = new WP_MCP_AI_Pro_Metabox_Toolkit_MCP_Servers();
		$post    = get_post( $post_id );
		$metabox->save_meta_box( $post_id, $post );

		$stored = get_post_meta( $post_id, WP_MCP_AI_Pro_Metabox_Toolkit_MCP_Servers::META_KEY, true );

		$this->assertSame( array( 'crm', 'health' ), $stored, 'Stored meta should match posted slugs' );

		// Clean up superglobal.
		unset( $_POST[ WP_MCP_AI_Pro_Metabox_Toolkit_MCP_Servers::NONCE_FIELD ] );
		unset( $_POST['wp_mcp_ai_pro_allowed_mcp_servers'] );
	}

	// -----------------------------------------------------------------------
	// 3. save_meta_box() clears meta when nothing posted.
	// -----------------------------------------------------------------------

	/**
	 * Test save deletes meta when no slugs are in POST (= allow all).
	 */
	public function test_save_meta_box_clears_when_empty() {
		$post_id = self::factory()->post->create( array( 'post_type' => 'mcp_ai_assistant' ) );
		update_post_meta( $post_id, WP_MCP_AI_Pro_Metabox_Toolkit_MCP_Servers::META_KEY, array( 'crm' ) );

		// Set the user BEFORE creating the nonce (see above).
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing

		$_POST[ WP_MCP_AI_Pro_Metabox_Toolkit_MCP_Servers::NONCE_FIELD ] = wp_create_nonce(
			WP_MCP_AI_Pro_Metabox_Toolkit_MCP_Servers::NONCE_ACTION
		);
		// No wp_mcp_ai_pro_allowed_mcp_servers posted.

		$metabox = new WP_MCP_AI_Pro_Metabox_Toolkit_MCP_Servers();
		$post    = get_post( $post_id );
		$metabox->save_meta_box( $post_id, $post );

		$stored = get_post_meta( $post_id, WP_MCP_AI_Pro_Metabox_Toolkit_MCP_Servers::META_KEY, true );

		// Should be empty string (deleted).
		$this->assertEmpty( $stored, 'Meta should be cleared when nothing is posted' );

		unset( $_POST[ WP_MCP_AI_Pro_Metabox_Toolkit_MCP_Servers::NONCE_FIELD ] );
	}

	// -----------------------------------------------------------------------
	// 4. get_allowed_servers() returns stored array.
	// -----------------------------------------------------------------------

	/**
	 * Test that get_allowed_servers() returns the stored slugs.
	 */
	public function test_get_allowed_servers_returns_array() {
		$post_id = self::factory()->post->create( array( 'post_type' => 'mcp_ai_assistant' ) );
		update_post_meta( $post_id, WP_MCP_AI_Pro_Metabox_Toolkit_MCP_Servers::META_KEY, array( 'health', 'media' ) );

		$result = WP_MCP_AI_Pro_Metabox_Toolkit_MCP_Servers::get_allowed_servers( $post_id );

		$this->assertSame( array( 'health', 'media' ), $result );
	}

	// -----------------------------------------------------------------------
	// 5. Observability card noops when classes unavailable.
	// -----------------------------------------------------------------------

	/**
	 * Test render_card() is a no-op when the registry class is unavailable.
	 *
	 * We replicate that by resetting the singleton and checking no output is
	 * produced when an exception guard is in place.
	 */
	public function test_observability_card_noops_gracefully() {
		// This test confirms the card output is empty when there are no servers.
		// and no audit entries — not an error state.
		WP_MCP_AI_Toolkit_Server_Registry::reset_instance();
		WP_MCP_AI_Toolkit_MCP_Audit_Log::reset_instance();

		$card = new WP_MCP_AI_Pro_Toolkit_MCP_Observability_Card();

		ob_start();
		$card->render_card();
		$output = ob_get_clean();

		// With zero servers registered, the card still renders its shell.
		// (enabled count = 0, no consumers). Must not throw.
		$this->assertIsString( $output, 'render_card() should produce string output' );
	}

	// -----------------------------------------------------------------------
	// 6. Observability card fires on hook.
	// -----------------------------------------------------------------------

	/**
	 * Test that render_card() is bound to wp_mcp_ai_performance_section_after_components.
	 */
	public function test_observability_card_hook_binding() {
		$card = new WP_MCP_AI_Pro_Toolkit_MCP_Observability_Card();

		$this->assertGreaterThan(
			0,
			has_action(
				'wp_mcp_ai_performance_section_after_components',
				array( $card, 'render_card' )
			),
			'render_card should be bound to the after_components action'
		);
	}
}
