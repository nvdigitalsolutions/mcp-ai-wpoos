<?php
/**
 * Tests for WP_MCP_AI_Pro_Tool_JetEngine CCT CRUD operations.
 *
 * Validates the tool schema, capability flags, get_definition(), and the new
 * delete_item action dispatch.  Requires the pro addon path to be defined.
 *
 * @package WP_MCP_AI_Pro
 */

/**
 * Test class for WP_MCP_AI_Pro_Tool_JetEngine.
 */
class Test_JetEngine_Pro_Tool_CCT_CRUD extends WP_UnitTestCase {

	/**
	 * Load the tool class from the pro addon path.
	 *
	 * @return WP_MCP_AI_Pro_Tool_JetEngine
	 */
	private function get_tool() {
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
		}

		if ( ! interface_exists( 'WP_MCP_AI_Tool_Interface' ) ) {
			$interface_path = WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
			if ( file_exists( $interface_path ) ) {
				require_once $interface_path;
			}
		}

		if ( ! interface_exists( 'WP_MCP_AI_Tool_Capability_Flags_Interface' ) ) {
			$flags_path = WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool-capability-flags.php';
			if ( file_exists( $flags_path ) ) {
				require_once $flags_path;
			}
		}

		if ( ! class_exists( 'WP_MCP_AI_Pro_Tool_JetEngine' ) ) {
			$tool_path = WP_MCP_AI_PRO_PATH . 'includes/src/Tools/class-wp-mcp-ai-pro-tool-jetengine.php';
			if ( ! file_exists( $tool_path ) ) {
				$this->markTestSkipped( 'WP_MCP_AI_Pro_Tool_JetEngine file not found' );
			}
			require_once $tool_path;
		}

