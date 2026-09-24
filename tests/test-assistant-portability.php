<?php
/**
 * Tests for the assistant portability engine.
 *
 * Covers the canonical nvoos-assistant bundle format: export fidelity
 * (including array-typed meta), credential redaction (assistant credential
 * hashes and MCP App tokens), legacy CLI + blueprint import compatibility,
 * import modes (skip/overwrite/duplicate), dry-run, and defence-in-depth
 * stripping of credential material on import.
 *
 * @package WP_MCP_AI
 * @since   1.1.80
 */

/**
 * Assistant portability engine tests.
 *
 * @since 1.1.80
 */
class Test_Assistant_Portability extends WP_UnitTestCase {

	/**
	 * Admin user ID for capability checks.
	 *
	 * @var int
	 */
	protected $admin_id;

	/**
	 * Set up fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'WP_MCP_AI_Assistant_CPT' ) ) {
			$cpt_file = WP_MCP_AI_PATH . 'includes/assistants/class-wp-mcp-ai-assistant-cpt.php';
			if ( file_exists( $cpt_file ) ) {
				require_once $cpt_file;
			}
		}

		if ( ! class_exists( 'WP_MCP_AI_Assistant_Portability' ) ) {
			$engine_file = WP_MCP_AI_PATH . 'includes/assistants/class-wp-mcp-ai-assistant-portability.php';
			if ( file_exists( $engine_file ) ) {
				require_once $engine_file;
			}
		}

		$this->admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );
	}

	/**
	 * Reset the current user.
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Create an assistant fixture with a representative meta surface.
	 *
	 * @return int Assistant post ID.
	 */
	protected function create_assistant_fixture() {
		$assistant_id = wp_insert_post(
			array(
				'post_type'    => 'mcp_ai_assistant',
				'post_title'   => 'Portability Test Assistant',
				'post_status'  => 'publish',
				'post_content' => 'A fixture assistant used by the portability tests.',
			)
		);

		// Real attachment fixtures — the CPT sanitizer drops dangling IDs.
		$file_101 = $this->factory->attachment->create();
		$file_202 = $this->factory->attachment->create();

		update_post_meta( $assistant_id, '_wp_mcp_ai_provider', 'openai' );
		update_post_meta( $assistant_id, '_wp_mcp_ai_model', 'gpt-4.1' );
		update_post_meta( $assistant_id, '_wp_mcp_ai_temperature', 0.4 );
		update_post_meta( $assistant_id, '_wp_mcp_ai_system_prompt', "You are a test assistant.\nBe concise." );
		update_post_meta( $assistant_id, '_wp_mcp_ai_tools', array( 'web_search', 'get_post' ) );
		update_post_meta( $assistant_id, '_wp_mcp_ai_memory_files', array( $file_101, $file_202 ) );
		update_post_meta( $assistant_id, '_wp_mcp_ai_credentials', 'hash_should_never_leak' );
		update_post_meta( $assistant_id, '_wp_mcp_ai_external_action_id', 'external_action_pointer' );
		update_post_meta( $assistant_id, '_edit_lock', '999:whatever' );

		return $assistant_id;
	}

	/**
	 * The export bundle carries the canonical envelope keys.
	 */
	public function test_export_bundle_shape() {
		$assistant_id = $this->create_assistant_fixture();

		$bundle = WP_MCP_AI_Assistant_Portability::export_assistants( array( $assistant_id ), array( 'include_a2a' => false ) );

		$this->assertNotWPError( $bundle );
		$this->assertSame( 'nvoos-assistant', $bundle['format'] );
		$this->assertSame( 1, $bundle['format_version'] );
		$this->assertCount( 1, $bundle['assistants'] );
		$this->assertSame( 'Portability Test Assistant', $bundle['assistants'][0]['title'] );
	}

	/**
	 * Credential hashes and other denylisted keys never leave the site.
	 */
	public function test_export_excludes_denylisted_meta() {
		$assistant_id = $this->create_assistant_fixture();

		$bundle = WP_MCP_AI_Assistant_Portability::export_assistants( array( $assistant_id ), array( 'include_a2a' => false ) );
		$meta   = $bundle['assistants'][0]['meta'];

		$this->assertArrayNotHasKey( '_wp_mcp_ai_credentials', $meta );
		$this->assertArrayNotHasKey( '_wp_mcp_ai_external_action_id', $meta );
		$this->assertArrayNotHasKey( '_edit_lock', $meta );
	}

