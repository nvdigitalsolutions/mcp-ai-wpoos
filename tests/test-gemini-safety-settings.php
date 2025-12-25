<?php
/**
 * Test Gemini safety settings functionality.
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for Gemini safety settings configuration.
 */
class Test_Gemini_Safety_Settings extends WP_UnitTestCase {

	/**
	 * Test safety settings are not added when not provided.
	 */
	public function test_safety_settings_not_added_when_absent() {
		update_option(
			'wp_mcp_ai_settings',
			array(
				'gemini_api_key' => 'test_key',
				'gemini_model'   => 'gemini-1.5-flash',
			)
		);

		$client       = new WP_MCP_AI_Gemini_Client();
		$messages     = array(
			array(
				'role'    => 'user',
				'content' => 'Test message',
			),
		);
		$payload_sent = null;

		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$payload_sent ) {
				if ( strpos( $url, 'generateContent' ) !== false ) {
					$payload_sent = json_decode( $args['body'], true );
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => wp_json_encode(
							array(
								'candidates' => array(
									array(
										'content' => array(
											'parts' => array(
												array( 'text' => 'Response' ),
											),
										),
									),
								),
							)
						),
					);
				}
				return $preempt;
			},
			10,
			3
		);

		$client->create_chat_completion( $messages );

		$this->assertNotNull( $payload_sent );
		$this->assertArrayNotHasKey( 'safetySettings', $payload_sent );
	}

	/**
	 * Test safety settings are added correctly.
	 */
	public function test_safety_settings_added_correctly() {
		update_option(
			'wp_mcp_ai_settings',
			array(
				'gemini_api_key' => 'test_key',
				'gemini_model'   => 'gemini-1.5-flash',
			)
		);

		$client       = new WP_MCP_AI_Gemini_Client();
		$messages     = array(
			array(
				'role'    => 'user',
				'content' => 'Test message',
			),
		);
		$payload_sent = null;

		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$payload_sent ) {
				if ( strpos( $url, 'generateContent' ) !== false ) {
					$payload_sent = json_decode( $args['body'], true );
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => wp_json_encode(
							array(
								'candidates' => array(
									array(
										'content' => array(
											'parts' => array(
												array( 'text' => 'Response' ),
											),
										),
									),
								),
							)
						),
					);
				}
				return $preempt;
			},
			10,
			3
		);

		$client->create_chat_completion(
			$messages,
			array(
				'safety_settings' => array(
					'HARM_CATEGORY_HARASSMENT'        => 'BLOCK_MEDIUM_AND_ABOVE',
					'HARM_CATEGORY_DANGEROUS_CONTENT' => 'BLOCK_ONLY_HIGH',
				),
			)
		);

		$this->assertNotNull( $payload_sent );
		$this->assertArrayHasKey( 'safetySettings', $payload_sent );
		$this->assertIsArray( $payload_sent['safetySettings'] );
		$this->assertCount( 2, $payload_sent['safetySettings'] );

		// Check first safety setting.
		$this->assertEquals( 'HARM_CATEGORY_HARASSMENT', $payload_sent['safetySettings'][0]['category'] );
		$this->assertEquals( 'BLOCK_MEDIUM_AND_ABOVE', $payload_sent['safetySettings'][0]['threshold'] );

		// Check second safety setting.
		$this->assertEquals( 'HARM_CATEGORY_DANGEROUS_CONTENT', $payload_sent['safetySettings'][1]['category'] );
		$this->assertEquals( 'BLOCK_ONLY_HIGH', $payload_sent['safetySettings'][1]['threshold'] );
	}

	/**
	 * Test safety settings with all harm categories.
	 */
	public function test_safety_settings_all_categories() {
		update_option(
			'wp_mcp_ai_settings',
			array(
				'gemini_api_key' => 'test_key',
				'gemini_model'   => 'gemini-1.5-flash',
			)
		);

		$client       = new WP_MCP_AI_Gemini_Client();
		$messages     = array(
			array(
				'role'    => 'user',
				'content' => 'Test',
			),
		);
		$payload_sent = null;

		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$payload_sent ) {
				if ( strpos( $url, 'generateContent' ) !== false ) {
					$payload_sent = json_decode( $args['body'], true );
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => wp_json_encode(
							array(
								'candidates' => array(
									array(
										'content' => array(
											'parts' => array(
												array( 'text' => 'Response' ),
											),
										),
									),
								),
							)
						),
					);
				}
				return $preempt;
			},
			10,
			3
		);

		$client->create_chat_completion(
			$messages,
			array(
				'safety_settings' => array(
					'HARM_CATEGORY_HARASSMENT'        => 'BLOCK_NONE',
					'HARM_CATEGORY_HATE_SPEECH'       => 'BLOCK_ONLY_HIGH',
					'HARM_CATEGORY_SEXUALLY_EXPLICIT' => 'BLOCK_MEDIUM_AND_ABOVE',
					'HARM_CATEGORY_DANGEROUS_CONTENT' => 'BLOCK_LOW_AND_ABOVE',
				),
			)
		);

		$this->assertNotNull( $payload_sent );
		$this->assertArrayHasKey( 'safetySettings', $payload_sent );
		$this->assertCount( 4, $payload_sent['safetySettings'] );
	}

	/**
	 * Test invalid categories are filtered out.
	 */
	public function test_safety_settings_filters_invalid_categories() {
		update_option(
			'wp_mcp_ai_settings',
			array(
				'gemini_api_key' => 'test_key',
				'gemini_model'   => 'gemini-1.5-flash',
			)
		);

		$client       = new WP_MCP_AI_Gemini_Client();
		$messages     = array(
			array(
				'role'    => 'user',
				'content' => 'Test',
			),
		);
		$payload_sent = null;

		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$payload_sent ) {
				if ( strpos( $url, 'generateContent' ) !== false ) {
					$payload_sent = json_decode( $args['body'], true );
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => wp_json_encode(
							array(
								'candidates' => array(
									array(
										'content' => array(
											'parts' => array(
												array( 'text' => 'Response' ),
											),
										),
									),
								),
							)
						),
					);
				}
				return $preempt;
			},
			10,
			3
		);

		$client->create_chat_completion(
			$messages,
			array(
				'safety_settings' => array(
					'HARM_CATEGORY_HARASSMENT' => 'BLOCK_MEDIUM_AND_ABOVE',
					'INVALID_CATEGORY'         => 'BLOCK_NONE',
					'ANOTHER_INVALID'          => 'BLOCK_ONLY_HIGH',
				),
			)
		);

		$this->assertNotNull( $payload_sent );
		$this->assertArrayHasKey( 'safetySettings', $payload_sent );
		// Only valid category should be included.
		$this->assertCount( 1, $payload_sent['safetySettings'] );
		$this->assertEquals( 'HARM_CATEGORY_HARASSMENT', $payload_sent['safetySettings'][0]['category'] );
	}

	/**
	 * Test invalid thresholds are filtered out.
	 */
	public function test_safety_settings_filters_invalid_thresholds() {
		update_option(
			'wp_mcp_ai_settings',
			array(
				'gemini_api_key' => 'test_key',
				'gemini_model'   => 'gemini-1.5-flash',
			)
		);

		$client       = new WP_MCP_AI_Gemini_Client();
		$messages     = array(
			array(
				'role'    => 'user',
				'content' => 'Test',
			),
		);
		$payload_sent = null;

		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$payload_sent ) {
				if ( strpos( $url, 'generateContent' ) !== false ) {
					$payload_sent = json_decode( $args['body'], true );
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => wp_json_encode(
							array(
								'candidates' => array(
									array(
										'content' => array(
											'parts' => array(
												array( 'text' => 'Response' ),
											),
										),
									),
								),
							)
						),
					);
				}
				return $preempt;
			},
			10,
			3
		);

		$client->create_chat_completion(
			$messages,
			array(
				'safety_settings' => array(
					'HARM_CATEGORY_HARASSMENT'  => 'INVALID_THRESHOLD',
					'HARM_CATEGORY_HATE_SPEECH' => 'BLOCK_NONE',
				),
			)
		);

		$this->assertNotNull( $payload_sent );
		$this->assertArrayHasKey( 'safetySettings', $payload_sent );
		// Only setting with valid threshold should be included.
		$this->assertCount( 1, $payload_sent['safetySettings'] );
		$this->assertEquals( 'HARM_CATEGORY_HATE_SPEECH', $payload_sent['safetySettings'][0]['category'] );
	}

	/**
	 * Test safety settings with array format.
	 */
	public function test_safety_settings_array_format() {
		update_option(
			'wp_mcp_ai_settings',
			array(
				'gemini_api_key' => 'test_key',
				'gemini_model'   => 'gemini-1.5-flash',
			)
		);

		$client       = new WP_MCP_AI_Gemini_Client();
		$messages     = array(
			array(
				'role'    => 'user',
				'content' => 'Test',
			),
		);
		$payload_sent = null;

		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$payload_sent ) {
				if ( strpos( $url, 'generateContent' ) !== false ) {
					$payload_sent = json_decode( $args['body'], true );
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => wp_json_encode(
							array(
								'candidates' => array(
									array(
										'content' => array(
											'parts' => array(
												array( 'text' => 'Response' ),
											),
										),
									),
								),
							)
						),
					);
				}
				return $preempt;
			},
			10,
			3
		);

		$client->create_chat_completion(
			$messages,
			array(
				'safety_settings' => array(
					array(
						'category'  => 'HARM_CATEGORY_HARASSMENT',
						'threshold' => 'BLOCK_MEDIUM_AND_ABOVE',
					),
					array(
						'category'  => 'HARM_CATEGORY_HATE_SPEECH',
						'threshold' => 'BLOCK_ONLY_HIGH',
					),
				),
			)
		);

		$this->assertNotNull( $payload_sent );
		$this->assertArrayHasKey( 'safetySettings', $payload_sent );
		$this->assertCount( 2, $payload_sent['safetySettings'] );
	}

	/**
	 * Test safety settings work with streaming.
	 */
	public function test_safety_settings_with_streaming() {
		update_option(
			'wp_mcp_ai_settings',
			array(
				'gemini_api_key' => 'test_key',
				'gemini_model'   => 'gemini-1.5-flash',
			)
		);

		$client       = new WP_MCP_AI_Gemini_Client();
		$messages     = array(
			array(
				'role'    => 'user',
				'content' => 'Test',
			),
		);
		$payload_sent = null;

		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$payload_sent ) {
				if ( strpos( $url, 'streamGenerateContent' ) !== false ) {
					$payload_sent = json_decode( $args['body'], true );
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => 'data: ' . wp_json_encode(
							array(
								'candidates' => array(
									array(
										'content' => array(
											'parts' => array(
												array( 'text' => 'Response' ),
											),
										),
									),
								),
							)
						) . "\n\ndata: [DONE]\n\n",
					);
				}
				return $preempt;
			},
			10,
			3
		);

		$client->stream_chat_completion(
			$messages,
			array(
				'safety_settings' => array(
					'HARM_CATEGORY_HARASSMENT' => 'BLOCK_NONE',
				),
			)
		);

		$this->assertNotNull( $payload_sent );
		$this->assertArrayHasKey( 'safetySettings', $payload_sent );
		$this->assertCount( 1, $payload_sent['safetySettings'] );
	}
}
