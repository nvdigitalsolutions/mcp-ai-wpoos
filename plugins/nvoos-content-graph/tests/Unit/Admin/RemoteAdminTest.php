<?php
declare(strict_types=1);

namespace NvoosContentGraph\Tests\Unit\Admin;

use NvoosContentGraph\Graph\Db;
use NvoosContentGraph\Schema;
use WPAjaxDieContinueException;
use WP_Ajax_UnitTestCase;

/**
 * Unit tests for the Remote Sources admin AJAX handlers.
 *
 * Focuses on the connection-test endpoint: testing an unsaved config
 * from the Add Source modal, testing saved sources (enabled or not),
 * and the permission gate. HTTP is mocked via pre_http_request.
 *
 * @since 1.0.9
 */
class RemoteAdminTest extends WP_Ajax_UnitTestCase {

	/**
	 * Set up an administrator and fire plugins_loaded (mirrors
	 * EnricherTest, guarantees the plugin tables + AJAX hooks exist).
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		if ( ! did_action( 'plugins_loaded' ) ) {
			do_action( 'plugins_loaded' );
		}

		// Plugin::registerAdmin() only runs when is_admin() — which is false
		// in unit tests — so register the AJAX hooks explicitly here.
		if ( ! has_action( 'wp_ajax_nvoos_content_graph_test_remote_source' ) ) {
			$remoteAdmin = new \NvoosContentGraph\Admin\RemoteAdmin();
			$remoteAdmin->register();
		}

		// _handleAjax() fires admin_init, whose core/plugin/theme update
		// checks would reach out to WordPress.org. Seed fresh check
		// timestamps so every update check short-circuits locally.
		foreach ( array( 'update_core', 'update_plugins', 'update_themes' ) as $key ) {
			if ( false === get_site_transient( $key ) ) {
				$fresh                  = new \stdClass();
				$fresh->last_checked    = time();
				$fresh->checked         = array();
				$fresh->version_checked = $GLOBALS['wp_version'] ?? '';
				set_site_transient( $key, $fresh );
			}
		}

		$this->_setRole( 'administrator' );
	}

	/**
	 * Tear down test fixtures.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		remove_all_filters( 'pre_http_request' );
		remove_all_filters( Schema::FILTER_ALLOW_PRIVATE_URLS );
		parent::tearDown();
	}

	/**
	 * Run the test-connection AJAX action and return the decoded payload.
	 *
	 * wp_send_json_*() terminates through the wp-phpunit die handler,
	 * which throws WPAjaxDieContinueException after capturing the JSON
	 * output into `_last_response`.
	 *
	 * @param array<string,mixed> $post POST payload (nonce/action auto-added).
	 * @return array<string,mixed> Decoded JSON response.
	 */
	private function runTestAjax( array $post ): array {
		$_POST           = $post;
		$_POST['nonce']  = wp_create_nonce( 'nvoos_content_graph_remote_action' );
		$_POST['action'] = 'nvoos_content_graph_test_remote_source';

		try {
			$this->_handleAjax( 'nvoos_content_graph_test_remote_source' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected: wp_send_json_* ends the request via wp_die().
		}

		$raw = (string) $this->_last_response;
		$this->assertNotSame( '', $raw, 'The AJAX handler produced no output.' );

		$payload = json_decode( $raw, true );
		$this->assertIsArray( $payload, 'The AJAX response must be JSON.' );

		return $payload;
	}

	/**
	 * Mock a JSON HTTP response for the Generic REST driver.
	 *
	 * @param array<string,mixed> $body JSON body to return.
	 * @return void
	 */
	private function mockItemsEndpoint( array $body ): void {
		add_filter( Schema::FILTER_ALLOW_PRIVATE_URLS, '__return_true' );
		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) use ( $body ) {
				// Only mock the fixture endpoints — the admin_init update
				// check must fall through to a WP_Error, never fake JSON.
				if ( false === strpos( (string) $url, '.test' ) ) {
					return new \WP_Error( 'http_request_failed', 'Not mocked.' );
				}
				return array(
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'body'     => wp_json_encode( $body ),
				);
			},
			10,
			3
		);
	}

	/**
	 * The modal's unsaved-config path tests the typed-in config directly.
	 *
	 * @return void
	 */
	public function test_unsaved_config_test_returns_probe_message(): void {
		$this->mockItemsEndpoint(
			array(
				'data' => array(
					'items' => array(
						array(
							'id'   => 1,
							'name' => 'One',
						),
					),
				),
			)
		);

		$payload = $this->runTestAjax(
			array(
				'driver' => 'generic_rest',
				'config' => array(
					'base_url'     => 'https://api.test/items',
					'path_results' => 'data.items',
				),
			)
		);

		$this->assertTrue( $payload['success'] );
		$this->assertArrayHasKey( 'message', $payload['data'] );
		$this->assertStringContainsString( '1', $payload['data']['message'] );
	}

	/**
	 * The unsaved-config path refuses unknown drivers.
	 *
	 * @return void
	 */
	public function test_unsaved_config_rejects_unknown_driver(): void {
		$payload = $this->runTestAjax(
			array(
				'driver' => 'does_not_exist',
				'config' => array(),
			)
		);

		$this->assertFalse( $payload['success'] );
		$this->assertStringContainsString( 'Unknown driver', $payload['data'] );
	}

	/**
	 * A saved source can be tested even when it is disabled — testing is
	 * the step users take BEFORE enabling an untrusted source.
	 *
	 * @return void
	 */
	public function test_saved_source_can_be_tested_while_disabled(): void {
		$this->mockItemsEndpoint(
			array(
				'items' => array(
					array(
						'id'   => 1,
						'name' => 'One',
					),
				),
			)
		);

		Db::saveRemoteSource(
			array(
				'slug'    => 'ajax_test_source',
				'driver'  => 'generic_rest',
				'label'   => 'AJAX Test Source',
				'enabled' => 0,
				'config'  => array(
					'base_url'     => 'https://api.test/items',
					'path_results' => 'items',
				),
			)
		);

		$payload = $this->runTestAjax( array( 'slug' => 'ajax_test_source' ) );

		Db::deleteRemoteSource( 'ajax_test_source' );

		$this->assertTrue( $payload['success'] );
		$this->assertStringContainsString( '1', $payload['data']['message'] );
	}

	/**
	 * A saved source whose endpoint fails reports the driver's error.
	 *
	 * @return void
	 */
	public function test_saved_source_reports_connection_failure(): void {
		add_filter( Schema::FILTER_ALLOW_PRIVATE_URLS, '__return_true' );
		add_filter(
			'pre_http_request',
			static function () {
				return new \WP_Error( 'http_request_failed', 'Connection refused.' );
			}
		);

		Db::saveRemoteSource(
			array(
				'slug'    => 'ajax_broken_source',
				'driver'  => 'generic_rest',
				'label'   => 'Broken Source',
				'enabled' => 1,
				// Unique URL: the HTTP client caches GET responses in
				// transients, so reusing another test's URL would serve its
				// cached 200 instead of reaching the mock.
				'config'  => array( 'base_url' => 'https://broken.test/items' ),
			)
		);

		$payload = $this->runTestAjax( array( 'slug' => 'ajax_broken_source' ) );

		Db::deleteRemoteSource( 'ajax_broken_source' );

		$this->assertFalse( $payload['success'] );
	}

	/**
	 * A missing slug (and no driver) is rejected.
	 *
	 * @return void
	 */
	public function test_missing_slug_is_rejected(): void {
		$payload = $this->runTestAjax( array() );

		$this->assertFalse( $payload['success'] );
		$this->assertStringContainsString( 'slug is required', $payload['data'] );
	}

	/**
	 * Non-administrators are refused before any outbound request happens.
	 *
	 * @return void
	 */
	public function test_editors_are_refused(): void {
		$this->_setRole( 'editor' );

		$payload = $this->runTestAjax(
			array(
				'driver' => 'generic_rest',
				'config' => array( 'base_url' => 'https://api.test/items' ),
			)
		);

		$this->assertFalse( $payload['success'] );
		$this->assertStringContainsString( 'Permission denied', $payload['data'] );
	}
}