	/**
	 * Array-typed meta (tools, memory files) survives the round trip.
	 */
	public function test_round_trip_preserves_array_meta() {
		$assistant_id = $this->create_assistant_fixture();
		$memory_files = get_post_meta( $assistant_id, '_wp_mcp_ai_memory_files', true );

		$bundle = WP_MCP_AI_Assistant_Portability::export_assistants( array( $assistant_id ), array( 'include_a2a' => false ) );
		$json   = wp_json_encode( $bundle );

		$parsed = WP_MCP_AI_Assistant_Portability::parse_import( $json );
		$this->assertNotWPError( $parsed );

		$report = WP_MCP_AI_Assistant_Portability::import_bundle( $parsed, array( 'mode' => 'duplicate' ) );

		$this->assertSame( 1, $report['created'] );
		$new_id = $report['items'][0]['assistant_id'];

		$this->assertSame( array( 'web_search', 'get_post' ), get_post_meta( $new_id, '_wp_mcp_ai_tools', true ) );
		$this->assertSame( array_map( 'absint', $memory_files ), array_map( 'absint', (array) get_post_meta( $new_id, '_wp_mcp_ai_memory_files', true ) ) );
		$this->assertSame( 'gpt-4.1', get_post_meta( $new_id, '_wp_mcp_ai_model', true ) );
	}

	/**
	 * MCP App credentials (`token` / `oauth_data`) are redacted from exports
	 * while the rest of each app config stays for round-trip fidelity.
	 */
	public function test_export_redacts_mcp_app_tokens() {
		$assistant_id = $this->create_assistant_fixture();

		update_post_meta(
			$assistant_id,
			'_wp_mcp_ai_mcp_apps',
			array(
				array(
					'label'       => 'Elementor',
					'server_url'  => 'https://example.com/wp-json/mcp/elementor-mcp-server',
					'auth_type'   => 'basic',
					'token'       => 'admin:secret-app-password',
					'header_name' => '',
					'enabled'     => true,
					'timeout'     => 30,
					'verify_ssl'  => true,
				),
				array(
					'label'      => 'OAuth App',
					'server_url' => 'https://api.example.com/mcp',
					'auth_type'  => 'oauth',
					'token'      => 'bearer-access-token',
					'oauth_data' => array(
						'access_token'  => 'access-secret',
						'refresh_token' => 'refresh-secret',
					),
				),
			)
		);

		$bundle = WP_MCP_AI_Assistant_Portability::export_assistants( array( $assistant_id ), array( 'include_a2a' => false ) );
		$apps   = $bundle['assistants'][0]['meta']['_wp_mcp_ai_mcp_apps'];

		$this->assertIsArray( $apps );
		$this->assertCount( 2, $apps );

		$this->assertArrayNotHasKey( 'token', $apps[0] );
		$this->assertArrayNotHasKey( 'token', $apps[1] );
		$this->assertArrayNotHasKey( 'oauth_data', $apps[1] );

		// Non-credential fields survive for round-trip fidelity.
		$this->assertSame( 'Elementor', $apps[0]['label'] );
		$this->assertSame( 'https://example.com/wp-json/mcp/elementor-mcp-server', $apps[0]['server_url'] );
		$this->assertSame( 'basic', $apps[0]['auth_type'] );
	}

	/**
	 * The export redaction can be opted out of for trusted migrations.
	 */
	public function test_export_mcp_app_tokens_opt_in_filter() {
		$assistant_id = $this->create_assistant_fixture();

		update_post_meta(
			$assistant_id,
			'_wp_mcp_ai_mcp_apps',
			array(
				array(
					'label'      => 'Elementor',
					'server_url' => 'https://example.com/mcp',
					'auth_type'  => 'bearer',
					'token'      => 'secret-token',
				),
			)
		);

		add_filter( 'wp_mcp_ai_assistant_export_redact_mcp_app_tokens', '__return_false' );
		$bundle = WP_MCP_AI_Assistant_Portability::export_assistants( array( $assistant_id ), array( 'include_a2a' => false ) );
		remove_filter( 'wp_mcp_ai_assistant_export_redact_mcp_app_tokens', '__return_false' );

		$apps = $bundle['assistants'][0]['meta']['_wp_mcp_ai_mcp_apps'];
		$this->assertSame( 'secret-token', $apps[0]['token'] );
	}

