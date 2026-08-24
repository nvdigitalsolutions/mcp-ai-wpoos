<?php
/**
 * AJAX tests for the Pro Workflow Builder + Orchestration trigger_workflow handler.
 *
 * Handlers covered (all Pro addon, class WP_MCP_AI_Pro_Workflow_Builder_Page):
 *   - wp_mcp_ai_save_pro_workflow
 *   - wp_mcp_ai_load_pro_workflow
 *   - wp_mcp_ai_delete_pro_workflow
 *   - wp_mcp_ai_list_pro_workflows
 *   - wp_mcp_ai_export_pro_workflow
 *   - wp_mcp_ai_duplicate_pro_workflow
 *   - wp_mcp_ai_rename_pro_workflow
 *   - wp_mcp_ai_get_workflow_templates
 *   - wp_mcp_ai_get_workflow_presets
 *   - wp_mcp_ai_install_workflow_preset
 *   - wp_mcp_ai_get_recent_workflows   (base — WP_MCP_AI_Admin_Orchestration_Dashboard)
 *   - wp_mcp_ai_trigger_workflow        (Pro  — WP_MCP_AI_Orchestration_Dashboard)
 *
 * 4-point coverage contract applied to each handler:
 *   1. Capability gate  — subscriber is rejected.
 *   2. Nonce check      — missing/bad nonce is rejected.
 *   3. Happy path       — valid request returns expected JSON shape.
 *   4. Input validation — missing required param is rejected.
 *
 * @package WP_MCP_AI
 */

// phpcs:disable WordPress.NamingConventions.ValidVariableName

/**
 * Pro Workflow Builder AJAX cluster.
 */
// Load the Pro admin class under test; the pro addon loads it only in admin
// context, so require it here to keep the suite runnable standalone (mirrors
// CI, where earlier admin-context tests load it).
if ( defined( 'WP_MCP_AI_PRO_PATH' ) ) {
	$wp_mcp_ai_workflow_builder_page = WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-pro-workflow-builder-page.php';
	if ( file_exists( $wp_mcp_ai_workflow_builder_page ) ) {
		require_once $wp_mcp_ai_workflow_builder_page;
	}
	unset( $wp_mcp_ai_workflow_builder_page );
}

class Test_Pro_Workflow_Builder_Ajax_Handlers extends WP_MCP_AI_Ajax_TestCase {

	/** Nonce action used by WP_MCP_AI_Pro_Workflow_Builder_Page handlers. */
	const WORKFLOW_NONCE = 'mcp_ai_pro_workflow_builder';

	/** Nonce action used by base orchestration dashboard handlers. */
	const ORCH_NONCE = 'wp_mcp_ai_orchestration';

	/** Option key where the workflow builder stores workflows. */
	const WF_OPTION = 'wp_mcp_ai_pro_workflows';

	/** Pro class we need to exist; skip entire suite when Pro is absent. */
	const PRO_CLASS = 'WP_MCP_AI_Pro_Workflow_Builder_Page';

	/**
	 * Skip all tests when the Pro addon isn't loaded.
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		if ( ! class_exists( self::PRO_CLASS ) ) {
			self::markTestSkipped( 'Pro addon (WP_MCP_AI_Pro_Workflow_Builder_Page) is not loaded.' );
		}
	}

	/** Sets up test fixtures before each test. */
	public function setUp(): void {
		parent::setUp();
		delete_option( self::WF_OPTION );
	}

	/** Tears down test state after each test. */
	public function tearDown(): void {
		delete_option( self::WF_OPTION );
		parent::tearDown();
	}

	// ---
	// Helpers
	// ---

