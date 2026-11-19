<?php
/**
 * AJAX tests for all "create from research" handlers (Pro addon).
 *
 * Every handler follows the same 3-point contract:
 *   1. Nonce check      — bad/missing nonce is rejected.
 *   2. Capability gate  — subscriber is rejected.
 *   3. Input validation — empty/missing research data is rejected.
 *
 * A happy path is not exercised: the handlers call into JetEngine / WP
 * post-creation APIs that need a fully bootstrapped environment, or into AI
 * services that are unavailable under test.
 *
 * Handlers covered (all listed capabilities are checked by the handler):
 *  - wp_mcp_ai_create_post_from_research           (edit_posts)
 *  - wp_mcp_ai_create_product_from_research        (edit_posts)
 *  - wp_mcp_ai_create_project_from_research        (edit_posts)
 *  - wp_mcp_ai_create_task_from_research           (edit_posts)
 *  - wp_mcp_ai_create_event_from_research          (edit_posts)
 *  - wp_mcp_ai_create_company_from_research        (edit_posts)
 *  - wp_mcp_ai_create_page_from_research           (edit_pages)
 *  - wp_mcp_ai_create_place_from_research          (edit_posts)
 *  - wp_mcp_ai_create_policy_from_research         (edit_posts)
 *  - wp_mcp_ai_create_quiz_from_research           (edit_posts)
 *  - wp_mcp_ai_create_registration_from_research   (edit_posts)
 *  - wp_mcp_ai_create_schedule_from_research       (manage_options)
 *  - wp_mcp_ai_create_financial_account_from_research (edit_posts)
 *  - wp_mcp_ai_create_ff_team_from_research        (fantasy-football addon;
 *                                                   skips — see handler_map())
 *  - wp_mcp_ai_create_image_template_from_research (edit_posts)
 *  - wp_mcp_ai_create_document_template_from_research (edit_posts)
 *  - wp_mcp_ai_create_lf_client_from_research      (edit_posts)
 *  - wp_mcp_ai_create_lf_matter_from_research      (edit_posts)
 *  - wp_mcp_ai_create_arch_drawing_from_research   (edit_posts)
 *  - wp_mcp_ai_create_arch_project_from_research   (edit_posts)
 *  - wp_mcp_ai_create_arch_spec_from_research      (edit_posts)
 *  - wp_mcp_ai_create_media_from_design            (upload_files)
 *  - wp_mcp_ai_preview_schedule_from_research      (manage_options)
 *  - wp_mcp_ai_create_appointment_from_research    (edit_posts)
 *
 * @package WP_MCP_AI
 */

// phpcs:disable WordPress.NamingConventions.ValidVariableName

/**
 * "Create from research" AJAX cluster (Pro addon).
 */
class Test_Create_From_Research_AJAX extends WP_MCP_AI_Ajax_TestCase {