	/**
	 * Import strips MCP App credentials even from a hand-edited payload.
	 */
	public function test_import_redacts_mcp_app_tokens_on_create() {
		$payload = array(
			'format'     => 'nvoos-assistant',
			'assistants' => array(
				array(
					'title' => 'MCP App Import',
					'meta'  => array(
						'_wp_mcp_ai_mcp_apps' => array(
							array(
								'label'      => 'Injected',
								'server_url' => 'https://example.com/mcp',
								'auth_type'  => 'bearer',
								'token'      => 'injected-token',
							),
						),
					),
				),
			),
		);

		$parsed = WP_MCP_AI_Assistant_Portability::parse_import( wp_json_encode( $payload ) );
		$report = WP_MCP_AI_Assistant_Portability::import_bundle( $parsed );

		$this->assertSame( 1, $report['created'] );
		$new_id = $report['items'][0]['assistant_id'];
		$apps   = get_post_meta( $new_id, '_wp_mcp_ai_mcp_apps', true );

		$this->assertIsArray( $apps );
		$this->assertCount( 1, $apps );
		$this->assertSame( 'https://example.com/mcp', $apps[0]['server_url'] );
		$this->assertArrayNotHasKey( 'token', $apps[0] );
	}

	/**
	 * The import redaction can be opted out of for trusted migrations.
	 */
	public function test_import_mcp_app_tokens_opt_in_filter() {
		$payload = array(
			'format'     => 'nvoos-assistant',
			'assistants' => array(
				array(
					'title' => 'MCP App Token Migration',
					'meta'  => array(
						'_wp_mcp_ai_mcp_apps' => array(
							array(
								'label'      => 'Migrated',
								'server_url' => 'https://example.com/mcp',
								'auth_type'  => 'bearer',
								'token'      => 'migrated-token',
							),
						),
					),
				),
			),
		);

		$parsed = WP_MCP_AI_Assistant_Portability::parse_import( wp_json_encode( $payload ) );

		add_filter( 'wp_mcp_ai_assistant_import_redact_mcp_app_tokens', '__return_false' );
		$report = WP_MCP_AI_Assistant_Portability::import_bundle( $parsed );
		remove_filter( 'wp_mcp_ai_assistant_import_redact_mcp_app_tokens', '__return_false' );

		$this->assertSame( 1, $report['created'] );
		$apps = get_post_meta( $report['items'][0]['assistant_id'], '_wp_mcp_ai_mcp_apps', true );

		$this->assertSame( 'migrated-token', $apps[0]['token'] );
	}

	/**
	 * Overwriting an assistant with a redacted bundle keeps stored MCP App
	 * credentials for matching apps while still applying config changes.
	 */
	public function test_import_overwrite_preserves_existing_mcp_app_tokens() {
		$assistant_id = $this->create_assistant_fixture();

		update_post_meta(
			$assistant_id,
			'_wp_mcp_ai_mcp_apps',
			array(
				array(
					'label'      => 'Elementor',
					'server_url' => 'https://example.com/wp-json/mcp/elementor-mcp-server',
					'auth_type'  => 'basic',
					'token'      => 'admin:keep-me',
					'enabled'    => true,
				),
			)
		);

		$payload = array(
			'format'     => 'nvoos-assistant',
			'assistants' => array(
				array(
					'title' => 'Portability Test Assistant',
					'meta'  => array(
						'_wp_mcp_ai_mcp_apps' => array(
							array(
								'label'      => 'Elementor',
								'server_url' => 'https://example.com/wp-json/mcp/elementor-mcp-server',
								'auth_type'  => 'basic',
								'enabled'    => false, // Config change that should land.
							),
						),
					),
				),
			),
		);

		$parsed = WP_MCP_AI_Assistant_Portability::parse_import( wp_json_encode( $payload ) );
		$report = WP_MCP_AI_Assistant_Portability::import_bundle( $parsed, array( 'mode' => 'overwrite' ) );

		$this->assertSame( 1, $report['updated'] );
		$apps = get_post_meta( $assistant_id, '_wp_mcp_ai_mcp_apps', true );

		$this->assertIsArray( $apps );
		$this->assertCount( 1, $apps );
		$this->assertSame( 'admin:keep-me', $apps[0]['token'] ); // Stored credential preserved.
		$this->assertFalse( $apps[0]['enabled'] ); // Payload change applied.
	}

