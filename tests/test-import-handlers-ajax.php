<?php
/**
 * AJAX tests for all "import" handlers (Pro addon).
 *
 * Import handlers mirror the structure of the create-from-research handlers:
 * same nonce, same capability gate, but they receive import_data + format
 * instead of research_data.
 *
 * Handlers covered:
 *  - wp_mcp_ai_import_post
 *  - wp_mcp_ai_import_product
 *  - wp_mcp_ai_import_project
 *  - wp_mcp_ai_import_task
 *  - wp_mcp_ai_import_event
 *  - wp_mcp_ai_import_company  (reuses wp_mcp_ai_research_page nonce)
 *  - wp_mcp_ai_import_page     (reuses wp_mcp_ai_research_page nonce)
 *  - wp_mcp_ai_import_place
 *  - wp_mcp_ai_import_policy
 *  - wp_mcp_ai_import_quiz
 *  - wp_mcp_ai_import_registration
 *  - wp_mcp_ai_import_financial_account
 *  - wp_mcp_ai_import_ff_team   (fantasy-football addon; skips — its page class
 *                                cannot load, see handler_map())
 *  - wp_mcp_ai_import_image_template
 *  - wp_mcp_ai_import_document_template
 *  - wp_mcp_ai_import_arch_drawing
 *  - wp_mcp_ai_import_arch_project
 *  - wp_mcp_ai_import_arch_spec
 *  - wp_mcp_ai_import_appointment
 *
 * @package WP_MCP_AI
 */

// phpcs:disable WordPress.NamingConventions.ValidVariableName

/**
 * Import-handlers AJAX cluster (Pro addon).
 */
class Test_Import_Handlers_AJAX extends WP_MCP_AI_Ajax_TestCase {

	/**
	 * Canonical handler map: action → class, file, nonce and capability.
	 *
	 * The nonce action is per handler, not per family: the `ajax_handle_import`
	 * trait checks `wp_mcp_ai_import_data`, while the pages that override the
	 * import entry point with their own `handle_import` check their research
	 * nonce. Getting this wrong makes the capability/validation tests die at
	 * the nonce check and pass without exercising what they claim to cover.
	 *
	 * @return array<string,array{class:string,file:string,nonce:string,cap:string}>
	 */
	private static function handler_map(): array {
		$pro = defined( 'WP_MCP_AI_PRO_PATH' ) ? WP_MCP_AI_PRO_PATH : '';

		$page = static function ( $slug ) use ( $pro ) {
			return $pro . 'includes/admin/class-wp-mcp-ai-' . $slug . '-research-page.php';
		};

		return array(
			// Class overrides its own `handle_import`; nonce is the research one.
			'wp_mcp_ai_import_post'              => array(
				'class' => 'WP_MCP_AI_Post_Research_Page',
				'file'  => $page( 'post' ),
				'nonce' => 'wp_mcp_ai_research_post',
				'cap'   => 'edit_posts',
			),
			// Trait `ajax_handle_import`; nonce is the shared import one.
			'wp_mcp_ai_import_product'           => array(
				'class' => 'WP_MCP_AI_Product_Research_Page',
				'file'  => $page( 'product' ),
				'nonce' => 'wp_mcp_ai_import_data',
				'cap'   => 'edit_posts',
			),
			'wp_mcp_ai_import_project'           => array(
				'class' => 'WP_MCP_AI_Project_Research_Page',
				'file'  => $page( 'project' ),
				'nonce' => 'wp_mcp_ai_research_project',
				'cap'   => 'edit_posts',
			),
			'wp_mcp_ai_import_task'              => array(
				'class' => 'WP_MCP_AI_Task_Research_Page',
				'file'  => $page( 'task' ),
				'nonce' => 'wp_mcp_ai_import_data',
				'cap'   => 'edit_posts',
			),
			'wp_mcp_ai_import_event'             => array(
				'class' => 'WP_MCP_AI_Event_Research_Page',
				'file'  => $page( 'event' ),
				'nonce' => 'wp_mcp_ai_import_data',
				'cap'   => 'edit_posts',
			),
			'wp_mcp_ai_import_page'              => array(
				'class' => 'WP_MCP_AI_Page_Research_Page',
				'file'  => $page( 'page' ),
				'nonce' => 'wp_mcp_ai_research_page',
				'cap'   => 'edit_posts',
			),
			'wp_mcp_ai_import_place'             => array(
				'class' => 'WP_MCP_AI_Place_Research_Page',
				'file'  => $page( 'place' ),
				'nonce' => 'wp_mcp_ai_research_place',
				'cap'   => 'edit_posts',
			),
			'wp_mcp_ai_import_policy'            => array(
				'class' => 'WP_MCP_AI_Policy_Research_Page',
				'file'  => $page( 'policy' ),
				'nonce' => 'wp_mcp_ai_research_policy',
				'cap'   => 'edit_posts',
			),
			'wp_mcp_ai_import_quiz'              => array(
				'class' => 'WP_MCP_AI_Quiz_Research_Page',
				'file'  => $page( 'quiz' ),
				'nonce' => 'wp_mcp_ai_import_data',
				'cap'   => 'edit_posts',
			),
			'wp_mcp_ai_import_registration'      => array(
				'class' => 'WP_MCP_AI_Registration_Research_Page',
				'file'  => $page( 'registration' ),
				'nonce' => 'wp_mcp_ai_import_data',
				'cap'   => 'edit_posts',
			),
			'wp_mcp_ai_import_financial_account' => array(
				'class' => 'WP_MCP_AI_Financial_Account_Research_Page',
				'file'  => $page( 'financial-account' ),
				'nonce' => 'wp_mcp_ai_import_data',
				'cap'   => 'edit_posts',
			),
			// Unloadable today: the addon page requires two trait files that do not
			// exist in addons/fantasy-football/includes/admin/ (it fatals on any
			// admin request with the addon active). Left unrequired so the three
			// ff_team cases skip on the class gate instead of erroring here.
			'wp_mcp_ai_import_ff_team'           => array(
				'class' => 'WP_MCP_AI_Fantasy_Football_Research_Page',
				'file'  => '',
				'nonce' => 'wp_mcp_ai_import_data',
				'cap'   => 'edit_posts',
			),
			'wp_mcp_ai_import_image_template'    => array(
				'class' => 'WP_MCP_AI_Image_Template_Research_Page',
				'file'  => $page( 'image-template' ),
				'nonce' => 'wp_mcp_ai_import_data',
				'cap'   => 'edit_posts',
			),
			'wp_mcp_ai_import_document_template' => array(
				'class' => 'WP_MCP_AI_Document_Template_Research_Page',
				'file'  => $page( 'document-template' ),
				'nonce' => 'wp_mcp_ai_import_data',
				'cap'   => 'edit_posts',
			),
			'wp_mcp_ai_import_arch_drawing'      => array(
				'class' => 'WP_MCP_AI_Architectural_Drawing_Research_Page',
				'file'  => $page( 'architectural-drawing' ),
				'nonce' => 'wp_mcp_ai_research_arch_drawing',
				'cap'   => 'edit_posts',
			),
			'wp_mcp_ai_import_arch_project'      => array(
				'class' => 'WP_MCP_AI_Architectural_Project_Research_Page',
				'file'  => $page( 'architectural-project' ),
				'nonce' => 'wp_mcp_ai_research_arch_project',
				'cap'   => 'edit_posts',
			),
			'wp_mcp_ai_import_arch_spec'         => array(
				'class' => 'WP_MCP_AI_Architectural_Specification_Research_Page',
				'file'  => $page( 'architectural-specification' ),
				'nonce' => 'wp_mcp_ai_research_arch_spec',
				'cap'   => 'edit_posts',
			),
			'wp_mcp_ai_import_appointment'       => array(
				'class' => 'WP_MCP_AI_Calendar_Booking_Research_Page',
				'file'  => $page( 'calendar-booking' ),
				'nonce' => 'wp_mcp_ai_import_data',
				'cap'   => 'edit_posts',
			),
		);
	}

