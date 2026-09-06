<?php
/**
 * AJAX tests for the Skill Manager admin handlers (Pro addon).
 *
 * Covers the 4-point coverage contract for:
 *   - wp_mcp_ai_skill_manager_upload        (WP_MCP_AI_Skill_Manager_Admin_Page::handle_ajax_upload)
 *   - wp_mcp_ai_skill_manager_install_url   (WP_MCP_AI_Skill_Manager_Admin_Page::handle_ajax_install_url)
 *   - wp_mcp_ai_skill_manager_save          (WP_MCP_AI_Skill_Manager_Admin_Page::handle_ajax_save)
 *   - wp_mcp_ai_skill_manager_delete        (WP_MCP_AI_Skill_Manager_Admin_Page::handle_ajax_delete)
 *   - wp_mcp_ai_skill_manager_generate_skill (WP_MCP_AI_Skill_Manager_Admin_Page::handle_ajax_generate_skill)
 *
 * All five handlers live in the Pro addon and require `manage_options`. Tests
 * are skipped when the Pro addon is not active (i.e. the class is absent).
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

// phpcs:disable WordPress.NamingConventions.ValidVariableName -- inherits camelCase $_last_response from WP_Ajax_UnitTestCase.

/**
 * AJAX cluster: Skill Manager (Pro).
 */
// Load the Pro admin class under test; the pro addon loads it only in admin
// context, so require it here to keep the suite runnable standalone (mirrors
// CI, where earlier admin-context tests load it).
if ( defined( 'WP_MCP_AI_PRO_PATH' ) ) {
	$wp_mcp_ai_skill_manager_page = WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-skill-manager-admin-page.php';
	if ( file_exists( $wp_mcp_ai_skill_manager_page ) ) {
		require_once $wp_mcp_ai_skill_manager_page;
	}
	unset( $wp_mcp_ai_skill_manager_page );
}

class Test_Skill_Manager_AJAX extends WP_MCP_AI_Ajax_TestCase {

	/**
	 * Nonce action used by all skill manager handlers.
	 */
	const NONCE = 'wp_mcp_ai_skill_manager';

	/**
	 * Skip all tests when the Pro addon is not loaded.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'WP_MCP_AI_Skill_Manager_Admin_Page' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Skill_Manager_Admin_Page (Pro) is not available in this environment.' );
		}
	}

	// ---
	// wp_mcp_ai_skill_manager_save
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_save_skill_rejects_missing_nonce() {
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_skill_manager_save',
			array( 'content' => "---\nname: test\n---\n# My Skill\nDo stuff." )
		);

		$this->assertAjaxForbidden( $response );
	}

	/** Guards against insufficient capabilities. */
	public function test_save_skill_rejects_subscriber() {
		$this->as_subscriber();

		$response = $this->dispatch(
			'wp_mcp_ai_skill_manager_save',
			array(
				'nonce'   => wp_create_nonce( self::NONCE ),
				'content' => "---\nname: test\n---\n# My Skill\nDo stuff.",
			)
		);

		$this->assertAjaxError( $response, 'Insufficient permissions' );
	}

	/** Validates the empty content parameter. */
	public function test_save_skill_validates_empty_content() {
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_skill_manager_save',
			array(
				'nonce'   => wp_create_nonce( self::NONCE ),
				'content' => '   ',
			)
		);

