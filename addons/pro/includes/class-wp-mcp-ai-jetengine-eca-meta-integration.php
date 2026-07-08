<?php
/**
 * JetEngine Meta Field Integration for ECA & Student CPTs.
 *
 * Registers the pre-existing `_eca_*` and `_student_*` post meta fields
 * with JetEngine's internal registry so they become discoverable in the
 * Listing Builder, Dynamic Field selector, and Query Builder without
 * requiring the user to manually recreate them in JetEngine's UI.
 *
 * This class calls `store_fields()` only — it does NOT create
 * `Jet_Engine_CPT_Meta` instances, so the existing native WordPress
 * metaboxes (Details, Schedule, Enrollment) are not duplicated.
 *
 * @package WP_MCP_AI_Pro
 * @since   3.5.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers NV oOS ECA/Student meta fields with JetEngine's field registry.
 *
 * Hooks:
 *   - jet-engine/meta-boxes/register-instances → registers field definitions
 *     so they appear in Listing Builder, Dynamic Tags, and JetEngine Query.
 */
class WP_MCP_AI_JetEngine_ECA_Meta_Integration {

	/**
	 * Whether hooks have been registered.
	 *
	 * @var bool
	 */
	private static $registered = false;

	/**
	 * Register hooks if JetEngine is active.
	 *
	 * @return void
	 */
	public static function init() {
		if ( self::$registered ) {
			return;
		}

		if ( ! function_exists( 'jet_engine' ) || ! class_exists( 'Jet_Engine' ) ) {
			return;
		}

		self::$registered = true;

		add_action(
			'jet-engine/meta-boxes/register-instances',
			array( __CLASS__, 'register_eca_meta_fields' ),
			20
		);
	}

	/**
	 * Register ECA and Student meta field definitions with JetEngine.
	 *
	 * Called on `jet-engine/meta-boxes/register-instances` after JetEngine
	 * has initialised its meta boxes module. Only calls `store_fields()` to
	 * populate JetEngine's internal field registry; does NOT create metabox
	 * instances (which would duplicate the existing native metaboxes).
	 *
	 * @param object $meta JetEngine meta boxes manager instance.
	 * @return void
	 */
	public static function register_eca_meta_fields( $meta ) {
		// Check ECA management is enabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_eca_management'] ) ) {
			return;
		}

		// Register ECA fields.
		$eca_fields = self::get_eca_field_definitions();
		$meta->store_fields( 'mcp_ai_eca', $eca_fields, 'post_type' );