	/**
	 * Imported MCP App configs are structurally sanitized: unknown auth types
	 * normalise, tags are stripped, booleans/ints are coerced, junk keys are
	 * dropped, and entries without a usable endpoint URL are removed.
	 */
	public function test_import_sanitizes_mcp_app_structure() {
		$payload = array(
			'format'     => 'nvoos-assistant',
			'assistants' => array(
				array(
					'title' => 'Sanitized Apps',
					'meta'  => array(
						'_wp_mcp_ai_mcp_apps' => array(
							array(
								'label'      => '<b>Evil</b>',
								'server_url' => 'https://example.com/mcp',
								'auth_type'  => 'evil',
								'token'      => 'should-be-stripped',
								'timeout'    => 9999,
								'verify_ssl' => 0,
								'junk'       => 'drop-me',
							),
							array(
								'label'      => 'Bad Scheme',
								'server_url' => 'javascript:alert(1)',
								'auth_type'  => 'none',
							),
							array(
								'label' => 'No Endpoint',
							),
						),
					),
				),
			),
		);

		$parsed = WP_MCP_AI_Assistant_Portability::parse_import( wp_json_encode( $payload ) );
		$report = WP_MCP_AI_Assistant_Portability::import_bundle( $parsed );

		$this->assertSame( 1, $report['created'] );
		$apps = get_post_meta( $report['items'][0]['assistant_id'], '_wp_mcp_ai_mcp_apps', true );

		$this->assertIsArray( $apps );
		$this->assertCount( 1, $apps ); // Bad-scheme and endpoint-less entries dropped.

		$app = $apps[0];
		$this->assertSame( 'Evil', $app['label'] ); // Tags stripped.
		$this->assertSame( 'https://example.com/mcp', $app['server_url'] );
		$this->assertSame( 'none', $app['auth_type'] ); // Unknown auth type normalised.
		$this->assertSame( 120, $app['timeout'] ); // Clamped to the registry ceiling.
		$this->assertFalse( $app['verify_ssl'] ); // Coerced to bool.
		$this->assertArrayNotHasKey( 'junk', $app );
		$this->assertArrayNotHasKey( 'token', $app );
	}

	/**
	 * Imported MCP App reference entries survive. Reference entries carry no
	 * endpoint URL (the reference resolves at chat time) but are non-secret
	 * pointers, so they must not be dropped as endpoint-less entries.
	 */
	public function test_import_preserves_mcp_app_connection_refs() {
		$payload = array(
			'format'     => 'nvoos-assistant',
			'assistants' => array(
				array(
					'title' => 'Ref Import',
					'meta'  => array(
						'_wp_mcp_ai_mcp_apps' => array(
							array(
								'label'          => 'Elementor',
								'connection_ref' => 'mcp_central',
								'enabled'        => true,
							),
						),
					),
				),
			),
		);

		$parsed = WP_MCP_AI_Assistant_Portability::parse_import( wp_json_encode( $payload ) );
		$report = WP_MCP_AI_Assistant_Portability::import_bundle( $parsed );

		$this->assertSame( 1, $report['created'] );
		$apps = get_post_meta( $report['items'][0]['assistant_id'], '_wp_mcp_ai_mcp_apps', true );

		$this->assertIsArray( $apps );
		$this->assertCount( 1, $apps );
		$this->assertSame( 'mcp_central', $apps[0]['connection_ref'] );
		$this->assertSame( '', $apps[0]['server_url'] );
		$this->assertSame( '', $apps[0]['token'] ); // No credential material.
	}

