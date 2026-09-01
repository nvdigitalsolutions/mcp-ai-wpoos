<?php
/**
 * WP-CLI surface port tests (Wave D6a).
 *
 * Characterization suite for the `NvoosContentGraphAi\Cli` command
 * classes. The WP-CLI runtime is absent in the test environment, so the
 * assertions exercise the plain data methods (`get_items`,
 * `get_tools`, `get_providers`, `get_settings_map`/`get_setting`,
 * `get_stats`) that the thin `run*()` wrappers format.
 *
 * @package NvoosContentGraphAi\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Tests;

use NvoosContentGraphAi\Cli\GraphCommand;
use NvoosContentGraphAi\Cli\ProvidersCommand;
use NvoosContentGraphAi\Cli\SettingsCommand;
use NvoosContentGraphAi\Cli\StatusCommand;
use NvoosContentGraphAi\Cli\ToolsCommand;

/**
 * @group cli
 */
class Test_Cli_Commands extends \WP_UnitTestCase {

	/**
	 * Tool slugs known to the active registry.
	 *
	 * @var array<string>
	 */
	private $known_slugs;

	public function setUp(): void {
		parent::setUp();

		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$all   = \WP_MCP_AI_Tool_Registry::get_instance()->get_tools();
			$slugs = array();
			foreach ( array_slice( $all, 0, 2 ) as $tool ) {
				if ( is_object( $tool ) && method_exists( $tool, 'get_slug' ) ) {
					$slugs[] = $tool->get_slug();
				}
			}
			$this->known_slugs = $slugs;
		} else {
			$this->known_slugs = array( 'ai_analyze_image', 'ai_create_text_embeddings' );
		}
	}

	// ─── status ────────────────────────────────────────────────────

	public function test_status_items_cover_core_and_plugin(): void {
		$items = StatusCommand::get_items();

		$this->assertNotEmpty( $items );

		$labels = wp_list_pluck( $items, 'label' );
		foreach ( array( 'WordPress Version', 'PHP Version', 'Content Graph AI Version', 'Install Mode', 'Default Provider' ) as $expected ) {
			$this->assertContains( $expected, $labels );
		}

		foreach ( $items as $item ) {
			$this->assertArrayHasKey( 'context', $item );
			$this->assertArrayHasKey( 'label', $item );
			$this->assertArrayHasKey( 'value', $item );
		}
	}

	public function test_status_install_mode_matches_matrix(): void {
		$expected = defined( 'WP_MCP_AI_PATH' ) ? 'monolith' : 'standalone';

		$found = null;
		foreach ( StatusCommand::get_items() as $item ) {
			if ( 'Install Mode' === $item['label'] ) {
				$found = $item['value'];
			}
		}

		$this->assertSame( $expected, $found );
	}

	public function test_status_versions_and_credentials_ratio(): void {
		$items = StatusCommand::get_items();
		$map   = array();
		foreach ( $items as $item ) {
			$map[ $item['label'] ] = $item['value'];
		}

		$this->assertSame( NVOOS_CONTENT_GRAPH_AI_VERSION, $map['Content Graph AI Version'] );
		$this->assertSame( NVOOS_CONTENT_GRAPH_VERSION, $map['Content Graph Version'] );
		$this->assertMatchesRegularExpression( '/^\d+\/\d+$/', $map['Providers with Credentials'] );
		$this->assertMatchesRegularExpression( '/^\d+$/', $map['Tools Registered'] );
	}

	// ─── providers ─────────────────────────────────────────────────

	public function test_providers_list_shape_and_default(): void {
		$rows = ProvidersCommand::get_providers();

		$this->assertNotEmpty( $rows );

		$defaults = 0;
		foreach ( $rows as $row ) {
			$this->assertSame( array( 'Provider', 'Credentials', 'Source', 'Default' ), array_keys( $row ) );
			if ( 'yes' === $row['Default'] ) {
				++$defaults;
			}
		}

		$this->assertSame( 1, $defaults );

		$slugs = wp_list_pluck( $rows, 'Provider' );
		$this->assertContains( 'openai', $slugs );
		$this->assertContains( 'gemini', $slugs );
	}

	public function test_providers_sorted_and_no_credentials_in_test_env(): void {
		$rows  = ProvidersCommand::get_providers();
		$slugs = wp_list_pluck( $rows, 'Provider' );

		$sorted = $slugs;
		sort( $sorted );
		$this->assertSame( $sorted, $slugs );

		// No API keys exist in the test environment.
		$openai = null;
		foreach ( $rows as $row ) {
			if ( 'openai' === $row['Provider'] ) {
				$openai = $row;
			}
		}
		$this->assertNotNull( $openai );
		$this->assertSame( 'no', $openai['Credentials'] );
	}

	// ─── tools ─────────────────────────────────────────────────────

	public function test_tools_list_includes_known_slugs(): void {
		$rows  = ToolsCommand::get_tools();
		$slugs = wp_list_pluck( $rows, 'Slug' );

		foreach ( $this->known_slugs as $slug ) {
			$match = false;
			foreach ( $slugs as $listed ) {
				if ( $slug === $listed || $slug . '_validated' === $listed ) {
					$match = true;
					break;
				}
			}
			$this->assertTrue( $match, "Expected a tool matching {$slug}." );
		}
	}

	public function test_tools_list_sorted(): void {
		$rows  = ToolsCommand::get_tools();
		$slugs = wp_list_pluck( $rows, 'Slug' );

		$sorted = $slugs;
		sort( $sorted );
		$this->assertSame( $sorted, $slugs );
	}

	public function test_tools_list_filter(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			// Monolith: filter by the first known base slug.
			$rows = ToolsCommand::get_tools( $this->known_slugs[0] );
			$this->assertNotEmpty( $rows );
			foreach ( $rows as $row ) {
				$this->assertStringContainsStringIgnoringCase( $this->known_slugs[0], $row['Slug'] );
			}
			return;
		}

		$rows = ToolsCommand::get_tools( 'ai_' );
		$this->assertNotEmpty( $rows );
		foreach ( $rows as $row ) {
			$this->assertStringContainsString( 'ai_', $row['Slug'] );
		}

		$this->assertSame( array(), ToolsCommand::get_tools( 'zzz_no_such_tool' ) );
	}

	// ─── settings ──────────────────────────────────────────────────

	public function test_settings_map_shape(): void {
		$rows = SettingsCommand::get_settings_map();

		$this->assertNotEmpty( $rows );

		$keys = wp_list_pluck( $rows, 'Key' );
		$this->assertContains( 'ai_default_provider', $keys );

		// Secret keys are refused outright.
		foreach ( $keys as $key ) {
			$this->assertStringNotContainsStringIgnoringCase( 'api_key', $key );
		}

		// Sorted by key.
		$sorted = $keys;
		sort( $sorted );
		$this->assertSame( $sorted, $keys );
	}

	public function test_get_setting_reads_defaults(): void {
		$this->assertSame( 'openai', SettingsCommand::get_setting( 'ai_default_provider' ) );
		$this->assertSame( 'gpt-4o', SettingsCommand::get_setting( 'ai_default_model' ) );
	}

	public function test_get_setting_refuses_secrets_and_unknown(): void {
		$this->assertNull( SettingsCommand::get_setting( 'ai_api_key_openai' ) );
		$this->assertNull( SettingsCommand::get_setting( 'no_such_key_anywhere' ) );
	}

	// ─── graph stats ───────────────────────────────────────────────

	public function test_graph_stats_rows(): void {
		$rows = GraphCommand::get_stats();

		$this->assertNotEmpty( $rows );

		foreach ( $rows as $row ) {
			$this->assertArrayHasKey( 'Label', $row );
			$this->assertArrayHasKey( 'Value', $row );
			$this->assertIsString( $row['Value'] );
		}

		$labels = wp_list_pluck( $rows, 'Label' );
		$this->assertContains( 'Nodes', $labels );
		$this->assertContains( 'Edges', $labels );
	}
}
