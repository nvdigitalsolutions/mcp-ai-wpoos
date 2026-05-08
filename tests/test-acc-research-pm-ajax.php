<?php
/**
 * AJAX tests for the Agent Command Center (ACC), Research Add Base,
 * and Project Management AI Actions handlers (Pro addon).
 *
 * Handlers covered:
 *  Agent Command Center (WP_MCP_AI_Pro_Agent_Command_Center):
 *   - wp_mcp_ai_acc_get_dashboard_data
 *   - wp_mcp_ai_acc_get_activity_log
 *   - wp_mcp_ai_acc_handle_approval
 *   - wp_mcp_ai_acc_get_analytics
 *
 *  Research Add Base (WP_MCP_AI_Research_Add_Base):
 *   - wp_mcp_ai_research_add_item
 *   - wp_mcp_ai_research_delete_item
 *   - wp_mcp_ai_research_get_item
 *   - wp_mcp_ai_research_ai_generate
 *
 *  Project Management AI Actions (WP_MCP_AI_Project_Management_AI_Actions):
 *   - wp_mcp_ai_pm_generate_description
 *   - wp_mcp_ai_pm_suggest_tasks
 *   - wp_mcp_ai_pm_analyze_project
 *   - wp_mcp_ai_pm_bulk_generate
 *
 *  Project Management Bulk AI (WP_MCP_AI_Project_Management_Bulk_AI):
 *   - wp_mcp_ai_pm_bulk_process
 *
 * @package WP_MCP_AI
 */

// phpcs:disable WordPress.NamingConventions.ValidVariableName

/**
 * ACC + Research + PM AI AJAX cluster.
 */
class Test_ACC_Research_PM_AJAX extends WP_MCP_AI_Ajax_TestCase {

	/** Pro class needed for the ACC section. */
	const ACC_CLASS = 'WP_MCP_AI_Pro_Agent_Command_Center';

	/** Pro class needed for the research section. */
	const RESEARCH_CLASS = 'WP_MCP_AI_Research_Add_Base';

	/** Pro class needed for the PM section. */
	const PM_CLASS = 'WP_MCP_AI_Project_Management_AI_Actions';

	/** Nonce for ACC handlers. */
	const ACC_NONCE = 'wp_mcp_ai_agent_command_center';

	/** Nonce for PM AI-actions handlers. */
	const PM_NONCE = 'wp_mcp_ai_pm_ai_actions';

	/** Nonce for PM bulk-process handler. */
	const PM_BULK_NONCE = 'wp_mcp_ai_pm_bulk';

	/**
	 * Whether the ACC class exists.
	 *
	 * @var bool
	 */
	private static bool $has_acc = false;

	/**
	 * Whether the Research class exists.
	 *
	 * @var bool
	 */
	private static bool $has_research = false;

	/**
	 * Whether the PM class exists.
	 *
	 * @var bool
	 */
	private static bool $has_pm = false;