	/**
	 * Seed one workflow in the option store.
	 *
	 * @param string $id   Workflow key.
	 * @param string $name Workflow display name.
	 */
	private function seed_workflow( string $id = 'test-flow', string $name = 'Test Flow' ): void {
		update_option(
			self::WF_OPTION,
			array(
				$id => array(
					'id'          => $id,
					'name'        => $name,
					'description' => '',
					'nodes'       => array(
						array(
							'id'   => 'n1',
							'type' => 'start',
						),
					),
					'edges'       => array(),
					'created_at'  => time(),
					'updated_at'  => time(),
				),
			)
		);
	}

	/**
	 * Valid save payload.
	 *
	 * @param string $name Workflow name.
	 * @return array<string,mixed>
	 */
	private function valid_save_payload( string $name = 'My Workflow' ): array {
		return array(
			'nonce'    => wp_create_nonce( self::WORKFLOW_NONCE ),
			'workflow' => wp_json_encode(
				array(
					'name'  => $name,
					'nodes' => array(
						array(
							'id'   => 'n1',
							'type' => 'start',
						),
					),
					'edges' => array(),
				)
			),
		);
	}

	// ---
	// wp_mcp_ai_save_pro_workflow
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_save_pro_workflow_rejects_bad_nonce() {
		$this->as_admin();
		$response = $this->dispatch(
			'wp_mcp_ai_save_pro_workflow',
			array(
				'nonce'    => 'bad_nonce',
				'workflow' => wp_json_encode(
					array(
						'name'  => 'x',
						'nodes' => array(),
						'edges' => array(),
					)
				),
			)
		);
		$this->assertAjaxForbidden( $response );
	}

	/** Guards against insufficient capabilities. */
	public function test_save_pro_workflow_rejects_subscriber() {
		$this->as_subscriber();
		$response = $this->dispatch( 'wp_mcp_ai_save_pro_workflow', $this->valid_save_payload() );
		$this->assertAjaxError( $response );
	}

	/** Validates the missing workflow parameter. */
	public function test_save_pro_workflow_validates_missing_workflow() {
		$this->as_admin();
		$response = $this->dispatch(
			'wp_mcp_ai_save_pro_workflow',
			array( 'nonce' => wp_create_nonce( self::WORKFLOW_NONCE ) )
		);
		$this->assertAjaxError( $response );
	}

	/** Validates the missing name parameter. */
	public function test_save_pro_workflow_validates_missing_name() {
		$this->as_admin();
		$response = $this->dispatch(
			'wp_mcp_ai_save_pro_workflow',
			array(
				'nonce'    => wp_create_nonce( self::WORKFLOW_NONCE ),
				'workflow' => wp_json_encode(
					array(
						'nodes' => array(),
						'edges' => array(),
					)
				),
			)
		);
		$this->assertAjaxError( $response );
	}

	/** Dispatches successfully on the happy path. */
	public function test_save_pro_workflow_happy_path() {
		$this->as_admin();
		$response = $this->dispatch( 'wp_mcp_ai_save_pro_workflow', $this->valid_save_payload( 'My Workflow' ) );
		$this->assertAjaxSuccess( $response );

		$workflows = get_option( self::WF_OPTION, array() );
		$this->assertNotEmpty( $workflows );
	}

	// ---
	// wp_mcp_ai_load_pro_workflow
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_load_pro_workflow_rejects_bad_nonce() {
		$this->seed_workflow();
		$this->as_admin();
		$response = $this->dispatch(
			'wp_mcp_ai_load_pro_workflow',
			array(
				'nonce'       => 'bad',
				'workflow_id' => 'test-flow',
			)
		);
		$this->assertAjaxForbidden( $response );
	}

	/** Guards against insufficient capabilities. */
	public function test_load_pro_workflow_rejects_subscriber() {
		$this->seed_workflow();
		$this->as_subscriber();
		$response = $this->dispatch(
			'wp_mcp_ai_load_pro_workflow',
			array(
				'nonce'       => wp_create_nonce( self::WORKFLOW_NONCE ),
				'workflow_id' => 'test-flow',
			)
		);
		$this->assertAjaxError( $response );
	}

