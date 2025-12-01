<?php
/**
 * Tests covering assistant tool registrations and sanitization.
 *
 * @package WP_MCP_AI
 */
class WP_MCP_AI_Assistant_Tools_Test extends WP_UnitTestCase {

	/**
	 * Ensure tool slugs are restricted to the registered tool list.
	 */
	public function test_sanitize_tools_meta_discards_unregistered_slugs() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$sanitized = WP_MCP_AI_Assistant_CPT::sanitize_tools_meta(
			array(
				'get_recent_posts',
				'invalid-tool',
				'GET_SITE_SUMMARY',
				'',
			)
		);

		$this->assertSame(
			array(
				'get_recent_posts',
				'get_site_summary',
			),
			$sanitized
		);
	}

	/**
	 * Ensure the update status tool is registered and mapped to the operations group.
	 */
	public function test_get_update_status_tool_is_registered() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$tool = $registry->get_tool( 'get_update_status' );

		$this->assertNotNull( $tool, 'The get_update_status tool should be registered by default.' );
		$this->assertInstanceOf( WP_MCP_AI_Tool_Interface::class, $tool );

		$group_map = $registry->get_tool_group_map();

		$this->assertArrayHasKey( 'get_update_status', $group_map );
		$this->assertSame( 'wordpress-core', $group_map['get_update_status'] );
	}

	/**
	 * Ensure invalid input types do not trigger notices and return an empty array.
	 */
	public function test_sanitize_tools_meta_handles_non_array_values() {
		$this->assertSame(
			array(),
			WP_MCP_AI_Assistant_CPT::sanitize_tools_meta( null )
		);

		$this->assertSame(
			array(),
			WP_MCP_AI_Assistant_CPT::sanitize_tools_meta( 'get_recent_posts' )
		);
	}

	/**
	 * Ensure argument-less tools expose a valid empty object for their parameter schema.
	 */
	public function test_tools_without_arguments_expose_object_properties_schema() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$tools_requiring_empty_properties = array(
			'get_site_summary',
			'open_openai_logs',
			'open_openai_usage',
		);

		foreach ( $tools_requiring_empty_properties as $slug ) {
			$tool   = $registry->get_tool( $slug );
			$schema = $tool ? $tool->get_parameters_schema() : null;

			$this->assertNotNull( $tool, sprintf( 'Tool %s should be registered.', $slug ) );
			$this->assertIsArray( $schema );
			$this->assertArrayHasKey( 'properties', $schema );
			$this->assertSame(
				'{}',
				wp_json_encode( $schema['properties'] ),
				sprintf( 'Tool %s should expose an empty object for the properties schema.', $slug )
			);
		}
	}

	/**
	 * Ensure tool role rules remove unknown entries and normalise values.
	 */
	public function test_sanitize_tool_role_rules_meta_filters_invalid_entries() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$sanitized = WP_MCP_AI_Assistant_CPT::sanitize_tool_role_rules_meta(
			array(
				array(
					'tool'                => 'get_recent_posts',
					'roles'               => array( 'administrator', 'invalid-role', 'editor', '' ),
					'groups'              => array( '15', 'foo', 0, 29 ),
					'flags'               => array( 'allow_guests', 'invalid-flag', 'allow_guests' ),
					'allow_authenticated' => '1',
				),
				array(
					'tool'  => 'not-a-tool',
					'roles' => array( 'administrator' ),
					'flags' => array( 'allow_guests' ),
				),
				'string-entry',
			)
		);

		$this->assertSame(
			array(
				array(
					'tool'   => 'get_recent_posts',
					'roles'  => array( 'administrator', 'editor' ),
					'groups' => array( 15, 29 ),
					'flags'  => array( 'allow_guests', 'allow_authenticated' ),
				),
			),
			$sanitized
		);
	}

	/**
	 * Ensure role rule entries without any constraints are discarded.
	 */
	public function test_sanitize_tool_role_rules_meta_discards_empty_entries() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$sanitized = WP_MCP_AI_Assistant_CPT::sanitize_tool_role_rules_meta(
			array(
				array(
					'tool' => 'get_recent_posts',
				),
			)
		);

		$this->assertSame( array(), $sanitized );
	}

	/**
	 * Ensure saving an assistant persists tool role rules and exposes them via configuration helpers.
	 */
	public function test_save_post_persists_tool_role_rules_meta() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$assistant_id = $this->factory->post->create(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_status' => 'publish',
			)
		);

		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$_POST['wp_mcp_ai_tools_meta_nonce'] = wp_create_nonce( 'wp_mcp_ai_tools_meta' );
		$_POST['wp_mcp_ai_tools']            = array( 'get_recent_posts' );
		$_POST['wp_mcp_ai_tool_role_rules']  = array(
			array(
				'tool'   => 'get_recent_posts',
				'roles'  => array( 'administrator', 'subscriber' ),
				'groups' => array( '42', '42', '3' ),
				'flags'  => array( 'allow_authenticated' ),
			),
		);

		$cpt = new WP_MCP_AI_Assistant_CPT( $registry );
		$cpt->save_post( $assistant_id, get_post( $assistant_id ) );

		$expected_rules = array(
			array(
				'tool'   => 'get_recent_posts',
				'roles'  => array( 'administrator', 'subscriber' ),
				'groups' => array( 42, 3 ),
				'flags'  => array( 'allow_authenticated' ),
			),
		);

		$this->assertSame(
			$expected_rules,
			get_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_TOOL_ROLE_RULES, true )
		);

		$config = WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $assistant_id );

		$this->assertArrayHasKey( 'tool_role_rules', $config );
		$this->assertSame( $expected_rules, $config['tool_role_rules'] );

		$_POST = array();
		wp_set_current_user( 0 );
	}

	/**
	 * Ensure assistant configuration continues to expose an empty array when no tool role rules exist.
	 */
	public function test_get_assistant_configuration_handles_missing_tool_role_rules() {
		$assistant_id = $this->factory->post->create(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_status' => 'publish',
			)
		);

		delete_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_TOOL_ROLE_RULES );

		$config = WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $assistant_id );

		$this->assertArrayHasKey( 'tool_role_rules', $config );
		$this->assertSame( array(), $config['tool_role_rules'] );
	}

	/**
	 * Ensure all supported providers are accepted by sanitize_provider_meta.
	 */
	public function test_sanitize_provider_meta_accepts_all_providers() {
		$this->assertSame( 'openai', WP_MCP_AI_Assistant_CPT::sanitize_provider_meta( 'openai' ) );
		$this->assertSame( 'gemini', WP_MCP_AI_Assistant_CPT::sanitize_provider_meta( 'gemini' ) );
		$this->assertSame( 'ollama', WP_MCP_AI_Assistant_CPT::sanitize_provider_meta( 'ollama' ) );
		$this->assertSame( 'lm_studio', WP_MCP_AI_Assistant_CPT::sanitize_provider_meta( 'lm_studio' ) );
	}

	/**
	 * Ensure invalid provider values are rejected.
	 */
	public function test_sanitize_provider_meta_rejects_invalid_providers() {
		$this->assertSame( '', WP_MCP_AI_Assistant_CPT::sanitize_provider_meta( 'invalid' ) );
		$this->assertSame( '', WP_MCP_AI_Assistant_CPT::sanitize_provider_meta( '' ) );
		$this->assertSame( '', WP_MCP_AI_Assistant_CPT::sanitize_provider_meta( 123 ) );
		$this->assertSame( '', WP_MCP_AI_Assistant_CPT::sanitize_provider_meta( null ) );
	}
}
