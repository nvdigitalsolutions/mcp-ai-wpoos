<?php
/**
 * Characterization tests for the Wave F4 healthcare wellness CRUD batch 3 —
 * the eleven ported checkup/allergy tools.
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   classes; the byte-identical surfaces and the graceful execute()
 *   contracts are asserted (the gated map files are required
 *   deterministically in setUp).
 * - Standalone matrix (base plugin absent): the ported copies in
 *   `src/tools/healthcare/wellness/` are asserted in full.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * Healthcare wellness CRUD batch 3 tests.
 */
class Test_Healthcare_Wellness_Crud3 extends WP_UnitTestCase {

	/**
	 * Monolith matrix: require the eleven gated base files deterministically.
	 * Standalone: the entry autoloader serves them on demand.
	 */
	protected function setUp(): void {
		parent::setUp();
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$files = array(
				'tools/healthcare/wellness/checkups/class-wp-mcp-ai-tool-create-checkup.php',
				'tools/healthcare/wellness/checkups/class-wp-mcp-ai-tool-list-checkups.php',
				'tools/healthcare/wellness/checkups/class-wp-mcp-ai-tool-get-checkup.php',
				'tools/healthcare/wellness/checkups/class-wp-mcp-ai-tool-update-checkup.php',
				'tools/healthcare/wellness/checkups/class-wp-mcp-ai-tool-delete-checkup.php',
				'tools/healthcare/wellness/checkups/class-wp-mcp-ai-tool-get-upcoming-checkups.php',
				'tools/healthcare/wellness/allergies/class-wp-mcp-ai-tool-create-allergy.php',
				'tools/healthcare/wellness/allergies/class-wp-mcp-ai-tool-list-allergies.php',
				'tools/healthcare/wellness/allergies/class-wp-mcp-ai-tool-get-allergy.php',
				'tools/healthcare/wellness/allergies/class-wp-mcp-ai-tool-update-allergy.php',
				'tools/healthcare/wellness/allergies/class-wp-mcp-ai-tool-delete-allergy.php',
			);
			foreach ( $files as $file ) {
				require_once WP_MCP_AI_PRO_PATH . 'includes/' . $file;
			}
		}
	}

	/**
	 * The eleven ported symbols must follow the ownership boundary.
	 */
	public function test_serving_sources(): void {
		$symbols = array(
			'WP_MCP_AI_Tool_Create_Checkup'        => 'tools/healthcare/wellness/checkups/class-wp-mcp-ai-tool-create-checkup.php',
			'WP_MCP_AI_Tool_List_Checkups'         => 'tools/healthcare/wellness/checkups/class-wp-mcp-ai-tool-list-checkups.php',
			'WP_MCP_AI_Tool_Get_Checkup'           => 'tools/healthcare/wellness/checkups/class-wp-mcp-ai-tool-get-checkup.php',
			'WP_MCP_AI_Tool_Update_Checkup'        => 'tools/healthcare/wellness/checkups/class-wp-mcp-ai-tool-update-checkup.php',
			'WP_MCP_AI_Tool_Delete_Checkup'        => 'tools/healthcare/wellness/checkups/class-wp-mcp-ai-tool-delete-checkup.php',
			'WP_MCP_AI_Tool_Get_Upcoming_Checkups' => 'tools/healthcare/wellness/checkups/class-wp-mcp-ai-tool-get-upcoming-checkups.php',
			'WP_MCP_AI_Tool_Create_Allergy'        => 'tools/healthcare/wellness/allergies/class-wp-mcp-ai-tool-create-allergy.php',
			'WP_MCP_AI_Tool_List_Allergies'        => 'tools/healthcare/wellness/allergies/class-wp-mcp-ai-tool-list-allergies.php',
			'WP_MCP_AI_Tool_Get_Allergy'           => 'tools/healthcare/wellness/allergies/class-wp-mcp-ai-tool-get-allergy.php',
			'WP_MCP_AI_Tool_Update_Allergy'        => 'tools/healthcare/wellness/allergies/class-wp-mcp-ai-tool-update-allergy.php',
			'WP_MCP_AI_Tool_Delete_Allergy'        => 'tools/healthcare/wellness/allergies/class-wp-mcp-ai-tool-delete-allergy.php',
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
	 * The tool surfaces must be byte-identical.
	 */
	public function test_tool_surfaces(): void {
		$slugs = array(
			'WP_MCP_AI_Tool_Create_Checkup'        => 'create_checkup',
			'WP_MCP_AI_Tool_List_Checkups'         => 'list_checkups',
			'WP_MCP_AI_Tool_Get_Checkup'           => 'get_checkup',
			'WP_MCP_AI_Tool_Update_Checkup'        => 'update_checkup',
			'WP_MCP_AI_Tool_Delete_Checkup'        => 'delete_checkup',
			'WP_MCP_AI_Tool_Get_Upcoming_Checkups' => 'get_upcoming_checkups',
			'WP_MCP_AI_Tool_Create_Allergy'        => 'create_allergy',
			'WP_MCP_AI_Tool_List_Allergies'        => 'list_allergies',
			'WP_MCP_AI_Tool_Get_Allergy'           => 'get_allergy',
			'WP_MCP_AI_Tool_Update_Allergy'        => 'update_allergy',
			'WP_MCP_AI_Tool_Delete_Allergy'        => 'delete_allergy',
		);

		foreach ( $slugs as $class => $slug ) {
			$tool = new $class();
			$this->assertSame( $slug, $tool->get_slug(), $class );
			$this->assertSame( 'edit_posts', $tool->get_required_capability(), $class );
		}
	}

	/**
	 * The deterministic create-checkup/create-allergy contracts degrade
	 * gracefully with their byte-identical first gates.
	 */
	public function test_create_missing_gates(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'author' ) );

		$checkup = ( new WP_MCP_AI_Tool_Create_Checkup() )->execute( array(), array( 'user_id' => $user_id ) );
		$this->assertInstanceOf( 'WP_Error', $checkup );
		$this->assertSame( 'wp_mcp_ai_missing_member', $checkup->get_error_code() );

		$allergy = ( new WP_MCP_AI_Tool_Create_Allergy() )->execute( array(), array( 'user_id' => $user_id ) );
		$this->assertInstanceOf( 'WP_Error', $allergy );
		$this->assertSame( 'wp_mcp_ai_missing_member', $allergy->get_error_code() );
	}
}
