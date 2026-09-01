<?php
/**
 * Blueprints greenfield tests (extraction Phase 4).
 *
 * Covers the full blueprint lifecycle (export → validate → store → import)
 * plus the REST CRUD surface and the validator's safety guards.
 *
 * @package NvoosContentGraphAiPlatform\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Tests;

use NvoosContentGraphAiPlatform\Blueprints\BlueprintExporter;
use NvoosContentGraphAiPlatform\Blueprints\BlueprintImporter;
use NvoosContentGraphAiPlatform\Blueprints\BlueprintRegistry;
use NvoosContentGraphAiPlatform\Blueprints\BlueprintRestController;
use NvoosContentGraphAiPlatform\Blueprints\BlueprintValidator;
use NvoosContentGraphAiPlatform\PostTypes\TemplateCpt;

/**
 * @group blueprints
 */
class Test_Platform_Blueprints extends \WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();

		// Clean any blueprint (template) posts left by other tests.
		$existing = get_posts(
			array(
				'post_type'      => TemplateCpt::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
		foreach ( $existing as $template_id ) {
			wp_delete_post( $template_id, true );
		}
	}

	private function sample_agent(): array {
		return array(
			'name'          => 'Support Analyst',
			'description'   => 'Triages support tickets.',
			'provider'      => 'openai',
			'model'         => 'gpt-4.1-mini',
			'system_prompt' => 'You are a support analyst.',
			'tools'         => array( 'web_search', 'get_recent_posts' ),
			'skills'        => array( 'code-reviewer' ),
			'settings'      => array( 'temperature' => 0.3 ),
		);
	}

	public function test_export_produces_versioned_agent_blueprint(): void {
		$exporter   = new BlueprintExporter();
		$definition = $exporter->export( $this->sample_agent() );

		$this->assertIsArray( $definition );
		$this->assertSame( BlueprintRegistry::SCHEMA_VERSION, $definition['blueprint_version'] );
		$this->assertSame( 'agent', $definition['kind'] );
		$this->assertSame( 'Support Analyst', $definition['agent']['name'] );
		$this->assertContains( 'web_search', $definition['agent']['tools'] );
	}

	public function test_exporter_rejects_irreversible_fields(): void {
		$agent             = $this->sample_agent();
		$agent['settings'] = array( 'api_key' => 'sk-secret-value' );

		$result = ( new BlueprintExporter() )->export( $agent );

		$this->assertWPError( $result );
		$this->assertSame( 'blueprint_irreversible_field', $result->get_error_code() );
	}

	public function test_validator_accepts_valid_agent_definition(): void {
		$definition = ( new BlueprintExporter() )->export( $this->sample_agent() );

		$this->assertTrue( ( new BlueprintValidator() )->validate( $definition ) );
	}

	public function test_validator_rejects_missing_and_unknown_fields(): void {
		$validator = new BlueprintValidator();

		// Missing envelope.
		$this->assertWPError( $validator->validate( array() ) );

		// Unknown kind.
		$this->assertSame(
			'blueprint_invalid_kind',
			$validator->validate(
				array(
					'blueprint_version' => '1.0',
					'kind'              => 'wizard',
				)
			)->get_error_code()
		);

		// Agent kind without agent payload.
		$this->assertSame(
			'blueprint_invalid_agent',
			$validator->validate(
				array(
					'blueprint_version' => '1.0',
					'kind'              => 'agent',
				)
			)->get_error_code()
		);
	}

	public function test_validator_rejects_newer_schema_versions(): void {
		$definition                      = ( new BlueprintExporter() )->export( $this->sample_agent() );
		$definition['blueprint_version'] = '99.0';

		$result = ( new BlueprintValidator() )->validate( $definition );

		$this->assertWPError( $result );
		$this->assertSame( 'blueprint_version_too_new', $result->get_error_code() );
	}

	public function test_full_lifecycle_export_store_import(): void {
		$exporter   = new BlueprintExporter();
		$definition = $exporter->export( $this->sample_agent() );

		// Store.
		$registry = new BlueprintRegistry();
		$created  = $registry->create(
			array(
				'title'       => 'Support Analyst Blueprint',
				'slug'        => 'support-analyst',
				'description' => 'Versioned support analyst.',
				'definition'  => $definition,
			)
		);
		$this->assertNotWPError( $created );

		// Retrieve.
		$record = $registry->get( $created );
		$this->assertNotNull( $record );
		$this->assertSame( 'support-analyst', $record['slug'] );
		$this->assertSame( 'agent', $record['kind'] );

		// Import back to an agent config.
		$config = ( new BlueprintImporter() )->import( $record['definition'] );
		$this->assertIsArray( $config );
		$this->assertSame( 'Support Analyst', $config['name'] );
		$this->assertContains( 'get_recent_posts', $config['tools'] );

		// Update.
		$updated = $registry->update( $created, array( 'title' => 'Support Analyst v2' ) );
		$this->assertNotWPError( $updated );
		$this->assertSame( 'Support Analyst v2', $registry->get( $created )['title'] );

		// Delete.
		$this->assertTrue( $registry->delete( $created ) );
		$this->assertNull( $registry->get( $created ) );
	}

	public function test_registry_rejects_missing_title_and_definition(): void {
		$registry = new BlueprintRegistry();

		$this->assertWPError(
			$registry->create(
				array(
					'definition' => array(
						'blueprint_version' => '1.0',
						'kind'              => 'agent',
					),
				)
			)
		);
		$this->assertWPError( $registry->create( array( 'title' => 'No Definition' ) ) );
	}

	public function test_importer_rejects_non_agent_kinds_for_agent_config(): void {
		$result = ( new BlueprintImporter() )->import(
			array(
				'blueprint_version' => '1.0',
				'kind'              => 'workflow',
				'workflow'          => array( 'steps' => array() ),
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'blueprint_unsupported_kind', $result->get_error_code() );
	}

	public function test_rest_crud_surface(): void {
		// Route registration must happen under rest_api_init.
		( new BlueprintRestController() )->register();
		do_action( 'rest_api_init' );

		// Routes exist (dispatch via rest_do_request is avoided — the test
		// framework's REST dispatch is unreliable for late-registered
		// routes; the federation/mesh suites call controllers directly).
		$routes = rest_get_server()->get_routes( BlueprintRestController::NAMESPACE );
		$this->assertArrayHasKey( '/' . BlueprintRestController::NAMESPACE, $routes );
		$this->assertArrayHasKey( '/' . BlueprintRestController::NAMESPACE . '/(?P<id>\d+)', $routes );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$controller = new BlueprintRestController();
		$definition = ( new BlueprintExporter() )->export( $this->sample_agent() );

		// Create.
		$create = new \WP_REST_Request( 'POST', '/' . BlueprintRestController::NAMESPACE );
		$create->set_body_params(
			array(
				'title'       => 'REST Blueprint',
				'description' => 'Created over REST.',
				'definition'  => $definition,
			)
		);
		$response   = $controller->create_item( $create );
		$created_id = $response->get_data()['id'];
		$this->assertSame( 201, $response->get_status() );

		// List.
		$list     = new \WP_REST_Request( 'GET', '/' . BlueprintRestController::NAMESPACE );
		$response = $controller->list_items( $list );
		$this->assertSame( 200, $response->get_status() );
		$this->assertNotEmpty( $response->get_data()['blueprints'] );

		// Get.
		$get = new \WP_REST_Request( 'GET', '/' . BlueprintRestController::NAMESPACE . '/' . $created_id );
		$get->set_param( 'id', $created_id );
		$response = $controller->get_item( $get );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'REST Blueprint', $response->get_data()['title'] );

		// Update.
		$update = new \WP_REST_Request( 'PUT', '/' . BlueprintRestController::NAMESPACE . '/' . $created_id );
		$update->set_body_params(
			array(
				'title'      => 'REST Blueprint v2',
				'definition' => $definition,
			)
		);
		// set_body_params() replaces the request params — set the ID after it.
		$update->set_param( 'id', $created_id );
		$response = $controller->update_item( $update );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'REST Blueprint v2', $response->get_data()['title'] );

		// Delete.
		$delete = new \WP_REST_Request( 'DELETE', '/' . BlueprintRestController::NAMESPACE . '/' . $created_id );
		$delete->set_param( 'id', $created_id );
		$response = $controller->delete_item( $delete );
		$this->assertSame( 200, $response->get_status() );
	}

	public function test_rest_rejects_invalid_definition(): void {
		( new BlueprintRestController() )->register();
		do_action( 'rest_api_init' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$create = new \WP_REST_Request( 'POST', '/' . BlueprintRestController::NAMESPACE );
		$create->set_body_params(
			array(
				'title'      => 'Invalid Blueprint',
				'definition' => array( 'kind' => 'wizard' ),
			)
		);

		$response = ( new BlueprintRestController() )->create_item( $create );
		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 400, $response->get_error_data()['status'] );
	}

	public function test_rest_permission_callbacks_fail_closed(): void {
		( new BlueprintRestController() )->register();
		do_action( 'rest_api_init' );

		$routes = rest_get_server()->get_routes( BlueprintRestController::NAMESPACE );
		$entry  = $routes[ '/' . BlueprintRestController::NAMESPACE . '/' ];

		// Read endpoint requires edit_posts.
		$read = $entry[0]['permission_callback'];
		$this->assertFalse( (bool) $read() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertFalse( (bool) $read() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertTrue( (bool) $read() );

		// Write endpoint requires manage_options.
		$write = $entry[1]['permission_callback'];
		$this->assertFalse( (bool) $write() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertTrue( (bool) $write() );
	}
}
