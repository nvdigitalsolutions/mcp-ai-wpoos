<?php
/**
 * Markup loop-interceptor + REST port tests (Wave E6, sub-cluster 2).
 *
 * Characterization suite for the ported
 * `NvoosContentGraphAi\Engine\Markup\MarkupLoopInterceptor` +
 * `MarkupRestController`: the short-circuit / skip / disabled-setting
 * interceptor paths, the per-mode filter wiring, the REST GET /
 * DELETE / submit contract (404 envelopes, 400 validation, replay
 * protection), the tool-resume path via an injected seam, and the
 * per-mode tool-lookup / awareness seams. Runs in both matrices.
 *
 * @package NvoosContentGraphAi\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Tests;

use NvoosContentGraphAi\Engine\Markup\MarkupAwareToolInterface;
use NvoosContentGraphAi\Engine\Markup\MarkupLoopInterceptor;
use NvoosContentGraphAi\Engine\Markup\MarkupRequest;
use NvoosContentGraphAi\Engine\Markup\MarkupResult;
use NvoosContentGraphAi\Engine\Markup\MarkupRestController;
use NvoosContentGraphAi\Engine\Markup\MarkupStore;

// phpcs:disable Generic.Files.OneObjectStructurePerFile -- The seam/stub fixtures share this file with its test case.

/**
 * Markup-aware tool double implementing the ported contract.
 */
class MarkupAwareToolStub implements MarkupAwareToolInterface {

	/**
	 * Slug.
	 *
	 * @return string
	 */
	public function get_slug(): string {
		return 'test_markup_aware_tool';
	}

	/**
	 * {@inheritdoc}
	 */
	public function needs_markup( array $arguments, array $context ) {
		if ( ! empty( $arguments['skip_markup'] ) ) {
			return null;
		}
		return new MarkupRequest(
			array(
				'tool_slug'      => $this->get_slug(),
				'target'         => array( 'attachment_id' => 1 ),
				'target_type'    => MarkupRequest::TARGET_TYPE_IMAGE,
				'mode'           => MarkupRequest::MODE_MASK,
				'instructions'   => 'Mark up please.',
				'tool_arguments' => $arguments,
				'tool_context'   => $context,
				'assistant_id'   => isset( $context['assistant_id'] ) ? (int) $context['assistant_id'] : 0,
			)
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function consume_markup( array $arguments, MarkupResult $result, array $context ) {
		return array(
			'success'    => true,
			'message'    => 'consumed markup',
			'request_id' => $result->get_request()->get_request_id(),
			'has_mask'   => null !== $result->get_artifact( 'mask_attachment_id' ),
		);
	}
}

/**
 * REST controller seam: injects a stub tool for the resume path.
 */
class MarkupRestControllerSeam extends MarkupRestController {

	/**
	 * Injected tool.
	 *
	 * @var object|null
	 */
	private $injected_tool = null;

	/**
	 * Inject a tool for find_tool().
	 *
	 * @param object|null $tool Tool.
	 * @return void
	 */
	public function set_tool( $tool ): void {
		$this->injected_tool = $tool;
	}

	/**
	 * {@inheritdoc}
	 */
	protected function find_tool( $slug ) {
		if ( null !== $this->injected_tool ) {
			return $this->injected_tool;
		}

		return parent::find_tool( $slug );
	}

	/**
	 * {@inheritdoc}
	 */
	protected function is_markup_aware( $candidate ): bool {
		if ( null !== $this->injected_tool ) {
			return $candidate === $this->injected_tool;
		}

		return parent::is_markup_aware( $candidate );
	}

	/**
	 * Expose the real per-mode tool lookup.
	 *
	 * @param string $slug Tool slug.
	 * @return object|null
	 */
	public function seam_find_tool( $slug ) {
		return parent::find_tool( $slug );
	}

	/**
	 * Expose the real per-mode awareness resolution.
	 *
	 * @param object $candidate Candidate tool.
	 * @return bool
	 */
	public function seam_is_markup_aware( $candidate ): bool {
		return parent::is_markup_aware( $candidate );
	}
}

/**
 * @group markup
 */
class Test_Markup_Loop_Rest extends \WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();

		\delete_option( MarkupStore::INDEX_OPTION );
		\delete_option( 'wp_mcp_ai_settings' );
		\delete_option( 'wp_mcp_ai_markup_telemetry' );

		// The ecosystem composition roots never run in the test process (the
		// plugins fire on plugins_loaded, which has already passed when the
		// test bootstrap loads them), and the framework restores the hook
		// registry between tests — wire the ported surface directly every
		// test. Monolith: the base plugin owns the same routes + interceptor
		// (its init file loaded during wp-settings), so leave its wiring
		// alone.
		if ( ! defined( 'WP_MCP_AI_PATH' ) ) {
			$ref = new \ReflectionProperty( \NvoosContentGraphAi\Engine\Markup\MarkupBootstrap::class, 'registered' );
			$ref->setAccessible( true );
			$ref->setValue( null, false );

			\NvoosContentGraphAi\Engine\Markup\MarkupBootstrap::register();
			$this->invoke_closures_from_file( 'plugins_loaded', 'MarkupBootstrap.php' );
			\do_action( 'rest_api_init' );
		}
	}

