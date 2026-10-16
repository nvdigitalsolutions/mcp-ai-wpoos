<?php
/**
 * F&B Blueprint Tests — the examples/ blueprint surface.
 *
 * Covers: file structure of the four A1–A4 blueprint mirrors, the READ /
 * DRAFT / ACT allowlist discipline, A2's deliberate purchase-request
 * grants, the unified-page enable-key registration, and the
 * import_fnb_blueprint tool round-trip through the shared installer.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test case: F&B assistant blueprints.
 */
class Test_Fnb_Blueprints extends WP_UnitTestCase {

	/**
	 * Toolkit directory.
	 *
	 * @var string
	 */
	private $toolkit_dir;

	/**
	 * Blueprint slugs in fixture order.
	 *
	 * @var string[]
	 */
	private $slugs = array(
		'fnb-a1-manager',
		'fnb-a2-kitchen-bar-stock',
		'fnb-a3-financial',
		'fnb-a4-content',
	);

	/**
	 * ACT-tier tool slugs that must never appear in any blueprint.
	 *
	 * @var string[]
	 */
	private $act_denylist = array(
		'fnb_pr_submit',
		'fnb_pr_return',
		'fnb_pr_approve',
		'fnb_pr_release',
		'fnb_pr_supersede',
		'fnb_pr_cancel',
		'fnb_import_table',
	);

	/**
	 * Set up.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->toolkit_dir = dirname( __DIR__, 4 ) . '/addons/pro/includes/tools/food-beverage';
	}

	/**
	 * Load a blueprint JSON.
	 *
	 * @param string $slug Blueprint slug.
	 * @return array Decoded blueprint.
	 */
	private function load_blueprint( $slug ) {
		$file = $this->toolkit_dir . '/examples/' . $slug . '.json';
		$this->assertFileExists( $file, $slug . '.json must exist' );

		$data = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local blueprint JSON, not a remote URL.
		$this->assertIsArray( $data, $slug . ' must parse as JSON' );

		return $data;
	}

	/**
	 * Test: every blueprint has the CRM-style schema with a populated allowlist.
	 */
	public function test_blueprints_have_schema_and_allowlists() {
		if ( ! is_dir( $this->toolkit_dir . '/examples' ) ) {
			$this->markTestSkipped( 'F&B examples directory not available' );
		}

		foreach ( $this->slugs as $slug ) {
			$data = $this->load_blueprint( $slug );

			$this->assertNotEmpty( $data['name'] ?? '', $slug . ' has a name' );
			$this->assertNotEmpty( $data['description'] ?? '', $slug . ' has a description' );
			$this->assertIsArray( $data['meta']['available_tools'] ?? null, $slug . ' has an available_tools array' );
			$this->assertNotEmpty( $data['meta']['available_tools'], $slug . ' grants at least one tool' );
			$this->assertNotEmpty( $data['meta']['instructions'] ?? '', $slug . ' has instructions' );
		}
	}

	/**
	 * Test: allowlists contain only fnb_* slugs and never ACT-tier tools.
	 */
	public function test_allowlist_discipline_no_act_tier() {
		if ( ! is_dir( $this->toolkit_dir . '/examples' ) ) {
			$this->markTestSkipped( 'F&B examples directory not available' );
		}

		foreach ( $this->slugs as $slug ) {
			$data  = $this->load_blueprint( $slug );
			$tools = $data['meta']['available_tools'];

			foreach ( $tools as $tool ) {
				$this->assertStringStartsWith( 'fnb_', (string) $tool, $slug . ' grants only fnb_* tools' );
			}
			foreach ( $this->act_denylist as $denied ) {
				$this->assertNotContains( $denied, $tools, $slug . ' must not grant ACT-tier tool ' . $denied );
			}
		}
	}