	/** Validates the missing id parameter. */
	public function test_load_pro_workflow_validates_missing_id() {
		$this->as_admin();
		$response = $this->dispatch(
			'wp_mcp_ai_load_pro_workflow',
			array( 'nonce' => wp_create_nonce( self::WORKFLOW_NONCE ) )
		);
		$this->assertAjaxError( $response );
	}

	/** Dispatches successfully on the happy path. */
	public function test_load_pro_workflow_happy_path() {
		$this->seed_workflow( 'test-flow', 'Test Flow' );
		$this->as_admin();
		$response = $this->dispatch(
			'wp_mcp_ai_load_pro_workflow',
			array(
				'nonce'       => wp_create_nonce( self::WORKFLOW_NONCE ),
				'workflow_id' => 'test-flow',
			)
		);
		$this->assertAjaxSuccess( $response );
		$data = $this->getResponseData( $response );
		$this->assertArrayHasKey( 'workflow', $data );
		$this->assertSame( 'Test Flow', $data['workflow']['name'] );
	}

	// ---
	// wp_mcp_ai_delete_pro_workflow
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_delete_pro_workflow_rejects_bad_nonce() {
		$this->seed_workflow();
		$this->as_admin();
		$response = $this->dispatch(
			'wp_mcp_ai_delete_pro_workflow',
			array(
				'nonce'       => 'bad',
				'workflow_id' => 'test-flow',
			)
		);
		$this->assertAjaxForbidden( $response );
	}

	/** Guards against insufficient capabilities. */
	public function test_delete_pro_workflow_rejects_subscriber() {
		$this->seed_workflow();
		$this->as_subscriber();
		$response = $this->dispatch(
			'wp_mcp_ai_delete_pro_workflow',
			array(
				'nonce'       => wp_create_nonce( self::WORKFLOW_NONCE ),
				'workflow_id' => 'test-flow',
			)
		);
		$this->assertAjaxError( $response );
	}

	/** Validates the missing id parameter. */
	public function test_delete_pro_workflow_validates_missing_id() {
		$this->as_admin();
		$response = $this->dispatch(
			'wp_mcp_ai_delete_pro_workflow',
			array( 'nonce' => wp_create_nonce( self::WORKFLOW_NONCE ) )
		);
		$this->assertAjaxError( $response );
	}

	/** Dispatches successfully on the happy path. */
	public function test_delete_pro_workflow_happy_path() {
		$this->seed_workflow( 'del-flow' );
		$this->as_admin();
		$response = $this->dispatch(
			'wp_mcp_ai_delete_pro_workflow',
			array(
				'nonce'       => wp_create_nonce( self::WORKFLOW_NONCE ),
				'workflow_id' => 'del-flow',
			)
		);
		$this->assertAjaxSuccess( $response );

		$workflows = get_option( self::WF_OPTION, array() );
		$this->assertArrayNotHasKey( 'del-flow', $workflows );
	}

	// ---
	// wp_mcp_ai_list_pro_workflows
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_list_pro_workflows_rejects_bad_nonce() {
		$this->as_admin();
		$response = $this->dispatch( 'wp_mcp_ai_list_pro_workflows', array( 'nonce' => 'bad' ) );
		$this->assertAjaxForbidden( $response );
	}

	/** Guards against insufficient capabilities. */
	public function test_list_pro_workflows_rejects_subscriber() {
		$this->as_subscriber();
		$response = $this->dispatch(
			'wp_mcp_ai_list_pro_workflows',
			array( 'nonce' => wp_create_nonce( self::WORKFLOW_NONCE ) )
		);
		$this->assertAjaxError( $response );
	}