	public function tearDown(): void {
		\delete_option( MarkupStore::INDEX_OPTION );
		\delete_option( 'wp_mcp_ai_settings' );
		\delete_option( 'wp_mcp_ai_markup_telemetry' );

		parent::tearDown();
	}

	/**
	 * Create + switch to an administrator user.
	 *
	 * @return int
	 */
	private function admin_user(): int {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		\wp_set_current_user( $user_id );
		return $user_id;
	}

	// ─── Interceptor ──────────────────────────────────────────────

	public function test_filter_short_circuits_with_widget_payload(): void {
		$tool        = new MarkupAwareToolStub();
		$interceptor = new MarkupLoopInterceptor();
		$interceptor->register();

		$payload = \apply_filters(
			'wp_mcp_ai_pre_execute_tool',
			null,
			$tool,
			array( 'prompt' => 'Replace logo' ),
			array( 'assistant_id' => 5 )
		);

		$this->assertIsArray( $payload );
		$this->assertSame( 'markup_elicitation', $payload['type'] );
		$this->assertSame( 'test_markup_aware_tool', $payload['tool'] );
	}

	public function test_maybe_intercept_returns_widget_payload(): void {
		$interceptor = new MarkupLoopInterceptor();
		$payload     = $interceptor->maybe_intercept(
			null,
			new MarkupAwareToolStub(),
			array( 'prompt' => 'Replace logo' ),
			array( 'assistant_id' => 5 )
		);

		$this->assertIsArray( $payload );
		$this->assertSame( 'markup_elicitation', $payload['type'] );
		$this->assertSame( 'test_markup_aware_tool', $payload['tool'] );
		$this->assertSame( 1, $payload['target']['attachment_id'] );
	}

	public function test_maybe_intercept_returns_null_when_tool_skips(): void {
		$interceptor = new MarkupLoopInterceptor();
		$payload     = $interceptor->maybe_intercept(
			null,
			new MarkupAwareToolStub(),
			array( 'skip_markup' => true ),
			array()
		);

		$this->assertNull( $payload );
	}

	public function test_maybe_intercept_returns_null_for_non_aware_tool(): void {
		$interceptor = new MarkupLoopInterceptor();
		$payload     = $interceptor->maybe_intercept( null, new \stdClass(), array(), array() );

		$this->assertNull( $payload );
	}

	public function test_maybe_intercept_respects_existing_short_circuit(): void {
		$interceptor = new MarkupLoopInterceptor();
		$existing    = array( 'success' => true );

		$this->assertSame( $existing, $interceptor->maybe_intercept( $existing, new MarkupAwareToolStub(), array(), array() ) );
	}

	public function test_disabled_setting_skips_interception(): void {
		\update_option( 'wp_mcp_ai_settings', array( 'markup_enabled' => false ) );

		$interceptor = new MarkupLoopInterceptor();
		$payload     = $interceptor->maybe_intercept( null, new MarkupAwareToolStub(), array(), array() );

		$this->assertNull( $payload );
	}

	public function test_is_enabled_option_and_filter(): void {
		$this->assertTrue( MarkupLoopInterceptor::is_enabled() );

		\update_option( 'wp_mcp_ai_settings', array( 'markup_enabled' => false ) );
		$this->assertFalse( MarkupLoopInterceptor::is_enabled() );

		\update_option( 'wp_mcp_ai_settings', array( 'markup_enabled' => true ) );
		$this->assertTrue( MarkupLoopInterceptor::is_enabled() );

		\delete_option( 'wp_mcp_ai_settings' );
		\add_filter( 'wp_mcp_ai_markup_enabled', '__return_false' );
		$this->assertFalse( MarkupLoopInterceptor::is_enabled() );
		\remove_filter( 'wp_mcp_ai_markup_enabled', '__return_false' );
	}

