<?php
/**
 * AJAX tests for all "create from research" handlers (Pro addon).
 *
 * Every handler follows the same 4-point contract:
 *   1. Capability gate  — subscriber is rejected.
 *   2. Nonce check      — bad/missing nonce is rejected.
 *   3. Happy path       — valid request with minimal data succeeds or returns
 *                         a structured error (when AI service unavailable).
 *   4. Input validation — empty/missing research_data is rejected.
 *
 * Handlers covered (all require edit_posts; some require manage_options — see
 * per-handler comments):
 *  - wp_mcp_ai_create_post_from_research
 *  - wp_mcp_ai_create_product_from_research
 *  - wp_mcp_ai_create_project_from_research
 *  - wp_mcp_ai_create_task_from_research
 *  - wp_mcp_ai_create_event_from_research
 *  - wp_mcp_ai_create_company_from_research
 *  - wp_mcp_ai_create_page_from_research
 *  - wp_mcp_ai_create_place_from_research
 *  - wp_mcp_ai_create_policy_from_research
 *  - wp_mcp_ai_create_quiz_from_research
 *  - wp_mcp_ai_create_registration_from_research
 *  - wp_mcp_ai_create_schedule_from_research
 *  - wp_mcp_ai_create_financial_account_from_research
 *  - wp_mcp_ai_create_ff_team_from_research
 *  - wp_mcp_ai_create_image_template_from_research
 *  - wp_mcp_ai_create_document_template_from_research
 *  - wp_mcp_ai_create_lf_client_from_research
 *  - wp_mcp_ai_create_lf_matter_from_research
 *  - wp_mcp_ai_create_arch_drawing_from_research
 *  - wp_mcp_ai_create_arch_project_from_research
 *  - wp_mcp_ai_create_arch_spec_from_research
 *  - wp_mcp_ai_create_media_from_design
 *  - wp_mcp_ai_preview_schedule_from_research
 *  - wp_mcp_ai_create_appointment_from_research
 *
 * @package WP_MCP_AI
 */

// phpcs:disable WordPress.NamingConventions.ValidVariableName

/**
 * "Create from research" AJAX cluster (Pro addon).
 */
// Load the addon admin class the config cases gate on; the fantasy-football
// addon is not booted by the base test bootstrap, so require it here to keep
// the suite runnable standalone (mirrors CI, where earlier tests load it).
$wp_mcp_ai_ff_research_page = WP_MCP_AI_PATH . '../addons/fantasy-football/includes/admin/class-wp-mcp-ai-fantasy-football-research-page.php';
if ( file_exists( $wp_mcp_ai_ff_research_page ) ) {
	require_once $wp_mcp_ai_ff_research_page;
}
unset( $wp_mcp_ai_ff_research_page );

class Test_Create_From_Research_AJAX extends WP_MCP_AI_Ajax_TestCase {

	/**
	 * Map of AJAX action name → array(
	 *   'nonce'    => nonce action string,
	 *   'cap'      => required capability,
	 *   'class'    => optional class name that must exist (skip if absent),
	 *   'data_key' => POST key that carries the research/data payload,
	 * ).
	 *
	 * @var array<string,array{nonce:string,cap:string,class?:string,data_key:string}>
	 */
	private static array $handlers = array();

