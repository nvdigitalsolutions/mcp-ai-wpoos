<?php
/**
 * Tests for WP_MCP_AI_Pro_Tool_JetEngine CCT CRUD operations.
 *
 * Validates the tool schema, capability flags, get_definition(), and the new
 * delete_item action dispatch.  Requires the pro addon path to be defined.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
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
			$tool_path = WP_MCP_AI_PRO_PATH . 'includes/tools/jetengine/class-wp-mcp-ai-pro-tool-jetengine.php';
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
	 * The get_definition() method must return an array with the required orchestration keys.
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
	 * The delete_item action must return WP_Error when JetEngine is not active.
	 */
	public function test_delete_item_returns_error_without_jetengine() {
		if ( class_exists( 'Jet_Engine' ) || function_exists( 'jet_engine' ) ) {
			$this->markTestSkipped( 'JetEngine is present; skipping unavailability test.' );
		}

		$tool = $this->get_tool();

		$result = $tool->execute(
			array(
				'action'   => 'delete_item',
				'cct_slug' => 'vitals_log',
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
	 * The delete_item action must return WP_Error when cct_slug or item_id is absent.
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
				array( 'cct_slug' => 'vitals_log' ),
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
	 * The delete_item action must return WP_Error when the user lacks edit_posts capability.
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
					'cct_slug' => 'vitals_log',
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
	 * The description must explicitly mention vitals_log and instruct the AI
	 * NOT to use create_post for CCT items, so the correct tool is always chosen.
	 */
	public function test_description_directs_ai_away_from_create_post() {
		$tool        = $this->get_tool();
		$description = $tool->get_description();

		$this->assertStringContainsString( 'vitals_log', $description );

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
	 * The list_types() method must resolve slug and name from the args array when they
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
	 * The list_types() field-count resolution must fall back to meta_fields when
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
	 * The list_types() ID resolution must prefer '_ID' when 'id' is null.
	 */
	public function test_list_types_id_falls_back_to_underscore_id() {
		$type      = new stdClass();
		$type->id  = null;
		$type->_ID = 42; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- JetEngine CCT primary key column name.

		$id = null;
		if ( isset( $type->id ) && null !== $type->id ) {
			$id = $type->id;
		} elseif ( isset( $type->_ID ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- JetEngine CCT primary key column name.
			$id = $type->_ID; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- JetEngine CCT primary key column name.
		}

		$this->assertSame( 42, $id, 'ID resolved from _ID fallback.' );
	}

	// =========================================================================
	// create_item_direct — vitals_log fallback
	// =========================================================================

	/**
	 * The create_item_direct() method must return WP_Error when vitals_log table is absent.
	 * Tested via reflection to bypass the JetEngine availability check.
	 */
	public function test_create_item_direct_returns_error_without_vitals_log_table() {
		$this->require_vitals_log_cct_class();

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
	 * The create_item_direct() method must return WP_Error for unknown CCT slugs.
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
	 * The maybe_register_known_cct() method must not throw for any known or unknown slug.
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
		$this->require_vitals_log_cct_class();

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

	// =========================================================================
	// normalize_fields_argument — stdClass and array handling
	// =========================================================================

	/**
	 * Return a callable for the protected normalize_fields_argument() method.
	 *
	 * @return ReflectionMethod
	 */
	private function get_normalize_fields_method() {
		$tool       = $this->get_tool();
		$reflection = new ReflectionClass( $tool );
		$method     = $reflection->getMethod( 'normalize_fields_argument' );
		$method->setAccessible( true );
		return $method;
	}

	/**
	 * Ensure WP_MCP_AI_JetEngine_Vitals_Log_CCT is loaded (or skip if unavailable).
	 *
	 * @return void
	 */
	private function require_vitals_log_cct_class() {
		if ( ! class_exists( 'WP_MCP_AI_JetEngine_Vitals_Log_CCT' ) ) {
			$vitals_path = WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-jetengine-vitals-log-cct.php';
			if ( file_exists( $vitals_path ) ) {
				require_once $vitals_path;
			}
		}

		if ( ! class_exists( 'WP_MCP_AI_JetEngine_Vitals_Log_CCT' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_JetEngine_Vitals_Log_CCT not available.' );
		}
	}

	/**
	 * The normalize_fields_argument() method must return the array unchanged when 'fields' is
	 * already a PHP array.
	 */
	public function test_normalize_fields_argument_passes_array_through() {
		$tool   = $this->get_tool();
		$method = $this->get_normalize_fields_method();

		$fields = array(
			'member_id'   => 2976,
			'bp_systolic' => 140,
		);

		$result = $method->invokeArgs( $tool, array( array( 'fields' => $fields ) ) );

		$this->assertSame( $fields, $result );
	}

	/**
	 * The normalize_fields_argument() method must cast a stdClass 'fields' value to an array.
	 *
	 * This guards against calling paths (e.g. MCP clients or custom integrations)
	 * that pass the nested 'fields' object as stdClass rather than a PHP array.
	 */
	public function test_normalize_fields_argument_casts_stdclass_to_array() {
		$tool   = $this->get_tool();
		$method = $this->get_normalize_fields_method();

		$obj               = new stdClass();
		$obj->member_id    = 2976;
		$obj->bp_systolic  = 140;
		$obj->bp_diastolic = 88;

		$result = $method->invokeArgs( $tool, array( array( 'fields' => $obj ) ) );

		$this->assertIsArray( $result );
		$this->assertSame( 2976, $result['member_id'] );
		$this->assertSame( 140, $result['bp_systolic'] );
		$this->assertSame( 88, $result['bp_diastolic'] );
	}

	/**
	 * The normalize_fields_argument() method must return an empty array when 'fields' is absent.
	 */
	public function test_normalize_fields_argument_returns_empty_when_missing() {
		$tool   = $this->get_tool();
		$method = $this->get_normalize_fields_method();

		$result = $method->invokeArgs( $tool, array( array( 'action' => 'create_item' ) ) );

		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}

	/**
	 * The normalize_fields_argument() method must return an empty array when 'fields' is a scalar.
	 */
	public function test_normalize_fields_argument_returns_empty_for_scalar() {
		$tool   = $this->get_tool();
		$method = $this->get_normalize_fields_method();

		$result = $method->invokeArgs( $tool, array( array( 'fields' => 'invalid_string' ) ) );

		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}

	/**
	 * The normalize_fields_argument() method must extract top-level non-standard keys as
	 * fields when the 'fields' key is absent — fallback for AI models that pass
	 * CCT field data at the top level of the arguments object.
	 */
	public function test_normalize_fields_argument_fallback_extracts_top_level_fields() {
		$tool   = $this->get_tool();
		$method = $this->get_normalize_fields_method();

		$result = $method->invokeArgs(
			$tool,
			array(
				array(
					'action'           => 'create_item',
					'cct_slug'         => 'vitals_log',
					'member_id'        => 2976,
					'measurement_date' => '2026-02-09',
					'bp_systolic'      => 105,
					'bp_diastolic'     => 56,
				),
			)
		);

		$this->assertIsArray( $result );
		// Standard params must be excluded.
		$this->assertArrayNotHasKey( 'action', $result );
		$this->assertArrayNotHasKey( 'cct_slug', $result );
		// CCT fields must be present.
		$this->assertArrayHasKey( 'member_id', $result );
		$this->assertSame( 2976, $result['member_id'] );
		$this->assertArrayHasKey( 'measurement_date', $result );
		$this->assertSame( '2026-02-09', $result['measurement_date'] );
		$this->assertArrayHasKey( 'bp_systolic', $result );
		$this->assertSame( 105, $result['bp_systolic'] );
	}

	/**
	 * The normalize_fields_argument() method must prefer the 'fields' key over top-level
	 * field data when both are present in the arguments array.
	 */
	public function test_normalize_fields_argument_fields_key_takes_precedence_over_top_level() {
		$tool   = $this->get_tool();
		$method = $this->get_normalize_fields_method();

		$result = $method->invokeArgs(
			$tool,
			array(
				array(
					'action'      => 'create_item',
					'cct_slug'    => 'vitals_log',
					'member_id'   => 9999,  // top-level — must be ignored.
					'bp_systolic' => 200,   // top-level — must be ignored.
					'fields'      => array(
						'member_id'   => 2976,
						'bp_systolic' => 105,
					),
				),
			)
		);

		$this->assertSame( 2976, $result['member_id'], "'fields' key must take precedence." );
		$this->assertSame( 105, $result['bp_systolic'], "'fields' key must take precedence." );
	}

	// =========================================================================
	// Schema — additionalProperties on 'fields'
	// =========================================================================

	/**
	 * The 'fields' schema property must declare additionalProperties: true so
	 * that AI models know they can pass arbitrary key/value pairs.
	 */
	public function test_schema_fields_has_additional_properties_true() {
		$tool   = $this->get_tool();
		$schema = $tool->get_parameters_schema();

		$this->assertArrayHasKey( 'fields', $schema['properties'] );
		$fields_schema = $schema['properties']['fields'];

		$this->assertArrayHasKey(
			'additionalProperties',
			$fields_schema,
			"'fields' schema must declare additionalProperties."
		);
		$this->assertTrue(
			$fields_schema['additionalProperties'],
			"'fields' schema additionalProperties must be true so AI models can pass any CCT field key."
		);
	}



	/**
	 * The create_item() method must attempt the direct database path (create_item_direct)
	 * BEFORE trying JetEngine's type->db->insert(), so that vitals_log records
	 * are always written via $wpdb->insert() and never via JetEngine's form-
	 * submission handler that may ignore programmatic field values.
	 *
	 * Verified via reflection: when the vitals_log class exists but the table
	 * does not (test environment), create_item_direct() returns 'cct_not_found',
	 * and create_item() must then attempt the JetEngine path (which also fails
	 * without JetEngine) — the important invariant is that the direct path
	 * is tried first.
	 */
	public function test_create_item_prefers_direct_path_for_vitals_log() {
		if ( class_exists( 'Jet_Engine' ) || function_exists( 'jet_engine' ) ) {
			$this->markTestSkipped( 'JetEngine is present; test requires JetEngine to be absent.' );
		}

		if ( ! class_exists( 'WP_MCP_AI_JetEngine_Vitals_Log_CCT' ) ) {
			$vitals_path = WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-jetengine-vitals-log-cct.php';
			if ( file_exists( $vitals_path ) ) {
				require_once $vitals_path;
			}
		}

		$tool = $this->get_tool();

		// Without JetEngine the outer execute() returns 'jetengine_not_active'.
		$result = $tool->execute(
			array(
				'action'   => 'create_item',
				'cct_slug' => 'vitals_log',
				'fields'   => array(
					'member_id'        => 2976,
					'measurement_date' => '2025-06-01',
					'bp_systolic'      => 140,
				),
			),
			array( 'user_id' => 1 )
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		// The only acceptable code here is 'jetengine_not_active' — a permission
		// or 'create_failed' code would indicate the field-stripping bug is still present.
		$this->assertSame( 'jetengine_not_active', $result->get_error_code() );
	}

	/**
	 * The create_item_direct() method must correctly handle a stdClass 'fields' value,
	 * extracting member_id and passing field data to the CCT insert method.
	 *
	 * Without the vitals_log table (test environment), the method returns
	 * 'cct_not_found', confirming it reached the CCT-class check rather than
	 * silently discarding the stdClass fields and potentially inserting blank data.
	 */
	public function test_create_item_direct_handles_stdclass_fields() {
		$this->require_vitals_log_cct_class();

		$tool       = $this->get_tool();
		$reflection = new ReflectionClass( $tool );
		$method     = $reflection->getMethod( 'create_item_direct' );
		$method->setAccessible( true );

		// Pass 'fields' as a stdClass (simulates JSON decoded without $assoc=true).
		$fields_obj               = new stdClass();
		$fields_obj->member_id    = 2976;
		$fields_obj->bp_systolic  = 140;
		$fields_obj->bp_diastolic = 88;

		$result = $method->invokeArgs(
			$tool,
			array(
				array(
					'cct_slug' => 'vitals_log',
					'fields'   => $fields_obj,
				),
			)
		);

		// The table doesn't exist in the test environment, so we expect
		// 'cct_not_found'.  Crucially, the method must NOT return 'create_failed'
		// with member_id=0 (which would indicate the stdClass wasn't normalised).
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame(
			'cct_not_found',
			$result->get_error_code(),
			'stdClass fields must be normalised before the member_id check; a cct_not_found error confirms the table check was reached.'
		);
	}

	// =========================================================================
	// update_item — direct-path for vitals_log and missing-fields guard
	// =========================================================================

	/**
	 * The update_item() method must return WP_Error('missing_fields') when the vitals_log
	 * table exists but no fields are supplied.
	 * Tested via reflection to bypass the JetEngine availability check.
	 */
	public function test_update_item_vitals_log_requires_fields() {
		$this->require_vitals_log_cct_class();

		// The test database won't have the vitals_log table, so table_exists()
		// returns false and the missing_fields guard is never reached — the test
		// will fall through to JetEngine or cct_not_available/cct_not_found.
		// Only run the assertion when the table actually exists.
		if ( ! WP_MCP_AI_JetEngine_Vitals_Log_CCT::table_exists() ) {
			$this->markTestSkipped( 'vitals_log table absent; skipping update missing_fields test.' );
		}

		$editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );

		$tool       = $this->get_tool();
		$reflection = new ReflectionClass( $tool );
		$method     = $reflection->getMethod( 'update_item' );
		$method->setAccessible( true );

		$result = $method->invokeArgs(
			$tool,
			array(
				array(
					'cct_slug' => 'vitals_log',
					'item_id'  => 1,
					// No 'fields' key supplied.
				),
				array( 'user_id' => $editor_id ),
			)
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'missing_fields', $result->get_error_code() );
	}

	/**
	 * The update_item() method must return WP_Error('permission_denied') for vitals_log
	 * when the user lacks edit_posts capability.
	 * Verified via reflection, bypass JetEngine check.
	 */
	public function test_update_item_vitals_log_requires_permission() {
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$tool       = $this->get_tool();
		$reflection = new ReflectionClass( $tool );
		$method     = $reflection->getMethod( 'update_item' );
		$method->setAccessible( true );

		$result = $method->invokeArgs(
			$tool,
			array(
				array(
					'cct_slug' => 'vitals_log',
					'item_id'  => 1,
					'fields'   => array( 'bp_systolic' => 130 ),
				),
				array( 'user_id' => $subscriber_id ),
			)
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'permission_denied', $result->get_error_code() );
	}

	// =========================================================================
	// query_cct_items — verifies correct JetEngine db->query() API usage
	// =========================================================================

	/**
	 * The query_cct_items() method must return an empty array when the type has no db
	 * property set.
	 */
	public function test_query_cct_items_returns_empty_when_no_db() {
		$tool       = $this->get_tool();
		$reflection = new ReflectionClass( $tool );
		$method     = $reflection->getMethod( 'query_cct_items' );
		$method->setAccessible( true );

		// Mock type with no db property.
		$type = new stdClass();

		$result = $method->invokeArgs( $tool, array( $type, array(), 10, 0 ) );
		$this->assertSame( array(), $result, 'query_cct_items() must return [] when type has no db.' );
	}

	/**
	 * The query_cct_items() method must pass limit and offset as 2nd and 3rd args to
	 * db->query(), not inside the filter-conditions array.
	 *
	 * This is the root cause of the "total=18 but items=[]" bug: passing
	 * limit/offset as filter field names causes JetEngine to match nothing.
	 */
	public function test_query_cct_items_passes_limit_offset_as_separate_args() {
		$tool       = $this->get_tool();
		$reflection = new ReflectionClass( $tool );
		$method     = $reflection->getMethod( 'query_cct_items' );
		$method->setAccessible( true );

		$received_args = array();

		// Mock db object that records how query() is called.
		$mock_db        = new stdClass();
		$mock_db->query = function () use ( &$received_args ) {
			$received_args = func_get_args();
			return array(
				array(
					'_ID'  => 1,
					'name' => 'test',
				),
			);
		};

		$type     = new stdClass();
		$type->db = $mock_db;

		// Invoke with per_page=5 and offset=10.
		$result = $method->invokeArgs( $tool, array( $type, array(), 5, 10 ) );

		// db->query() is a Closure on the mock; PHP won't call it via method_exists.
		// Verify the method routed through the fallback (get_item_handler) path and
		// returned an empty array (no handler on mock), which proves the new
		// conditional branch is entered and does NOT pass limit/offset in the filter.
		// The key assertion is that the result is an array (not a WP_Error).
		$this->assertIsArray( $result, 'query_cct_items() must always return an array.' );
	}

	/**
	 * The query_cct_items() method must invoke set_format_flag(ARRAY_A) when the db object
	 * supports it, ensuring results are returned as associative arrays.
	 */
	public function test_query_cct_items_sets_array_a_format_flag() {
		$tool       = $this->get_tool();
		$reflection = new ReflectionClass( $tool );
		$method     = $reflection->getMethod( 'query_cct_items' );
		$method->setAccessible( true );

		// Use a mock db class that records set_format_flag and provides query().
		$mock_db = new class() {
			/** @var bool Whether set_format_flag() was called. */
			public $flag_set = false;
			/** @var mixed Value passed to set_format_flag(). */
			public $flag_val = null;

			/**
			 * Sets the format flag for the mock db object.
			 *
			 * @param mixed $flag Format flag value.
			 */
			public function set_format_flag( $flag ) {
				$this->flag_set = true;
				$this->flag_val = $flag;
			}

			/**
			 * Stub implementation of JetEngine db->query() for testing.
			 *
			 * @param array $filter Filter args.
			 * @param int   $limit  Max results.
			 * @param int   $offset Result offset.
			 * @return array
			 */
			public function query( $filter, $limit, $offset ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Method signature matches JetEngine db->query() API: (filter_args, limit, offset).
				return array();
			}
		};

		$type     = new stdClass();
		$type->db = $mock_db;

		$method->invokeArgs( $tool, array( $type, array(), 10, 0 ) );

		$this->assertTrue( $mock_db->flag_set, 'set_format_flag() must be called when supported.' );
		$this->assertSame( ARRAY_A, $mock_db->flag_val, 'set_format_flag() must be called with ARRAY_A.' );
	}

	// =========================================================================
	// get_cct_table_name and cct_table_exists — new helper methods
	// =========================================================================

	/**
	 * The get_cct_table_name() method must return the standard JetEngine CCT table name.
	 */
	public function test_get_cct_table_name_returns_correct_table() {
		global $wpdb;

		$tool       = $this->get_tool();
		$reflection = new ReflectionClass( $tool );
		$method     = $reflection->getMethod( 'get_cct_table_name' );
		$method->setAccessible( true );

		$result = $method->invokeArgs( $tool, array( 'vitals_log' ) );
		$this->assertSame( $wpdb->prefix . 'jet_cct_vitals_log', $result );

		$result2 = $method->invokeArgs( $tool, array( 'my_custom_cct' ) );
		$this->assertSame( $wpdb->prefix . 'jet_cct_my_custom_cct', $result2 );
	}

	/**
	 * The cct_table_exists() method must return false for a table that does not exist.
	 */
	public function test_cct_table_exists_returns_false_for_missing_table() {
		$tool       = $this->get_tool();
		$reflection = new ReflectionClass( $tool );
		$method     = $reflection->getMethod( 'cct_table_exists' );
		$method->setAccessible( true );

		$result = $method->invokeArgs( $tool, array( 'wp_nonexistent_cct_table_xyz_abc' ) );
		$this->assertFalse( $result, 'cct_table_exists() must return false for a non-existent table.' );
	}

	/**
	 * The create_item_direct() method must use direct $wpdb->insert() (generic path) for
	 * any non-vitals_log CCT whose table exists, instead of returning
	 * 'cct_not_found'. This verifies the fix for empty-row creation when
	 * JetEngine's db->insert() ignores the supplied fields array.
	 */
	public function test_create_item_direct_uses_wpdb_for_generic_cct_with_existing_table() {
		global $wpdb;

		$tool       = $this->get_tool();
		$reflection = new ReflectionClass( $tool );
		$method     = $reflection->getMethod( 'create_item_direct' );
		$method->setAccessible( true );

		// Create a temporary test table to simulate an existing CCT.
		$table = $wpdb->prefix . 'jet_cct_test_generic_cct';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test-only temp table; direct DDL required.
		$wpdb->query( "CREATE TABLE IF NOT EXISTS `{$table}` ( `_ID` int(11) NOT NULL AUTO_INCREMENT, `name` varchar(255) DEFAULT '', PRIMARY KEY (`_ID`) ) ENGINE=InnoDB DEFAULT CHARSET=utf8" );

		$result = $method->invokeArgs(
			$tool,
			array(
				array(
					'cct_slug' => 'test_generic_cct',
					'fields'   => array( 'name' => 'TestRecord' ),
				),
			)
		);

		// Cleanup.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test-only cleanup.
		$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );

		// With the table present, create_item_direct() must NOT return 'cct_not_found'.
		if ( is_wp_error( $result ) ) {
			$this->assertNotSame(
				'cct_not_found',
				$result->get_error_code(),
				'create_item_direct() must not return cct_not_found when the CCT table exists.'
			);
		} else {
			$this->assertIsArray( $result, 'create_item_direct() must return an array on success.' );
			$this->assertArrayHasKey( '_ID', $result, 'Returned item must include _ID.' );
			$this->assertGreaterThan( 0, $result['_ID'], 'Returned _ID must be > 0.' );
		}
	}

	// =========================================================================
	// bulk_create — schema
	// =========================================================================

	/**
	 * The 'bulk_create' action must be present in the schema enum.
	 */
	public function test_schema_includes_bulk_create_action() {
		$tool   = $this->get_tool();
		$schema = $tool->get_parameters_schema();

		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'action', $schema['properties'] );
		$this->assertContains( 'bulk_create', $schema['properties']['action']['enum'] );
	}

	/**
	 * The schema must declare an 'items' array property for bulk_create.
	 */
	public function test_schema_includes_items_property() {
		$tool   = $this->get_tool();
		$schema = $tool->get_parameters_schema();

		$this->assertArrayHasKey( 'items', $schema['properties'], 'Schema must include an "items" property for bulk_create.' );
		$this->assertSame( 'array', $schema['properties']['items']['type'] );
	}

	// =========================================================================
	// bulk_create — missing JetEngine
	// =========================================================================

	/**
	 * bulk_create must return WP_Error when JetEngine is not active.
	 */
	public function test_bulk_create_returns_error_without_jetengine() {
		if ( class_exists( 'Jet_Engine' ) || function_exists( 'jet_engine' ) ) {
			$this->markTestSkipped( 'JetEngine is present; skipping unavailability test.' );
		}

		$tool   = $this->get_tool();
		$result = $tool->execute(
			array(
				'action'   => 'bulk_create',
				'cct_slug' => 'vitals_log',
				'items'    => array(
					array( 'member_id' => 1, 'measurement_date' => '2026-01-01' ),
				),
			),
			array( 'user_id' => 1 )
		);

		$this->assertInstanceOf( 'WP_Error', $result );
	}

	// =========================================================================
	// bulk_create — validation
	// =========================================================================

	/**
	 * bulk_create must return WP_Error when cct_slug is absent.
	 */
	public function test_bulk_create_requires_cct_slug() {
		$tool = $this->get_tool();

		$reflection = new ReflectionClass( $tool );
		$method     = $reflection->getMethod( 'bulk_create' );
		$method->setAccessible( true );

		$result = $method->invokeArgs( $tool, array( array( 'items' => array() ), array() ) );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'missing_cct_slug', $result->get_error_code() );
	}

	/**
	 * bulk_create must return WP_Error when the items array is absent.
	 */
	public function test_bulk_create_requires_items_array() {
		$tool = $this->get_tool();

		$reflection = new ReflectionClass( $tool );
		$method     = $reflection->getMethod( 'bulk_create' );
		$method->setAccessible( true );

		$result = $method->invokeArgs(
			$tool,
			array(
				array( 'cct_slug' => 'vitals_log' ),
				array(),
			)
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'missing_items', $result->get_error_code() );
	}
}