	/**
	 * Test: blueprints are venue-neutral and carry tenant-profile tokens.
	 */
	public function test_blueprints_are_venue_neutral_with_tokens() {
		if ( ! is_dir( $this->toolkit_dir . '/examples' ) ) {
			$this->markTestSkipped( 'F&B examples directory not available' );
		}

		$venue_strings   = array( 'Surf Club', 'Midigama', 'Sri Lanka', 'Rella', 'Sanda', 'LKR', 'PE firm', 'deity' );
		$required_tokens = array( '{{venue_name}}', '{{currency}}' );

		foreach ( $this->slugs as $slug ) {
			$data        = $this->load_blueprint( $slug );
			$instructions = $data['meta']['instructions'] ?? '';
			$description  = $data['description'] ?? '';

			foreach ( $venue_strings as $needle ) {
				$this->assertStringNotContainsStringIgnoringCase( $needle, $instructions, $slug . ' instructions must not contain venue/currency string ' . $needle );
				$this->assertStringNotContainsStringIgnoringCase( $needle, $description, $slug . ' description must not contain venue/currency string ' . $needle );
			}
			foreach ( $required_tokens as $token ) {
				$this->assertStringContainsString( $token, $instructions, $slug . ' instructions must contain tenant token ' . $token );
			}
		}
	}

	/**
	 * Test: only A2 gets the purchase-request tools, and its prompt restates
	 * the ACT-tier guardrail.
	 */
	public function test_a2_grants_draft_tier_pr_tools() {
		if ( ! is_dir( $this->toolkit_dir . '/examples' ) ) {
			$this->markTestSkipped( 'F&B examples directory not available' );
		}

		$a2 = $this->load_blueprint( 'fnb-a2-kitchen-bar-stock' );
		$this->assertContains( 'fnb_pr_create', $a2['meta']['available_tools'] );
		$this->assertContains( 'fnb_pr_list', $a2['meta']['available_tools'] );
		$this->assertContains( 'fnb_pr_audit', $a2['meta']['available_tools'] );
		$this->assertStringContainsString( 'never submit, approve, release, supersede or cancel', $a2['meta']['instructions'] );

		foreach ( array( 'fnb-a1-manager', 'fnb-a3-financial', 'fnb-a4-content' ) as $slug ) {
			$data = $this->load_blueprint( $slug );
			foreach ( $data['meta']['available_tools'] as $tool ) {
				$this->assertStringStartsNotWith( 'fnb_pr_', (string) $tool, $slug . ' must not grant purchase-request tools' );
			}
		}
	}

	/**
	 * Test: the unified Blueprints page lists F&B only when the toolkit is on.
	 */
	public function test_unified_page_enable_key_registration() {
		if ( ! class_exists( 'WP_MCP_AI_Unified_Blueprints_Page' ) ) {
			$this->markTestSkipped( 'Unified Blueprints page not available' );
		}

		$ref  = new ReflectionClass( 'WP_MCP_AI_Unified_Blueprints_Page' );
		$prop = $ref->getProperty( 'toolkit_enable_keys' );
		$prop->setAccessible( true );
		$key_map = $prop->getValue();

		$this->assertArrayHasKey( 'food-beverage', $key_map, 'F&B toolkit must be mapped on the Blueprints page' );
		$this->assertSame( array( 'enable_fnb_toolkit' ), $key_map['food-beverage'] );
	}