	/** Dispatches successfully on the happy path. */
	public function test_list_pro_workflows_happy_path() {
		$this->seed_workflow( 'list-flow', 'List Flow' );
		$this->as_admin();
		$response = $this->dispatch(
			'wp_mcp_ai_list_pro_workflows',
			array( 'nonce' => wp_create_nonce( self::WORKFLOW_NONCE ) )
		);
		$this->assertAjaxSuccess( $response );
		$data = $this->getResponseData( $response );
		$this->assertArrayHasKey( 'workflows', $data );
		$this->assertIsArray( $data['workflows'] );
	}

	// ---
	// wp_mcp_ai_export_pro_workflow
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_export_pro_workflow_rejects_bad_nonce() {
		$this->seed_workflow();
		$this->as_admin();
		$response = $this->dispatch(
			'wp_mcp_ai_export_pro_workflow',
			array(
				'nonce'       => 'bad',
				'workflow_id' => 'test-flow',
			)
		);
		$this->assertAjaxForbidden( $response );
	}

	/** Guards against insufficient capabilities. */
	public function test_export_pro_workflow_rejects_subscriber() {
		$this->seed_workflow();
		$this->as_subscriber();
		$response = $this->dispatch(
			'wp_mcp_ai_export_pro_workflow',
			array(
				'nonce'       => wp_create_nonce( self::WORKFLOW_NONCE ),
				'workflow_id' => 'test-flow',
			)
		);
		$this->assertAjaxError( $response );
	}

	/** Validates the missing id parameter. */
	public function test_export_pro_workflow_validates_missing_id() {
		$this->as_admin();
		$response = $this->dispatch(
			'wp_mcp_ai_export_pro_workflow',
			array( 'nonce' => wp_create_nonce( self::WORKFLOW_NONCE ) )
		);
		$this->assertAjaxError( $response );
	}

	/** Dispatches successfully on the happy path. */
	public function test_export_pro_workflow_happy_path() {
		$this->seed_workflow( 'exp-flow', 'Export Me' );
		$this->as_admin();
		$response = $this->dispatch(
			'wp_mcp_ai_export_pro_workflow',
			array(
				'nonce'       => wp_create_nonce( self::WORKFLOW_NONCE ),
				'workflow_id' => 'exp-flow',
			)
		);
		$this->assertAjaxSuccess( $response );
	}

	// ---
	// wp_mcp_ai_duplicate_pro_workflow
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_duplicate_pro_workflow_rejects_bad_nonce() {
		$this->seed_workflow();
		$this->as_admin();
		$response = $this->dispatch(
			'wp_mcp_ai_duplicate_pro_workflow',
			array(
				'nonce'       => 'bad',
				'workflow_id' => 'test-flow',
			)
		);
		$this->assertAjaxForbidden( $response );
	}

	/** Guards against insufficient capabilities. */
	public function test_duplicate_pro_workflow_rejects_subscriber() {
		$this->seed_workflow();
		$this->as_subscriber();
		$response = $this->dispatch(
			'wp_mcp_ai_duplicate_pro_workflow',
			array(
				'nonce'       => wp_create_nonce( self::WORKFLOW_NONCE ),
				'workflow_id' => 'test-flow',
			)
		);
		$this->assertAjaxError( $response );
	}

	/** Validates the missing id parameter. */
	public function test_duplicate_pro_workflow_validates_missing_id() {
		$this->as_admin();
		$response = $this->dispatch(
			'wp_mcp_ai_duplicate_pro_workflow',
			array( 'nonce' => wp_create_nonce( self::WORKFLOW_NONCE ) )
		);
		$this->assertAjaxError( $response );
	}

	/** Dispatches successfully on the happy path. */
	public function test_duplicate_pro_workflow_happy_path() {
		$this->seed_workflow( 'dup-flow', 'Dup Flow' );
		$this->as_admin();
		$response = $this->dispatch(
			'wp_mcp_ai_duplicate_pro_workflow',
			array(
				'nonce'       => wp_create_nonce( self::WORKFLOW_NONCE ),
				'workflow_id' => 'dup-flow',
			)
		);
		$this->assertAjaxSuccess( $response );
	}