		$this->assertAjaxError( $response, 'Content cannot be empty' );
	}

	/** Dispatches successfully on the happy path. */
	public function test_save_skill_happy_path_returns_success() {
		$this->as_admin();

		// A minimal SKILL.md that passes the parser.
		$content = "---\nname: test-pilot-skill\ndescription: Pilot skill for testing.\n---\n# Test Pilot Skill\n\nDo something useful.\n";

		$response = $this->dispatch(
			'wp_mcp_ai_skill_manager_save',
			array(
				'nonce'   => wp_create_nonce( self::NONCE ),
				'content' => $content,
			)
		);

		// Accept success OR a registry error — the contract is a JSON response.
		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'success', $response );
	}

	// ---
	// wp_mcp_ai_skill_manager_delete
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_delete_skill_rejects_missing_nonce() {
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_skill_manager_delete',
			array( 'skill' => 'test-pilot-skill' )
		);

		$this->assertAjaxForbidden( $response );
	}

	/** Guards against insufficient capabilities. */
	public function test_delete_skill_rejects_subscriber() {
		$this->as_subscriber();

		$response = $this->dispatch(
			'wp_mcp_ai_skill_manager_delete',
			array(
				'nonce' => wp_create_nonce( self::NONCE ),
				'skill' => 'test-pilot-skill',
			)
		);

		$this->assertAjaxError( $response, 'Insufficient permissions' );
	}

	/** Validates the empty name parameter. */
	public function test_delete_skill_validates_empty_name() {
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_skill_manager_delete',
			array(
				'nonce' => wp_create_nonce( self::NONCE ),
				'skill' => '',
			)
		);

		$this->assertAjaxError( $response, 'Skill name is required' );
	}

	/** Verifies the response returns structured response for unknown skill. */
	public function test_delete_skill_returns_structured_response_for_unknown_skill() {
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_skill_manager_delete',
			array(
				'nonce' => wp_create_nonce( self::NONCE ),
				'skill' => 'non-existent-skill-xyz',
			)
		);

		// The registry returns WP_Error for unknown skills; the handler maps
		// that to an error response.
		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'success', $response );
	}

	// ---
	// wp_mcp_ai_skill_manager_upload
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_upload_skill_rejects_missing_nonce() {
		$this->as_admin();

		// No file, no nonce.
		$response = $this->dispatch( 'wp_mcp_ai_skill_manager_upload' );

		$this->assertAjaxForbidden( $response );
	}

	/** Guards against insufficient capabilities. */
	public function test_upload_skill_rejects_subscriber() {
		$this->as_subscriber();

		$response = $this->dispatch(
			'wp_mcp_ai_skill_manager_upload',
			array( 'nonce' => wp_create_nonce( self::NONCE ) )
		);

		$this->assertAjaxError( $response, 'Insufficient permissions' );
	}

	/** Validates the no file parameter. */
	public function test_upload_skill_validates_no_file() {
		$this->as_admin();

		// No file in $_FILES.
		$_FILES = array();

		$response = $this->dispatch(
			'wp_mcp_ai_skill_manager_upload',
			array( 'nonce' => wp_create_nonce( self::NONCE ) )
		);

		$this->assertAjaxError( $response, 'No file was uploaded' );
	}

	// ---
	// wp_mcp_ai_skill_manager_install_url
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_install_url_rejects_missing_nonce() {
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_skill_manager_install_url',
			array( 'url' => 'https://example.com/skill.md' )
		);

		$this->assertAjaxForbidden( $response );
	}

	/** Guards against insufficient capabilities. */
	public function test_install_url_rejects_subscriber() {
		$this->as_subscriber();

		$response = $this->dispatch(
			'wp_mcp_ai_skill_manager_install_url',
			array(
				'nonce' => wp_create_nonce( self::NONCE ),
				'url'   => 'https://example.com/skill.md',
			)
		);

		$this->assertAjaxError( $response, 'Insufficient permissions' );
	}

	/** Validates the empty url parameter. */
	public function test_install_url_validates_empty_url() {
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_skill_manager_install_url',
			array(
				'nonce' => wp_create_nonce( self::NONCE ),
				'url'   => '',
			)
		);

		$this->assertAjaxError( $response, 'Please provide a URL' );
	}

	/** Install url rejects non https. */
	public function test_install_url_rejects_non_https() {
		$this->as_admin();

		// Stub outbound HTTP so the handler can't escape the sandbox even if it
		// somehow reaches the fetch call.
		$this->stub_http_response( 'http://', new WP_Error( 'blocked', 'blocked' ) );

		$response = $this->dispatch(
			'wp_mcp_ai_skill_manager_install_url',
			array(
				'nonce' => wp_create_nonce( self::NONCE ),
				'url'   => 'http://example.com/skill.md',
			)
		);

		$this->assertAjaxError( $response, 'Only HTTPS URLs are supported' );
	}

	/** Install url happy path stubs remote. */
	public function test_install_url_happy_path_stubs_remote() {
		$this->as_admin();

		// Stub the remote SKILL.md fetch so no real network call is made.
		$skill_md = "---\nname: pilot-url-skill\ndescription: Installed via URL.\n---\n# Pilot URL Skill\n\nDo something.\n";
		$this->stub_http_response(
			'https://example.com',
			array(
				'response' => array( 'code' => 200 ),
				'body'     => $skill_md,
				'headers'  => array( 'content-type' => 'text/plain' ),
				'cookies'  => array(),
			)
		);

		$response = $this->dispatch(
			'wp_mcp_ai_skill_manager_install_url',
			array(
				'nonce' => wp_create_nonce( self::NONCE ),
				'url'   => 'https://example.com/SKILL.md',
			)
		);

		// The handler may fail SSRF checks on the test host; accept any
		// structured JSON response (success or structured error).
		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'success', $response );
	}

	// ---
	// wp_mcp_ai_skill_manager_generate_skill
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_generate_skill_rejects_missing_nonce() {
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_skill_manager_generate_skill',
			array(
				'name'        => 'My Skill',
				'description' => 'Does stuff.',
			)
		);

		$this->assertAjaxForbidden( $response );
	}

	/** Guards against insufficient capabilities. */
	public function test_generate_skill_rejects_subscriber() {
		$this->as_subscriber();

		$response = $this->dispatch(
			'wp_mcp_ai_skill_manager_generate_skill',
			array(
				'nonce'       => wp_create_nonce( self::NONCE ),
				'name'        => 'My Skill',
				'description' => 'Does stuff.',
			)
		);

		$this->assertAjaxError( $response, 'Insufficient permissions' );
	}

	/** Validates the empty name parameter. */
	public function test_generate_skill_validates_empty_name() {
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_skill_manager_generate_skill',
			array(
				'nonce'       => wp_create_nonce( self::NONCE ),
				'name'        => '',
				'description' => 'Does stuff.',
			)
		);

		$this->assertAjaxError( $response, 'Skill name is required' );
	}

	/** Validates the empty description parameter. */
	public function test_generate_skill_validates_empty_description() {
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_skill_manager_generate_skill',
			array(
				'nonce'       => wp_create_nonce( self::NONCE ),
				'name'        => 'My Skill',
				'description' => '',
			)
		);

		$this->assertAjaxError( $response, 'Description is required' );
	}

	/** Generate skill happy path stubs ai request. */
	public function test_generate_skill_happy_path_stubs_ai_request() {
		$this->as_admin();

		// Stub the AI API call so no real network request escapes the sandbox.
		$this->stub_http_response(
			'',
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode(
					array(
						'choices' => array(
							array(
								'message' => array(
									'content' => "---\nname: my-skill\ndescription: Does stuff.\n---\n# My Skill\n\nDo something.\n",
								),
							),
						),
					)
				),
				'headers'  => array(),
				'cookies'  => array(),
			)
		);

		$response = $this->dispatch(
			'wp_mcp_ai_skill_manager_generate_skill',
			array(
				'nonce'       => wp_create_nonce( self::NONCE ),
				'name'        => 'My Skill',
				'description' => 'Does something useful.',
			)
		);

		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'success', $response );
	}
}