	/**
	 * Test: import_fnb_blueprint round-trips through the shared installer.
	 */
	public function test_import_tool_installs_detects_duplicates_and_overwrites() {
		$tool_file = $this->toolkit_dir . '/examples/class-wp-mcp-ai-tool-import-fnb-blueprint.php';
		if ( ! file_exists( $tool_file ) ) {
			$this->markTestSkipped( 'F&B blueprint import tool not available' );
		}

		// Ensure the trait chain is loaded before the class.
		if ( ! trait_exists( 'WP_MCP_AI_Tool_Envelope' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/tools/trait-wp-mcp-ai-tool-envelope.php';
		}
		if ( ! trait_exists( 'WP_MCP_AI_Tool_Chat_Response' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/tools/trait-wp-mcp-ai-tool-chat-response.php';
		}
		require_once $tool_file;

		if ( ! class_exists( 'WP_MCP_AI_Tool_Import_Fnb_Blueprint' ) ) {
			$this->markTestSkipped( 'F&B blueprint import tool class not available' );
		}

		update_option( 'wp_mcp_ai_settings', array( 'enable_fnb_toolkit' => true ) );
		$this->assertTrue( WP_MCP_AI_Tool_Import_Fnb_Blueprint::is_available() );

		// The assistant CPT's registered-meta sanitizer (sanitize_tools_meta)
		// keeps only slugs the live tool registry knows. The registry
		// bootstraps once at suite load — before this test enables the
		// toolkit — so register every tool the A2 blueprint grants here to
		// mirror production (where enable_fnb_toolkit is on at boot).
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();
		$a2 = $this->load_blueprint( 'fnb-a2-kitchen-bar-stock' );
		foreach ( $a2['meta']['available_tools'] as $tool_slug ) {
			if ( null !== $registry->get_tool( $tool_slug ) ) {
				continue;
			}
			$class = 'WP_MCP_AI_Tool_' . str_replace( ' ', '_', ucwords( str_replace( '_', ' ', $tool_slug ) ) );
			$dir   = 0 === strpos( $tool_slug, 'fnb_pr_' ) ? $this->toolkit_dir . '/pr/' : $this->toolkit_dir . '/';
			$file  = $dir . 'class-' . str_replace( '_', '-', strtolower( $class ) ) . '.php';
			if ( file_exists( $file ) ) {
				require_once $file;
				if ( class_exists( $class ) ) {
					$registry->register_tool( new $class() );
				}
			}
		}

		$admin = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$tool = new WP_MCP_AI_Tool_Import_Fnb_Blueprint();

		$first = $tool->execute( array( 'blueprint' => 'fnb-a2-kitchen-bar-stock' ), array( 'user_id' => $admin ) );
		$this->assertTrue( $first['success'], 'Blueprint import must succeed' );
		$this->assertSame( 'fnb-a2-kitchen-bar-stock', get_post_meta( $first['assistant_id'], '_blueprint_source', true ) );

		$tools = get_post_meta( $first['assistant_id'], '_wp_mcp_ai_tools', true );
		$this->assertIsArray( $tools );
		$this->assertContains( 'fnb_pr_create', $tools, 'A2 blueprint assistant grants fnb_pr_create' );
		$this->assertContains( 'fnb_read_table', $tools );
		$this->assertNotContains( 'fnb_pr_release', $tools );

		// Tenant-profile tokens are rendered at install time into the assistant's
		// system prompt (post_content), matching the WP-CLI seeder's behaviour.
		update_option(
			'wp_mcp_ai_fnb_settings',
			array(
				'venue_name' => 'Example Kitchen',
				'currency'   => 'USD',
			)
		);
		$rendered = $tool->execute(
			array(
				'blueprint' => 'fnb-a2-kitchen-bar-stock',
				'overwrite' => true,
			),
			array( 'user_id' => $admin )
		);
		$this->assertTrue( $rendered['success'] );
		$again_content = get_post_field( 'post_content', $rendered['assistant_id'] );
		$this->assertStringContainsString( 'Example Kitchen', $again_content, 'Rendered tenant venue name must appear in the assistant content' );
		$this->assertStringContainsString( 'USD', $again_content, 'Rendered currency code must appear in the assistant content' );
		$this->assertStringNotContainsString( '{{venue_name}}', $again_content, 'Unrendered token must not remain in the assistant content' );

		// Duplicate detection without overwrite.
		$dupe = $tool->execute( array( 'blueprint' => 'fnb-a2-kitchen-bar-stock' ), array( 'user_id' => $admin ) );
		$this->assertWPError( $dupe );
		$this->assertSame( 'blueprint_duplicate', $dupe->get_error_code() );

		// Overwrite replaces in place.
		$again = $tool->execute(
			array(
				'blueprint' => 'fnb-a2-kitchen-bar-stock',
				'overwrite' => true,
			),
			array( 'user_id' => $admin )
		);
		$this->assertTrue( $again['success'] );
		$this->assertSame( $first['assistant_id'], $again['assistant_id'] );

		// A bad slug is rejected before any file IO.
		$bad = $tool->execute( array( 'blueprint' => 'fnb-a9-does-not-exist' ), array( 'user_id' => $admin ) );
		$this->assertWPError( $bad );
	}
}
