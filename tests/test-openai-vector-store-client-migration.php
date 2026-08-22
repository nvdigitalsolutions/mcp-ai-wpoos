<?php
/**
 * Tests for the Assistants API → Responses API vector store migration.
 *
 * Verifies that the WP OpenAI client:
 *  - never sends the deprecated OpenAI-Beta: assistants=v2 header,
 *  - routes file ingestion through the file_batches endpoint,
 *  - polls batches to a terminal state,
 *  - falls back to headerless single-file adds on batch 404,
 *  - normalizes per-file results for the tool contract.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Client migration tests.
 */
class Test_OpenAI_Vector_Store_Client_Migration extends WP_UnitTestCase {

	use WP_MCP_AI_HTTP_Test_Helper;

	/**
	 * Captured request header sets, one entry per intercepted request.
	 *
	 * @var array<int,array<string,string>>
	 */
	private $captured_headers = array();

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		update_option( 'wp_mcp_ai_settings', array( 'openai_api_key' => 'sk-test' ) );
		$this->init_http_stubs();
		$this->captured_headers = array();
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		$this->reset_http_stubs();
		delete_option( 'wp_mcp_ai_settings' );
		parent::tearDown();
	}

	/**
	 * Register a callable stub that returns the given body and records headers.
	 *
	 * @param array $body Decoded response body.
	 * @param int   $status HTTP status code.
	 */
	private function stub_with_header_capture( array $body, $status = 200 ) {
		$this->mock_http_response_callable(
			function ( $url, $args ) use ( $body, $status ) {
				$this->captured_headers[] = isset( $args['headers'] ) ? $args['headers'] : array();

				return array(
					'response' => array(
						'code'    => $status,
						'message' => 200 === $status ? 'OK' : 'Error',
					),
					'body'     => wp_json_encode( $body ),
				);
			}
		);
	}

	/**
	 * Assert no intercepted request carried the Assistants beta header.
	 */
	private function assert_no_beta_header_sent() {
		$this->assertNotEmpty( $this->captured_headers, 'Expected at least one intercepted request.' );

		foreach ( $this->captured_headers as $headers ) {
			$this->assertArrayNotHasKey( 'OpenAI-Beta', $headers, 'The Assistants beta header must not be sent.' );
		}
	}

	// ───────────────────────────────────────────────────────────────────────
	// Header removal across the eight client methods
	// ───────────────────────────────────────────────────────────────────────

	/**
	 * Create_vector_store must not send the beta header.
	 */
	public function test_create_vector_store_sends_no_beta_header() {
		$this->stub_with_header_capture(
			array(
				'id'     => 'vs_1',
				'name'   => 'KB',
				'status' => 'completed',
			)
		);

		$result = ( new WP_MCP_AI_OpenAI_Client() )->create_vector_store( 'KB' );

		$this->assertNotWPError( $result );
		$this->assert_no_beta_header_sent();
	}

	/**
	 * List_vector_stores must not send the beta header.
	 */
	public function test_list_vector_stores_sends_no_beta_header() {
		$this->stub_with_header_capture(
			array(
				'data'     => array(),
				'has_more' => false,
			)
		);

		$result = ( new WP_MCP_AI_OpenAI_Client() )->list_vector_stores();

		$this->assertNotWPError( $result );
		$this->assert_no_beta_header_sent();
	}

	/**
	 * Retrieve_vector_store must not send the beta header.
	 */
	public function test_retrieve_vector_store_sends_no_beta_header() {
		$this->stub_with_header_capture(
			array(
				'id'     => 'vs_1',
				'name'   => 'KB',
				'status' => 'completed',
			)
		);

		$result = ( new WP_MCP_AI_OpenAI_Client() )->retrieve_vector_store( 'vs_1' );

		$this->assertNotWPError( $result );
		$this->assert_no_beta_header_sent();
	}

	/**
	 * Delete_vector_store must not send the beta header.
	 */
	public function test_delete_vector_store_sends_no_beta_header() {
		$this->stub_with_header_capture(
			array(
				'id'      => 'vs_1',
				'deleted' => true,
			)
		);

		$result = ( new WP_MCP_AI_OpenAI_Client() )->delete_vector_store( 'vs_1' );

		$this->assertNotWPError( $result );
		$this->assert_no_beta_header_sent();
	}

	/**
	 * List_vector_store_files must not send the beta header.
	 */
	public function test_list_vector_store_files_sends_no_beta_header() {
		$this->stub_with_header_capture(
			array(
				'data'     => array(),
				'has_more' => false,
				'first_id' => null,
				'last_id'  => null,
			)
		);

		$result = ( new WP_MCP_AI_OpenAI_Client() )->list_vector_store_files( 'vs_1' );

		$this->assertNotWPError( $result );
		$this->assert_no_beta_header_sent();
	}

	/**
	 * Remove_vector_store_file must not send the beta header.
	 */
	public function test_remove_vector_store_file_sends_no_beta_header() {
		$this->stub_with_header_capture(
			array(
				'id'      => 'file-1',
				'deleted' => true,
			)
		);

		$result = ( new WP_MCP_AI_OpenAI_Client() )->remove_vector_store_file( 'vs_1', 'file-1' );

		$this->assertNotWPError( $result );
		$this->assert_no_beta_header_sent();
	}

	/**
	 * Search_vector_store must not send the beta header.
	 */
	public function test_search_vector_store_sends_no_beta_header() {
		$this->stub_with_header_capture(
			array(
				'data'     => array(),
				'has_more' => false,
			)
		);

		$result = ( new WP_MCP_AI_OpenAI_Client() )->search_vector_store( 'vs_1', 'query' );

		$this->assertNotWPError( $result );
		$this->assert_no_beta_header_sent();
	}

	// ───────────────────────────────────────────────────────────────────────
	// file_batches ingestion path
	// ───────────────────────────────────────────────────────────────────────

	/**
	 * Add_vector_store_files must use file_batches and normalize per-file results.
	 */
	public function test_add_vector_store_files_uses_file_batches() {
		$this->mock_http_response_callable(
			function ( $url, $args ) {
				$this->captured_headers[] = isset( $args['headers'] ) ? $args['headers'] : array();

				if ( false !== strpos( $url, '/file_batches/vsfb_1/files' ) ) {
					$body = array(
						'data'     => array(
							array(
								'id'     => 'file-1',
								'status' => 'completed',
							),
						),
						'has_more' => false,
					);
				} elseif ( false !== strpos( $url, '/file_batches/vsfb_1' ) ) {
					$body = array(
						'id'              => 'vsfb_1',
						'status'          => 'completed',
						'vector_store_id' => 'vs_x',
					);
				} else {
					$body = array(
						'id'              => 'vsfb_1',
						'object'          => 'vector_store.files_batch',
						'status'          => 'in_progress',
						'vector_store_id' => 'vs_x',
					);
				}

				return array(
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'body'     => wp_json_encode( $body ),
				);
			}
		);

		$result = ( new WP_MCP_AI_OpenAI_Client() )->add_vector_store_files( 'vs_x', array( 'file-1' ) );

		$this->assertNotWPError( $result );
		$this->assert_http_request_made_to( '/file_batches' );
		$this->assertSame( 'vsfb_1', $result['batch_id'] );
		$this->assertSame( 'completed', $result['status'] );
		$this->assertSame( 'completed', $result['results'][0]['status'] );
		$this->assertSame( 'file-1', $result['results'][0]['file_id'] );
		$this->assert_no_beta_header_sent();
	}

	/**
	 * Add_vector_store_files must report in_progress when the poll cap is hit.
	 */
	public function test_add_vector_store_files_reports_in_progress_at_poll_cap() {
		$this->mock_http_response_callable(
			function ( $url, $args ) {
				$this->captured_headers[] = isset( $args['headers'] ) ? $args['headers'] : array();

				if ( false !== strpos( $url, '/file_batches/vsfb_1' ) ) {
					$body = array(
						'id'              => 'vsfb_1',
						'status'          => 'in_progress',
						'vector_store_id' => 'vs_x',
					);
				} else {
					$body = array(
						'id'              => 'vsfb_1',
						'object'          => 'vector_store.files_batch',
						'status'          => 'in_progress',
						'vector_store_id' => 'vs_x',
					);
				}

				return array(
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'body'     => wp_json_encode( $body ),
				);
			}
		);

		$result = ( new WP_MCP_AI_OpenAI_Client() )->add_vector_store_files(
			'vs_x',
			array( 'file-1' ),
			array(
				'poll'             => true,
				'poll_max_seconds' => 1,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertSame( 'in_progress', $result['status'] );
		$this->assertSame( 'in_progress', $result['results'][0]['status'] );
		$this->assert_no_beta_header_sent();
	}

	/**
	 * Add_vector_store_files must fall back to headerless single-file adds on 404.
	 */
	public function test_add_vector_store_files_falls_back_on_batch_404() {
		$this->mock_http_response_callable(
			function ( $url, $args ) {
				$this->captured_headers[] = isset( $args['headers'] ) ? $args['headers'] : array();

				if ( false !== strpos( $url, '/file_batches' ) ) {
					return array(
						'response' => array(
							'code'    => 404,
							'message' => 'Not Found',
						),
						'body'     => wp_json_encode( array( 'error' => array( 'message' => 'Not found' ) ) ),
					);
				}

				return array(
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'body'     => wp_json_encode(
						array(
							'id'     => 'file-1',
							'status' => 'completed',
						)
					),
				);
			}
		);

		$result = ( new WP_MCP_AI_OpenAI_Client() )->add_vector_store_files( 'vs_x', array( 'file-1' ) );

		$this->assertNotWPError( $result );
		$this->assert_http_request_made_to( '/files' );
		$this->assertSame( 'completed', $result['results'][0]['status'] );
		$this->assert_no_beta_header_sent();
	}

	/**
	 * Non-404 batch errors must surface (no fallback) with the HTTP status.
	 */
	public function test_add_vector_store_files_surfaces_non_404_batch_errors() {
		$this->mock_http_response( '/file_batches', 400, array( 'error' => array( 'message' => 'Invalid file.' ) ) );

		$result = ( new WP_MCP_AI_OpenAI_Client() )->add_vector_store_files( 'vs_x', array( 'file-1' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_openai_vector_store_error', $result->get_error_code() );

		$data = $result->get_error_data();
		$this->assertSame( 400, $data['status'] );
	}

	/**
	 * Create_vector_store_file_batch must reject batches above the 2000-file cap.
	 */
	public function test_create_vector_store_file_batch_rejects_over_2000_files() {
		$file_ids = array();
		for ( $i = 0; $i < 2001; $i++ ) {
			$file_ids[] = 'file-' . $i;
		}

		$result = ( new WP_MCP_AI_OpenAI_Client() )->create_vector_store_file_batch( 'vs_x', $file_ids );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_too_many_file_ids', $result->get_error_code() );
	}
}
