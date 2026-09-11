<?php
/**
 * Characterization tests for the Wave F5 chat-channels admin slice — the
 * top-level menu and the settings page ported from the base Pro addon.
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   classes; the byte-identical surfaces are asserted.
 * - Standalone matrix (base plugin absent): the ported copies in `src/admin/`
 *   are asserted in full, including the slim init's now-firing gate targets.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * Chat Channels admin slice tests.
 */
class Test_Chat_Channels_Admin_Slice extends WP_UnitTestCase {

	/**
	 * The two ported symbols must follow the ownership boundary.
	 */
	public function test_serving_sources(): void {
		$symbols = array(
			'WP_MCP_AI_Chat_Channels_Menu'          => 'admin/class-wp-mcp-ai-chat-channels-menu.php',
			'WP_MCP_AI_Chat_Channels_Settings_Page' => 'admin/class-wp-mcp-ai-chat-channels-settings-page.php',
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
	}

	/**
	 * The menu constants + constructor hook wiring must be byte-identical.
	 */
	public function test_menu_contracts(): void {
		$this->assertSame( 'wp-mcp-ai-chat-channels', WP_MCP_AI_Chat_Channels_Menu::MENU_SLUG );
		$this->assertSame( 'manage_options', WP_MCP_AI_Chat_Channels_Menu::CAPABILITY );

		new WP_MCP_AI_Chat_Channels_Menu();
		$this->assertNotFalse( has_action( 'admin_menu' ) );
	}

	/**
	 * The settings page constructor props must be byte-identical (a
	 * toolkit-settings-base child).
	 */
	public function test_settings_props(): void {
		$page    = new WP_MCP_AI_Chat_Channels_Settings_Page();
		$reflect = new ReflectionObject( $page );

		$read = static function ( string $prop ) use ( $reflect, $page ) {
			$property = $reflect->getProperty( $prop );
			$property->setAccessible( true );
			return $property->getValue( $page );
		};

		$this->assertSame( 'chat_channels', $read( 'toolkit_slug' ) );
		$this->assertSame( 'wp_mcp_ai_chat_channels_toolkit_settings', $read( 'option_name' ) );
		$this->assertSame( 'wp-mcp-ai-chat-channels-toolkit-settings', $read( 'page_slug' ) );
		$this->assertFalse( $read( 'has_research' ) );
		$this->assertFalse( $read( 'has_remote_sites' ) );
	}

	/**
	 * Standalone only: the slim init's file-gated admin requires have their
	 * gate targets present, plus the inbox assets.
	 */
	public function test_init_gate_targets_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base chat-channels init wires the slice at boot.' );
		}

		$targets = array(
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-chat-channels-menu.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-chat-channels-settings-page.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'assets/css/chat-channels-inbox.css',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'assets/js/chat-channels-inbox.js',
		);
		foreach ( $targets as $target ) {
			$this->assertFileExists( $target );
		}
	}
}
