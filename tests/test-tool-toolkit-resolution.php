<?php
/**
 * Tests for WP_MCP_AI_Tool_Registry::get_tool_toolkit() — the central
 * toolkit-namespace resolver (declared definition key, then the
 * directory-derived fallback under a tools/ folder).
 *
 * @package MCP_AI_WPooS
 */

require_once __DIR__ . '/fixtures/tools/document-generation/class-test-toolkit-fixture-tool.php';

/**
 * Toolkit resolution tests.
 */
class Test_Tool_Toolkit_Resolution extends WP_UnitTestCase {

	/**
	 * Registry instance under test.
	 *
	 * @var WP_MCP_AI_Tool_Registry
	 */
	private $registry;

	/**
	 * Set up.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->registry = WP_MCP_AI_Tool_Registry::get_instance();
	}

	/**
	 * A declared toolkit key wins over any directory derivation.
	 */
	public function test_declared_toolkit_wins() {
		$declared = new class() {
			/**
			 * Legacy definition with an explicit toolkit key.
			 *
			 * @return array
			 */
			public function get_definition() {
				return array( 'toolkit' => 'declared_namespace' );
			}
		};

		$this->assertSame( 'declared_namespace', $this->registry->get_tool_toolkit( $declared ) );
	}

	/**
	 * A tool without a declared toolkit falls back to its declaring folder
	 * directly under a tools/ directory, hyphen-normalised.
	 */
	public function test_directory_fallback_derives_toolkit() {
		$this->assertSame(
			'document_generation',
			$this->registry->get_tool_toolkit( new Test_Toolkit_Fixture_Tool() )
		);
	}

	/**
	 * A tool declared in a flat location with no declaration resolves to an
	 * empty string rather than a fabricated namespace.
	 */
	public function test_flat_location_resolves_empty() {
		// Declared in this file: tests/ has no enclosing tools/ folder.
		$flat = new class() {
			/**
			 * No definition at all — no declared toolkit.
			 */
		};

		$this->assertSame( '', $this->registry->get_tool_toolkit( $flat ) );
	}

	/**
	 * Wrapped legacy tools derive from the INNER class location, never the
	 * wrapper's own file.
	 */
	public function test_wrapper_derives_from_inner_class() {
		$wrapper = new WP_MCP_AI_Legacy_Tool_Wrapper( new Test_Toolkit_Fixture_Tool() );

		$this->assertSame( 'document_generation', $this->registry->get_tool_toolkit( $wrapper ) );
	}

	/**
	 * The wp_mcp_ai_tool_toolkit filter overrides the resolved namespace.
	 */
	public function test_filter_overrides_resolution() {
		$callback = function () {
			return 'custom_namespace';
		};

		add_filter( 'wp_mcp_ai_tool_toolkit', $callback, 10, 3 );
		$resolved = $this->registry->get_tool_toolkit( new Test_Toolkit_Fixture_Tool() );
		remove_filter( 'wp_mcp_ai_tool_toolkit', $callback, 10 );

		$this->assertSame( 'custom_namespace', $resolved );
	}

	/**
	 * An unknown slug resolves to an empty string.
	 */
	public function test_unknown_slug_resolves_empty() {
		$this->assertSame( '', $this->registry->get_tool_toolkit( 'toolkit_never_registered_slug' ) );
	}
}
