<?php
/**
 * Test suite for the Higgsfield API client.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test class for WP_MCP_AI_Higgsfield_Client.
 */
class Test_Higgsfield_Client extends WP_UnitTestCase {

	/**
	 * Configure test credentials and zero out polling delays.
	 */
	public function set_up() {
		parent::set_up();

		update_option(
			'wp_mcp_ai_settings',
			array(
				'higgsfield_api_key_id'     => 'key-1',
				'higgsfield_api_key_secret' => 'secret-1',
			)
		);

		if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) && method_exists( 'WP_MCP_AI_Admin_Settings', 'reset_settings_cache' ) ) {
			WP_MCP_AI_Admin_Settings::reset_settings_cache();
		}

		add_filter( 'wp_mcp_ai_higgsfield_poll_initial_delay', '__return_zero' );
	}

	/**
	 * Clean up HTTP mocks and credentials.
	 */
	public function tear_down() {
		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'wp_mcp_ai_higgsfield_poll_initial_delay' );
		parent::tear_down();
	}

	/**
	 * Load the client.
	 *
	 * @return WP_MCP_AI_Higgsfield_Client
	 */
	private function get_client() {
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-higgsfield-client.php';
		return new WP_MCP_AI_Higgsfield_Client();
	}

	/**
	 * Test credentials resolve from settings.
	 */
	public function test_credentials_from_settings() {
		$client = $this->get_client();
		$creds  = $client->get_credentials();

		$this->assertSame( 'key-1', $creds['key_id'] );
		$this->assertSame( 'secret-1', $creds['secret'] );
	}

	/**
	 * Test the auth header format (industry-standard Key {ID}:{SECRET}).
	 */
	public function test_auth_headers() {
		$client  = $this->get_client();
		$headers = $client->get_auth_headers();

		$this->assertSame( 'Key key-1:secret-1', $headers['Authorization'] );
		$this->assertSame( 'application/json', $headers['Content-Type'] );
	}

	/**
	 * Test submit_request parses the queued handle.
	 */
	public function test_submit_request_success() {
		$client = $this->get_client();

		add_filter(
			'pre_http_request',
			function () {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'status'     => 'queued',
							'request_id' => 'd7e6c0f3-6699-4f6c-bb45-2ad7fd9158ff',
							'status_url' => 'https://api.higgsfield.ai/requests/d7e6c0f3-6699-4f6c-bb45-2ad7fd9158ff/status',
							'cancel_url' => 'https://api.higgsfield.ai/requests/d7e6c0f3-6699-4f6c-bb45-2ad7fd9158ff/cancel',
						)
					),
				);
			},
			10,
			3
		);

		$result = $client->submit_request( '/higgsfield/cinema-studio/4.0', array( 'prompt' => 'Test' ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'queued', $result['status'] );
		$this->assertSame( 'd7e6c0f3-6699-4f6c-bb45-2ad7fd9158ff', $result['request_id'] );
	}

	/**
	 * Test submit_request surfaces provider errors.
	 */
	public function test_submit_request_error() {
		$client = $this->get_client();

		add_filter(
			'pre_http_request',
			function () {
				return array(
					'response' => array( 'code' => 401 ),
					'body'     => wp_json_encode( array( 'detail' => 'Invalid credentials' ) ),
				);
			},
			10,
			3
		);

		$result = $client->submit_request( '/higgsfield/cinema-studio/4.0', array( 'prompt' => 'Test' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_higgsfield_error', $result->get_error_code() );
		$this->assertSame( 'Invalid credentials', $result->get_error_message() );
	}

	/**
	 * Test get_request_status normalises the state.
	 */
	public function test_get_request_status() {
		$client = $this->get_client();

		add_filter(
			'pre_http_request',
			function () {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'status'     => 'in_progress',
							'request_id' => 'req-1',
						)
					),
				);
			},
			10,
			3
		);

		$status = $client->get_request_status( 'req-1' );

		$this->assertIsArray( $status );
		$this->assertSame( 'in_progress', $status['status'] );
		$this->assertSame( 'req-1', $status['request_id'] );
	}

	/**
	 * Test cancel_request on a 202 (canceled).
	 */
	public function test_cancel_request_success() {
		$client = $this->get_client();

		add_filter(
			'pre_http_request',
			function () {
				return array(
					'response' => array( 'code' => 202 ),
					'body'     => '',
				);
			},
			10,
			3
		);

		$result = $client->cancel_request( 'req-1' );

		$this->assertTrue( $result );
	}

	/**
	 * Test cancel_request on a 400 (already started).
	 */
	public function test_cancel_request_started() {
		$client = $this->get_client();

		add_filter(
			'pre_http_request',
			function () {
				return array(
					'response' => array( 'code' => 400 ),
					'body'     => wp_json_encode( array( 'detail' => 'The request has already started processing and can no longer be canceled.' ) ),
				);
			},
			10,
			3
		);

		$result = $client->cancel_request( 'req-1' );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_higgsfield_cancel_started', $result->get_error_code() );
	}

	/**
	 * Test wait_for_completion returns outputs on a completed request.
	 */
	public function test_wait_for_completion_completed() {
		$client = $this->get_client();

		add_filter(
			'pre_http_request',
			function () {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'status'     => 'completed',
							'request_id' => 'req-1',
							'video'      => array( 'url' => 'https://cdn.example.com/output.mp4' ),
						)
					),
				);
			},
			10,
			3
		);

		$outputs = $client->wait_for_completion( 'req-1', 60, 0 );

		$this->assertIsArray( $outputs );
		$this->assertSame( 'completed', $outputs['status'] );
		$this->assertSame( 'https://cdn.example.com/output.mp4', $outputs['video_url'] );
	}

	/**
	 * Test wait_for_completion maps failed requests to a WP_Error.
	 */
	public function test_wait_for_completion_failed() {
		$client = $this->get_client();

		add_filter(
			'pre_http_request',
			function () {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'status'     => 'failed',
							'request_id' => 'req-1',
							'error'      => 'Model error',
						)
					),
				);
			},
			10,
			3
		);

		$result = $client->wait_for_completion( 'req-1', 60, 0 );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_higgsfield_generation_failed', $result->get_error_code() );
		$this->assertSame( 'Model error', $result->get_error_message() );
	}

	/**
	 * Test wait_for_completion maps nsfw requests to a WP_Error.
	 */
	public function test_wait_for_completion_nsfw() {
		$client = $this->get_client();

		add_filter(
			'pre_http_request',
			function () {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( array( 'status' => 'nsfw', 'request_id' => 'req-1' ) ),
				);
			},
			10,
			3
		);

		$result = $client->wait_for_completion( 'req-1', 60, 0 );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_higgsfield_nsfw', $result->get_error_code() );
	}

	/**
	 * Test wait_for_completion aborts on a 404 (permanent error).
	 */
	public function test_wait_for_completion_permanent_http_error() {
		$client = $this->get_client();

		add_filter(
			'pre_http_request',
			function () {
				return array(
					'response' => array( 'code' => 404 ),
					'body'     => wp_json_encode( array( 'detail' => 'Request not found' ) ),
				);
			},
			10,
			3
		);

		$result = $client->wait_for_completion( 'req-1', 60, 0 );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_higgsfield_status_error', $result->get_error_code() );
	}

	/**
	 * Test extract_outputs handles video, images, and audio.
	 */
	public function test_extract_outputs() {
		$client = $this->get_client();

		$outputs = $client->extract_outputs(
			array(
				'status'     => 'completed',
				'request_id' => 'req-1',
				'video'      => array( 'url' => 'https://cdn.example.com/video.mp4' ),
				'images'     => array(
					array( 'url' => 'https://cdn.example.com/1.png' ),
					array( 'url' => 'https://cdn.example.com/2.png' ),
				),
				'audios'     => array( array( 'url' => 'https://cdn.example.com/audio.mp3' ) ),
			)
		);

		$this->assertSame( 'https://cdn.example.com/video.mp4', $outputs['video_url'] );
		$this->assertCount( 2, $outputs['image_urls'] );
		$this->assertCount( 1, $outputs['audio_urls'] );
	}

	/**
	 * Test download_file rejects invalid URLs without making a request.
	 */
	public function test_download_file_rejects_invalid_url() {
		$client = $this->get_client();

		$result = $client->download_file( 'javascript:alert(1)' );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_higgsfield_invalid_media_url', $result->get_error_code() );
	}
}