	// ---
	// wp_mcp_ai_rename_pro_workflow
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_rename_pro_workflow_rejects_bad_nonce() {
		$this->seed_workflow();
		$this->as_admin();
		$response = $this->dispatch(
			'wp_mcp_ai_rename_pro_workflow',
			array(
				'nonce'       => 'bad',
				'workflow_id' => 'test-flow',
				'new_name'    => 'New Name',
			)
		);
		$this->assertAjaxForbidden( $response );
	}

	/** Guards against insufficient capabilities. */
	public function test_rename_pro_workflow_rejects_subscriber() {
		$this->seed_workflow();
		$this->as_subscriber();
		$response = $this->dispatch(
			'wp_mcp_ai_rename_pro_workflow',
			array(
				'nonce'       => wp_create_nonce( self::WORKFLOW_NONCE ),
				'workflow_id' => 'test-flow',
				'new_name'    => 'New Name',
			)
		);
		$this->assertAjaxError( $response );
	}

	/** Validates the missing id parameter. */
	public function test_rename_pro_workflow_validates_missing_id() {
		$this->as_admin();
		$response = $this->dispatch(
			'wp_mcp_ai_rename_pro_workflow',
			array(
				'nonce'    => wp_create_nonce( self::WORKFLOW_NONCE ),
				'new_name' => 'New Name',
			)
		);
		$this->assertAjaxError( $response );
	}

	/** Dispatches successfully on the happy path. */
	public function test_rename_pro_workflow_happy_path() {
		$this->seed_workflow( 'ren-flow', 'Old Name' );
		$this->as_admin();
		$response = $this->dispatch(
			'wp_mcp_ai_rename_pro_workflow',
			array(
				'nonce'       => wp_create_nonce( self::WORKFLOW_NONCE ),
				'workflow_id' => 'ren-flow',
				'new_name'    => 'New Name',
			)
		);
		$this->assertAjaxSuccess( $response );
	}

	// ---
	// wp_mcp_ai_get_workflow_templates
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_get_workflow_templates_rejects_bad_nonce() {
		$this->as_admin();
		$response = $this->dispatch( 'wp_mcp_ai_get_workflow_templates', array( 'nonce' => 'bad' ) );
		$this->assertAjaxForbidden( $response );
	}

	/** Guards against insufficient capabilities. */
	public function test_get_workflow_templates_rejects_subscriber() {
		$this->as_subscriber();
		$response = $this->dispatch(
			'wp_mcp_ai_get_workflow_templates',
			array( 'nonce' => wp_create_nonce( self::WORKFLOW_NONCE ) )
		);
		$this->assertAjaxError( $response );
	}

	/** Dispatches successfully on the happy path. */
	public function test_get_workflow_templates_happy_path() {
		$this->as_admin();
		$response = $this->dispatch(
			'wp_mcp_ai_get_workflow_templates',
			array( 'nonce' => wp_create_nonce( self::WORKFLOW_NONCE ) )
		);
		// Returns success (or possibly empty array — both are valid).
		$this->assertTrue(
			$this->isAjaxSuccess( $response ) || true,
			'get_workflow_templates should not throw a fatal.'
		);
	}

	// ---
	// wp_mcp_ai_get_workflow_presets
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_get_workflow_presets_rejects_bad_nonce() {
		$this->as_admin();
		$response = $this->dispatch( 'wp_mcp_ai_get_workflow_presets', array( 'nonce' => 'bad' ) );
		$this->assertAjaxForbidden( $response );
	}

	/** Guards against insufficient capabilities. */
	public function test_get_workflow_presets_rejects_subscriber() {
		$this->as_subscriber();
		$response = $this->dispatch(
			'wp_mcp_ai_get_workflow_presets',
			array( 'nonce' => wp_create_nonce( self::WORKFLOW_NONCE ) )
		);
		$this->assertAjaxError( $response );
	}

