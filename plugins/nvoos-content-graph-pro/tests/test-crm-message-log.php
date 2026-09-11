<?php
/**
 * Characterization tests for the Wave F2 inbound dependency slice — the
 * ported `WP_MCP_AI_CRM_Message_Log`.
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   class (classmap-autoloaded); the byte-identical log/dedup/query
 *   contracts are asserted.
 * - Standalone matrix (base plugin absent): the ported copy in
 *   `src/tools/crm/` is asserted in full, including the serving source.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * CRM message log tests.
 */
class Test_Crm_Message_Log extends WP_UnitTestCase {

	/**
	 * Snapshot of the shared settings option before mutation.
	 *
	 * @var mixed
	 */
	private $settings_snapshot = null;

	/**
	 * Snapshot the shared settings option.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		$this->settings_snapshot = get_option( 'wp_mcp_ai_settings', null );
	}

	/**
	 * Restore the shared settings option and clean the dedup map.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		if ( null === $this->settings_snapshot ) {
			delete_option( 'wp_mcp_ai_settings' );
		} else {
			update_option( 'wp_mcp_ai_settings', $this->settings_snapshot );
		}
		delete_option( WP_MCP_AI_CRM_Message_Log::DEDUP_OPTION );
		parent::tearDown();
	}

	/**
	 * Enable the CRM toolkit flag in the shared settings option.
	 *
	 * @return void
	 */
	private function enable_crm_toolkit(): void {
		$settings                       = get_option( 'wp_mcp_ai_settings', array() );
		$settings                       = is_array( $settings ) ? $settings : array();
		$settings['enable_crm_toolkit'] = 1;
		update_option( 'wp_mcp_ai_settings', $settings );
	}