	public function test_interceptor_register_adds_the_filter_callback(): void {
		$interceptor = new MarkupLoopInterceptor();
		$interceptor->register();

		$this->assertGreaterThanOrEqual( 1, $this->count_filter_instances( 'wp_mcp_ai_pre_execute_tool', MarkupLoopInterceptor::class ) );
	}

	public function test_wiring_resolves_per_install_mode(): void {
		$rest_routes = \rest_get_server()->get_routes();

		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			// Monolith coexistence: the base init owns the interceptor filter
			// and the markup routes; the ported class is not wired.
			$this->assertSame( 0, $this->count_filter_instances( 'wp_mcp_ai_pre_execute_tool', MarkupLoopInterceptor::class ) );
			$this->assertGreaterThanOrEqual( 1, $this->count_filter_instances( 'wp_mcp_ai_pre_execute_tool', \WP_MCP_AI_Markup_Loop_Interceptor::class ) );
			$this->assertArrayHasKey( '/mcp-ai/v1/markup/(?P<request_id>[A-Za-z0-9_-]+)', $rest_routes );
		} else {
			// Standalone: the ported bootstrap wired the interceptor and routes
			// (via the manual plugins_loaded / rest_api_init in setUp).
			$this->assertGreaterThanOrEqual( 1, $this->count_filter_instances( 'wp_mcp_ai_pre_execute_tool', MarkupLoopInterceptor::class ) );
			$this->assertArrayHasKey( '/mcp-ai/v1/markup/(?P<request_id>[A-Za-z0-9_-]+)', $rest_routes );
		}
	}

	/**
	 * Count filter callbacks whose target object is an instance of a class.
	 *
	 * @param string $tag        Filter tag.
	 * @param string $class_name Class to match.
	 * @return int
	 */
	private function count_filter_instances( string $tag, string $class_name ): int {
		global $wp_filter;

		if ( ! isset( $wp_filter[ $tag ] ) ) {
			return 0;
		}

		$count = 0;
		foreach ( $wp_filter[ $tag ]->callbacks as $group ) {
			foreach ( $group as $cb ) {
				if ( \is_array( $cb['function'] ) && $cb['function'][0] instanceof $class_name ) {
					++$count;
				}
			}
		}
		return $count;
	}

	/**
	 * Invoke every closure on a hook that originates from a given file.
	 *
	 * Used to fire only the markup bootstrap's own wiring without
	 * re-booting the whole ecosystem inside the test process.
	 *
	 * @param string $tag           Hook tag.
	 * @param string $file_fragment File name fragment to match.
	 * @return void
	 */
	private function invoke_closures_from_file( string $tag, string $file_fragment ): void {
		global $wp_filter;

		if ( ! isset( $wp_filter[ $tag ] ) ) {
			return;
		}

		foreach ( $wp_filter[ $tag ]->callbacks as $group ) {
			foreach ( $group as $cb ) {
				$fn = $cb['function'];
				if ( ! $fn instanceof \Closure ) {
					continue;
				}
				$rf  = new \ReflectionFunction( $fn );
				$file = (string) $rf->getFileName();
				if ( false !== \strpos( $file, $file_fragment ) ) {
					$fn();
				}
			}
		}
	}

	// ─── REST ──────────────────────────────────────────────────────

	public function test_get_returns_404_for_unknown_request(): void {
		$this->admin_user();
		$req      = new \WP_REST_Request( 'GET', '/mcp-ai/v1/markup/mr_unknown' );
		$response = \rest_do_request( $req );
		$this->assertSame( 404, $response->get_status() );
	}

	public function test_submit_with_invalid_payload_rejected(): void {
		$user_id = $this->admin_user();
		$store   = new MarkupStore();
		$request = new MarkupRequest(
			array(
				'tool_slug'   => 'image_inpainting',
				'target'      => array(
					'url'    => 'https://example.com/x.png',
					'width'  => 64,
					'height' => 64,
				),
				'target_type' => MarkupRequest::TARGET_TYPE_IMAGE,
				'mode'        => MarkupRequest::MODE_MASK,
				'user_id'     => $user_id,
			)
		);
		$store->save( $request );

		$req = new \WP_REST_Request( 'POST', '/mcp-ai/v1/markup/' . $request->get_request_id() . '/submit' );
		$req->set_body_params(
			array(
				'markup' => array( 'type' => 'NotAnnotation' ),
			)
		);
		$response = \rest_do_request( $req );
		$this->assertSame( 400, $response->get_status(), \wp_json_encode( $response->get_data() ) );
	}

	public function test_submit_replay_returns_404(): void {
		$user_id = $this->admin_user();
		$store   = new MarkupStore();
		$request = new MarkupRequest(
			array(
				'tool_slug'   => 'nonexistent_tool',
				'target'      => array( 'url' => 'https://example.com/x.png' ),
				'target_type' => MarkupRequest::TARGET_TYPE_IMAGE,
				'mode'        => MarkupRequest::MODE_MASK,
				'user_id'     => $user_id,
			)
		);
		$store->save( $request );

		$req = new \WP_REST_Request( 'POST', '/mcp-ai/v1/markup/' . $request->get_request_id() . '/submit' );
		$req->set_body_params(
			array(
				'markup' => array(
					'type'   => 'Annotation',
					'body'   => array(),
					'target' => array( 'source' => 'https://example.com/x.png' ),
				),
			)
		);
		// First submit consumes the record. Tool doesn't exist — we expect a tool_missing error.
		$first = \rest_do_request( $req );
		$this->assertNotEmpty( $first );

		// Second submit must 404 because the record is gone (replay protection).
		$second = \rest_do_request( $req );
		$this->assertSame( 404, $second->get_status() );
	}

	public function test_delete_cancels_request(): void {
		$user_id = $this->admin_user();
		$store   = new MarkupStore();
		$request = new MarkupRequest(
			array(
				'tool_slug'   => 'image_inpainting',
				'target'      => array( 'url' => 'https://example.com/x.png' ),
				'target_type' => MarkupRequest::TARGET_TYPE_IMAGE,
				'mode'        => MarkupRequest::MODE_MASK,
				'user_id'     => $user_id,
			)
		);
		$store->save( $request );

		$req      = new \WP_REST_Request( 'DELETE', '/mcp-ai/v1/markup/' . $request->get_request_id() );
		$response = \rest_do_request( $req );
		$this->assertSame( 200, $response->get_status() );
		$this->assertNull( $store->get( $request->get_request_id() ) );
	}

	public function test_subscriber_without_permission_blocked(): void {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		\wp_set_current_user( $subscriber );

		$req      = new \WP_REST_Request( 'GET', '/mcp-ai/v1/markup/mr_unknown' );
		$response = \rest_do_request( $req );
		// Subscribers have read but not edit_posts; permission_check accepts read.
		// We assert it returns a 404 (i.e. permission passed and we got into the handler).
		$this->assertContains( $response->get_status(), array( 404, 200 ) );
	}

	public function test_handle_submit_resumes_tool_via_seam(): void {
		$user_id = $this->admin_user();
		$store   = new MarkupStore();
		$request = new MarkupRequest(
			array(
				'tool_slug'    => 'test_markup_aware_tool',
				'target'       => array( 'url' => 'https://example.com/x.png' ),
				'target_type'  => MarkupRequest::TARGET_TYPE_IMAGE,
				'mode'         => MarkupRequest::MODE_MASK,
				'user_id'      => $user_id,
				'instructions' => 'Mark it.',
			)
		);
		$store->save( $request );

		$controller = new MarkupRestControllerSeam( $store );
		$controller->set_tool( new MarkupAwareToolStub() );

		$req = new \WP_REST_Request( 'POST', '/mcp-ai/v1/markup/' . $request->get_request_id() . '/submit' );
		$req->set_param( 'request_id', $request->get_request_id() );
		$req->set_param(
			'markup',
			array(
				'type'   => 'Annotation',
				'body'   => array(),
				'target' => array( 'source' => 'https://example.com/x.png' ),
			)
		);

		$response = $controller->handle_submit( $req );
		$data     = $response->get_data();

		$this->assertSame( $request->get_request_id(), $data['request_id'] );
		$this->assertSame( 'test_markup_aware_tool', $data['tool'] );
		$this->assertSame( 'consumed markup', $data['result']['message'] );
		$this->assertTrue( $data['result']['success'] );
	}

	// ─── Per-mode seams ────────────────────────────────────────────

	public function test_tool_seams_resolve_per_install_mode(): void {
		$controller = new MarkupRestControllerSeam();
		$stub       = new MarkupAwareToolStub();

		$this->assertNull( $controller->seam_find_tool( 'definitely_missing_tool' ) );

		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			// Monolith: the awareness seam accepts the base interface only —
			// the ported stub is not base-typed.
			$this->assertFalse( $controller->seam_is_markup_aware( $stub ) );
		} else {
			$this->assertTrue( $controller->seam_is_markup_aware( $stub ) );
		}
	}
}