	/**
	 * Canonical handler map: action → class, file, nonce, capability, payload key.
	 *
	 * `role` is the role the validation test runs as. It defaults to editor and
	 * is elevated only where the handler requires a capability an editor does
	 * not hold (the schedule handlers need manage_options) — otherwise the
	 * validation case would stop at the capability gate and pass without
	 * exercising validation.
	 *
	 * @return array<string,array{class:string,file:string,nonce:string,cap:string,data_key:string,role?:string}>
	 */
	private static function handler_map(): array {
		$pro = defined( 'WP_MCP_AI_PRO_PATH' ) ? WP_MCP_AI_PRO_PATH : '';

		$page = static function ( $slug ) use ( $pro ) {
			return $pro . 'includes/admin/class-wp-mcp-ai-' . $slug . '-research-page.php';
		};

		return array(
			'wp_mcp_ai_create_post_from_research'               => array(
				'class'    => 'WP_MCP_AI_Post_Research_Page',
				'file'     => $page( 'post' ),
				'nonce'    => 'wp_mcp_ai_research_post',
				'cap'      => 'edit_posts',
				'data_key' => 'research_data',
			),
			'wp_mcp_ai_create_product_from_research'            => array(
				'class'    => 'WP_MCP_AI_Product_Research_Page',
				'file'     => $page( 'product' ),
				'nonce'    => 'wp_mcp_ai_research_product',
				'cap'      => 'edit_posts',
				'data_key' => 'research_data',
			),
			'wp_mcp_ai_create_project_from_research'            => array(
				'class'    => 'WP_MCP_AI_Project_Research_Page',
				'file'     => $page( 'project' ),
				'nonce'    => 'wp_mcp_ai_research_project',
				'cap'      => 'edit_posts',
				'data_key' => 'research_data',
			),
			'wp_mcp_ai_create_task_from_research'               => array(
				'class'    => 'WP_MCP_AI_Task_Research_Page',
				'file'     => $page( 'task' ),
				'nonce'    => 'wp_mcp_ai_research_task',
				'cap'      => 'edit_posts',
				'data_key' => 'research_data',
			),
			'wp_mcp_ai_create_event_from_research'              => array(
				'class'    => 'WP_MCP_AI_Event_Research_Page',
				'file'     => $page( 'event' ),
				'nonce'    => 'wp_mcp_ai_research_event',
				'cap'      => 'edit_posts',
				'data_key' => 'research_data',
			),
			// Company shares the wp_mcp_ai_research_page nonce.
			'wp_mcp_ai_create_company_from_research'            => array(
				'class'    => 'WP_MCP_AI_Company_Research_Page',
				'file'     => $page( 'company' ),
				'nonce'    => 'wp_mcp_ai_research_page',
				'cap'      => 'edit_posts',
				'data_key' => 'research_data',
			),
			'wp_mcp_ai_create_page_from_research'               => array(
				'class'    => 'WP_MCP_AI_Page_Research_Page',
				'file'     => $page( 'page' ),
				'nonce'    => 'wp_mcp_ai_research_page',
				'cap'      => 'edit_pages',
				'data_key' => 'research_data',
			),
			'wp_mcp_ai_create_place_from_research'              => array(
				'class'    => 'WP_MCP_AI_Place_Research_Page',
				'file'     => $page( 'place' ),
				'nonce'    => 'wp_mcp_ai_research_place',
				'cap'      => 'edit_posts',
				'data_key' => 'research_data',
			),
			'wp_mcp_ai_create_policy_from_research'             => array(
				'class'    => 'WP_MCP_AI_Policy_Research_Page',
				'file'     => $page( 'policy' ),
				'nonce'    => 'wp_mcp_ai_research_policy',
				'cap'      => 'edit_posts',
				'data_key' => 'research_data',
			),
			'wp_mcp_ai_create_quiz_from_research'               => array(
				'class'    => 'WP_MCP_AI_Quiz_Research_Page',
				'file'     => $page( 'quiz' ),
				'nonce'    => 'wp_mcp_ai_research_quiz',
				'cap'      => 'edit_posts',
				'data_key' => 'research_data',
			),
			'wp_mcp_ai_create_registration_from_research'       => array(
				'class'    => 'WP_MCP_AI_Registration_Research_Page',
				'file'     => $page( 'registration' ),
				'nonce'    => 'wp_mcp_ai_research_registration',
				'cap'      => 'edit_posts',
				'data_key' => 'research_data',
			),
			'wp_mcp_ai_create_schedule_from_research'           => array(
				'class'    => 'WP_MCP_AI_Pro_Schedule_Research_Page',
				'file'     => $page( 'pro-schedule' ),
				'nonce'    => 'wp_mcp_ai_research_pro_schedule',
				'cap'      => 'manage_options',
				'data_key' => 'research_data',
				'role'     => 'admin',
			),
			'wp_mcp_ai_create_financial_account_from_research'  => array(
				'class'    => 'WP_MCP_AI_Financial_Account_Research_Page',
				'file'     => $page( 'financial-account' ),
				'nonce'    => 'wp_mcp_ai_research_financial_account',
				'cap'      => 'edit_posts',
				'data_key' => 'research_data',
			),
			// Unloadable today: the addon page requires two trait files that do
			// not exist in addons/fantasy-football/includes/admin/ (it fatals on
			// any admin request with the addon active). Left unrequired so the
			// three ff_team cases skip on the class gate instead of erroring.
			'wp_mcp_ai_create_ff_team_from_research'            => array(
				'class'    => 'WP_MCP_AI_Fantasy_Football_Research_Page',
				'file'     => '',
				'nonce'    => 'wp_mcp_ai_research_ff_team',
				'cap'      => 'edit_posts',
				'data_key' => 'research_data',
			),
			'wp_mcp_ai_create_image_template_from_research'     => array(
				'class'    => 'WP_MCP_AI_Image_Template_Research_Page',
				'file'     => $page( 'image-template' ),
				'nonce'    => 'wp_mcp_ai_research_image_template',
				'cap'      => 'edit_posts',
				'data_key' => 'research_data',
			),
			'wp_mcp_ai_create_document_template_from_research'  => array(
				'class'    => 'WP_MCP_AI_Document_Template_Research_Page',
				'file'     => $page( 'document-template' ),
				'nonce'    => 'wp_mcp_ai_research_document_template',
				'cap'      => 'edit_posts',
				'data_key' => 'research_data',
			),
			'wp_mcp_ai_create_lf_client_from_research'          => array(
				'class'    => 'WP_MCP_AI_Law_Firm_Research_Page',
				'file'     => $page( 'law-firm' ),
				'nonce'    => 'wp_mcp_ai_lf_research',
				'cap'      => 'edit_posts',
				'data_key' => 'research_data',
			),
			'wp_mcp_ai_create_lf_matter_from_research'          => array(
				'class'    => 'WP_MCP_AI_Law_Firm_Research_Page',
				'file'     => $page( 'law-firm' ),
				'nonce'    => 'wp_mcp_ai_lf_research',
				'cap'      => 'edit_posts',
				'data_key' => 'research_data',
			),
			'wp_mcp_ai_create_arch_drawing_from_research'       => array(
				'class'    => 'WP_MCP_AI_Architectural_Drawing_Research_Page',
				'file'     => $page( 'architectural-drawing' ),
				'nonce'    => 'wp_mcp_ai_research_arch_drawing',
				'cap'      => 'edit_posts',
				'data_key' => 'research_data',
			),
			'wp_mcp_ai_create_arch_project_from_research'       => array(
				'class'    => 'WP_MCP_AI_Architectural_Project_Research_Page',
				'file'     => $page( 'architectural-project' ),
				'nonce'    => 'wp_mcp_ai_research_arch_project',
				'cap'      => 'edit_posts',
				'data_key' => 'research_data',
			),
			'wp_mcp_ai_create_arch_spec_from_research'          => array(
				'class'    => 'WP_MCP_AI_Architectural_Specification_Research_Page',
				'file'     => $page( 'architectural-specification' ),
				'nonce'    => 'wp_mcp_ai_research_arch_spec',
				'cap'      => 'edit_posts',
				'data_key' => 'research_data',
			),
			'wp_mcp_ai_create_media_from_design'                => array(
				'class'    => 'WP_MCP_AI_Media_Design_Page',
				'file'     => $pro . 'includes/admin/class-wp-mcp-ai-media-design-page.php',
				'nonce'    => 'wp_mcp_ai_design_media',
				'cap'      => 'upload_files',
				'data_key' => 'design_data',
			),
			'wp_mcp_ai_preview_schedule_from_research'          => array(
				'class'    => 'WP_MCP_AI_Pro_Schedule_Research_Page',
				'file'     => $page( 'pro-schedule' ),
				'nonce'    => 'wp_mcp_ai_research_pro_schedule',
				'cap'      => 'manage_options',
				'data_key' => 'schedule_data',
				'role'     => 'admin',
			),
			'wp_mcp_ai_create_appointment_from_research'        => array(
				'class'    => 'WP_MCP_AI_Calendar_Booking_Research_Page',
				'file'     => $page( 'calendar-booking' ),
				'nonce'    => 'wp_mcp_ai_research_appointment',
				'cap'      => 'edit_posts',
				'data_key' => 'research_data',
			),
		);
	}