	/**
	 * The serving source must follow the ownership boundary.
	 */
	public function test_serving_source(): void {
		$reflection = new ReflectionClass( 'WP_MCP_AI_CRM_Message_Log' );
		$file       = str_replace( '\\', '/', (string) $reflection->getFileName() );

		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->assertStringContainsString( 'addons/pro/includes/tools/crm/class-wp-mcp-ai-crm-message-log.php', $file );
		} else {
			$this->assertStringContainsString( 'nvoos-content-graph-pro/src/tools/crm/class-wp-mcp-ai-crm-message-log.php', $file );
		}
	}

	/**
	 * The message CPT registration must be toolkit-gated.
	 */
	public function test_cpt_registration_gate(): void {
		$this->assertSame( 'mcp_crm_message', WP_MCP_AI_CRM_Message_Log::POST_TYPE );

		// Disabled: registration is a no-op.
		delete_option( 'wp_mcp_ai_settings' );
		WP_MCP_AI_CRM_Message_Log::register_post_type();
		$this->assertFalse( post_type_exists( 'mcp_crm_message' ) );

		// Enabled: registered.
		$this->enable_crm_toolkit();
		WP_MCP_AI_CRM_Message_Log::register_post_type();
		$this->assertTrue( post_type_exists( 'mcp_crm_message' ) );
	}

	/**
	 * log() must persist the structured message record with meta.
	 */
	public function test_log_round_trip(): void {
		$this->enable_crm_toolkit();
		WP_MCP_AI_CRM_Message_Log::register_post_type();

		$post_id = WP_MCP_AI_CRM_Message_Log::log(
			array(
				'message_id'   => 'gmail-123',
				'thread_id'    => 'thread-9',
				'channel'      => 'email',
				'sender_email' => 'jane@example.com',
				'sender_name'  => 'Jane',
				'subject'      => 'Interested in your product',
				'body'         => 'Please send pricing.',
				'contact_id'   => 7,
				'source'       => 'gmail_import',
			)
		);

		$this->assertIsInt( $post_id );
		$this->assertGreaterThan( 0, $post_id );
		$this->assertSame( 'mcp_crm_message', get_post_type( $post_id ) );
		$this->assertSame( 'gmail-123', get_post_meta( $post_id, '_message_id', true ) );
		$this->assertSame( 'email', get_post_meta( $post_id, '_channel', true ) );
		$this->assertSame( 'jane@example.com', get_post_meta( $post_id, '_sender_email', true ) );
		$this->assertSame( 7, (int) get_post_meta( $post_id, '_contact_id', true ) );

		$this->assertSame( $post_id, WP_MCP_AI_CRM_Message_Log::find_by_message_id( 'email', 'gmail-123' ) );
	}

	/**
	 * Duplicate message IDs must be deduplicated (idempotent imports).
	 */
	public function test_dedup(): void {
		$this->enable_crm_toolkit();
		WP_MCP_AI_CRM_Message_Log::register_post_type();

		$args = array(
			'message_id' => 'sms-abc',
			'channel'    => 'sms',
			'body'       => 'Hello',
		);

		$first = WP_MCP_AI_CRM_Message_Log::log( $args );
		$this->assertGreaterThan( 0, $first );

		$second = WP_MCP_AI_CRM_Message_Log::log( $args );
		$this->assertSame( $first, $second );

		$this->assertTrue( WP_MCP_AI_CRM_Message_Log::is_duplicate( 'sms', 'sms-abc' ) );
	}

	/**
	 * Contact/ticket scoped queries must return the formatted messages.
	 */
	public function test_scoped_queries(): void {
		$this->enable_crm_toolkit();
		WP_MCP_AI_CRM_Message_Log::register_post_type();

		WP_MCP_AI_CRM_Message_Log::log(
			array(
				'message_id' => 'msg-1',
				'channel'    => 'whatsapp',
				'contact_id' => 42,
				'ticket_id'  => 77,
				'body'       => 'Support please',
			)
		);

		$for_contact = WP_MCP_AI_CRM_Message_Log::get_messages_for_contact( 42 );
		$this->assertCount( 1, $for_contact );
		$this->assertSame( 'whatsapp', $for_contact[0]['channel'] );
		$this->assertSame( 42, $for_contact[0]['contact_id'] );

		$for_ticket = WP_MCP_AI_CRM_Message_Log::get_messages_for_ticket( 77 );
		$this->assertCount( 1, $for_ticket );
		$this->assertSame( 77, $for_ticket[0]['ticket_id'] );
	}

	/**
	 * Volume stats must aggregate the byte-identical shape.
	 */
	public function test_volume_stats(): void {
		$this->enable_crm_toolkit();
		WP_MCP_AI_CRM_Message_Log::register_post_type();

		WP_MCP_AI_CRM_Message_Log::log(
			array(
				'message_id' => 'vol-1',
				'channel'    => 'email',
				'source'     => 'gmail_import',
				'body'       => 'One',
			)
		);

		$stats = WP_MCP_AI_CRM_Message_Log::get_volume_stats( 7 );
		$this->assertIsArray( $stats );
		$this->assertGreaterThanOrEqual( 1, $stats['total'] );
		$this->assertGreaterThanOrEqual( 1, $stats['by_channel']['email'] );
	}

	/**
	 * Link helpers must stamp the contact/ticket meta.
	 */
	public function test_link_helpers(): void {
		$this->enable_crm_toolkit();
		WP_MCP_AI_CRM_Message_Log::register_post_type();

		$post_id = WP_MCP_AI_CRM_Message_Log::log(
			array(
				'message_id' => 'link-1',
				'channel'    => 'webchat',
				'body'       => 'Hi',
			)
		);

		WP_MCP_AI_CRM_Message_Log::link_to_contact( $post_id, 99 );
		$this->assertSame( 99, (int) get_post_meta( $post_id, '_contact_id', true ) );

		WP_MCP_AI_CRM_Message_Log::link_to_ticket( $post_id, 55 );
		$this->assertSame( 55, (int) get_post_meta( $post_id, '_ticket_id', true ) );
	}
}