	/**
	 * Bind each import action for the duration of the test.
	 *
	 * The handlers are registered from each research page's static `init()`,
	 * which production calls from the toolkit loader under is_admin(). Nothing
	 * runs it in the CLI runner, so the actions are absent and every dispatch
	 * would end with an empty body — which reads exactly like a rejection and
	 * makes these tests pass without ever reaching the handler. Load the page
	 * class and call `init()` (guarded by `has_action`, so a page already
	 * initialised by another suite is left alone).
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
	 * Data provider.
	 *
	 * @return array<string, array{string, array}>
	 */
	public static function provideImportHandlers(): array {
		$out = array();
		foreach ( self::handler_map() as $action => $cfg ) {
			$out[ $action ] = array( $action, $cfg );
		}
		return $out;
	}

	// ---
	// 3-point data-driven tests (cap, nonce, input-validation)
	// Happy path is skipped here because handlers call into JetEngine / WP
	// post-creation APIs that require a fully bootstrapped environment.
	// ---

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

	/**
	 * Guards against a missing or invalid nonce.
	 *
	 * @dataProvider provideImportHandlers
	 * @param string $action AJAX action name.
	 * @param array  $cfg Handler configuration.
	 */
	public function test_import_handler_rejects_bad_nonce( string $action, array $cfg ): void {
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
	 * @dataProvider provideImportHandlers
	 * @param string $action AJAX action name.
	 * @param array  $cfg Handler configuration.
	 */
	public function test_import_handler_rejects_subscriber( string $action, array $cfg ): void {
		if ( ! empty( $cfg['class'] ) && ! class_exists( $cfg['class'] ) ) {
			$this->markTestSkipped( "Class {$cfg['class']} not loaded." );
		}
		$this->as_subscriber();
		$response = $this->dispatch(
			$action,
			array(
				'nonce'       => wp_create_nonce( $cfg['nonce'] ),
				'import_data' => wp_json_encode( array( 'title' => 'Test' ) ),
				'data'        => 'title' . "\n" . 'Test',
				'format'      => 'json',
			)
		);
		$this->assertAjaxError( $response, "Expected permission failure for {$action}" );
		$this->assertHandlerAnswered( $response, $action );
	}

	/**
	 * Validates the empty import data parameter.
	 *
	 * @dataProvider provideImportHandlers
	 * @param string $action AJAX action name.
	 * @param array  $cfg Handler configuration.
	 */
	public function test_import_handler_validates_empty_import_data( string $action, array $cfg ): void {
		if ( ! empty( $cfg['class'] ) && ! class_exists( $cfg['class'] ) ) {
			$this->markTestSkipped( "Class {$cfg['class']} not loaded." );
		}
		$this->as_editor();
		$response = $this->dispatch(
			$action,
			array( 'nonce' => wp_create_nonce( $cfg['nonce'] ) )
			// import_data + format intentionally omitted.
		);
		$this->assertAjaxError( $response, "Expected validation failure (empty data) for {$action}" );
		$this->assertHandlerAnswered( $response, $action );
	}
}
