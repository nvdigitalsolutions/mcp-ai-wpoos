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
 *  - wp_mcp_ai_import_ff_team   (fantasy-football addon)
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
// Load the addon admin class the config cases gate on; the fantasy-football
// addon is not booted by the base test bootstrap, so require it here to keep
// the suite runnable standalone (mirrors CI, where earlier tests load it).
$wp_mcp_ai_ff_research_page = WP_MCP_AI_PATH . '../addons/fantasy-football/includes/admin/class-wp-mcp-ai-fantasy-football-research-page.php';
if ( file_exists( $wp_mcp_ai_ff_research_page ) ) {
	require_once $wp_mcp_ai_ff_research_page;
}
unset( $wp_mcp_ai_ff_research_page );

class Test_Import_Handlers_AJAX extends WP_MCP_AI_Ajax_TestCase {

	/**
	 * Map of AJAX action → config.
	 *
	 * @var array<string,array{nonce:string,cap:string,class?:string}>
	 */
	private static array $handlers = array();

	/** Sets up shared state before any test in the class. */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		self::$handlers = array(
			'wp_mcp_ai_import_post'              => array(
				'nonce' => 'wp_mcp_ai_research_post',
				'cap'   => 'edit_posts',
			),
			'wp_mcp_ai_import_product'           => array(
				'nonce' => 'wp_mcp_ai_research_product',
				'cap'   => 'edit_posts',
			),
			'wp_mcp_ai_import_project'           => array(
				'nonce' => 'wp_mcp_ai_research_project',
				'cap'   => 'edit_posts',
			),
			'wp_mcp_ai_import_task'              => array(
				'nonce' => 'wp_mcp_ai_import_data',
				'cap'   => 'edit_posts',
			),
			'wp_mcp_ai_import_event'             => array(
				'nonce' => 'wp_mcp_ai_research_event',
				'cap'   => 'edit_posts',
			),
			'wp_mcp_ai_import_page'              => array(
				'nonce' => 'wp_mcp_ai_research_page',
				'cap'   => 'edit_posts',
			),
			'wp_mcp_ai_import_place'             => array(
				'nonce' => 'wp_mcp_ai_research_place',
				'cap'   => 'edit_posts',
			),
			'wp_mcp_ai_import_policy'            => array(
				'nonce' => 'wp_mcp_ai_research_policy',
				'cap'   => 'edit_posts',
			),
			'wp_mcp_ai_import_quiz'              => array(
				'nonce' => 'wp_mcp_ai_research_quiz',
				'cap'   => 'edit_posts',
			),
			'wp_mcp_ai_import_registration'      => array(
				'nonce' => 'wp_mcp_ai_research_registration',
				'cap'   => 'edit_posts',
			),
			'wp_mcp_ai_import_financial_account' => array(
				'nonce' => 'wp_mcp_ai_research_financial_account',
				'cap'   => 'edit_posts',
			),
			'wp_mcp_ai_import_ff_team'           => array(
				'nonce' => 'wp_mcp_ai_research_ff_team',
				'cap'   => 'edit_posts',
				'class' => 'WP_MCP_AI_Fantasy_Football_Research_Page',
			),
			'wp_mcp_ai_import_image_template'    => array(
				'nonce' => 'wp_mcp_ai_research_image_template',
				'cap'   => 'edit_posts',
			),
			'wp_mcp_ai_import_document_template' => array(
				'nonce' => 'wp_mcp_ai_research_document_template',
				'cap'   => 'edit_posts',
			),
			'wp_mcp_ai_import_arch_drawing'      => array(
				'nonce' => 'wp_mcp_ai_research_arch_drawing',
				'cap'   => 'edit_posts',
			),
			'wp_mcp_ai_import_arch_project'      => array(
				'nonce' => 'wp_mcp_ai_research_arch_project',
				'cap'   => 'edit_posts',
			),
			'wp_mcp_ai_import_arch_spec'         => array(
				'nonce' => 'wp_mcp_ai_research_arch_spec',
				'cap'   => 'edit_posts',
			),
			'wp_mcp_ai_import_appointment'       => array(
				'nonce' => 'wp_mcp_ai_research_appointment',
				'cap'   => 'edit_posts',
			),
		);
	}

	// ---
	// Data provider
	// ---

	/**
	 * Data provider.
	 *
	 * @return array<string, array{string, array}>
	 */
	public static function provideImportHandlers(): array {
		$raw = array(
			array(
				'wp_mcp_ai_import_post',
				array(
					'nonce' => 'wp_mcp_ai_research_post',
					'cap'   => 'edit_posts',
				),
			),
			array(
				'wp_mcp_ai_import_product',
				array(
					'nonce' => 'wp_mcp_ai_research_product',
					'cap'   => 'edit_posts',
				),
			),
			array(
				'wp_mcp_ai_import_project',
				array(
					'nonce' => 'wp_mcp_ai_research_project',
					'cap'   => 'edit_posts',
				),
			),
			array(
				'wp_mcp_ai_import_task',
				array(
					'nonce' => 'wp_mcp_ai_import_data',
					'cap'   => 'edit_posts',
				),
			),
			array(
				'wp_mcp_ai_import_event',
				array(
					'nonce' => 'wp_mcp_ai_research_event',
					'cap'   => 'edit_posts',
				),
			),
			array(
				'wp_mcp_ai_import_page',
				array(
					'nonce' => 'wp_mcp_ai_research_page',
					'cap'   => 'edit_posts',
				),
			),
			array(
				'wp_mcp_ai_import_place',
				array(
					'nonce' => 'wp_mcp_ai_research_place',
					'cap'   => 'edit_posts',
				),
			),
			array(
				'wp_mcp_ai_import_policy',
				array(
					'nonce' => 'wp_mcp_ai_research_policy',
					'cap'   => 'edit_posts',
				),
			),
			array(
				'wp_mcp_ai_import_quiz',
				array(
					'nonce' => 'wp_mcp_ai_research_quiz',
					'cap'   => 'edit_posts',
				),
			),
			array(
				'wp_mcp_ai_import_registration',
				array(
					'nonce' => 'wp_mcp_ai_research_registration',
					'cap'   => 'edit_posts',
				),
			),
			array(
				'wp_mcp_ai_import_financial_account',
				array(
					'nonce' => 'wp_mcp_ai_research_financial_account',
					'cap'   => 'edit_posts',
				),
			),
			array(
				'wp_mcp_ai_import_ff_team',
				array(
					'nonce' => 'wp_mcp_ai_research_ff_team',
					'cap'   => 'edit_posts',
					'class' => 'WP_MCP_AI_Fantasy_Football_Research_Page',
				),
			),
			array(
				'wp_mcp_ai_import_image_template',
				array(
					'nonce' => 'wp_mcp_ai_research_image_template',
					'cap'   => 'edit_posts',
				),
			),
			array(
				'wp_mcp_ai_import_document_template',
				array(
					'nonce' => 'wp_mcp_ai_research_document_template',
					'cap'   => 'edit_posts',
				),
			),
			array(
				'wp_mcp_ai_import_arch_drawing',
				array(
					'nonce' => 'wp_mcp_ai_research_arch_drawing',
					'cap'   => 'edit_posts',
				),
			),
			array(
				'wp_mcp_ai_import_arch_project',
				array(
					'nonce' => 'wp_mcp_ai_research_arch_project',
					'cap'   => 'edit_posts',
				),
			),
			array(
				'wp_mcp_ai_import_arch_spec',
				array(
					'nonce' => 'wp_mcp_ai_research_arch_spec',
					'cap'   => 'edit_posts',
				),
			),
			array(
				'wp_mcp_ai_import_appointment',
				array(
					'nonce' => 'wp_mcp_ai_research_appointment',
					'cap'   => 'edit_posts',
				),
			),
		);

		$out = array();
		foreach ( $raw as $row ) {
			$out[ $row[0] ] = $row;
		}
		return $out;
	}

	// ---
	// 3-point data-driven tests (cap, nonce, input-validation)
	// Happy path is skipped here because handlers call into JetEngine / WP
	// post-creation APIs that require a fully bootstrapped environment.
	// ---

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
				'format'      => 'json',
			)
		);
		$this->assertAjaxError( $response, "Expected permission failure for {$action}" );
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
	}
}