	/**
	 * Legacy CLI export files still import, with the mcp_ai_ prefix re-applied.
	 */
	public function test_import_legacy_cli_format() {
		$legacy = array(
			'version'   => '1.1.0',
			'exported'  => gmdate( 'c' ),
			'assistant' => array(
				'title'   => 'Legacy Assistant',
				'status'  => 'draft',
				'content' => 'Legacy body',
				'meta'    => array(
					'model'      => 'legacy-model',
					'capability' => 'edit_posts',
				),
			),
		);

		$parsed = WP_MCP_AI_Assistant_Portability::parse_import( wp_json_encode( $legacy ) );
		$this->assertNotWPError( $parsed );

		$report = WP_MCP_AI_Assistant_Portability::import_bundle( $parsed );
		$this->assertSame( 1, $report['created'] );

		$new_id = $report['items'][0]['assistant_id'];
		$this->assertSame( 'legacy-model', get_post_meta( $new_id, 'mcp_ai_model', true ) );
	}

	/**
	 * Healthcare-style blueprint JSON imports with meta_input applied.
	 */
	public function test_import_blueprint_healthcare_style() {
		$blueprint = array(
			'post_title'   => 'Healthcare Assistant',
			'post_status'  => 'publish',
			'post_content' => 'Healthcare instructions',
			'meta_input'   => array(
				'_wp_mcp_ai_system_prompt' => 'You are a healthcare assistant.',
				'_wp_mcp_ai_provider'      => 'gemini',
			),
		);

		$parsed = WP_MCP_AI_Assistant_Portability::parse_import( wp_json_encode( $blueprint ) );
		$this->assertNotWPError( $parsed );

		$report = WP_MCP_AI_Assistant_Portability::import_bundle( $parsed );
		$this->assertSame( 1, $report['created'] );

		$new_id = $report['items'][0]['assistant_id'];
		$this->assertSame( 'You are a healthcare assistant.', get_post_meta( $new_id, '_wp_mcp_ai_system_prompt', true ) );
		$this->assertSame( 'publish', get_post_status( $new_id ) );
	}

	/**
	 * CRM-style blueprint JSON maps abstracted keys onto canonical meta.
	 */
	public function test_import_blueprint_crm_style() {
		$blueprint = array(
			'name' => 'CRM Assistant',
			'meta' => array(
				'instructions'    => 'You manage the CRM pipeline.',
				'available_tools' => array( 'web_search', 'get_post' ),
				'provider'        => 'openai',
				'model'           => 'gpt-4.1',
				'temperature'     => 0.3,
			),
		);

		$parsed = WP_MCP_AI_Assistant_Portability::parse_import( wp_json_encode( $blueprint ) );
		$this->assertNotWPError( $parsed );

		$report = WP_MCP_AI_Assistant_Portability::import_bundle( $parsed );
		$this->assertSame( 1, $report['created'] );

		$new_id = $report['items'][0]['assistant_id'];
		$this->assertSame( 'You manage the CRM pipeline.', get_post_meta( $new_id, '_wp_mcp_ai_system_prompt', true ) );
		$this->assertSame( array( 'web_search', 'get_post' ), get_post_meta( $new_id, '_wp_mcp_ai_tools', true ) );
		$this->assertSame( 'gpt-4.1', get_post_meta( $new_id, '_wp_mcp_ai_model', true ) );
	}

	/**
	 * Tool slugs not registered on the target site are dropped by the
	 * assistant CPT's registered meta sanitizer — portability contract.
	 */
	public function test_import_drops_unregistered_tools() {
		$registered = get_registered_meta_keys( 'post', 'mcp_ai_assistant' );
		if ( ! isset( $registered['_wp_mcp_ai_tools'] ) ) {
			$this->markTestSkipped( 'Assistant tools meta is not registered in this run.' );
		}

		$blueprint = array(
			'name' => 'Unknown Tools Assistant',
			'meta' => array(
				'available_tools' => array( 'tool_that_does_not_exist_xyz' ),
			),
		);

		$parsed = WP_MCP_AI_Assistant_Portability::parse_import( wp_json_encode( $blueprint ) );
		$report = WP_MCP_AI_Assistant_Portability::import_bundle( $parsed );

		$this->assertSame( 1, $report['created'] );
		$new_id = $report['items'][0]['assistant_id'];

		// The engine writes the array; the CPT sanitizer filters unknown slugs.
		$this->assertSame( array(), get_post_meta( $new_id, '_wp_mcp_ai_tools', true ) );
	}

