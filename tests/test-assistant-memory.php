<?php
/**
 * Tests for assistant memory integration.
 *
 * @package WP_MCP_AI
 */
class WP_MCP_AI_Assistant_Memory_Test extends WP_UnitTestCase {
	use WP_MCP_AI_Docx_Test_Helper;

	/**
	 * Ensure chat requests include assistant memory payloads.
	 */
	public function test_chat_request_includes_memory_payload() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$assistant_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_title'  => 'Memory Assistant',
				'post_status' => 'publish',
			)
		);

		$content = str_repeat( 'Knowledge line. ', 400 );
		$upload  = wp_upload_bits( 'knowledge.txt', null, $content );
		$this->assertFalse( $upload['error'] );

		$attachment_id = self::factory()->attachment->create_upload_object( $upload['file'] );
		wp_update_post(
			array(
				'ID'         => $attachment_id,
				'post_title' => 'Knowledge Base',
			)
		);

		update_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_MEMORY_FILES, array( $attachment_id ) );
		update_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_VECTOR_STORE_ID, 'store_123' );

		if ( isset( $GLOBALS['wp_mcp_ai_rest_controller'] ) ) {
			remove_action( 'rest_api_init', array( $GLOBALS['wp_mcp_ai_rest_controller'], 'register_routes' ) );
		}

		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'create_chat_completion' ) )
			->getMock();

		$mock_client
			->expects( $this->once() )
			->method( 'create_chat_completion' )
			->with(
				$this->callback(
					function ( $messages ) {
						return is_array( $messages ) && ! empty( $messages );
					}
				),
				$this->callback(
					function ( $options ) use ( $attachment_id ) {
						$this->assertArrayHasKey( 'provider', $options );
						$this->assertSame( 'openai', $options['provider'] );
						$this->assertArrayHasKey( 'memory_documents', $options );
						$this->assertArrayHasKey( 'memory_files', $options );
						$this->assertSame( array( $attachment_id ), $options['memory_files'] );
						$this->assertSame( 'store_123', $options['vector_store_id'] );

						$documents = $options['memory_documents'];
						$this->assertNotEmpty( $documents );
						$document = $documents[0];
						$this->assertArrayHasKey( 'chunks', $document );
						$this->assertNotEmpty( $document['chunks'] );
						$first_chunk = $document['chunks'][0];
						$this->assertIsString( $first_chunk );
						$this->assertNotEmpty( trim( $first_chunk ) );
						$this->assertLessThanOrEqual( WP_MCP_AI_REST::MEMORY_MAX_DOCUMENT_CHARS + 50, strlen( $first_chunk ) );

						return true;
					}
				)
			)
			->willReturn(
				array(
					'id'      => 'chatcmpl-test',
					'choices' => array(),
				)
			);

		$registry                             = WP_MCP_AI_Tool_Registry::get_instance();
		$GLOBALS['wp_mcp_ai_rest_controller'] = new WP_MCP_AI_REST( $registry, $mock_client );

		rest_get_server();
		do_action( 'rest_api_init' );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$request->set_param( 'assistant_id', $assistant_id );
		$request->set_param(
			'messages',
			array(
				array(
					'role'    => 'user',
					'content' => 'Hello',
				),
			)
		);
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * Ensure DOCX memory files are extracted and forwarded as text chunks.
	 */
	public function test_docx_memory_document_is_extracted() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$assistant_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_title'  => 'DOCX Memory Assistant',
				'post_status' => 'publish',
			)
		);

		$upload = $this->create_docx_upload( 'knowledge.docx', "First line.\nSecond line." );

		$attachment_id = self::factory()->attachment->create_upload_object( $upload['file'] );

		wp_update_post(
			array(
				'ID'          => $attachment_id,
				'post_title'  => 'DOCX Knowledge',
				'post_author' => $user_id,
				'post_status' => 'inherit',
			)
		);

		update_post_meta(
			$assistant_id,
			WP_MCP_AI_Assistant_CPT::META_MEMORY_FILES,
			array( $attachment_id )
		);

		if ( isset( $GLOBALS['wp_mcp_ai_rest_controller'] ) ) {
			remove_action( 'rest_api_init', array( $GLOBALS['wp_mcp_ai_rest_controller'], 'register_routes' ) );
		}

		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'create_chat_completion' ) )
			->getMock();

		$mock_client
			->expects( $this->once() )
			->method( 'create_chat_completion' )
			->with(
				$this->callback(
					function ( $messages ) {
						return is_array( $messages ) && ! empty( $messages );
					}
				),
				$this->callback(
					function ( $options ) use ( $attachment_id ) {
						$this->assertArrayHasKey( 'provider', $options );
						$this->assertSame( 'openai', $options['provider'] );
						$this->assertArrayHasKey( 'memory_documents', $options );
						$this->assertCount( 1, $options['memory_documents'] );

						$document = $options['memory_documents'][0];
						$this->assertSame( $attachment_id, $document['id'] );
						$this->assertIsArray( $document['chunks'] );
						$this->assertNotEmpty( $document['chunks'] );

						$chunk = $document['chunks'][0];
						$this->assertStringContainsString( 'First line.', $chunk );
						$this->assertStringContainsString( 'Second line.', $chunk );

						return true;
					}
				)
			)
			->willReturn(
				array(
					'id'      => 'chatcmpl-docx',
					'choices' => array(),
				)
			);

		$registry                             = WP_MCP_AI_Tool_Registry::get_instance();
		$GLOBALS['wp_mcp_ai_rest_controller'] = new WP_MCP_AI_REST( $registry, $mock_client );

		rest_get_server();
		do_action( 'rest_api_init' );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$request->set_param( 'assistant_id', $assistant_id );
		$request->set_param(
			'messages',
			array(
				array(
					'role'    => 'user',
					'content' => 'Hello',
				),
			)
		);
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * Ensure private memory files that the user cannot read are excluded.
	 */
	public function test_private_memory_files_are_excluded_from_payload() {
		$author_id = self::factory()->user->create( array( 'role' => 'author' ) );
		$admin_id  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $author_id );

		$assistant_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_title'  => 'Restricted Memory Assistant',
				'post_status' => 'publish',
			)
		);

		$public_attachment_id  = $this->create_memory_attachment( 'knowledge.txt', 'Shared knowledge.', $author_id, 'inherit' );
		$private_attachment_id = $this->create_memory_attachment( 'secret.txt', 'Hidden knowledge.', $admin_id, 'private' );

		update_post_meta(
			$assistant_id,
			WP_MCP_AI_Assistant_CPT::META_MEMORY_FILES,
			array( $public_attachment_id, $private_attachment_id )
		);

		if ( isset( $GLOBALS['wp_mcp_ai_rest_controller'] ) ) {
			remove_action( 'rest_api_init', array( $GLOBALS['wp_mcp_ai_rest_controller'], 'register_routes' ) );
		}

		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'create_chat_completion' ) )
			->getMock();

		$mock_client
			->expects( $this->once() )
			->method( 'create_chat_completion' )
			->with(
				$this->callback(
					function ( $messages ) {
						return is_array( $messages ) && ! empty( $messages );
					}
				),
				$this->callback(
					function ( $options ) use ( $public_attachment_id, $private_attachment_id ) {
						$this->assertArrayHasKey( 'provider', $options );
						$this->assertSame( 'openai', $options['provider'] );
						$this->assertArrayHasKey( 'memory_files', $options );
						$this->assertSame( array( $public_attachment_id ), $options['memory_files'] );

						$this->assertArrayHasKey( 'memory_documents', $options );
						$this->assertCount( 1, $options['memory_documents'] );
						$document = $options['memory_documents'][0];

						$this->assertSame( $public_attachment_id, $document['id'] );
						$this->assertIsArray( $document['chunks'] );
						$this->assertNotEmpty( $document['chunks'] );

						return true;
					}
				)
			)
			->willReturn(
				array(
					'id'      => 'chatcmpl-test',
					'choices' => array(),
				)
			);

		$registry                             = WP_MCP_AI_Tool_Registry::get_instance();
		$GLOBALS['wp_mcp_ai_rest_controller'] = new WP_MCP_AI_REST( $registry, $mock_client );

		rest_get_server();
		do_action( 'rest_api_init' );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$request->set_param( 'assistant_id', $assistant_id );
		$request->set_param(
			'messages',
			array(
				array(
					'role'    => 'user',
					'content' => 'Hello',
				),
			)
		);
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * Ensure oversized memory files are rejected before being read.
	 */
	public function test_request_rejected_when_memory_file_too_large() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$assistant_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_title'  => 'Large File Assistant',
				'post_status' => 'publish',
			)
		);

		$large_content = str_repeat( 'A', 2048 );
		$attachment_id = $this->create_memory_attachment( 'too-large.txt', $large_content, $admin_id, 'inherit' );

		update_post_meta(
			$assistant_id,
			WP_MCP_AI_Assistant_CPT::META_MEMORY_FILES,
			array( $attachment_id )
		);

		$limit_filter = function ( $max_bytes, $file_id ) {
			return 100;
		};

		add_filter( 'wp_mcp_ai_memory_max_file_bytes', $limit_filter, 10, 2 );

		if ( isset( $GLOBALS['wp_mcp_ai_rest_controller'] ) ) {
			remove_action( 'rest_api_init', array( $GLOBALS['wp_mcp_ai_rest_controller'], 'register_routes' ) );
		}

		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'create_chat_completion' ) )
			->getMock();

		$mock_client
			->expects( $this->never() )
			->method( 'create_chat_completion' );

		$registry                             = WP_MCP_AI_Tool_Registry::get_instance();
		$GLOBALS['wp_mcp_ai_rest_controller'] = new WP_MCP_AI_REST( $registry, $mock_client );

		rest_get_server();
		do_action( 'rest_api_init' );

		$response = null;

		try {
			$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
			$request->set_param( 'assistant_id', $assistant_id );
			$request->set_param(
				'messages',
				array(
					array(
						'role'    => 'user',
						'content' => 'Hello',
					),
				)
			);
			$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

			$response = rest_get_server()->dispatch( $request );
		} finally {
			remove_filter( 'wp_mcp_ai_memory_max_file_bytes', $limit_filter, 10 );
		}

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 400, $response->get_status() );

		$data = $response->get_data();
		$this->assertSame( 'wp_mcp_ai_memory_file_too_large', $data['code'] );
		$this->assertArrayHasKey( 'data', $data );
		$this->assertArrayHasKey( 'file_id', $data['data'] );
		$this->assertSame( $attachment_id, $data['data']['file_id'] );
	}

	/**
	 * Ensure requests fail when no memory files remain after permission checks.
	 */
	public function test_request_rejected_when_all_memory_files_forbidden() {
		$author_id = self::factory()->user->create( array( 'role' => 'author' ) );
		$admin_id  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $author_id );

		$assistant_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_title'  => 'Forbidden Memory Assistant',
				'post_status' => 'publish',
			)
		);

		$private_attachment_id = $this->create_memory_attachment( 'secret.txt', 'Hidden knowledge.', $admin_id, 'private' );

		update_post_meta(
			$assistant_id,
			WP_MCP_AI_Assistant_CPT::META_MEMORY_FILES,
			array( $private_attachment_id )
		);

		if ( isset( $GLOBALS['wp_mcp_ai_rest_controller'] ) ) {
			remove_action( 'rest_api_init', array( $GLOBALS['wp_mcp_ai_rest_controller'], 'register_routes' ) );
		}

		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'create_chat_completion' ) )
			->getMock();

		$mock_client
			->expects( $this->never() )
			->method( 'create_chat_completion' );

		$registry                             = WP_MCP_AI_Tool_Registry::get_instance();
		$GLOBALS['wp_mcp_ai_rest_controller'] = new WP_MCP_AI_REST( $registry, $mock_client );

		rest_get_server();
		do_action( 'rest_api_init' );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$request->set_param( 'assistant_id', $assistant_id );
		$request->set_param(
			'messages',
			array(
				array(
					'role'    => 'user',
					'content' => 'Hello',
				),
			)
		);
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 403, $response->get_status() );

		$data = $response->get_data();
		$this->assertSame( 'wp_mcp_ai_memory_files_forbidden', $data['code'] );
		$this->assertArrayHasKey( 'forbidden_ids', $data['data'] );
		$this->assertSame( array( $private_attachment_id ), $data['data']['forbidden_ids'] );
	}

	/**
	 * Ensure guest sessions can access public memory attachments.
	 */
	public function test_guest_request_uses_public_memory_files() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$assistant_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_title'  => 'Guest Memory Assistant',
				'post_status' => 'publish',
				'post_author' => $admin_id,
			)
		);

		$attachment_id = $this->create_memory_attachment( 'guest-knowledge.txt', 'Guest visible knowledge.', $admin_id, 'inherit' );

		wp_update_post(
			array(
				'ID'          => $attachment_id,
				'post_parent' => $assistant_id,
			)
		);

		update_post_meta(
			$assistant_id,
			WP_MCP_AI_Assistant_CPT::META_MEMORY_FILES,
			array( $attachment_id )
		);

		$token = WP_MCP_AI_Shortcode::generate_guest_token( $assistant_id );
		$this->assertNotEmpty( $token );

		if ( isset( $GLOBALS['wp_mcp_ai_rest_controller'] ) ) {
			remove_action( 'rest_api_init', array( $GLOBALS['wp_mcp_ai_rest_controller'], 'register_routes' ) );
		}

		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'create_chat_completion' ) )
			->getMock();

		$mock_client
			->expects( $this->once() )
			->method( 'create_chat_completion' )
			->with(
				$this->callback(
					function ( $messages ) {
						return is_array( $messages ) && ! empty( $messages );
					}
				),
				$this->callback(
					function ( $options ) use ( $attachment_id ) {
						$this->assertArrayHasKey( 'memory_files', $options );
						$this->assertSame( array( $attachment_id ), $options['memory_files'] );

						$this->assertArrayHasKey( 'memory_documents', $options );
						$this->assertCount( 1, $options['memory_documents'] );

						$document = $options['memory_documents'][0];
						$this->assertSame( $attachment_id, $document['id'] );
						$this->assertIsArray( $document['chunks'] );
						$this->assertNotEmpty( $document['chunks'] );

						return true;
					}
				)
			)
			->willReturn(
				array(
					'id'      => 'chatcmpl-guest',
					'choices' => array(),
				)
			);

		$registry                             = WP_MCP_AI_Tool_Registry::get_instance();
		$GLOBALS['wp_mcp_ai_rest_controller'] = new WP_MCP_AI_REST( $registry, $mock_client );

		rest_get_server();
		do_action( 'rest_api_init' );

		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$request->set_param( 'assistant_id', $assistant_id );
		$request->set_param(
			'messages',
			array(
				array(
					'role'    => 'user',
					'content' => 'Hello',
				),
			)
		);
		$request->set_header( 'X-WP-MCP-AI-Guest', $token );

		$response = rest_get_server()->dispatch( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * Create an attachment for use as assistant memory.
	 *
	 * @param string $filename File name.
	 * @param string $contents File contents.
	 * @param int    $author_id Post author.
	 * @param string $status Post status.
	 * @return int
	 */
	protected function create_memory_attachment( $filename, $contents, $author_id, $status ) {
		$upload = wp_upload_bits( $filename, null, $contents );
		$this->assertFalse( $upload['error'] );

		$attachment_id = self::factory()->attachment->create_upload_object( $upload['file'] );

		wp_update_post(
			array(
				'ID'          => $attachment_id,
				'post_title'  => $filename,
				'post_author' => $author_id,
				'post_status' => $status,
			)
		);

		return $attachment_id;
	}
}
