<?php
/**
 * Tests for the deprecated-alias tool-resolution mechanism.
 *
 * Validates Phase P5 Part 2 infrastructure of the Unix Theory Compliance
 * Enhancement Proposal: tool decompositions land with a one-cycle back-compat
 * alias so callers that still reference the old slug keep working.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 *
 * phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Fixture tools are grouped for readability.
 * phpcs:disable Squiz.Commenting.FunctionComment.Missing -- Fixture tools are intentionally terse.
 * phpcs:disable Squiz.Commenting.ClassComment.Missing -- See above.
 * phpcs:disable Generic.Commenting.DocComment.Missing -- See above.
 */

/**
 * Minimal new-style tool that the deprecated alias will resolve to.
 */
class WP_MCP_AI_Test_Tool_Alias_New implements WP_MCP_AI_Tool_Interface {
	use WP_MCP_AI_Tool_Default_Capability;

	public function get_slug() {
		return 'test_alias_new';
	}
	public function get_name() {
		return 'Test Alias New';
	}
	public function get_description() {
		return 'Replacement tool.';
	}
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(),
		);
	}
	public function execute( array $arguments = array(), array $context = array() ) {
		return array(
			'success' => true,
			'message' => 'new-tool-ran',
		);
	}
}

/**
 * @group p5-part-2
 */
class Test_Tool_Deprecated_Alias extends WP_UnitTestCase {

	/**
	 * @var WP_MCP_AI_Tool_Registry
	 */
	protected $registry;

	public function setUp(): void {
		parent::setUp();
		$this->registry = WP_MCP_AI_Tool_Registry::get_instance();
		$this->registry->init();
		$this->registry->register_tool( new WP_MCP_AI_Test_Tool_Alias_New() );
		$this->registry->reset_deprecated_alias_invocations();
	}

	public function tearDown(): void {
		$this->registry->unregister_tool( 'test_alias_new' );
		// Aliases have no public unregister API by design (they are immutable
		// for the life of a request) — clear via reflection so tests stay
		// isolated.
		$reflection = new ReflectionClass( WP_MCP_AI_Tool_Registry::class );
		$prop       = $reflection->getProperty( 'deprecated_aliases' );
		$prop->setAccessible( true );
		$prop->setValue( $this->registry, array() );
		$this->registry->reset_deprecated_alias_invocations();
		parent::tearDown();
	}

	public function test_register_deprecated_alias_returns_true_on_success() {
		$ok = $this->registry->register_deprecated_alias(
			'test_alias_old',
			'test_alias_new',
			array(
				'since'   => '1.3.0',
				'remove'  => '1.4.0',
				'message' => 'Use test_alias_new instead.',
			)
		);
		$this->assertTrue( $ok );

		$aliases = $this->registry->get_deprecated_aliases();
		$this->assertArrayHasKey( 'test_alias_old', $aliases );
		$this->assertSame( 'test_alias_new', $aliases['test_alias_old']['new_slug'] );
		$this->assertSame( '1.3.0', $aliases['test_alias_old']['since'] );
		$this->assertSame( '1.4.0', $aliases['test_alias_old']['remove'] );
	}

	public function test_register_deprecated_alias_rejects_empty_slugs() {
		$this->assertFalse( $this->registry->register_deprecated_alias( '', 'test_alias_new' ) );
		$this->assertFalse( $this->registry->register_deprecated_alias( 'test_alias_old', '' ) );
		$this->assertFalse( $this->registry->register_deprecated_alias( 'same', 'same' ) );
	}

	public function test_register_deprecated_alias_refuses_to_shadow_existing_tool() {
		// Cannot alias a slug that is itself a real registered tool.
		$ok = $this->registry->register_deprecated_alias( 'test_alias_new', 'test_alias_new' );
		$this->assertFalse( $ok );
	}

	public function test_get_tool_resolves_deprecated_alias() {
		$this->registry->register_deprecated_alias( 'test_alias_old', 'test_alias_new' );

		$tool = $this->registry->get_tool( 'test_alias_old' );
		$this->assertNotNull( $tool );
		$this->assertSame( 'test_alias_new', $tool->get_slug() );
	}

	public function test_is_tool_registered_resolves_deprecated_alias() {
		$this->registry->register_deprecated_alias( 'test_alias_old', 'test_alias_new' );

		$this->assertTrue( $this->registry->is_tool_registered( 'test_alias_old' ) );
	}

	public function test_get_tool_returns_null_for_alias_pointing_at_unknown_slug() {
		$this->registry->register_deprecated_alias( 'test_alias_old', 'no_such_tool' );

		$this->assertNull( $this->registry->get_tool( 'test_alias_old' ) );
	}

	public function test_get_tool_definition_resolves_deprecated_alias() {
		$this->registry->register_deprecated_alias( 'test_alias_old', 'test_alias_new' );

		$definition = $this->registry->get_tool_definition( 'test_alias_old' );
		$this->assertIsArray( $definition );
		// Definition reflects the *new* tool — the model never sees the old slug.
		$this->assertSame( 'test_alias_new', $definition['name'] );
	}

	public function test_resolve_deprecated_alias_fires_action_once_per_request() {
		$this->registry->register_deprecated_alias(
			'test_alias_old',
			'test_alias_new',
			array( 'since' => '1.3.0' )
		);

		$calls = array();
		add_action(
			'wp_mcp_ai_tool_deprecated_alias_invoked',
			static function ( $old, $new, $entry ) use ( &$calls ) {
				$calls[] = array( $old, $new, $entry );
			},
			10,
			3
		);

		// Three resolutions in the same "request" must fire the hook exactly once.
		$this->registry->resolve_deprecated_alias( 'test_alias_old' );
		$this->registry->resolve_deprecated_alias( 'test_alias_old' );
		$this->registry->get_tool( 'test_alias_old' );

		$this->assertCount( 1, $calls );
		$this->assertSame( 'test_alias_old', $calls[0][0] );
		$this->assertSame( 'test_alias_new', $calls[0][1] );
		$this->assertSame( '1.3.0', $calls[0][2]['since'] );

		// After reset, the hook fires again.
		$this->registry->reset_deprecated_alias_invocations();
		$this->registry->resolve_deprecated_alias( 'test_alias_old' );
		$this->assertCount( 2, $calls );
	}

	public function test_resolve_deprecated_alias_passes_through_unknown_slug() {
		$this->assertSame(
			'completely_unknown',
			$this->registry->resolve_deprecated_alias( 'completely_unknown' )
		);
	}

	public function test_alias_is_invisible_to_llm_payload_assembler() {
		$this->registry->register_deprecated_alias( 'test_alias_old', 'test_alias_new' );

		// get_tools() returns *registered* tools — aliases are not in this list,
		// so the LLM payload assembler (which iterates the assistant's enabled
		// slugs and looks each up) never sees the deprecated name surfaced as a
		// distinct entry.
		$slugs = array_map(
			static function ( $tool ) {
				return $tool->get_slug();
			},
			$this->registry->get_tools()
		);
		$this->assertContains( 'test_alias_new', $slugs );
		$this->assertNotContains( 'test_alias_old', $slugs );
	}
}