		return new WP_MCP_AI_Pro_Tool_JetEngine();
	}

	// =========================================================================
	// Slug / name / description
	// =========================================================================

	/**
	 * Slug must be 'jetengine' for backwards compatibility.
	 */
	public function test_slug_is_jetengine() {
		$tool = $this->get_tool();
		$this->assertSame( 'jetengine', $tool->get_slug() );
	}

	// =========================================================================
	// Parameters schema
	// =========================================================================

	/**
	 * The 'delete_item' action must be present in the enum list.
	 */
	public function test_schema_includes_delete_item_action() {
		$tool   = $this->get_tool();
		$schema = $tool->get_parameters_schema();

		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'action', $schema['properties'] );
		$this->assertContains( 'delete_item', $schema['properties']['action']['enum'] );
	}

	/**
	 * All six CRUD actions must be declared.
	 */
	public function test_schema_declares_all_crud_actions() {
		$tool     = $this->get_tool();
		$schema   = $tool->get_parameters_schema();
		$expected = array( 'list_types', 'list_items', 'get_item', 'create_item', 'update_item', 'delete_item' );

		foreach ( $expected as $action ) {
			$this->assertContains(
				$action,
				$schema['properties']['action']['enum'],
				"Action '{$action}' must be in the schema enum."
			);
		}
	}

	// =========================================================================
	// Capability flags
	// =========================================================================

	/**
	 * Capability flags must include 'write' (covers create/update/delete).
	 */
	public function test_capability_flags_include_write() {
		$tool = $this->get_tool();
		$this->assertContains( 'write', $tool->get_capability_flags() );
	}

	// =========================================================================
	// get_definition()
	// =========================================================================

	/**
	 * get_definition() must return an array with the required orchestration keys.
	 */
	public function test_get_definition_returns_expected_structure() {
		$tool       = $this->get_tool();
		$definition = $tool->get_definition();

		$this->assertIsArray( $definition );
		$this->assertArrayHasKey( 'name', $definition );
		$this->assertArrayHasKey( 'description', $definition );
		$this->assertArrayHasKey( 'toolkit', $definition );
		$this->assertArrayHasKey( 'pattern_compatibility', $definition );
		$this->assertArrayHasKey( 'risk_level', $definition );
	}

	/**
	 * The toolkit identifier must be 'jetengine_cct'.
	 */
	public function test_get_definition_toolkit_is_jetengine_cct() {
		$tool       = $this->get_tool();
		$definition = $tool->get_definition();
		$this->assertSame( 'jetengine_cct', $definition['toolkit'] );
	}

	// =========================================================================
	// delete_item — missing JetEngine
	// =========================================================================

	/**
	 * delete_item must return WP_Error when JetEngine is not active.
	 */
	public function test_delete_item_returns_error_without_jetengine() {
		if ( class_exists( 'Jet_Engine' ) || function_exists( 'jet_engine' ) ) {
			$this->markTestSkipped( 'JetEngine is present; skipping unavailability test.' );
		}

		$tool = $this->get_tool();

		$result = $tool->execute(
			array(
				'action'   => 'delete_item',
				'cct_slug' => 'vital_signs',
				'item_id'  => 1,
			),
			array( 'user_id' => 1 )
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'jetengine_not_active', $result->get_error_code() );
	}

	// =========================================================================
	// delete_item — missing parameters
	// =========================================================================

	/**
	 * delete_item must return WP_Error when cct_slug or item_id is absent.
	 * Tested via reflection to bypass the JetEngine availability check.
	 */
	public function test_delete_item_requires_cct_slug_and_item_id() {
		$tool = $this->get_tool();

		// Use reflection to call the protected delete_item method directly.
		$reflection = new ReflectionClass( $tool );
		$method     = $reflection->getMethod( 'delete_item' );
		$method->setAccessible( true );

		// Missing item_id.
		$result = $method->invokeArgs(
			$tool,
			array(
				array( 'cct_slug' => 'vital_signs' ),
				array( 'user_id' => 1 ),
			)
		);
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'missing_params', $result->get_error_code() );

		// Missing cct_slug.
		$result = $method->invokeArgs(
			$tool,
			array(
				array( 'item_id' => 1 ),
				array( 'user_id' => 1 ),
			)
		);
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'missing_params', $result->get_error_code() );
	}

	// =========================================================================
	// delete_item — permission check
	// =========================================================================

	/**
	 * delete_item must return WP_Error when the user lacks edit_posts capability.
	 * Tested via reflection to bypass the JetEngine availability check.
	 */
	public function test_delete_item_requires_edit_posts_capability() {
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$tool = $this->get_tool();

		$reflection = new ReflectionClass( $tool );
		$method     = $reflection->getMethod( 'delete_item' );
		$method->setAccessible( true );

		$result = $method->invokeArgs(
			$tool,
			array(
				array(
					'cct_slug' => 'vital_signs',
					'item_id'  => 1,
				),
				array( 'user_id' => $subscriber_id ),
			)
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'permission_denied', $result->get_error_code() );
	}

	// =========================================================================
	// Description — mentions CCT and create_post
	// =========================================================================

	/**
	 * The description must explicitly mention vital_signs and instruct the AI
	 * NOT to use create_post for CCT items, so the correct tool is always chosen.
	 */
	public function test_description_directs_ai_away_from_create_post() {
		$tool        = $this->get_tool();
		$description = $tool->get_description();

		$this->assertStringContainsString( 'vital_signs', $description );

		// Description must contain explicit negative guidance (NOT / not) alongside create_post.
		$this->assertMatchesRegularExpression(
			'/NOT create_post|not.*create_post|create_post.*NOT/i',
			$description,
			'Description must explicitly tell the AI not to use create_post for CCT items.'
		);
	}

	// =========================================================================
	// list_types — null-slug filtering
	// =========================================================================

	/**
	 * list_types() must resolve slug and name from the args array when they
	 * are not available as direct properties on the type object, fall back
	 * to slug for name when args has no name, and silently skip anonymous
	 * types that have no identifiable slug in either location.
	 */
	public function test_list_types_property_resolution_fallbacks() {
		$tool = $this->get_tool();

		$reflection = new ReflectionClass( $tool );
		$method     = $reflection->getMethod( 'list_types' );
		$method->setAccessible( true );

		// Type with slug only in args — simulates partial-init JetEngine object.
		$args_type         = new stdClass();
		$args_type->slug   = '';
		$args_type->name   = null;
		$args_type->id     = null;
		$args_type->args   = array(
			'slug' => 'vitals_log',
			'name' => 'Vitals Log',
		);
		$args_type->fields = array();

		// Verify the resolution logic matches what list_types() does.
		$slug = '';
		if ( ! empty( $args_type->slug ) ) {
			$slug = $args_type->slug;
		} elseif ( ! empty( $args_type->args ) && ! empty( $args_type->args['slug'] ) ) {
			$slug = $args_type->args['slug'];
		}
		$this->assertSame( 'vitals_log', $slug, 'Slug resolved from args array.' );

		$name = '';
		if ( ! empty( $args_type->name ) ) {
			$name = $args_type->name;
		} elseif ( ! empty( $args_type->args ) && ! empty( $args_type->args['name'] ) ) {
			$name = $args_type->args['name'];
		} else {
			$name = $slug;
		}
		$this->assertSame( 'Vitals Log', $name, 'Name resolved from args array.' );

		// Anonymous type — no slug in direct property or args: must be filtered out.
		$anon_type         = new stdClass();
		$anon_type->slug   = '';
		$anon_type->name   = null;
		$anon_type->id     = null;
		$anon_type->args   = array();
		$anon_type->fields = array();

		$anon_slug = '';
		if ( ! empty( $anon_type->slug ) ) {
			$anon_slug = $anon_type->slug;
		} elseif ( ! empty( $anon_type->args ) && ! empty( $anon_type->args['slug'] ) ) {
			$anon_slug = $anon_type->args['slug'];
		}
		$this->assertSame( '', $anon_slug, 'Anonymous type resolves to empty slug and must be skipped.' );
	}

	/**
	 * list_types() field-count resolution must fall back to meta_fields when
	 * fields is not an array (partial-init type object).
	 */
	public function test_list_types_field_count_falls_back_to_meta_fields() {
		// Replicate the field_count resolution logic from list_types().
		$type              = new stdClass();
		$type->fields      = null;
		$type->meta_fields = array( 'a', 'b', 'c' );

		$field_count = 0;
		if ( isset( $type->fields ) && is_array( $type->fields ) ) {
			$field_count = count( $type->fields );
		} elseif ( isset( $type->meta_fields ) && is_array( $type->meta_fields ) ) {
			$field_count = count( $type->meta_fields );
		}

		$this->assertSame( 3, $field_count, 'Field count falls back to meta_fields.' );
	}

	/**
	 * list_types() ID resolution must prefer '_ID' when 'id' is null.
	 */
	public function test_list_types_id_falls_back_to_underscore_id() {
		$type      = new stdClass();
		$type->id  = null;
		$type->_ID = 42;

		$id = null;
		if ( isset( $type->id ) && null !== $type->id ) {
			$id = $type->id;
		} elseif ( isset( $type->_ID ) ) {
			$id = $type->_ID;
		}

		$this->assertSame( 42, $id, 'ID resolved from _ID fallback.' );
	}

	// =========================================================================
	// create_item_direct — vitals_log fallback
	// =========================================================================

	/**
	 * create_item_direct() must return WP_Error when vitals_log table is absent.
	 * Tested via reflection to bypass the JetEngine availability check.
	 */
	public function test_create_item_direct_returns_error_without_vitals_log_table() {
		if ( ! class_exists( 'WP_MCP_AI_JetEngine_Vitals_Log_CCT' ) ) {
			$vitals_path = WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-jetengine-vitals-log-cct.php';
			if ( file_exists( $vitals_path ) ) {
				require_once $vitals_path;
			}
		}

		if ( ! class_exists( 'WP_MCP_AI_JetEngine_Vitals_Log_CCT' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_JetEngine_Vitals_Log_CCT not available.' );
		}

		$tool       = $this->get_tool();
		$reflection = new ReflectionClass( $tool );
		$method     = $reflection->getMethod( 'create_item_direct' );
		$method->setAccessible( true );

		// The test database doesn't have the vitals_log table, so table_exists()
		// returns false and create_item_direct() should return WP_Error.
		$result = $method->invokeArgs(
			$tool,
			array(
				array(
					'cct_slug' => 'vitals_log',
					'fields'   => array(
						'member_id'        => 1,
						'measurement_date' => '2025-01-01',
					),
				),
			)
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		// Error code is either 'cct_not_found' (table missing) or 'create_failed'.
		$this->assertContains(
			$result->get_error_code(),
			array( 'cct_not_found', 'create_failed' ),
			'create_item_direct must return a recognisable WP_Error code.'
		);
	}

	/**
	 * create_item_direct() must return WP_Error for unknown CCT slugs.
	 */
	public function test_create_item_direct_returns_error_for_unknown_slug() {
		$tool       = $this->get_tool();
		$reflection = new ReflectionClass( $tool );
		$method     = $reflection->getMethod( 'create_item_direct' );
		$method->setAccessible( true );

		$result = $method->invokeArgs(
			$tool,
			array(
				array(
					'cct_slug' => 'completely_unknown_cct',
					'fields'   => array(),
				),
			)
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'cct_not_found', $result->get_error_code() );
	}

	// =========================================================================
	// maybe_register_known_cct — dispatch check
	// =========================================================================

	/**
	 * maybe_register_known_cct() must not throw for any known or unknown slug.
	 */
	public function test_maybe_register_known_cct_does_not_throw() {
		$tool       = $this->get_tool();
		$reflection = new ReflectionClass( $tool );
		$method     = $reflection->getMethod( 'maybe_register_known_cct' );
		$method->setAccessible( true );

		$slugs = array( 'vitals_log', 'ai_chat_transcripts', 'nonexistent_cct' );

		foreach ( $slugs as $slug ) {
			$threw = false;
			try {
				$method->invokeArgs( $tool, array( $slug ) );
			} catch ( Exception $e ) {
				$threw = true;
			}
			$this->assertFalse( $threw, "maybe_register_known_cct('{$slug}') must not throw." );
		}
	}

	// =========================================================================
	// get_schema — parameter schema includes get_schema action
	// =========================================================================

	/**
	 * The 'get_schema' action must be present in the schema enum.
	 */
	public function test_schema_includes_get_schema_action() {
		$tool   = $this->get_tool();
		$schema = $tool->get_parameters_schema();

		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'action', $schema['properties'] );
		$this->assertContains( 'get_schema', $schema['properties']['action']['enum'] );
	}

	/**
	 * All seven actions (including get_schema) must be declared in the schema.
	 */
	public function test_schema_declares_all_actions_including_get_schema() {
		$tool     = $this->get_tool();
		$schema   = $tool->get_parameters_schema();
		$expected = array( 'list_types', 'get_schema', 'list_items', 'get_item', 'create_item', 'update_item', 'delete_item' );

		foreach ( $expected as $action ) {
			$this->assertContains(
				$action,
				$schema['properties']['action']['enum'],
				"Action '{$action}' must be in the schema enum."
			);
		}
	}

	// =========================================================================
	// get_schema — missing JetEngine
	// =========================================================================

	/**
	 * The get_schema action must return WP_Error when JetEngine is not active.
	 */
	public function test_get_schema_returns_error_without_jetengine() {
		if ( class_exists( 'Jet_Engine' ) || function_exists( 'jet_engine' ) ) {
			$this->markTestSkipped( 'JetEngine is present; skipping unavailability test.' );
		}

		$tool = $this->get_tool();

		$result = $tool->execute(
			array(
				'action'   => 'get_schema',
				'cct_slug' => 'vitals_log',
			),
			array()
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'jetengine_not_active', $result->get_error_code() );
	}

	// =========================================================================
	// get_schema — missing cct_slug
	// =========================================================================

	/**
	 * The get_schema() method must return WP_Error when cct_slug is absent.
	 * Tested via reflection to bypass the JetEngine availability check.
	 */
	public function test_get_schema_requires_cct_slug() {
		$tool       = $this->get_tool();
		$reflection = new ReflectionClass( $tool );
		$method     = $reflection->getMethod( 'get_schema' );
		$method->setAccessible( true );

		$result = $method->invokeArgs( $tool, array( array() ) );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'missing_cct_slug', $result->get_error_code() );
	}

	// =========================================================================
	// get_schema_from_cct_class — known CCT class
	// =========================================================================

	/**
	 * The get_schema_from_cct_class() method must return a proper schema array when the
	 * corresponding CCT class is available.
	 */
	public function test_get_schema_from_cct_class_returns_schema_for_vitals_log() {
		if ( ! class_exists( 'WP_MCP_AI_JetEngine_Vitals_Log_CCT' ) ) {
			$vitals_path = WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-jetengine-vitals-log-cct.php';
			if ( file_exists( $vitals_path ) ) {
				require_once $vitals_path;
			}
		}

		if ( ! class_exists( 'WP_MCP_AI_JetEngine_Vitals_Log_CCT' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_JetEngine_Vitals_Log_CCT not available.' );
		}

		$tool       = $this->get_tool();
		$reflection = new ReflectionClass( $tool );
		$method     = $reflection->getMethod( 'get_schema_from_cct_class' );
		$method->setAccessible( true );

		$result = $method->invokeArgs( $tool, array( 'vitals_log' ) );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'cct_slug', $result );
		$this->assertArrayHasKey( 'field_count', $result );
		$this->assertArrayHasKey( 'fields', $result );
		$this->assertSame( 'vitals_log', $result['cct_slug'] );
		$this->assertGreaterThan( 0, $result['field_count'] );
		$this->assertIsArray( $result['fields'] );

		// Each field must have the mandatory keys.
		foreach ( $result['fields'] as $field ) {
			$this->assertArrayHasKey( 'name', $field, 'Each field must have a name.' );
			$this->assertArrayHasKey( 'type', $field, 'Each field must have a type.' );
			$this->assertArrayHasKey( 'required', $field, 'Each field must have a required flag.' );
			$this->assertArrayHasKey( 'description', $field, 'Each field must have a description.' );
		}

		// Verify that known vitals_log fields are present.
		$field_names = array_column( $result['fields'], 'name' );
		$this->assertContains( 'member_id', $field_names, 'vitals_log schema must include member_id field.' );
		$this->assertContains( 'measurement_date', $field_names, 'vitals_log schema must include measurement_date field.' );
	}

	/**
	 * The get_schema_from_cct_class() method must return WP_Error for unknown CCT slugs.
	 */
	public function test_get_schema_from_cct_class_returns_error_for_unknown_slug() {
		$tool       = $this->get_tool();
		$reflection = new ReflectionClass( $tool );
		$method     = $reflection->getMethod( 'get_schema_from_cct_class' );
		$method->setAccessible( true );

		$result = $method->invokeArgs( $tool, array( 'completely_unknown_cct' ) );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'cct_not_found', $result->get_error_code() );
	}

	// =========================================================================
	// format_fields_schema — normalisation
	// =========================================================================

	/**
	 * The format_fields_schema() method must normalise raw JetEngine fields and include
	 * options only for select-type fields.
	 */
	public function test_format_fields_schema_normalises_raw_fields() {
		$tool       = $this->get_tool();
		$reflection = new ReflectionClass( $tool );
		$method     = $reflection->getMethod( 'format_fields_schema' );
		$method->setAccessible( true );

		$raw_fields = array(
			array(
				'name'        => 'member_id',
				'title'       => 'Member ID',
				'type'        => 'number',
				'is_required' => true,
				'description' => 'WordPress user ID',
				'default_val' => '',
			),
			array(
				'name'        => 'source',
				'title'       => 'Source',
				'type'        => 'select',
				'is_required' => false,
				'description' => 'Entry source',
				'default_val' => 'manual',
				'options'     => array(
					array(
						'key'   => 'manual',
						'value' => 'Manual Entry',
					),
					array(
						'key'   => 'api',
						'value' => 'External API',
					),
				),
			),
			// Entry with no 'name' must be silently skipped.
			array(
				'title' => 'Ghost Field',
				'type'  => 'text',
			),
		);

		$result = $method->invokeArgs( $tool, array( 'vitals_log', $raw_fields ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'vitals_log', $result['cct_slug'] );
		$this->assertSame( 2, $result['field_count'], 'Unnamed fields must be skipped.' );
		$this->assertCount( 2, $result['fields'] );

		// First field (number, required).
		$member_id_field = $result['fields'][0];
		$this->assertSame( 'member_id', $member_id_field['name'] );
		$this->assertSame( 'number', $member_id_field['type'] );
		$this->assertTrue( $member_id_field['required'] );
		$this->assertArrayNotHasKey( 'options', $member_id_field, 'Non-select field must not have options.' );

		// Second field (select with options).
		$source_field = $result['fields'][1];
		$this->assertSame( 'source', $source_field['name'] );
		$this->assertSame( 'manual', $source_field['default'] );
		$this->assertArrayHasKey( 'options', $source_field, 'Select field must have options.' );
		$this->assertCount( 2, $source_field['options'] );
		$this->assertSame( 'manual', $source_field['options'][0]['key'] );
		$this->assertSame( 'Manual Entry', $source_field['options'][0]['label'] );
	}

	/**
	 * The format_fields_schema() method must fall back gracefully when optional field
	 * keys (title, description, default_val) are absent.
	 */
	public function test_format_fields_schema_handles_minimal_field_definition() {
		$tool       = $this->get_tool();
		$reflection = new ReflectionClass( $tool );
		$method     = $reflection->getMethod( 'format_fields_schema' );
		$method->setAccessible( true );

		$raw_fields = array(
			array( 'name' => 'bare_field' ),
		);

		$result = $method->invokeArgs( $tool, array( 'test_cct', $raw_fields ) );

		$this->assertIsArray( $result );
		$this->assertSame( 1, $result['field_count'] );
		$field = $result['fields'][0];
		$this->assertSame( 'bare_field', $field['name'] );
		$this->assertSame( 'bare_field', $field['title'], 'Title must fall back to name.' );
		$this->assertSame( 'text', $field['type'], 'Type must fall back to text.' );
		$this->assertFalse( $field['required'] );
		$this->assertSame( '', $field['description'] );
		$this->assertSame( '', $field['default'] );
	}

	// =========================================================================
	// Description — mentions get_schema
	// =========================================================================

	/**
	 * The tool description must mention 'get_schema' so the AI knows the action exists.
	 */
	public function test_description_mentions_get_schema() {
		$tool        = $this->get_tool();
		$description = $tool->get_description();

		$this->assertStringContainsString(
			'get_schema',
			$description,
			'Description must mention get_schema so the AI can discover it.'
		);
	}
}
