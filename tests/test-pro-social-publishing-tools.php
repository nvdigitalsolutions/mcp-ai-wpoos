<?php
/**
 * Tests covering social publishing tools.
 */
class WP_MCP_AI_Social_Publishing_Tools_Test extends WP_UnitTestCase {

	/**
	 * Ensure the Meta publishing tool is registered and can submit a Facebook post.
	 */
	public function test_post_facebook_instagram_tool_executes_facebook_request() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$tool = $registry->get_tool( 'post_facebook_instagram' );

		$this->assertInstanceOf( WP_MCP_AI_Pro_Tool_Post_Facebook_Instagram::class, $tool );

		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );

		$requests = array();

		$filter = function ( $preempt, $parsed_args, $url ) use ( &$requests ) {
			if ( false === strpos( $url, 'graph.facebook.com' ) ) {
				return $preempt;
			}

			$requests[] = array(
				'url'  => $url,
				'body' => isset( $parsed_args['body'] ) ? $parsed_args['body'] : array(),
			);

			return array(
				'body'     => wp_json_encode( array( 'id' => '123_456' ) ),
				'response' => array( 'code' => 200 ),
			);
		};

		add_filter( 'pre_http_request', $filter, 10, 3 );

		$result = $tool->execute(
			array(
				'platform'     => 'facebook',
				'access_token' => 'valid-token',
				'target_id'    => '123',
				'message'      => 'Hello World',
			),
			array( 'user_id' => $admin_id )
		);

		remove_filter( 'pre_http_request', $filter, 10 );

		$this->assertIsArray( $result );
		$this->assertSame( 'facebook', $result['platform'] );
		$this->assertSame( '123_456', $result['post_id'] );
		$this->assertCount( 1, $requests );
		$this->assertStringContainsString( '/feed', $requests[0]['url'] );
		$this->assertArrayHasKey( 'message', $requests[0]['body'] );
	}

	/**
	 * Ensure the TikTok publishing tool dispatches the expected payload.
	 */
	public function test_post_tiktok_video_tool_executes_request() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$tool = $registry->get_tool( 'post_tiktok_video' );

		$this->assertInstanceOf( WP_MCP_AI_Pro_Tool_Post_Tiktok_Video::class, $tool );

		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );

		$requests = array();

		$filter = function ( $preempt, $parsed_args, $url ) use ( &$requests ) {
			if ( false === strpos( $url, 'open-api.tiktok.com' ) ) {
				return $preempt;
			}

			$requests[] = array(
				'url'  => $url,
				'body' => isset( $parsed_args['body'] ) ? $parsed_args['body'] : '',
			);

			return array(
				'body'     => wp_json_encode(
					array(
						'data' => array(
							'error_code' => 0,
							'video_id'   => 'video123',
							'publish_id' => 'publish456',
							'status'     => 'processing',
						),
					)
				),
				'response' => array( 'code' => 200 ),
			);
		};

		add_filter( 'pre_http_request', $filter, 10, 3 );

		$result = $tool->execute(
			array(
				'access_token' => 'token123',
				'open_id'      => 'open123',
				'video_url'    => 'https://example.com/video.mp4',
				'caption'      => 'Caption text',
			),
			array( 'user_id' => $admin_id )
		);

		remove_filter( 'pre_http_request', $filter, 10 );

		$this->assertIsArray( $result );
		$this->assertSame( 'video123', $result['video_id'] );
		$this->assertCount( 1, $requests );

		$decoded = json_decode( $requests[0]['body'], true );
		$this->assertIsArray( $decoded );
		$this->assertSame( 'open123', $decoded['open_id'] );
		$this->assertSame( 'https://example.com/video.mp4', $decoded['video_url'] );
		$this->assertSame( 'Caption text', $decoded['text'] );
	}

	/**
	 * Ensure the Google Business tool issues an authenticated request.
	 */
	public function test_post_google_business_tool_executes_request() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$tool = $registry->get_tool( 'post_google_business_update' );

		$this->assertInstanceOf( WP_MCP_AI_Tool_Post_Google_Business_Update::class, $tool );

		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );

		$requests = array();

		$filter = function ( $preempt, $parsed_args, $url ) use ( &$requests ) {
			if ( false === strpos( $url, 'mybusiness.googleapis.com' ) ) {
				return $preempt;
			}

			$requests[] = array(
				'url'     => $url,
				'body'    => isset( $parsed_args['body'] ) ? $parsed_args['body'] : '',
				'headers' => isset( $parsed_args['headers'] ) ? $parsed_args['headers'] : array(),
			);

			return array(
				'body'     => wp_json_encode(
					array(
						'name'  => 'accounts/1/locations/2/localPosts/3',
						'state' => 'LIVE',
					)
				),
				'response' => array( 'code' => 200 ),
			);
		};

		add_filter( 'pre_http_request', $filter, 10, 3 );

		$result = $tool->execute(
			array(
				'access_token'   => 'googletoken',
				'location'       => 'accounts/1/locations/2',
				'summary'        => 'Business summary',
				'language_code'  => 'en',
				'call_to_action' => 'LEARN_MORE',
				'action_url'     => 'https://example.com/learn',
			),
			array( 'user_id' => $admin_id )
		);

		remove_filter( 'pre_http_request', $filter, 10 );

		$this->assertIsArray( $result );
		$this->assertSame( 'accounts/1/locations/2/localPosts/3', $result['name'] );
		$this->assertCount( 1, $requests );
		$this->assertSame( 'Bearer googletoken', $requests[0]['headers']['Authorization'] );

		$decoded = json_decode( $requests[0]['body'], true );
		$this->assertSame( 'Business summary', $decoded['summary'] );
		$this->assertSame( 'LEARN_MORE', $decoded['callToAction']['actionType'] );
	}

	/**
	 * Ensure the LinkedIn tool issues a correctly formatted request.
	 */
	public function test_post_linkedin_tool_executes_request() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$tool = $registry->get_tool( 'post_linkedin_update' );

		$this->assertInstanceOf( WP_MCP_AI_Pro_Tool_Post_Linkedin_Update::class, $tool );

		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );

		$requests = array();

		$filter = function ( $preempt, $parsed_args, $url ) use ( &$requests ) {
			if ( false === strpos( $url, 'api.linkedin.com' ) ) {
				return $preempt;
			}

			$requests[] = array(
				'url'     => $url,
				'body'    => isset( $parsed_args['body'] ) ? $parsed_args['body'] : '',
				'headers' => isset( $parsed_args['headers'] ) ? $parsed_args['headers'] : array(),
			);

			return array(
				'body'     => wp_json_encode(
					array(
						'id'             => 'urn:li:ugcPost:1',
						'lifecycleState' => 'PUBLISHED',
					)
				),
				'response' => array( 'code' => 201 ),
			);
		};

		add_filter( 'pre_http_request', $filter, 10, 3 );

		$result = $tool->execute(
			array(
				'access_token' => 'linkedin-token',
				'author'       => 'urn:li:organization:123',
				'text'         => 'LinkedIn text',
				'share_url'    => 'https://example.com/article',
			),
			array( 'user_id' => $admin_id )
		);

		remove_filter( 'pre_http_request', $filter, 10 );

		$this->assertIsArray( $result );
		$this->assertSame( 'urn:li:ugcPost:1', $result['urn'] );
		$this->assertSame( 'PUBLISHED', $result['status'] );
		$this->assertCount( 1, $requests );
		$this->assertSame( 'Bearer linkedin-token', $requests[0]['headers']['Authorization'] );

		$decoded = json_decode( $requests[0]['body'], true );
		$this->assertSame( 'urn:li:organization:123', $decoded['author'] );
		$this->assertSame( 'LinkedIn text', $decoded['specificContent']['com.linkedin.ugc.ShareContent']['shareCommentary']['text'] );
		$this->assertSame( 'ARTICLE', $decoded['specificContent']['com.linkedin.ugc.ShareContent']['shareMediaCategory'] );
	}
}