	/**
	 * Bind each create-from-research action for the duration of the test.
	 *
	 * The handlers are registered from each research page's static `init()`,
	 * which production calls from the toolkit loader under is_admin() — never
	 * in the CLI runner. Unbound, every dispatch ends with an empty body, which
	 * reads exactly like a rejection and lets these tests pass without reaching
	 * a handler. Load the page class and call `init()` (guarded by
	 * `has_action`, so pages another suite already initialised are left alone).
	 */
	public function setUp(): void {
		parent::setUp();

		foreach ( self::handler_map() as $action => $cfg ) {
			if ( false !== has_action( 'wp_ajax_' . $action ) ) {
				continue;
			}

			if ( ! class_exists( $cfg['class'] ) && ! empty( $cfg['file'] ) && file_exists( $cfg['file'] ) ) {
				require_once $cfg['file'];
			}

			if ( class_exists( $cfg['class'] ) && is_callable( array( $cfg['class'], 'init' ) ) ) {
				call_user_func( array( $cfg['class'], 'init' ) );
			}
		}
	}

	/**
	 * Prove a rejection came from the handler, not from the nonce gate.
	 *
	 * A failed `check_ajax_referer()` dies before the handler body runs and
	 * leaves an empty body, which `assertAjaxError()` accepts. Every handler in
	 * this cluster answers its capability and validation branches with
	 * `wp_send_json_error( array( 'message' => … ) )`, so a non-empty message is
	 * the evidence that the request reached the branch under test.
	 *
	 * @param array  $response Decoded response from {@see self::dispatch()}.
	 * @param string $action   Action name, for the failure message.
	 */
	private function assertHandlerAnswered( array $response, string $action ): void {
		$data    = isset( $response['data'] ) ? $response['data'] : null;
		$message = is_array( $data ) && isset( $data['message'] ) ? $data['message'] : $data;

		$this->assertIsString( $message, "{$action}: expected a handler message, got an empty body (nonce gate rejected the dispatch)." );
		$this->assertNotSame( '', trim( $message ), "{$action}: expected a handler message, got an empty body (nonce gate rejected the dispatch)." );
	}

	// ---
	// Generic 3-point tests driven by self::handler_map()
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
		$this->assertHandlerAnswered( $response, $action );
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

		if ( 'admin' === ( isset( $cfg['role'] ) ? $cfg['role'] : 'editor' ) ) {
			$this->as_admin();
		} else {
			$this->as_editor();
		}

		$response = $this->dispatch(
			$action,
			array( 'nonce' => wp_create_nonce( $cfg['nonce'] ) )
			// data_key intentionally omitted → handler must reject.
		);
		$this->assertAjaxError( $response, "Expected validation failure (empty data) for {$action}" );
		$this->assertHandlerAnswered( $response, $action );
	}

	/**
	 * Data provider: yields [ action_name, config_array ] pairs.
	 *
	 * @return array<string, array{string, array}>
	 */
	public static function provideHandlers(): array {
		$out = array();
		foreach ( self::handler_map() as $action => $cfg ) {
			$out[ $action ] = array( $action, $cfg );
		}
		return $out;
	}
}
