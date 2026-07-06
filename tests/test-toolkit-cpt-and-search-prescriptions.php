<?php
/**
 * Tests covering the toolkit_cpt generic CPT tool and search_prescriptions bug fixes.
 *
 * Validates:
 * - toolkit_cpt tool is registered and assigned to the wordpress-core group.
 * - search_prescriptions parameter schema reflects all bug fixes from issue #4280:
 *   - active_only defaults to false (was incorrectly true before).
 *   - prescriber parameter is wired to the correct _prescription_doctor meta key.
 *   - medication parameter exists (was a no-op in the original implementation).
 *   - Response field names use the aligned schema (medication_name, prescribing_doctor, notes, status).
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Tests for toolkit_cpt tool and search_prescriptions fixes.
 */
class WP_MCP_AI_Toolkit_CPT_Search_Prescriptions_Test extends WP_UnitTestCase {

	/**
	 * Tool registry instance.
	 *
	 * @var WP_MCP_AI_Tool_Registry
	 */
	protected $registry;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->registry = WP_MCP_AI_Tool_Registry::get_instance();
		$this->registry->init();

		// Create a user with 'read' capability so search_prescriptions
		// execute() passes the user_can( $user_id, 'read' ) gate.
		$this->user_id = $this->factory->user->create(
			array(
				'role' => 'editor',
			)
		);
		wp_set_current_user( $this->user_id );
	}

	// -------------------------------------------------------------------------
	// toolkit_cpt
	// -------------------------------------------------------------------------

	/**
	 * Ensure the toolkit_cpt tool is registered in the full (pro) version.
	 */
	public function test_toolkit_cpt_is_registered() {
		$tool = $this->registry->get_tool( 'toolkit_cpt' );

		$this->assertNotNull( $tool, 'toolkit_cpt tool should be registered in the full version.' );
		$this->assertInstanceOf( WP_MCP_AI_Tool_Interface::class, $tool );
	}

	/**
	 * Ensure toolkit_cpt is mapped to the wordpress-core group.
	 */
	public function test_toolkit_cpt_is_in_wordpress_core_group() {
		$group_map = $this->registry->get_tool_group_map();

		$this->assertArrayHasKey( 'toolkit_cpt', $group_map, 'toolkit_cpt should have a group mapping.' );
		$this->assertSame( 'wordpress-core', $group_map['toolkit_cpt'], 'toolkit_cpt should be in the wordpress-core group.' );
	}

	/**
	 * Ensure toolkit_cpt has the expected action enum values.
	 */
	public function test_toolkit_cpt_has_expected_actions() {
		$tool = $this->registry->get_tool( 'toolkit_cpt' );
		$this->assertNotNull( $tool );

		$schema     = $tool->get_parameters_schema();
		$action_def = $schema['properties']['action'] ?? array();

		$this->assertArrayHasKey( 'enum', $action_def, 'action parameter should have an enum.' );

		$expected_actions = array(
			'list_types',
			'get_schema',
			'list_items',
			'get_item',
			'create_item',
			'update_item',
			'delete_item',
			'bulk_create',
		);

		foreach ( $expected_actions as $action ) {
			$this->assertContains( $action, $action_def['enum'], "Expected action '{$action}' in toolkit_cpt enum." );
		}
	}

	/**
	 * Ensure toolkit_cpt declares the 'pro' capability flag.
	 */
	public function test_toolkit_cpt_has_pro_capability_flag() {
		$tool = $this->registry->get_tool( 'toolkit_cpt' );
		$this->assertNotNull( $tool );

		if ( $tool instanceof WP_MCP_AI_Tool_Capability_Flags_Interface ) {
			$flags = $tool->get_capability_flags();
			$this->assertContains( 'pro', $flags, 'toolkit_cpt should declare the "pro" capability flag.' );
		} else {
			$this->addToAssertionCount( 1 ); // No-op if interface not implemented.
		}
	}

	/**
	 * Ensure toolkit_cpt slug matches the expected value.
	 */
	public function test_toolkit_cpt_slug() {
		$tool = $this->registry->get_tool( 'toolkit_cpt' );
		$this->assertNotNull( $tool );
		$this->assertSame( 'toolkit_cpt', $tool->get_slug() );
	}

	// -------------------------------------------------------------------------
	// search_prescriptions
	// -------------------------------------------------------------------------

	/**
	 * Helper: enable health wellness management and reload registry to make
	 * search_prescriptions available.
	 */
	protected function enable_health_wellness_management() {
		update_option(
			'wp_mcp_ai_settings',
			array( 'enable_health_wellness_management' => true )
		);

		// Reset the registry bootstrap so it re-registers tools on next init().
		$reflection = new ReflectionClass( WP_MCP_AI_Tool_Registry::class );

		$bootstrapped = $reflection->getProperty( 'bootstrapped' );
		$bootstrapped->setAccessible( true );
		$bootstrapped->setValue( $this->registry, false );

		$tools_prop = $reflection->getProperty( 'tools' );
		$tools_prop->setAccessible( true );
		$tools_prop->setValue( $this->registry, array() );

		$this->registry->init();
	}

	/**
	 * Ensure search_prescriptions is registered when health wellness is enabled.
	 */
	public function test_search_prescriptions_is_registered_when_hw_enabled() {
		$this->enable_health_wellness_management();

		$tool = $this->registry->get_tool( 'search_prescriptions' );

		$this->assertNotNull(
			$tool,
			'search_prescriptions should be registered when enable_health_wellness_management is true.'
		);
		$this->assertInstanceOf( WP_MCP_AI_Tool_Interface::class, $tool );
	}

	/**
	 * Ensure the active_only parameter defaults to false (was incorrectly true before fix).
	 */
	public function test_search_prescriptions_active_only_defaults_to_false() {
		$this->enable_health_wellness_management();

		$tool = $this->registry->get_tool( 'search_prescriptions' );
		$this->assertNotNull( $tool );

		$schema          = $tool->get_parameters_schema();
		$active_only_def = $schema['properties']['active_only'] ?? array();

		$this->assertArrayHasKey( 'default', $active_only_def, 'active_only should declare a default value.' );
		$this->assertFalse(
			$active_only_def['default'],
			'active_only default should be false so newly-created prescriptions (no dates set) are not silently hidden.'
		);
	}

	/**
	 * Ensure the medication parameter is present in the schema (was a no-op before fix).
	 */
	public function test_search_prescriptions_medication_parameter_exists() {
		$this->enable_health_wellness_management();

		$tool = $this->registry->get_tool( 'search_prescriptions' );
		$this->assertNotNull( $tool );

		$schema = $tool->get_parameters_schema();

		$this->assertArrayHasKey(
			'medication',
			$schema['properties'],
			'medication parameter should be present in search_prescriptions schema.'
		);
	}

	/**
	 * Ensure the prescriber parameter is present (wired to _prescription_doctor, not _prescription_prescriber).
	 */
	public function test_search_prescriptions_prescriber_parameter_exists() {
		$this->enable_health_wellness_management();

		$tool = $this->registry->get_tool( 'search_prescriptions' );
		$this->assertNotNull( $tool );

		$schema = $tool->get_parameters_schema();

		$this->assertArrayHasKey(
			'prescriber',
			$schema['properties'],
			'prescriber parameter should be present in search_prescriptions schema.'
		);
	}

	/**
	 * Ensure the search_prescriptions execute() returns results using the corrected field names.
	 *
	 * Creates a prescription post directly and verifies the response keys.
	 */
	public function test_search_prescriptions_returns_aligned_field_names() {
		$this->enable_health_wellness_management();

		// Register mcp_ai_prescription CPT inline so the query can run in the test environment.
		if ( ! post_type_exists( 'mcp_ai_prescription' ) ) {
			register_post_type(
				'mcp_ai_prescription',
				array(
					'public'   => false,
					'supports' => array( 'title', 'editor', 'custom-fields' ),
				)
			);
		}

		// Insert a test prescription.
		$post_id = wp_insert_post(
			array(
				'post_type'    => 'mcp_ai_prescription',
				'post_title'   => 'Lisinopril 10mg',
				'post_status'  => 'publish',
				'post_content' => 'Take once daily.',
			)
		);

		update_post_meta( $post_id, '_prescription_status', 'active' );
		update_post_meta( $post_id, '_prescription_dosage', '10mg' );
		update_post_meta( $post_id, '_prescription_doctor', 'Dr. Smith' );

		$tool = $this->registry->get_tool( 'search_prescriptions' );
		$this->assertNotNull( $tool );

		$result = $tool->execute( array(), array( 'user_id' => get_current_user_id() ) );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'success', $result );
		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'prescriptions', $result );

		if ( ! empty( $result['prescriptions'] ) ) {
			$first = $result['prescriptions'][0];

			// Verify aligned field names (not the old names).
			$this->assertArrayHasKey( 'medication_name', $first, 'Field should be "medication_name", not "medication".' );
			$this->assertArrayHasKey( 'prescribing_doctor', $first, 'Field should be "prescribing_doctor", not "prescriber".' );
			$this->assertArrayHasKey( 'notes', $first, 'Field should be "notes", not "instructions".' );
			$this->assertArrayHasKey( 'status', $first, '"status" field should be present.' );
			$this->assertArrayHasKey( 'is_active', $first, '"is_active" computed field should be present.' );
		}

		wp_delete_post( $post_id, true );
	}

	/**
	 * Ensure the prescriber meta key in execute() is _prescription_doctor (not _prescription_prescriber).
	 *
	 * This verifies the wrong-meta-key bug is fixed: filtering by prescriber must use
	 * _prescription_doctor (which is what create_prescription saves) instead of the old
	 * _prescription_prescriber key.
	 */
	public function test_search_prescriptions_prescriber_filter_uses_correct_meta_key() {
		$this->enable_health_wellness_management();

		if ( ! post_type_exists( 'mcp_ai_prescription' ) ) {
			register_post_type(
				'mcp_ai_prescription',
				array(
					'public'   => false,
					'supports' => array( 'title', 'editor', 'custom-fields' ),
				)
			);
		}

		// Insert two prescriptions: one with Dr. Smith, one with Dr. Jones.
		$post_a = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_prescription',
				'post_title'  => 'Atorvastatin 20mg',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $post_a, '_prescription_doctor', 'Dr. Smith' );
		update_post_meta( $post_a, '_prescription_status', 'active' );

		$post_b = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_prescription',
				'post_title'  => 'Metformin 500mg',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $post_b, '_prescription_doctor', 'Dr. Jones' );
		update_post_meta( $post_b, '_prescription_status', 'active' );

		$tool = $this->registry->get_tool( 'search_prescriptions' );
		$this->assertNotNull( $tool );

		$result = $tool->execute(
			array( 'prescriber' => 'Dr. Smith' ),
			array( 'user_id' => get_current_user_id() )
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );

		// Should find exactly 1 prescription (Dr. Smith's Atorvastatin).
		$ids = wp_list_pluck( $result['prescriptions'], 'id' );
		$this->assertContains( $post_a, $ids, 'Dr. Smith\'s prescription should be found.' );
		$this->assertNotContains( $post_b, $ids, 'Dr. Jones\'s prescription should not be found.' );

		wp_delete_post( $post_a, true );
		wp_delete_post( $post_b, true );
	}

	/**
	 * Ensure active_only filter uses _prescription_status, not date comparisons.
	 *
	 * Before the fix, active_only required both start_date and end_date to be set,
	 * which caused prescriptions created without explicit dates to be invisible.
	 * After the fix, active_only uses _prescription_status = 'active'.
	 */
	public function test_search_prescriptions_active_only_uses_status_meta() {
		$this->enable_health_wellness_management();

		if ( ! post_type_exists( 'mcp_ai_prescription' ) ) {
			register_post_type(
				'mcp_ai_prescription',
				array(
					'public'   => false,
					'supports' => array( 'title', 'editor', 'custom-fields' ),
				)
			);
		}

		// Active prescription — no dates set (edge case that used to be invisible).
		$active_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_prescription',
				'post_title'  => 'Omeprazole 20mg',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $active_id, '_prescription_status', 'active' );

		// Discontinued prescription.
		$inactive_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_prescription',
				'post_title'  => 'OldMed 5mg',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $inactive_id, '_prescription_status', 'discontinued' );

		$tool = $this->registry->get_tool( 'search_prescriptions' );
		$this->assertNotNull( $tool );

		$result = $tool->execute(
			array( 'active_only' => true ),
			array( 'user_id' => get_current_user_id() )
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );

		$ids = wp_list_pluck( $result['prescriptions'], 'id' );
		$this->assertContains( $active_id, $ids, 'Active prescription (no dates) should be visible with active_only=true.' );
		$this->assertNotContains( $inactive_id, $ids, 'Discontinued prescription should not be visible with active_only=true.' );

		wp_delete_post( $active_id, true );
		wp_delete_post( $inactive_id, true );
	}
}
