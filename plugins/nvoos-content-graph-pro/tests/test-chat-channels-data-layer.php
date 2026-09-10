<?php
/**
 * Characterization tests for the Wave F5 chat-channels data layer — the
 * channel contacts/messages CPT + CCT classes, the Google Chat webhook
 * handler, the webhook init, and the optimization class ported from the
 * base Pro addon.
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   classes (classmap-autoloaded); the byte-identical surfaces are
 *   asserted.
 * - Standalone matrix (base plugin absent): the ported copies in `src/` +
 *   `src/ChatChannels/` + `src/tools/chat-channels/` are asserted in full,
 *   including the slim init's file-gate targets and the standalone-only
 *   tool wiring scaffolding.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * Chat Channels data layer tests.
 */
class Test_Chat_Channels_Data_Layer extends WP_UnitTestCase {

	/**
	 * The ported symbols must follow the ownership boundary.
	 */
	public function test_serving_sources(): void {
		$symbols = array(
			'WP_MCP_AI_Channel_Contacts_CPT'       => 'class-wp-mcp-ai-channel-contacts-cpt.php',
			'WP_MCP_AI_Channel_Messages_CPT'       => 'class-wp-mcp-ai-channel-messages-cpt.php',
			'WP_MCP_AI_Channel_Contacts_CCT'       => 'class-wp-mcp-ai-channel-contacts-cct.php',
			'WP_MCP_AI_Channel_Messages_CCT'       => 'class-wp-mcp-ai-channel-messages-cct.php',
			'WP_MCP_AI_Chat_Channels_Optimization' => 'tools/chat-channels/class-wp-mcp-ai-chat-channels-optimization.php',
		);

		foreach ( $symbols as $class => $file ) {
			$reflection = new ReflectionClass( $class );
			$path       = str_replace( '\\', '/', (string) $reflection->getFileName() );
			if ( defined( 'WP_MCP_AI_PATH' ) ) {
				$this->assertStringContainsString( 'addons/pro/includes/' . $file, $path, $class );
			} else {
				$this->assertStringContainsString( 'nvoos-content-graph-pro/src/' . $file, $path, $class );
			}
		}

		// The webhook handler ships under the base's `includes/src/ChatChannels/`
		// subtree — the monolith and standalone path shapes differ.
		$handler_path = str_replace( '\\', '/', (string) ( new ReflectionClass( 'WP_MCP_AI_Google_Chat_Webhook_Handler' ) )->getFileName() );
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->assertStringContainsString( 'addons/pro/includes/src/ChatChannels/class-wp-mcp-ai-google-chat-webhook-handler.php', $handler_path );
		} else {
			$this->assertStringContainsString( 'nvoos-content-graph-pro/src/ChatChannels/class-wp-mcp-ai-google-chat-webhook-handler.php', $handler_path );
		}
	}

	/**
	 * The channel CPT + CCT constants must be byte-identical.
	 */
	public function test_constants(): void {
		$this->assertSame( 'mcp_chan_contact', WP_MCP_AI_Channel_Contacts_CPT::POST_TYPE );
		$this->assertSame( 'mcp_chan_message', WP_MCP_AI_Channel_Messages_CPT::POST_TYPE );
		$this->assertSame( 'channel_contacts', WP_MCP_AI_Channel_Contacts_CCT::SLUG );
		$this->assertSame( 42000, WP_MCP_AI_Channel_Contacts_CCT::FIELD_ID_BASE );
		$this->assertSame( 'channel_messages', WP_MCP_AI_Channel_Messages_CCT::SLUG );
		$this->assertSame( 41000, WP_MCP_AI_Channel_Messages_CCT::FIELD_ID_BASE );
		$this->assertSame( 'new', WP_MCP_AI_Channel_Contacts_CCT::STATUS_NEW );
		$this->assertSame( 'active', WP_MCP_AI_Channel_Contacts_CCT::STATUS_ACTIVE );
		$this->assertSame( 'resolved', WP_MCP_AI_Channel_Contacts_CCT::STATUS_RESOLVED );
		$this->assertSame( 'blocked', WP_MCP_AI_Channel_Contacts_CCT::STATUS_BLOCKED );
	}

	/**
	 * The optimization constants + hook wiring must be byte-identical.
	 */
	public function test_optimization_contracts(): void {
		$this->assertSame( 'wp_mcp_ai_cc_daily_optimize', WP_MCP_AI_Chat_Channels_Optimization::OPTIMIZE_HOOK );
		$this->assertSame( 90, WP_MCP_AI_Chat_Channels_Optimization::DEFAULT_RETENTION_DAYS );
		$this->assertSame( 'wp_mcp_ai_chat_channels_toolkit_settings', WP_MCP_AI_Chat_Channels_Optimization::SETTINGS_OPTION );
	}

	/**
	 * The channel CPT bootstrap must wire the init registration hooks and
	 * the register methods must register the post types directly (no
	 * settings gate — byte-identical; the registration is asserted without
	 * re-firing `init` because the WooCommerce block registry re-registration
	 * pollutes the incorrect-usage buffer).
	 */
	public function test_cpt_bootstrap_and_registration(): void {
		WP_MCP_AI_Channel_Contacts_CPT::bootstrap();
		WP_MCP_AI_Channel_Messages_CPT::bootstrap();
		$this->assertNotFalse( has_action( 'init', array( 'WP_MCP_AI_Channel_Contacts_CPT', 'register_post_type' ) ) );
		$this->assertNotFalse( has_action( 'init', array( 'WP_MCP_AI_Channel_Messages_CPT', 'register_post_type' ) ) );

		WP_MCP_AI_Channel_Contacts_CPT::register_post_type();
		WP_MCP_AI_Channel_Messages_CPT::register_post_type();
		$this->assertTrue( post_type_exists( 'mcp_chan_contact' ) );
		$this->assertTrue( post_type_exists( 'mcp_chan_message' ) );
	}

	/**
	 * Standalone only: the slim init's file targets must exist, the tool
	 * filter must carry zero chat-channels tools (the map fills as the tool
	 * batch lands), and the standalone helper functions must load.
	 */
	public function test_init_gate_targets_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base chat-channels init wires the slice at boot.' );
		}

		$targets = array(
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-channel-contacts-cpt.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-channel-messages-cpt.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-channel-contacts-cct.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-channel-messages-cct.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/google-chat-webhook-init.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/ChatChannels/class-wp-mcp-ai-google-chat-webhook-handler.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/chat-channels/class-wp-mcp-ai-chat-channels-optimization.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/chat-channels/init.php',
		);
		foreach ( $targets as $target ) {
			$this->assertFileExists( $target );
		}

		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/chat-channels/init.php';
		add_filter( 'wp_mcp_ai_pro_tools', 'wp_mcp_ai_pro_register_chat_channels_tools', 10 );
		$this->assertCount( 0, apply_filters( 'wp_mcp_ai_pro_tools', array() ) );
		$this->assertTrue( function_exists( 'wp_mcp_ai_pro_register_chat_channels_ecosystem_tools' ) );
		$this->assertTrue( function_exists( 'wp_mcp_ai_enqueue_chat_channels_toolkit_admin_styles' ) );
		$this->assertTrue( function_exists( 'wp_mcp_ai_load_chat_channels_tools' ) );
		$this->assertTrue( function_exists( 'wp_mcp_ai_chat_channel_is_rate_limited' ) );
	}
}