	/**
	 * Dry-run reports without writing anything.
	 */
	public function test_import_dry_run_writes_nothing() {
		$blueprint = array(
			'name' => 'Dry Run Assistant',
			'meta' => array( 'instructions' => 'Never written.' ),
		);

		$parsed = WP_MCP_AI_Assistant_Portability::parse_import( wp_json_encode( $blueprint ) );
		$report = WP_MCP_AI_Assistant_Portability::import_bundle( $parsed, array( 'dry_run' => true ) );

		$this->assertSame( 1, $report['created'] );
		$this->assertSame( 'dry_run', $report['items'][0]['status'] );

		$found = get_posts(
			array(
				'post_type'      => 'mcp_ai_assistant',
				'title'          => 'Dry Run Assistant',
				'posts_per_page' => 1,
				'post_status'    => 'any',
				'fields'         => 'ids',
			)
		);
		$this->assertEmpty( $found );
	}

	/**
	 * Skip mode leaves an existing assistant untouched.
	 */
	public function test_import_skip_existing() {
		$assistant_id = $this->create_assistant_fixture();

		$bundle = WP_MCP_AI_Assistant_Portability::export_assistants( array( $assistant_id ), array( 'include_a2a' => false ) );
		$parsed = WP_MCP_AI_Assistant_Portability::parse_import( wp_json_encode( $bundle ) );

		$report = WP_MCP_AI_Assistant_Portability::import_bundle( $parsed, array( 'mode' => 'skip' ) );

		$this->assertSame( 1, $report['skipped'] );
		$this->assertSame( 0, $report['created'] );
		$this->assertSame( $assistant_id, $report['items'][0]['assistant_id'] );
	}

	/**
	 * Overwrite mode updates the existing assistant in place.
	 */
	public function test_import_overwrite_existing() {
		$assistant_id = $this->create_assistant_fixture();

		// Change the model in the bundle before re-importing.
		$bundle = WP_MCP_AI_Assistant_Portability::export_assistants( array( $assistant_id ), array( 'include_a2a' => false ) );
		$bundle['assistants'][0]['meta']['_wp_mcp_ai_model'] = 'gpt-4o-mini';
		$parsed = WP_MCP_AI_Assistant_Portability::parse_import( wp_json_encode( $bundle ) );

		$report = WP_MCP_AI_Assistant_Portability::import_bundle( $parsed, array( 'mode' => 'overwrite' ) );

		$this->assertSame( 1, $report['updated'] );
		$this->assertSame( 'gpt-4o-mini', get_post_meta( $assistant_id, '_wp_mcp_ai_model', true ) );
	}

	/**
	 * Duplicate mode always creates a new post.
	 */
	public function test_import_duplicate_existing() {
		$assistant_id = $this->create_assistant_fixture();

		$bundle = WP_MCP_AI_Assistant_Portability::export_assistants( array( $assistant_id ), array( 'include_a2a' => false ) );
		$parsed = WP_MCP_AI_Assistant_Portability::parse_import( wp_json_encode( $bundle ) );

		$report = WP_MCP_AI_Assistant_Portability::import_bundle( $parsed, array( 'mode' => 'duplicate' ) );

		$this->assertSame( 1, $report['created'] );
		$this->assertNotSame( $assistant_id, $report['items'][0]['assistant_id'] );
	}

	/**
	 * Import strips credential hashes even from a hand-edited payload.
	 */
	public function test_import_strips_credentials_defense_in_depth() {
		$payload = array(
			'format'     => 'nvoos-assistant',
			'assistants' => array(
				array(
					'title' => 'Injected Credentials',
					'meta'  => array(
						'_wp_mcp_ai_credentials' => 'injected_hash',
						'_wp_mcp_ai_model'       => 'gpt-4.1',
					),
				),
			),
		);

		$parsed = WP_MCP_AI_Assistant_Portability::parse_import( wp_json_encode( $payload ) );
		$report = WP_MCP_AI_Assistant_Portability::import_bundle( $parsed );

		$this->assertSame( 1, $report['created'] );
		$new_id = $report['items'][0]['assistant_id'];

		$this->assertSame( '', get_post_meta( $new_id, '_wp_mcp_ai_credentials', true ) );
		$this->assertSame( 'gpt-4.1', get_post_meta( $new_id, '_wp_mcp_ai_model', true ) );
	}