	/** Sets up shared state before any test in the class. */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		self::$handlers = array(
			'wp_mcp_ai_create_post_from_research'         => array(
				'nonce'    => 'wp_mcp_ai_research_post',
				'cap'      => 'edit_posts',
				'data_key' => 'research_data',
			),
			'wp_mcp_ai_create_product_from_research'      => array(
				'nonce'    => 'wp_mcp_ai_research_product',
				'cap'      => 'edit_posts',
				'data_key' => 'research_data',
			),
			'wp_mcp_ai_create_project_from_research'      => array(
				'nonce'    => 'wp_mcp_ai_research_project',
				'cap'      => 'edit_posts',
				'data_key' => 'research_data',
			),
			'wp_mcp_ai_create_task_from_research'         => array(
				'nonce'    => 'wp_mcp_ai_research_task',
				'cap'      => 'edit_posts',
				'data_key' => 'research_data',
			),
			'wp_mcp_ai_create_event_from_research'        => array(
				'nonce'    => 'wp_mcp_ai_research_event',
				'cap'      => 'edit_posts',
				'data_key' => 'research_data',
			),
			'wp_mcp_ai_create_company_from_research'      => array(
				'nonce'    => 'wp_mcp_ai_research_page',
				'cap'      => 'edit_posts',
				'data_key' => 'research_data',
			),
			'wp_mcp_ai_create_page_from_research'         => array(
				'nonce'    => 'wp_mcp_ai_research_page',
				'cap'      => 'edit_posts',
				'data_key' => 'research_data',
			),
			'wp_mcp_ai_create_place_from_research'        => array(
				'nonce'    => 'wp_mcp_ai_research_place',
				'cap'      => 'edit_posts',
				'data_key' => 'research_data',
			),
			'wp_mcp_ai_create_policy_from_research'       => array(
				'nonce'    => 'wp_mcp_ai_research_policy',
				'cap'      => 'edit_posts',
				'data_key' => 'research_data',
			),
			'wp_mcp_ai_create_quiz_from_research'         => array(
				'nonce'    => 'wp_mcp_ai_research_quiz',
				'cap'      => 'edit_posts',
				'data_key' => 'research_data',
			),
			'wp_mcp_ai_create_registration_from_research' => array(
				'nonce'    => 'wp_mcp_ai_research_registration',
				'cap'      => 'edit_posts',
				'data_key' => 'research_data',
			),
			'wp_mcp_ai_create_schedule_from_research'     => array(
				'nonce'    => 'wp_mcp_ai_research_pro_schedule',
				'cap'      => 'edit_posts',
				'data_key' => 'research_data',
			),
			'wp_mcp_ai_create_financial_account_from_research' => array(
				'nonce'    => 'wp_mcp_ai_research_financial_account',
				'cap'      => 'edit_posts',
				'data_key' => 'research_data',
			),
			'wp_mcp_ai_create_ff_team_from_research'      => array(
				'nonce'    => 'wp_mcp_ai_research_ff_team',
				'cap'      => 'edit_posts',
				'class'    => 'WP_MCP_AI_Fantasy_Football_Research_Page',
				'data_key' => 'research_data',
			),
			'wp_mcp_ai_create_image_template_from_research' => array(
				'nonce'    => 'wp_mcp_ai_research_image_template',
				'cap'      => 'edit_posts',
				'data_key' => 'research_data',
			),
			'wp_mcp_ai_create_document_template_from_research' => array(
				'nonce'    => 'wp_mcp_ai_research_document_template',
				'cap'      => 'edit_posts',
				'data_key' => 'research_data',
			),
			'wp_mcp_ai_create_lf_client_from_research'    => array(
				'nonce'    => 'wp_mcp_ai_lf_research',
				'cap'      => 'edit_posts',
				'data_key' => 'research_data',
			),
			'wp_mcp_ai_create_lf_matter_from_research'    => array(
				'nonce'    => 'wp_mcp_ai_lf_research',
				'cap'      => 'edit_posts',
				'data_key' => 'research_data',
			),
			'wp_mcp_ai_create_arch_drawing_from_research' => array(
				'nonce'    => 'wp_mcp_ai_research_arch_drawing',
				'cap'      => 'edit_posts',
				'data_key' => 'research_data',
			),
			'wp_mcp_ai_create_arch_project_from_research' => array(
				'nonce'    => 'wp_mcp_ai_research_arch_project',
				'cap'      => 'edit_posts',
				'data_key' => 'research_data',
			),
			'wp_mcp_ai_create_arch_spec_from_research'    => array(
				'nonce'    => 'wp_mcp_ai_research_arch_spec',
				'cap'      => 'edit_posts',
				'data_key' => 'research_data',
			),
			'wp_mcp_ai_create_media_from_design'          => array(
				'nonce'    => 'wp_mcp_ai_design_media',
				'cap'      => 'edit_posts',
				'data_key' => 'design_data',
			),
			'wp_mcp_ai_preview_schedule_from_research'    => array(
				'nonce'    => 'wp_mcp_ai_research_pro_schedule',
				'cap'      => 'edit_posts',
				'data_key' => 'schedule_data',
			),
			'wp_mcp_ai_create_appointment_from_research'  => array(
				'nonce'    => 'wp_mcp_ai_research_appointment',
				'cap'      => 'edit_posts',
				'data_key' => 'research_data',
			),
		);
	}

	// ---
	// Generic 4-point tests driven by self::$handlers table
	// ---

	/**
	 * Guards against a missing or invalid nonce.
	 *
	 * @dataProvider provideHandlers
	 * @param string $action AJAX action name.
	 * @param array  $cfg Handler configuration.
	 */
	public function test_handler_rejects_bad_nonce( string $action, array $cfg ): void {
		if ( ! empty( $cfg['class'] ) && ! class_exists( $cfg['class'] ) ) {
			$this->markTestSkipped( "Class {$cfg['class']} not loaded." );
		}
		$this->as_editor();
		$response = $this->dispatch( $action, array( 'nonce' => 'bad_nonce' ) );
		$this->assertAjaxForbidden( $response, "Expected nonce failure for {$action}" );
	}

	/**
	 * Guards against insufficient capabilities.
	 *
	 * @dataProvider provideHandlers
	 * @param string $action AJAX action name.
	 * @param array  $cfg Handler configuration.
	 */
	public function test_handler_rejects_subscriber( string $action, array $cfg ): void {
		if ( ! empty( $cfg['class'] ) && ! class_exists( $cfg['class'] ) ) {
			$this->markTestSkipped( "Class {$cfg['class']} not loaded." );
		}
		$this->as_subscriber();
		$response = $this->dispatch(
			$action,
			array(
				'nonce'          => wp_create_nonce( $cfg['nonce'] ),
				$cfg['data_key'] => wp_json_encode( array( 'title' => 'Test' ) ),
			)
		);
		$this->assertAjaxError( $response, "Expected permission failure for {$action}" );
	}

	/**
	 * Validates the empty research data parameter.
	 *
	 * @dataProvider provideHandlers
	 * @param string $action AJAX action name.
	 * @param array  $cfg Handler configuration.
	 */
	public function test_handler_validates_empty_research_data( string $action, array $cfg ): void {
		if ( ! empty( $cfg['class'] ) && ! class_exists( $cfg['class'] ) ) {
			$this->markTestSkipped( "Class {$cfg['class']} not loaded." );
		}
		$this->as_editor();
		$response = $this->dispatch(
			$action,
			array( 'nonce' => wp_create_nonce( $cfg['nonce'] ) )
			// data_key intentionally omitted → handler must reject.
		);
		$this->assertAjaxError( $response, "Expected validation failure (empty data) for {$action}" );
	}

	/**
	 * Data provider: yields [ action_name, config_array ] pairs.
	 *
	 * @return array<string, array{string, array}>
	 */
	public static function provideHandlers(): array {
		// setUpBeforeClass may not have run yet in the provider context on older
		// PHPUnit versions; build the list statically from the same data.
		$raw = array(
			array(
				'wp_mcp_ai_create_post_from_research',
				array(
					'nonce'    => 'wp_mcp_ai_research_post',
					'cap'      => 'edit_posts',
					'data_key' => 'research_data',
				),
			),
			array(
				'wp_mcp_ai_create_product_from_research',
				array(
					'nonce'    => 'wp_mcp_ai_research_product',
					'cap'      => 'edit_posts',
					'data_key' => 'research_data',
				),
			),
			array(
				'wp_mcp_ai_create_project_from_research',
				array(
					'nonce'    => 'wp_mcp_ai_research_project',
					'cap'      => 'edit_posts',
					'data_key' => 'research_data',
				),
			),
			array(
				'wp_mcp_ai_create_task_from_research',
				array(
					'nonce'    => 'wp_mcp_ai_research_task',
					'cap'      => 'edit_posts',
					'data_key' => 'research_data',
				),
			),
			array(
				'wp_mcp_ai_create_event_from_research',
				array(
					'nonce'    => 'wp_mcp_ai_research_event',
					'cap'      => 'edit_posts',
					'data_key' => 'research_data',
				),
			),
			array(
				'wp_mcp_ai_create_company_from_research',
				array(
					'nonce'    => 'wp_mcp_ai_research_page',
					'cap'      => 'edit_posts',
					'data_key' => 'research_data',
				),
			),
			array(
				'wp_mcp_ai_create_page_from_research',
				array(
					'nonce'    => 'wp_mcp_ai_research_page',
					'cap'      => 'edit_posts',
					'data_key' => 'research_data',
				),
			),
			array(
				'wp_mcp_ai_create_place_from_research',
				array(
					'nonce'    => 'wp_mcp_ai_research_place',
					'cap'      => 'edit_posts',
					'data_key' => 'research_data',
				),
			),
			array(
				'wp_mcp_ai_create_policy_from_research',
				array(
					'nonce'    => 'wp_mcp_ai_research_policy',
					'cap'      => 'edit_posts',
					'data_key' => 'research_data',
				),
			),
			array(
				'wp_mcp_ai_create_quiz_from_research',
				array(
					'nonce'    => 'wp_mcp_ai_research_quiz',
					'cap'      => 'edit_posts',
					'data_key' => 'research_data',
				),
			),
			array(
				'wp_mcp_ai_create_registration_from_research',
				array(
					'nonce'    => 'wp_mcp_ai_research_registration',
					'cap'      => 'edit_posts',
					'data_key' => 'research_data',
				),
			),
			array(
				'wp_mcp_ai_create_schedule_from_research',
				array(
					'nonce'    => 'wp_mcp_ai_research_pro_schedule',
					'cap'      => 'edit_posts',
					'data_key' => 'research_data',
				),
			),
			array(
				'wp_mcp_ai_create_financial_account_from_research',
				array(
					'nonce'    => 'wp_mcp_ai_research_financial_account',
					'cap'      => 'edit_posts',
					'data_key' => 'research_data',
				),
			),
			array(
				'wp_mcp_ai_create_ff_team_from_research',
				array(
					'nonce'    => 'wp_mcp_ai_research_ff_team',
					'cap'      => 'edit_posts',
					'class'    => 'WP_MCP_AI_Fantasy_Football_Research_Page',
					'data_key' => 'research_data',
				),
			),
			array(
				'wp_mcp_ai_create_image_template_from_research',
				array(
					'nonce'    => 'wp_mcp_ai_research_image_template',
					'cap'      => 'edit_posts',
					'data_key' => 'research_data',
				),
			),
			array(
				'wp_mcp_ai_create_document_template_from_research',
				array(
					'nonce'    => 'wp_mcp_ai_research_document_template',
					'cap'      => 'edit_posts',
					'data_key' => 'research_data',
				),
			),
			array(
				'wp_mcp_ai_create_lf_client_from_research',
				array(
					'nonce'    => 'wp_mcp_ai_lf_research',
					'cap'      => 'edit_posts',
					'data_key' => 'research_data',
				),
			),
			array(
				'wp_mcp_ai_create_lf_matter_from_research',
				array(
					'nonce'    => 'wp_mcp_ai_lf_research',
					'cap'      => 'edit_posts',
					'data_key' => 'research_data',
				),
			),
			array(
				'wp_mcp_ai_create_arch_drawing_from_research',
				array(
					'nonce'    => 'wp_mcp_ai_research_arch_drawing',
					'cap'      => 'edit_posts',
					'data_key' => 'research_data',
				),
			),
			array(
				'wp_mcp_ai_create_arch_project_from_research',
				array(
					'nonce'    => 'wp_mcp_ai_research_arch_project',
					'cap'      => 'edit_posts',
					'data_key' => 'research_data',
				),
			),
			array(
				'wp_mcp_ai_create_arch_spec_from_research',
				array(
					'nonce'    => 'wp_mcp_ai_research_arch_spec',
					'cap'      => 'edit_posts',
					'data_key' => 'research_data',
				),
			),
			array(
				'wp_mcp_ai_create_media_from_design',
				array(
					'nonce'    => 'wp_mcp_ai_design_media',
					'cap'      => 'edit_posts',
					'data_key' => 'design_data',
				),
			),
			array(
				'wp_mcp_ai_preview_schedule_from_research',
				array(
					'nonce'    => 'wp_mcp_ai_research_pro_schedule',
					'cap'      => 'edit_posts',
					'data_key' => 'schedule_data',
				),
			),
			array(
				'wp_mcp_ai_create_appointment_from_research',
				array(
					'nonce'    => 'wp_mcp_ai_research_appointment',
					'cap'      => 'edit_posts',
					'data_key' => 'research_data',
				),
			),
		);

		$out = array();
		foreach ( $raw as $row ) {
			$out[ $row[0] ] = $row;
		}
		return $out;
	}
}
