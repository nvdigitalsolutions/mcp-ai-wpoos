<?php
/**
 * Command-tree regression tests for the WP-CLI surface (Proposal 050).
 *
 * The command classes are only defined under WP_CLI, so these tests assert
 * on the registration surface itself: every expected command (canonical name
 * plus legacy alias) must be present, and every mutating verb must carry a
 * capability gate. A renamed or silently unregistered command fails here.
 *
 * @package WP_MCP_AI
 * @since 1.2.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Command-tree regression tests.
 *
 * @since 1.2.0
 */
class Test_WP_CLI_Command_Tree extends WP_UnitTestCase {

	/**
	 * Read a plugin PHP file for assertions.
	 *
	 * @param string $relative_path Path relative to the plugin root.
	 * @return string File contents.
	 */
	private function read_plugin_file( $relative_path ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local plugin file for assertion.
		$contents = file_get_contents( WP_MCP_AI_PATH . $relative_path );
		$this->assertNotFalse( $contents, "File should be readable: {$relative_path}" );
		return (string) $contents;
	}

	/**
	 * Concatenate every CLI file (base + Pro + dispatcher + integration CLIs).
	 *
	 * @return string Combined source of the CLI surface.
	 */
	private function cli_surface_source() {
		$parts = array(
			$this->read_plugin_file( 'includes/class-wp-mcp-ai-cli-command.php' ),
		);

		foreach ( glob( WP_MCP_AI_PATH . 'includes/cli/class-wp-mcp-ai-cli-*.php' ) ?: array() as $file ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local plugin file for assertion.
			$parts[] = (string) file_get_contents( $file );
		}

		foreach ( glob( WP_MCP_AI_PATH . 'addons/pro/includes/cli/class-wp-mcp-ai-pro-cli-*.php' ) ?: array() as $file ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local plugin file for assertion.
			$parts[] = (string) file_get_contents( $file );
		}

		foreach ( array( 'ezuite', 'flowhub', 'shopify-sync' ) as $integration ) {
			$path = WP_MCP_AI_PATH . 'addons/pro/includes/class-wp-mcp-ai-' . $integration . '-cli.php';
			if ( file_exists( $path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local plugin file for assertion.
				$parts[] = (string) file_get_contents( $path );
			}
		}

		return implode( "\n", $parts );
	}

	/**
	 * Every canonical command registers under the mcp-ai tree.
	 *
	 * @dataProvider data_expected_commands
	 *
	 * @param string $command Command name as passed to WP_CLI::add_command().
	 */
	public function test_command_is_registered( $command ) {
		$source = $this->cli_surface_source();

		$this->assertStringContainsString(
			"WP_CLI::add_command( '{$command}'",
			$source,
			"Command '{$command}' should be registered"
		);
	}

	/**
	 * Data provider: canonical commands plus legacy aliases.
	 *
	 * @return array<string,array{string}>
	 */
	public static function data_expected_commands() {
		$commands = array(
			// Base — extracted dispatcher commands.
			'mcp-ai',
			'mcp-ai plugins',
			'mcp-ai queue',
			'mcp-ai token',
			'mcp-ai rabbitmq',
			'mcp-ai stdio',
			'mcp-ai health',
			'mcp-ai cache clear',
			'mcp-ai version',
			// Base — includes/cli.
			'mcp-ai assistant',
			'mcp-ai approval',
			'mcp-ai bulk',
			'mcp-ai chat',
			'mcp-ai content',
			'mcp-ai credential',
			'mcp-ai cron',
			'mcp-ai dlq',
			'mcp-ai log',
			'mcp-ai measurement',
			'mcp-ai memory',
			'mcp-ai provider',
			'mcp-ai restrictions',
			'mcp-ai settings',
			'mcp-ai sla',
			'mcp-ai slash',
			'mcp-ai thread',
			'mcp-ai tool',
			'mcp-ai transcript',
			'mcp-ai security',
			'mcp-ai model',
			// Pro.
			'mcp-ai pro status',
			'mcp-ai connection',
			'mcp-ai project',
			'mcp-ai task',
			'mcp-ai mcp-server',
			'mcp-ai toolkit',
			'mcp-ai composition',
			'mcp-ai crm',
			'mcp-ai incident',
			'mcp-ai maintenance',
			'mcp-ai schedule',
			'mcp-ai workflow',
			'mcp-ai vault',
			'mcp-ai remote-site',
			'mcp-ai communication',
			'mcp-ai media-studio',
			// Namespace unification (canonical + legacy alias).
			'mcp-ai calendar',
			'mcp calendar',
			'mcp-ai place',
			'mcp place',
			'mcp-ai profession seed-orchestration',
			'profession seed-orchestration',
		);

		$data = array();
		foreach ( $commands as $command ) {
			$data[ $command ] = array( $command );
		}
		return $data;
	}

	/**
	 * Integration CLIs register both the canonical mcp-ai name and the
	 * legacy top-level alias (loop-based registration).
	 *
	 * @dataProvider data_integration_clis
	 *
	 * @param string $noun   Noun used in the command names (ezuite, flowhub, shopify-sync).
	 * @param string $method One representative method name from the verb map.
	 */
	public function test_integration_clis_register_canonical_and_legacy_names( $noun, $method ) {
		$source = $this->read_plugin_file( 'addons/pro/includes/class-wp-mcp-ai-' . $noun . '-cli.php' );

		$this->assertStringContainsString( "'mcp-ai {$noun} ' . ", $source, "{$noun} CLI should register the canonical mcp-ai namespace" );
		$this->assertStringContainsString( "'{$noun} ' . ", $source, "{$noun} CLI should keep the legacy top-level namespace" );
		$this->assertStringContainsString( "'{$method}'", $source, "{$noun} CLI verb map should include {$method}" );
	}

	/**
	 * Data provider: integration CLI nouns.
	 *
	 * @return array<string,array{string,string}>
	 */
	public static function data_integration_clis() {
		return array(
			'ezuite'      => array( 'ezuite', 'sync_log' ),
			'flowhub'     => array( 'flowhub', 'compliance_report' ),
			'shopify'     => array( 'shopify-sync', 'cost_report' ),
		);
	}

	/**
	 * Every mutating Pro verb gates on manage_options.
	 *
	 * @dataProvider data_pro_mutating_verbs
	 *
	 * @param string $file  CLI file (relative to addons/pro/includes/cli/).
	 * @param string $verb  Method name that mutates state.
	 */
	public function test_pro_mutating_verbs_are_gated( $file, $verb ) {
		$source = $this->read_plugin_file( 'addons/pro/includes/cli/' . $file );

		$pos = strpos( $source, 'public function ' . $verb . '(' );
		$this->assertNotFalse( $pos, "{$file}::{$verb} should exist" );

		$body = substr( $source, $pos, 3000 );
		$this->assertStringContainsString( "require_capability( 'manage_options' )", $body, "{$file}::{$verb} should be capability gated" );
	}

	/**
	 * Data provider: Pro mutating verbs.
	 *
	 * @return array<string,array{string,string}>
	 */
	public static function data_pro_mutating_verbs() {
		$rows = array(
			'incident-resolve'     => array( 'class-wp-mcp-ai-pro-cli-incident-command.php', 'resolve' ),
			'maintenance-cancel'   => array( 'class-wp-mcp-ai-pro-cli-maintenance-command.php', 'cancel' ),
			'schedule-run'         => array( 'class-wp-mcp-ai-pro-cli-schedule-command.php', 'run' ),
			'vault-list'           => array( 'class-wp-mcp-ai-pro-cli-vault-command.php', 'list' ),
		);

		$data = array();
		foreach ( $rows as $key => $row ) {
			$data[ $key ] = $row;
		}
		return $data;
	}
}