	/**
	 * Invalid JSON produces a WP_Error.
	 */
	public function test_import_invalid_json_errors() {
		$parsed = WP_MCP_AI_Assistant_Portability::parse_import( '{not json' );
		$this->assertWPError( $parsed );
		$this->assertSame( 'wp_mcp_ai_portability_invalid_json', $parsed->get_error_code() );
	}

	/**
	 * Unrecognised payloads produce a WP_Error.
	 */
	public function test_import_unknown_format_errors() {
		$parsed = WP_MCP_AI_Assistant_Portability::parse_import( wp_json_encode( array( 'unrelated' => true ) ) );
		$this->assertWPError( $parsed );
		$this->assertSame( 'wp_mcp_ai_portability_unknown_format', $parsed->get_error_code() );
	}

	/**
	 * A bundle without any assistants fails schema validation.
	 */
	public function test_import_empty_bundle_errors() {
		$payload = array(
			'format'     => 'nvoos-assistant',
			'assistants' => array(),
		);
		$parsed  = WP_MCP_AI_Assistant_Portability::parse_import( wp_json_encode( $payload ) );
		$this->assertWPError( $parsed );
	}

	/**
	 * Exporting an invalid ID produces a WP_Error.
	 */
	public function test_export_invalid_id_errors() {
		$bundle = WP_MCP_AI_Assistant_Portability::export_assistants( array( 99999999 ) );
		$this->assertWPError( $bundle );
		$this->assertSame( 'wp_mcp_ai_portability_invalid_assistant', $bundle->get_error_code() );
	}

	/**
	 * The blueprint converter maps canonical meta onto the blueprint dialect.
	 */
	public function test_to_blueprint_json_mapping() {
		$assistant_id = $this->create_assistant_fixture();

		$bundle    = WP_MCP_AI_Assistant_Portability::export_assistants( array( $assistant_id ), array( 'include_a2a' => false ) );
		$blueprint = WP_MCP_AI_Assistant_Portability::to_blueprint_json( $bundle['assistants'][0] );

		$this->assertSame( 'Portability Test Assistant', $blueprint['name'] );
		$this->assertSame( array( 'web_search', 'get_post' ), $blueprint['meta']['available_tools'] );
		$this->assertSame( 'gpt-4.1', $blueprint['meta']['model'] );
		$this->assertStringContainsString( 'You are a test assistant', $blueprint['meta']['instructions'] );
	}

	/**
	 * A2A card export returns a card with a name and skills array.
	 */
	public function test_export_a2a_card() {
		if ( ! class_exists( 'WP_MCP_AI_A2A_Agent_Card' ) ) {
			$this->markTestSkipped( 'A2A agent card builder not loaded.' );
		}

		$assistant_id = $this->create_assistant_fixture();

		$card = WP_MCP_AI_Assistant_Portability::export_a2a_card( $assistant_id );

		$this->assertNotWPError( $card );
		$this->assertSame( 'Portability Test Assistant', $card['name'] );
		$this->assertArrayHasKey( 'skills', $card );
	}

	/**
	 * The backup export provider shares the engine denylist.
	 */
	public function test_backup_provider_redacts_credentials() {
		if ( ! class_exists( 'WP_MCP_AI_Export_Provider_Assistants' ) ) {
			$this->markTestSkipped( 'Backup export provider not loaded.' );
		}

		$assistant_id = $this->create_assistant_fixture();

		$provider = new WP_MCP_AI_Export_Provider_Assistants();
		$export   = $provider->export();

		$this->assertNotEmpty( $export['posts'] );

		$found = false;
		foreach ( $export['posts'] as $post_data ) {
			if ( 'Portability Test Assistant' === $post_data['post_title'] ) {
				$found = true;
				$this->assertArrayNotHasKey( '_wp_mcp_ai_credentials', $post_data['meta'] );
				$this->assertArrayHasKey( '_wp_mcp_ai_model', $post_data['meta'] );
			}
		}

		$this->assertTrue( $found, 'The fixture assistant should appear in the backup export.' );
	}
}