	/** Sets up shared state before any test in the class. */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		self::$has_acc      = class_exists( self::ACC_CLASS );
		self::$has_research = class_exists( self::RESEARCH_CLASS );
		self::$has_pm       = class_exists( self::PM_CLASS );
	}

	// ---
	// ACC — wp_mcp_ai_acc_get_dashboard_data
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_acc_get_dashboard_data_rejects_bad_nonce() {
		if ( ! self::$has_acc ) {
			$this->markTestSkipped( 'ACC class not loaded.' );
		}
		$this->as_admin();
		$response = $this->dispatch(
			'wp_mcp_ai_acc_get_dashboard_data',
			array( 'nonce' => 'bad_nonce' )
		);
		$this->assertAjaxForbidden( $response );
	}

	/** Guards against insufficient capabilities. */
	public function test_acc_get_dashboard_data_rejects_subscriber() {
		if ( ! self::$has_acc ) {
			$this->markTestSkipped( 'ACC class not loaded.' );
		}
		$this->as_subscriber();
		$response = $this->dispatch(
			'wp_mcp_ai_acc_get_dashboard_data',
			array( 'nonce' => wp_create_nonce( self::ACC_NONCE ) )
		);
		$this->assertAjaxError( $response );
	}

	/** Dispatches successfully on the happy path. */
	public function test_acc_get_dashboard_data_happy_path() {
		if ( ! self::$has_acc ) {
			$this->markTestSkipped( 'ACC class not loaded.' );
		}
		$this->as_admin();
		$response = $this->dispatch(
			'wp_mcp_ai_acc_get_dashboard_data',
			array( 'nonce' => wp_create_nonce( self::ACC_NONCE ) )
		);
		$this->assertAjaxSuccess( $response );
	}

	// ---
	// ACC — wp_mcp_ai_acc_get_activity_log
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_acc_get_activity_log_rejects_bad_nonce() {
		if ( ! self::$has_acc ) {
			$this->markTestSkipped( 'ACC class not loaded.' );
		}
		$this->as_admin();
		$response = $this->dispatch( 'wp_mcp_ai_acc_get_activity_log', array( 'nonce' => 'bad' ) );
		$this->assertAjaxForbidden( $response );
	}

	/** Guards against insufficient capabilities. */
	public function test_acc_get_activity_log_rejects_subscriber() {
		if ( ! self::$has_acc ) {
			$this->markTestSkipped( 'ACC class not loaded.' );
		}
		$this->as_subscriber();
		$response = $this->dispatch(
			'wp_mcp_ai_acc_get_activity_log',
			array( 'nonce' => wp_create_nonce( self::ACC_NONCE ) )
		);
		$this->assertAjaxError( $response );
	}

	/** Dispatches successfully on the happy path. */
	public function test_acc_get_activity_log_happy_path() {
		if ( ! self::$has_acc ) {
			$this->markTestSkipped( 'ACC class not loaded.' );
		}
		$this->as_admin();
		$response = $this->dispatch(
			'wp_mcp_ai_acc_get_activity_log',
			array( 'nonce' => wp_create_nonce( self::ACC_NONCE ) )
		);
		$this->assertAjaxSuccess( $response );
	}

	// ---
	// ACC — wp_mcp_ai_acc_handle_approval
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_acc_handle_approval_rejects_bad_nonce() {
		if ( ! self::$has_acc ) {
			$this->markTestSkipped( 'ACC class not loaded.' );
		}
		$this->as_admin();
		$response = $this->dispatch(
			'wp_mcp_ai_acc_handle_approval',
			array(
				'nonce'       => 'bad',
				'approval_id' => '1',
				'action'      => 'approve',
			)
		);
		$this->assertAjaxForbidden( $response );
	}

	/** Guards against insufficient capabilities. */
	public function test_acc_handle_approval_rejects_subscriber() {
		if ( ! self::$has_acc ) {
			$this->markTestSkipped( 'ACC class not loaded.' );
		}
		$this->as_subscriber();
		$response = $this->dispatch(
			'wp_mcp_ai_acc_handle_approval',
			array(
				'nonce'       => wp_create_nonce( self::ACC_NONCE ),
				'approval_id' => '1',
				'action'      => 'approve',
			)
		);
		$this->assertAjaxError( $response );
	}

	/** Validates the missing id parameter. */
	public function test_acc_handle_approval_validates_missing_id() {
		if ( ! self::$has_acc ) {
			$this->markTestSkipped( 'ACC class not loaded.' );
		}
		$this->as_admin();
		$response = $this->dispatch(
			'wp_mcp_ai_acc_handle_approval',
			array(
				'nonce'  => wp_create_nonce( self::ACC_NONCE ),
				'action' => 'approve',
			)
		);
		$this->assertAjaxError( $response );
	}

	// ---
	// ACC — wp_mcp_ai_acc_get_analytics
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_acc_get_analytics_rejects_bad_nonce() {
		if ( ! self::$has_acc ) {
			$this->markTestSkipped( 'ACC class not loaded.' );
		}
		$this->as_admin();
		$response = $this->dispatch( 'wp_mcp_ai_acc_get_analytics', array( 'nonce' => 'bad' ) );
		$this->assertAjaxForbidden( $response );
	}

	/** Guards against insufficient capabilities. */
	public function test_acc_get_analytics_rejects_subscriber() {
		if ( ! self::$has_acc ) {
			$this->markTestSkipped( 'ACC class not loaded.' );
		}
		$this->as_subscriber();
		$response = $this->dispatch(
			'wp_mcp_ai_acc_get_analytics',
			array( 'nonce' => wp_create_nonce( self::ACC_NONCE ) )
		);
		$this->assertAjaxError( $response );
	}

	/** Dispatches successfully on the happy path. */
	public function test_acc_get_analytics_happy_path() {
		if ( ! self::$has_acc ) {
			$this->markTestSkipped( 'ACC class not loaded.' );
		}
		$this->as_admin();
		$response = $this->dispatch(
			'wp_mcp_ai_acc_get_analytics',
			array( 'nonce' => wp_create_nonce( self::ACC_NONCE ) )
		);
		$this->assertAjaxSuccess( $response );
	}

	// ---
	// Research — wp_mcp_ai_research_add_item
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_research_add_item_rejects_bad_nonce() {
		if ( ! self::$has_research ) {
			$this->markTestSkipped( 'Research class not loaded.' );
		}
		$this->as_admin();
		$response = $this->dispatch(
			'wp_mcp_ai_research_add_item',
			array(
				'nonce'     => 'bad',
				'item_data' => wp_json_encode( array( 'label' => 'x' ) ),
			)
		);
		$this->assertAjaxForbidden( $response );
	}

	/** Guards against insufficient capabilities. */
	public function test_research_add_item_rejects_subscriber() {
		if ( ! self::$has_research ) {
			$this->markTestSkipped( 'Research class not loaded.' );
		}
		$this->as_subscriber();
		$response = $this->dispatch(
			'wp_mcp_ai_research_add_item',
			array(
				'nonce'     => wp_create_nonce( 'wp_mcp_ai_research_add_item' ),
				'item_data' => wp_json_encode( array( 'label' => 'x' ) ),
			)
		);
		$this->assertAjaxError( $response );
	}

	/** Validates the missing data parameter. */
	public function test_research_add_item_validates_missing_data() {
		if ( ! self::$has_research ) {
			$this->markTestSkipped( 'Research class not loaded.' );
		}
		$this->as_admin();
		$response = $this->dispatch(
			'wp_mcp_ai_research_add_item',
			array( 'nonce' => wp_create_nonce( 'wp_mcp_ai_research_add_item' ) )
		);
		$this->assertAjaxError( $response );
	}

	// ---
	// Research — wp_mcp_ai_research_delete_item
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_research_delete_item_rejects_bad_nonce() {
		if ( ! self::$has_research ) {
			$this->markTestSkipped( 'Research class not loaded.' );
		}
		$this->as_admin();
		$response = $this->dispatch(
			'wp_mcp_ai_research_delete_item',
			array(
				'nonce'   => 'bad',
				'item_id' => '5',
			)
		);
		$this->assertAjaxForbidden( $response );
	}

	/** Guards against insufficient capabilities. */
	public function test_research_delete_item_rejects_subscriber() {
		if ( ! self::$has_research ) {
			$this->markTestSkipped( 'Research class not loaded.' );
		}
		$this->as_subscriber();
		$response = $this->dispatch(
			'wp_mcp_ai_research_delete_item',
			array(
				'nonce'   => wp_create_nonce( 'wp_mcp_ai_research_delete_item' ),
				'item_id' => '5',
			)
		);
		$this->assertAjaxError( $response );
	}

	/** Validates the missing id parameter. */
	public function test_research_delete_item_validates_missing_id() {
		if ( ! self::$has_research ) {
			$this->markTestSkipped( 'Research class not loaded.' );
		}
		$this->as_admin();
		$response = $this->dispatch(
			'wp_mcp_ai_research_delete_item',
			array( 'nonce' => wp_create_nonce( 'wp_mcp_ai_research_delete_item' ) )
		);
		$this->assertAjaxError( $response );
	}

	// ---
	// Research — wp_mcp_ai_research_get_item
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_research_get_item_rejects_bad_nonce() {
		if ( ! self::$has_research ) {
			$this->markTestSkipped( 'Research class not loaded.' );
		}
		$this->as_admin();
		$response = $this->dispatch(
			'wp_mcp_ai_research_get_item',
			array(
				'nonce'   => 'bad',
				'item_id' => '5',
			)
		);
		$this->assertAjaxForbidden( $response );
	}

	/** Guards against insufficient capabilities. */
	public function test_research_get_item_rejects_subscriber() {
		if ( ! self::$has_research ) {
			$this->markTestSkipped( 'Research class not loaded.' );
		}
		$this->as_subscriber();
		$response = $this->dispatch(
			'wp_mcp_ai_research_get_item',
			array(
				'nonce'   => wp_create_nonce( 'wp_mcp_ai_research_get_item' ),
				'item_id' => '5',
			)
		);
		$this->assertAjaxError( $response );
	}

	/** Validates the missing id parameter. */
	public function test_research_get_item_validates_missing_id() {
		if ( ! self::$has_research ) {
			$this->markTestSkipped( 'Research class not loaded.' );
		}
		$this->as_admin();
		$response = $this->dispatch(
			'wp_mcp_ai_research_get_item',
			array( 'nonce' => wp_create_nonce( 'wp_mcp_ai_research_get_item' ) )
		);
		$this->assertAjaxError( $response );
	}

	// ---
	// Research — wp_mcp_ai_research_ai_generate
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_research_ai_generate_rejects_bad_nonce() {
		if ( ! self::$has_research ) {
			$this->markTestSkipped( 'Research class not loaded.' );
		}
		$this->as_admin();
		$response = $this->dispatch(
			'wp_mcp_ai_research_ai_generate',
			array(
				'nonce'  => 'bad',
				'prompt' => 'Hello',
			)
		);
		$this->assertAjaxForbidden( $response );
	}

	/** Guards against insufficient capabilities. */
	public function test_research_ai_generate_rejects_subscriber() {
		if ( ! self::$has_research ) {
			$this->markTestSkipped( 'Research class not loaded.' );
		}
		$this->as_subscriber();
		$response = $this->dispatch(
			'wp_mcp_ai_research_ai_generate',
			array(
				'nonce'  => wp_create_nonce( 'wp_mcp_ai_research_ai_generate' ),
				'prompt' => 'Hello',
			)
		);
		$this->assertAjaxError( $response );
	}

	// ---
	// PM — wp_mcp_ai_pm_generate_description
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_pm_generate_description_rejects_bad_nonce() {
		if ( ! self::$has_pm ) {
			$this->markTestSkipped( 'PM class not loaded.' );
		}
		$this->as_editor();
		$response = $this->dispatch(
			'wp_mcp_ai_pm_generate_description',
			array(
				'nonce'      => 'bad',
				'task_title' => 'Build widget',
				'context'    => '',
			)
		);
		$this->assertAjaxForbidden( $response );
	}

	/** Guards against insufficient capabilities. */
	public function test_pm_generate_description_rejects_subscriber() {
		if ( ! self::$has_pm ) {
			$this->markTestSkipped( 'PM class not loaded.' );
		}
		$this->as_subscriber();
		$response = $this->dispatch(
			'wp_mcp_ai_pm_generate_description',
			array(
				'nonce'      => wp_create_nonce( self::PM_NONCE ),
				'task_title' => 'Build widget',
				'context'    => '',
			)
		);
		$this->assertAjaxError( $response );
	}

	/** Validates the missing title parameter. */
	public function test_pm_generate_description_validates_missing_title() {
		if ( ! self::$has_pm ) {
			$this->markTestSkipped( 'PM class not loaded.' );
		}
		$this->as_editor();
		$response = $this->dispatch(
			'wp_mcp_ai_pm_generate_description',
			array( 'nonce' => wp_create_nonce( self::PM_NONCE ) )
		);
		$this->assertAjaxError( $response );
	}

	// ---
	// PM — wp_mcp_ai_pm_suggest_tasks
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_pm_suggest_tasks_rejects_bad_nonce() {
		if ( ! self::$has_pm ) {
			$this->markTestSkipped( 'PM class not loaded.' );
		}
		$this->as_editor();
		$response = $this->dispatch(
			'wp_mcp_ai_pm_suggest_tasks',
			array(
				'nonce'      => 'bad',
				'project_id' => '1',
			)
		);
		$this->assertAjaxForbidden( $response );
	}

	/** Guards against insufficient capabilities. */
	public function test_pm_suggest_tasks_rejects_subscriber() {
		if ( ! self::$has_pm ) {
			$this->markTestSkipped( 'PM class not loaded.' );
		}
		$this->as_subscriber();
		$response = $this->dispatch(
			'wp_mcp_ai_pm_suggest_tasks',
			array(
				'nonce'      => wp_create_nonce( self::PM_NONCE ),
				'project_id' => '1',
			)
		);
		$this->assertAjaxError( $response );
	}

	/** Validates the missing project id parameter. */
	public function test_pm_suggest_tasks_validates_missing_project_id() {
		if ( ! self::$has_pm ) {
			$this->markTestSkipped( 'PM class not loaded.' );
		}
		$this->as_editor();
		$response = $this->dispatch(
			'wp_mcp_ai_pm_suggest_tasks',
			array( 'nonce' => wp_create_nonce( self::PM_NONCE ) )
		);
		$this->assertAjaxError( $response );
	}

	// ---
	// PM — wp_mcp_ai_pm_analyze_project
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_pm_analyze_project_rejects_bad_nonce() {
		if ( ! self::$has_pm ) {
			$this->markTestSkipped( 'PM class not loaded.' );
		}
		$this->as_editor();
		$response = $this->dispatch(
			'wp_mcp_ai_pm_analyze_project',
			array(
				'nonce'      => 'bad',
				'project_id' => '1',
			)
		);
		$this->assertAjaxForbidden( $response );
	}

	/** Guards against insufficient capabilities. */
	public function test_pm_analyze_project_rejects_subscriber() {
		if ( ! self::$has_pm ) {
			$this->markTestSkipped( 'PM class not loaded.' );
		}
		$this->as_subscriber();
		$response = $this->dispatch(
			'wp_mcp_ai_pm_analyze_project',
			array(
				'nonce'      => wp_create_nonce( self::PM_NONCE ),
				'project_id' => '1',
			)
		);
		$this->assertAjaxError( $response );
	}

	// ---
	// PM — wp_mcp_ai_pm_bulk_generate
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_pm_bulk_generate_rejects_bad_nonce() {
		if ( ! self::$has_pm ) {
			$this->markTestSkipped( 'PM class not loaded.' );
		}
		$this->as_editor();
		$response = $this->dispatch(
			'wp_mcp_ai_pm_bulk_generate',
			array(
				'nonce'      => 'bad',
				'project_id' => '1',
			)
		);
		$this->assertAjaxForbidden( $response );
	}

	/** Guards against insufficient capabilities. */
	public function test_pm_bulk_generate_rejects_subscriber() {
		if ( ! self::$has_pm ) {
			$this->markTestSkipped( 'PM class not loaded.' );
		}
		$this->as_subscriber();
		$response = $this->dispatch(
			'wp_mcp_ai_pm_bulk_generate',
			array(
				'nonce'      => wp_create_nonce( self::PM_NONCE ),
				'project_id' => '1',
			)
		);
		$this->assertAjaxError( $response );
	}

	// ---
	// PM — wp_mcp_ai_pm_bulk_process (WP_MCP_AI_Project_Management_Bulk_AI)
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_pm_bulk_process_rejects_bad_nonce() {
		if ( ! class_exists( 'WP_MCP_AI_Project_Management_Bulk_AI' ) ) {
			$this->markTestSkipped( 'PM Bulk AI class not loaded.' );
		}
		$this->as_editor();
		$response = $this->dispatch(
			'wp_mcp_ai_pm_bulk_process',
			array(
				'nonce'       => 'bad',
				'action_type' => 'generate_descriptions',
				'post_ids'    => wp_json_encode( array( 1 ) ),
			)
		);
		$this->assertAjaxForbidden( $response );
	}

	/** Guards against insufficient capabilities. */
	public function test_pm_bulk_process_rejects_subscriber() {
		if ( ! class_exists( 'WP_MCP_AI_Project_Management_Bulk_AI' ) ) {
			$this->markTestSkipped( 'PM Bulk AI class not loaded.' );
		}
		$this->as_subscriber();
		$response = $this->dispatch(
			'wp_mcp_ai_pm_bulk_process',
			array(
				'nonce'       => wp_create_nonce( self::PM_BULK_NONCE ),
				'action_type' => 'generate_descriptions',
				'post_ids'    => wp_json_encode( array( 1 ) ),
			)
		);
		$this->assertAjaxError( $response );
	}

	/** Validates the missing action type parameter. */
	public function test_pm_bulk_process_validates_missing_action_type() {
		if ( ! class_exists( 'WP_MCP_AI_Project_Management_Bulk_AI' ) ) {
			$this->markTestSkipped( 'PM Bulk AI class not loaded.' );
		}
		$this->as_editor();
		$response = $this->dispatch(
			'wp_mcp_ai_pm_bulk_process',
			array(
				'nonce'    => wp_create_nonce( self::PM_BULK_NONCE ),
				'post_ids' => wp_json_encode( array( 1 ) ),
			)
		);
		$this->assertAjaxError( $response );
	}
}