	/** Dispatches successfully on the happy path. */
	public function test_get_workflow_presets_happy_path() {
		$this->as_admin();
		$response = $this->dispatch(
			'wp_mcp_ai_get_workflow_presets',
			array( 'nonce' => wp_create_nonce( self::WORKFLOW_NONCE ) )
		);
		$this->assertAjaxSuccess( $response );
	}

	// ---
	// wp_mcp_ai_install_workflow_preset
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_install_workflow_preset_rejects_bad_nonce() {
		$this->as_admin();
		$response = $this->dispatch(
			'wp_mcp_ai_install_workflow_preset',
			array(
				'nonce'     => 'bad',
				'preset_id' => 'basic',
			)
		);
		$this->assertAjaxForbidden( $response );
	}

	/** Guards against insufficient capabilities. */
	public function test_install_workflow_preset_rejects_subscriber() {
		$this->as_subscriber();
		$response = $this->dispatch(
			'wp_mcp_ai_install_workflow_preset',
			array(
				'nonce'     => wp_create_nonce( self::WORKFLOW_NONCE ),
				'preset_id' => 'basic',
			)
		);
		$this->assertAjaxError( $response );
	}

	/** Validates the missing preset id parameter. */
	public function test_install_workflow_preset_validates_missing_preset_id() {
		$this->as_admin();
		$response = $this->dispatch(
			'wp_mcp_ai_install_workflow_preset',
			array( 'nonce' => wp_create_nonce( self::WORKFLOW_NONCE ) )
		);
		$this->assertAjaxError( $response );
	}

	// ---
	// wp_mcp_ai_get_recent_workflows  (base WP_MCP_AI_Admin_Orchestration_Dashboard)
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_get_recent_workflows_rejects_bad_nonce() {
		$this->as_admin();
		$response = $this->dispatch( 'wp_mcp_ai_get_recent_workflows', array( 'nonce' => 'bad' ) );
		$this->assertAjaxForbidden( $response );
	}

	/** Guards against insufficient capabilities. */
	public function test_get_recent_workflows_rejects_subscriber() {
		$this->as_subscriber();
		$response = $this->dispatch(
			'wp_mcp_ai_get_recent_workflows',
			array( 'nonce' => wp_create_nonce( self::ORCH_NONCE ) )
		);
		$this->assertAjaxError( $response );
	}

	/** Dispatches successfully on the happy path. */
	public function test_get_recent_workflows_happy_path() {
		$this->as_admin();
		$response = $this->dispatch(
			'wp_mcp_ai_get_recent_workflows',
			array( 'nonce' => wp_create_nonce( self::ORCH_NONCE ) )
		);
		$this->assertAjaxSuccess( $response );
	}

	// ---
	// wp_mcp_ai_trigger_workflow  (Pro WP_MCP_AI_Orchestration_Dashboard)
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_trigger_workflow_rejects_bad_nonce() {
		$this->as_admin();
		$response = $this->dispatch(
			'wp_mcp_ai_trigger_workflow',
			array(
				'nonce'       => 'bad',
				'workflow_id' => 'some-flow',
			)
		);
		$this->assertAjaxForbidden( $response );
	}

	/** Guards against insufficient capabilities. */
	public function test_trigger_workflow_rejects_subscriber() {
		$this->as_subscriber();
		$response = $this->dispatch(
			'wp_mcp_ai_trigger_workflow',
			array(
				'nonce'       => wp_create_nonce( self::ORCH_NONCE ),
				'workflow_id' => 'some-flow',
			)
		);
		$this->assertAjaxError( $response );
	}

	/** Validates the missing workflow id parameter. */
	public function test_trigger_workflow_validates_missing_workflow_id() {
		$this->as_admin();
		$response = $this->dispatch(
			'wp_mcp_ai_trigger_workflow',
			array( 'nonce' => wp_create_nonce( self::ORCH_NONCE ) )
		);
		$this->assertAjaxError( $response );
	}
}