		// Register Student fields.
		$student_fields = self::get_student_field_definitions();
		$meta->store_fields( 'mcp_ai_student', $student_fields, 'post_type' );
	}

	/**
	 * Build the JetEngine field definitions for the ECA post type.
	 *
	 * Field type mapping (NV oOS → JetEngine):
	 *   string  → text
	 *   integer → number
	 *   number  → number
	 *   boolean → switcher
	 *   array   → textarea (serialised storage; read-only hint via description)
	 *
	 * Each field's `name` matches the actual `_eca_*` meta key so that
	 * existing post meta values are read without migration.
	 *
	 * @return array JetEngine-compatible field definitions.
	 */
	private static function get_eca_field_definitions() {
		return array(
			// ---- Details metabox fields ----------------------------------------
			array(
				'title'       => __( 'ECA Code', 'mcp-ai-wpoos-pro' ),
				'name'        => '_eca_code',
				'object_type' => 'field',
				'type'        => 'text',
				'width'       => '100%',
				'description' => __( 'Short code identifier for the extra-curricular activity.', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'title'       => __( 'ECA Type', 'mcp-ai-wpoos-pro' ),
				'name'        => '_eca_type',
				'object_type' => 'field',
				'type'        => 'select',
				'width'       => '100%',
				'description' => __( 'Category of activity (e.g. sport, arts, academic, service).', 'mcp-ai-wpoos-pro' ),
				'options'     => array(
					array( 'key' => 'sports', 'value' => __( 'Sports', 'mcp-ai-wpoos-pro' ) ),
					array( 'key' => 'arts', 'value' => __( 'Arts', 'mcp-ai-wpoos-pro' ) ),
					array( 'key' => 'academic', 'value' => __( 'Academic', 'mcp-ai-wpoos-pro' ) ),
					array( 'key' => 'service', 'value' => __( 'Community Service', 'mcp-ai-wpoos-pro' ) ),
					array( 'key' => 'leadership', 'value' => __( 'Leadership', 'mcp-ai-wpoos-pro' ) ),
					array( 'key' => 'technology', 'value' => __( 'Technology', 'mcp-ai-wpoos-pro' ) ),
				),
			),
			array(
				'title'       => __( 'Venue', 'mcp-ai-wpoos-pro' ),
				'name'        => '_eca_venue',
				'object_type' => 'field',
				'type'        => 'text',
				'width'       => '100%',
				'description' => __( 'Location where the activity takes place.', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'title'       => __( 'Status', 'mcp-ai-wpoos-pro' ),
				'name'        => '_eca_status',
				'object_type' => 'field',
				'type'        => 'select',
				'width'       => '100%',
				'description' => __( 'Activity status.', 'mcp-ai-wpoos-pro' ),
				'options'     => array(
					array( 'key' => 'active', 'value' => __( 'Active', 'mcp-ai-wpoos-pro' ) ),
					array( 'key' => 'inactive', 'value' => __( 'Inactive', 'mcp-ai-wpoos-pro' ) ),
					array( 'key' => 'cancelled', 'value' => __( 'Cancelled', 'mcp-ai-wpoos-pro' ) ),
				),
			),
			array(
				'title'       => __( 'Paid Activity', 'mcp-ai-wpoos-pro' ),
				'name'        => '_eca_is_paid',
				'object_type' => 'field',
				'type'        => 'switcher',
				'width'       => '100%',
				'description' => __( 'Whether the activity requires a participation fee.', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'title'       => __( 'Cost', 'mcp-ai-wpoos-pro' ),
				'name'        => '_eca_cost',
				'object_type' => 'field',
				'type'        => 'number',
				'width'       => '100%',
				'description' => __( 'Participation fee amount.', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'title'       => __( 'Cost Period', 'mcp-ai-wpoos-pro' ),
				'name'        => '_eca_cost_period',
				'object_type' => 'field',
				'type'        => 'select',
				'width'       => '100%',
				'description' => __( 'Billing period for the cost.', 'mcp-ai-wpoos-pro' ),
				'options'     => array(
					array( 'key' => 'per_term', 'value' => __( 'Per Term', 'mcp-ai-wpoos-pro' ) ),
					array( 'key' => 'per_year', 'value' => __( 'Per Year', 'mcp-ai-wpoos-pro' ) ),
					array( 'key' => 'one_time', 'value' => __( 'One Time', 'mcp-ai-wpoos-pro' ) ),
				),
			),

			// ---- Schedule metabox fields ---------------------------------------
			array(
				'title'       => __( 'Day', 'mcp-ai-wpoos-pro' ),
				'name'        => '_eca_day',
				'object_type' => 'field',
				'type'        => 'select',
				'width'       => '100%',
				'description' => __( 'Day of the week the activity runs.', 'mcp-ai-wpoos-pro' ),
				'options'     => array(
					array( 'key' => 'Monday', 'value' => __( 'Monday', 'mcp-ai-wpoos-pro' ) ),
					array( 'key' => 'Tuesday', 'value' => __( 'Tuesday', 'mcp-ai-wpoos-pro' ) ),
					array( 'key' => 'Wednesday', 'value' => __( 'Wednesday', 'mcp-ai-wpoos-pro' ) ),
					array( 'key' => 'Thursday', 'value' => __( 'Thursday', 'mcp-ai-wpoos-pro' ) ),
					array( 'key' => 'Friday', 'value' => __( 'Friday', 'mcp-ai-wpoos-pro' ) ),
				),
			),
			array(
				'title'       => __( 'Start Time', 'mcp-ai-wpoos-pro' ),
				'name'        => '_eca_start_time',
				'object_type' => 'field',
				'type'        => 'time',
				'width'       => '100%',
				'description' => __( 'Activity start time in HH:MM (24-hour) format.', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'title'       => __( 'End Time', 'mcp-ai-wpoos-pro' ),
				'name'        => '_eca_end_time',
				'object_type' => 'field',
				'type'        => 'time',
				'width'       => '100%',
				'description' => __( 'Activity end time in HH:MM (24-hour) format.', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'title'       => __( 'Teachers / Staff', 'mcp-ai-wpoos-pro' ),
				'name'        => '_eca_teachers',
				'object_type' => 'field',
				'type'        => 'textarea',
				'width'       => '100%',
				'description' => __( 'Comma-separated WordPress user IDs supervising the activity.', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'title'       => __( 'Eligible Year Groups', 'mcp-ai-wpoos-pro' ),
				'name'        => '_eca_year_groups',
				'object_type' => 'field',
				'type'        => 'textarea',
				'width'       => '100%',
				'description' => __( 'Comma-separated year groups eligible for this ECA.', 'mcp-ai-wpoos-pro' ),
			),

			// ---- Enrollment metabox fields ------------------------------------
			array(
				'title'       => __( 'Max Students', 'mcp-ai-wpoos-pro' ),
				'name'        => '_eca_max_students',
				'object_type' => 'field',
				'type'        => 'number',
				'width'       => '100%',
				'description' => __( 'Maximum number of students allowed to enroll.', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'title'       => __( 'Requires Audition', 'mcp-ai-wpoos-pro' ),
				'name'        => '_eca_requires_audition',
				'object_type' => 'field',
				'type'        => 'switcher',
				'width'       => '100%',
				'description' => __( 'Whether students must audition or try out to join.', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'title'       => __( 'Booking Type', 'mcp-ai-wpoos-pro' ),
				'name'        => '_eca_booking_type',
				'object_type' => 'field',
				'type'        => 'select',
				'width'       => '100%',
				'description' => __( 'How enrollment is handled.', 'mcp-ai-wpoos-pro' ),
				'options'     => array(
					array( 'key' => 'open', 'value' => __( 'Open', 'mcp-ai-wpoos-pro' ) ),
					array( 'key' => 'audition', 'value' => __( 'Audition', 'mcp-ai-wpoos-pro' ) ),
					array( 'key' => 'invite_only', 'value' => __( 'Invite Only', 'mcp-ai-wpoos-pro' ) ),
				),
			),
			array(
				'title'       => __( 'Current Enrollment', 'mcp-ai-wpoos-pro' ),
				'name'        => '_eca_current_enrollment',
				'object_type' => 'field',
				'type'        => 'number',
				'width'       => '100%',
				'description' => __( 'Current number of enrolled students. Managed automatically by enrollment tools — manual editing is not recommended.', 'mcp-ai-wpoos-pro' ),
			),
		);
	}

	/**
	 * Build the JetEngine field definitions for the Student post type.
	 *
	 * @return array JetEngine-compatible field definitions.
	 */
	private static function get_student_field_definitions() {
		return array(
			array(
				'title'       => __( 'First Name', 'mcp-ai-wpoos-pro' ),
				'name'        => '_student_first_name',
				'object_type' => 'field',
				'type'        => 'text',
				'width'       => '100%',
				'description' => __( "Student's given (first) name.", 'mcp-ai-wpoos-pro' ),
			),
			array(
				'title'       => __( 'Last Name', 'mcp-ai-wpoos-pro' ),
				'name'        => '_student_last_name',
				'object_type' => 'field',
				'type'        => 'text',
				'width'       => '100%',
				'description' => __( "Student's family (last) name.", 'mcp-ai-wpoos-pro' ),
			),
			array(
				'title'       => __( 'Year Group', 'mcp-ai-wpoos-pro' ),
				'name'        => '_student_year_group',
				'object_type' => 'field',
				'type'        => 'text',
				'width'       => '100%',
				'description' => __( 'Academic year group or grade level (e.g. "Year 10", "Grade 5").', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'title'       => __( 'House', 'mcp-ai-wpoos-pro' ),
				'name'        => '_student_house',
				'object_type' => 'field',
				'type'        => 'text',
				'width'       => '100%',
				'description' => __( 'School house or homeroom group.', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'title'       => __( 'Email', 'mcp-ai-wpoos-pro' ),
				'name'        => '_student_email',
				'object_type' => 'field',
				'type'        => 'text',
				'width'       => '100%',
				'description' => __( "Student's school or personal email address.", 'mcp-ai-wpoos-pro' ),
			),
			array(
				'title'       => __( 'iSAMS ID', 'mcp-ai-wpoos-pro' ),
				'name'        => '_student_isams_id',
				'object_type' => 'field',
				'type'        => 'text',
				'width'       => '100%',
				'description' => __( 'Unique identifier from the iSAMS MIS system.', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'title'       => __( 'ECA Enrollments', 'mcp-ai-wpoos-pro' ),
				'name'        => '_student_eca_enrollments',
				'object_type' => 'field',
				'type'        => 'textarea',
				'width'       => '100%',
				'description' => __( 'Serialised array of ECA post IDs the student is enrolled in. Managed by AI tools — manual editing is not recommended.', 'mcp-ai-wpoos-pro' ),
			),
		);
	}
}

WP_MCP_AI_JetEngine_ECA_Meta_Integration::init();
