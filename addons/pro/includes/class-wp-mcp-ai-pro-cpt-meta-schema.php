<?php
/**
 * Pro CPT Meta Schema Registry.
 *
 * Registers custom meta-field definitions for every CPT managed by the pro
 * toolkit so they are exposed via the `get_post_type_schema` base tool.
 *
 * @package WP_MCP_AI_Pro
 * @since   3.4.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides custom meta-field schema definitions for all pro-managed CPTs.
 *
 * Other code can retrieve the schema for a specific CPT via:
 *   apply_filters( 'wp_mcp_ai_post_type_meta_schema', array(), $post_type )
 *
 * or directly:
 *   WP_MCP_AI_Pro_CPT_Meta_Schema::get( 'mcp_ai_task' )
 */
class WP_MCP_AI_Pro_CPT_Meta_Schema {

	/**
	 * Initialize the class by hooking into the base tool filter.
	 *
	 * @since 3.4.0
	 */
	public static function init() {
		add_filter( 'wp_mcp_ai_post_type_meta_schema', array( __CLASS__, 'filter_meta_schema' ), 10, 2 );
	}

	/**
	 * Filter callback: inject the pro meta schema for a given post type.
	 *
	 * @param array  $schema    Existing meta schema (may be populated by other hooks).
	 * @param string $post_type The post type slug being described.
	 * @return array Merged meta schema.
	 */
	public static function filter_meta_schema( $schema, $post_type ) {
		$pro_schema = self::get( $post_type );
		if ( ! empty( $pro_schema ) ) {
			$schema = array_merge( $schema, $pro_schema );
		}
		return $schema;
	}

	/**
	 * Return the meta-field schema for a given pro-managed CPT.
	 *
	 * Each entry is keyed by the meta key (without the leading underscore used
	 * for internal storage) and contains:
	 *   - meta_key   (string)  Actual storage key used in get/update_post_meta.
	 *   - label      (string)  Human-readable field label.
	 *   - type       (string)  JSON-Schema primitive: string|integer|number|boolean|array|object.
	 *   - description (string) What the field stores.
	 *   - enum       (array)   Optional list of allowed values.
	 *
	 * @param string $post_type The CPT slug.
	 * @return array Meta field definitions, or empty array for unknown post types.
	 */
	public static function get( $post_type ) {
		$schemas = self::all_schemas();
		return isset( $schemas[ $post_type ] ) ? $schemas[ $post_type ] : array();
	}

	/**
	 * Return meta schemas for all pro-managed CPTs.
	 *
	 * @return array Associative array keyed by CPT slug.
	 */
	public static function all_schemas() {
		return array(

			// ----------------------------------------------------------------
			// Project Management Toolkit
			// ----------------------------------------------------------------

			'mcp_ai_task'         => array(
				'_task_status'           => array(
					'meta_key'    => '_task_status',
					'label'       => __( 'Task Status', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Current workflow status of the task.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'todo', 'in-progress', 'review', 'completed', 'cancelled' ),
				),
				'_task_priority'         => array(
					'meta_key'    => '_task_priority',
					'label'       => __( 'Priority', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Task urgency level.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'low', 'medium', 'high', 'urgent' ),
				),
				'_task_category'         => array(
					'meta_key'    => '_task_category',
					'label'       => __( 'Category', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Task category/type.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'general', 'bug', 'feature', 'maintenance', 'research', 'documentation', 'design', 'testing' ),
				),
				'_task_project_id'       => array(
					'meta_key'    => '_task_project_id',
					'label'       => __( 'Project ID', 'mcp-ai-wpoos-pro' ),
					'type'        => 'integer',
					'description' => __( 'ID of the parent mcp_ai_project post.', 'mcp-ai-wpoos-pro' ),
				),
				'_task_due_date'         => array(
					'meta_key'    => '_task_due_date',
					'label'       => __( 'Due Date', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Due date in YYYY-MM-DD format.', 'mcp-ai-wpoos-pro' ),
				),
				'_task_assigned_to'      => array(
					'meta_key'    => '_task_assigned_to',
					'label'       => __( 'Assigned To', 'mcp-ai-wpoos-pro' ),
					'type'        => 'integer',
					'description' => __( 'WordPress user ID the task is assigned to.', 'mcp-ai-wpoos-pro' ),
				),
				'_task_tags'             => array(
					'meta_key'    => '_task_tags',
					'label'       => __( 'Tags', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Comma-separated list of tags.', 'mcp-ai-wpoos-pro' ),
				),
				'_task_estimated_effort' => array(
					'meta_key'    => '_task_estimated_effort',
					'label'       => __( 'Estimated Effort (hours)', 'mcp-ai-wpoos-pro' ),
					'type'        => 'number',
					'description' => __( 'Estimated hours required to complete the task.', 'mcp-ai-wpoos-pro' ),
				),
				'_task_actual_effort'    => array(
					'meta_key'    => '_task_actual_effort',
					'label'       => __( 'Actual Effort (hours)', 'mcp-ai-wpoos-pro' ),
					'type'        => 'number',
					'description' => __( 'Actual hours spent on the task.', 'mcp-ai-wpoos-pro' ),
				),
			),

			'mcp_ai_project'      => array(
				'_project_status'      => array(
					'meta_key'    => '_project_status',
					'label'       => __( 'Project Status', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Current phase of the project.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'planning', 'active', 'on-hold', 'completed', 'cancelled' ),
				),
				'_project_start_date'  => array(
					'meta_key'    => '_project_start_date',
					'label'       => __( 'Start Date', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Project start date in YYYY-MM-DD format.', 'mcp-ai-wpoos-pro' ),
				),
				'_project_end_date'    => array(
					'meta_key'    => '_project_end_date',
					'label'       => __( 'End Date', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Project end/deadline date in YYYY-MM-DD format.', 'mcp-ai-wpoos-pro' ),
				),
				'_project_assigned_to' => array(
					'meta_key'    => '_project_assigned_to',
					'label'       => __( 'Assigned To', 'mcp-ai-wpoos-pro' ),
					'type'        => 'array',
					'description' => __( 'Array of WordPress user IDs assigned to the project.', 'mcp-ai-wpoos-pro' ),
				),
			),

			'mcp_task_plan'       => array(
				'_goal'        => array(
					'meta_key'    => '_goal',
					'label'       => __( 'Goal', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'High-level goal the plan is intended to achieve.', 'mcp-ai-wpoos-pro' ),
				),
				'_task_count'  => array(
					'meta_key'    => '_task_count',
					'label'       => __( 'Task Count', 'mcp-ai-wpoos-pro' ),
					'type'        => 'integer',
					'description' => __( 'Number of tasks defined in the plan.', 'mcp-ai-wpoos-pro' ),
				),
				'_project_id'  => array(
					'meta_key'    => '_project_id',
					'label'       => __( 'Project ID', 'mcp-ai-wpoos-pro' ),
					'type'        => 'integer',
					'description' => __( 'ID of the mcp_ai_project this plan belongs to.', 'mcp-ai-wpoos-pro' ),
				),
				'_template_id' => array(
					'meta_key'    => '_template_id',
					'label'       => __( 'Template ID', 'mcp-ai-wpoos-pro' ),
					'type'        => 'integer',
					'description' => __( 'ID of the mcp_task_template used to generate this plan.', 'mcp-ai-wpoos-pro' ),
				),
			),

			'mcp_task_template'   => array(
				'_category'     => array(
					'meta_key'    => '_category',
					'label'       => __( 'Category', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Template category for filtering.', 'mcp-ai-wpoos-pro' ),
				),
				'_task_count'   => array(
					'meta_key'    => '_task_count',
					'label'       => __( 'Task Count', 'mcp-ai-wpoos-pro' ),
					'type'        => 'integer',
					'description' => __( 'Number of tasks defined in the template.', 'mcp-ai-wpoos-pro' ),
				),
				'_placeholders' => array(
					'meta_key'    => '_placeholders',
					'label'       => __( 'Placeholders', 'mcp-ai-wpoos-pro' ),
					'type'        => 'array',
					'description' => __( 'List of {{placeholder}} tokens found in the template markdown.', 'mcp-ai-wpoos-pro' ),
				),
			),

			// ----------------------------------------------------------------
			// Event Management Toolkit
			// ----------------------------------------------------------------

			'mcp_ai_event'        => array(
				'_event_start_date' => array(
					'meta_key'    => '_event_start_date',
					'label'       => __( 'Start Date', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Event start date in YYYY-MM-DD format.', 'mcp-ai-wpoos-pro' ),
				),
				'_event_end_date'   => array(
					'meta_key'    => '_event_end_date',
					'label'       => __( 'End Date', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Event end date in YYYY-MM-DD format. Defaults to start date for single-day events.', 'mcp-ai-wpoos-pro' ),
				),
				'_event_start_time' => array(
					'meta_key'    => '_event_start_time',
					'label'       => __( 'Start Time', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Event start time in HH:MM (24-hour) format. Empty for all-day events.', 'mcp-ai-wpoos-pro' ),
				),
				'_event_end_time'   => array(
					'meta_key'    => '_event_end_time',
					'label'       => __( 'End Time', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Event end time in HH:MM (24-hour) format.', 'mcp-ai-wpoos-pro' ),
				),
				'_event_all_day'    => array(
					'meta_key'    => '_event_all_day',
					'label'       => __( 'All Day', 'mcp-ai-wpoos-pro' ),
					'type'        => 'boolean',
					'description' => __( 'Whether the event spans the entire day (stored as "1" or "0").', 'mcp-ai-wpoos-pro' ),
				),
				'_event_type'       => array(
					'meta_key'    => '_event_type',
					'label'       => __( 'Event Type', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Classification of the event.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'meeting', 'deadline', 'milestone', 'reminder', 'other' ),
				),
				'_event_location'   => array(
					'meta_key'    => '_event_location',
					'label'       => __( 'Location', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Physical or virtual location for the event.', 'mcp-ai-wpoos-pro' ),
				),
				'_event_attendees'  => array(
					'meta_key'    => '_event_attendees',
					'label'       => __( 'Attendees', 'mcp-ai-wpoos-pro' ),
					'type'        => 'array',
					'description' => __( 'Serialized array of WordPress user IDs attending the event.', 'mcp-ai-wpoos-pro' ),
				),
				'_event_project_id' => array(
					'meta_key'    => '_event_project_id',
					'label'       => __( 'Project ID', 'mcp-ai-wpoos-pro' ),
					'type'        => 'integer',
					'description' => __( 'ID of the mcp_ai_project this event is associated with.', 'mcp-ai-wpoos-pro' ),
				),
			),

			// ----------------------------------------------------------------
			// Places Toolkit
			// ----------------------------------------------------------------

			'mcp_ai_place'        => array(
				'_place_type'               => array(
					'meta_key'    => '_place_type',
					'label'       => __( 'Place Type', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Category of the place (e.g. restaurant, hotel, museum, park).', 'mcp-ai-wpoos-pro' ),
				),
				'_place_address'            => array(
					'meta_key'    => '_place_address',
					'label'       => __( 'Full Address', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Full street address as a single string.', 'mcp-ai-wpoos-pro' ),
				),
				'_place_latitude'           => array(
					'meta_key'    => '_place_latitude',
					'label'       => __( 'Latitude', 'mcp-ai-wpoos-pro' ),
					'type'        => 'number',
					'description' => __( 'Latitude coordinate (−90 to 90).', 'mcp-ai-wpoos-pro' ),
				),
				'_place_longitude'          => array(
					'meta_key'    => '_place_longitude',
					'label'       => __( 'Longitude', 'mcp-ai-wpoos-pro' ),
					'type'        => 'number',
					'description' => __( 'Longitude coordinate (−180 to 180).', 'mcp-ai-wpoos-pro' ),
				),
				'_place_address_components' => array(
					'meta_key'    => '_place_address_components',
					'label'       => __( 'Address Components', 'mcp-ai-wpoos-pro' ),
					'type'        => 'object',
					'description' => __( 'Structured address parts (street, city, state, country, postal_code).', 'mcp-ai-wpoos-pro' ),
				),
				'_place_phone'              => array(
					'meta_key'    => '_place_phone',
					'label'       => __( 'Phone', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Contact phone number.', 'mcp-ai-wpoos-pro' ),
				),
				'_place_email'              => array(
					'meta_key'    => '_place_email',
					'label'       => __( 'Email', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Contact email address.', 'mcp-ai-wpoos-pro' ),
				),
				'_place_website'            => array(
					'meta_key'    => '_place_website',
					'label'       => __( 'Website', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Website URL.', 'mcp-ai-wpoos-pro' ),
				),
				'_place_rating'             => array(
					'meta_key'    => '_place_rating',
					'label'       => __( 'Rating', 'mcp-ai-wpoos-pro' ),
					'type'        => 'number',
					'description' => __( 'Overall rating from 0 to 5.', 'mcp-ai-wpoos-pro' ),
				),
				'_place_price_level'        => array(
					'meta_key'    => '_place_price_level',
					'label'       => __( 'Price Level', 'mcp-ai-wpoos-pro' ),
					'type'        => 'integer',
					'description' => __( 'Price tier from 1 (cheapest) to 4 (most expensive).', 'mcp-ai-wpoos-pro' ),
				),
				'_place_business_hours'     => array(
					'meta_key'    => '_place_business_hours',
					'label'       => __( 'Business Hours', 'mcp-ai-wpoos-pro' ),
					'type'        => 'object',
					'description' => __( 'Serialized object of hours by weekday (monday, tuesday, … sunday).', 'mcp-ai-wpoos-pro' ),
				),
				'_place_amenities'          => array(
					'meta_key'    => '_place_amenities',
					'label'       => __( 'Amenities', 'mcp-ai-wpoos-pro' ),
					'type'        => 'array',
					'description' => __( 'Serialized array of amenity slugs (e.g. "wifi", "parking").', 'mcp-ai-wpoos-pro' ),
				),
				'_place_google_place_id'    => array(
					'meta_key'    => '_place_google_place_id',
					'label'       => __( 'Google Place ID', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Google Places API identifier for this location.', 'mcp-ai-wpoos-pro' ),
				),
			),

			// ----------------------------------------------------------------
			// Health & Wellness Toolkit
			// ----------------------------------------------------------------

			'mcp_ai_member'       => array(
				'_member_date_of_birth'      => array(
					'meta_key'    => '_member_date_of_birth',
					'label'       => __( 'Date of Birth', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Date of birth in YYYY-MM-DD format.', 'mcp-ai-wpoos-pro' ),
				),
				'_member_gender'             => array(
					'meta_key'    => '_member_gender',
					'label'       => __( 'Gender', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Self-reported gender.', 'mcp-ai-wpoos-pro' ),
				),
				'_member_blood_type'         => array(
					'meta_key'    => '_member_blood_type',
					'label'       => __( 'Blood Type', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'ABO blood group (e.g. A+, O−).', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-' ),
				),
				'_member_email'              => array(
					'meta_key'    => '_member_email',
					'label'       => __( 'Email', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Contact email address for the member.', 'mcp-ai-wpoos-pro' ),
				),
				'_member_phone'              => array(
					'meta_key'    => '_member_phone',
					'label'       => __( 'Phone', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Contact phone number for the member.', 'mcp-ai-wpoos-pro' ),
				),
				'_member_address'            => array(
					'meta_key'    => '_member_address',
					'label'       => __( 'Address', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Physical address of the member.', 'mcp-ai-wpoos-pro' ),
				),
				'_member_emergency_contact'  => array(
					'meta_key'    => '_member_emergency_contact',
					'label'       => __( 'Emergency Contact', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Emergency contact name and details.', 'mcp-ai-wpoos-pro' ),
				),
				'_member_mrn'                => array(
					'meta_key'    => '_member_mrn',
					'label'       => __( 'Medical Record Number (MRN)', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Internal or provider-assigned medical record number.', 'mcp-ai-wpoos-pro' ),
				),
				'_member_preferred_pharmacy' => array(
					'meta_key'    => '_member_preferred_pharmacy',
					'label'       => __( 'Preferred Pharmacy', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Preferred pharmacy name and/or address.', 'mcp-ai-wpoos-pro' ),
				),
				'_pet_species'               => array(
					'meta_key'    => '_pet_species',
					'label'       => __( 'Species (pet)', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Animal species when member type is "pet".', 'mcp-ai-wpoos-pro' ),
				),
				'_pet_breed'                 => array(
					'meta_key'    => '_pet_breed',
					'label'       => __( 'Breed (pet)', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Animal breed when member type is "pet".', 'mcp-ai-wpoos-pro' ),
				),
			),

			'mcp_ai_med_record'   => array(
				'_medical_record_member_id'           => array(
					'meta_key'    => '_medical_record_member_id',
					'label'       => __( 'Member ID', 'mcp-ai-wpoos-pro' ),
					'type'        => 'integer',
					'description' => __( 'ID of the mcp_ai_member this record belongs to.', 'mcp-ai-wpoos-pro' ),
				),
				'_medical_record_date'                => array(
					'meta_key'    => '_medical_record_date',
					'label'       => __( 'Record Date', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Date the medical record was created (YYYY-MM-DD).', 'mcp-ai-wpoos-pro' ),
				),
				'_medical_record_icd_code'            => array(
					'meta_key'    => '_medical_record_icd_code',
					'label'       => __( 'ICD-10 Code', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'ICD-10-CM diagnosis code (e.g. J18.9).', 'mcp-ai-wpoos-pro' ),
				),
				'_medical_record_lab_value'           => array(
					'meta_key'    => '_medical_record_lab_value',
					'label'       => __( 'Lab Value', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Numeric or text result of a lab test.', 'mcp-ai-wpoos-pro' ),
				),
				'_medical_record_lab_unit'            => array(
					'meta_key'    => '_medical_record_lab_unit',
					'label'       => __( 'Lab Unit', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Unit of measure for the lab value (e.g. mg/dL, mmol/L).', 'mcp-ai-wpoos-pro' ),
				),
				'_medical_record_lab_reference_range' => array(
					'meta_key'    => '_medical_record_lab_reference_range',
					'label'       => __( 'Lab Reference Range', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Normal range for the lab value (e.g. "70–100").', 'mcp-ai-wpoos-pro' ),
				),
				'_medical_record_lab_abnormal'        => array(
					'meta_key'    => '_medical_record_lab_abnormal',
					'label'       => __( 'Abnormal Flag', 'mcp-ai-wpoos-pro' ),
					'type'        => 'boolean',
					'description' => __( 'Whether the lab result is outside the reference range.', 'mcp-ai-wpoos-pro' ),
				),
				'_medical_record_provider'            => array(
					'meta_key'    => '_medical_record_provider',
					'label'       => __( 'Provider', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Name of the healthcare provider or facility.', 'mcp-ai-wpoos-pro' ),
				),
			),

			'mcp_ai_allergy'      => array(
				'_allergy_member_id'          => array(
					'meta_key'    => '_allergy_member_id',
					'label'       => __( 'Member ID', 'mcp-ai-wpoos-pro' ),
					'type'        => 'integer',
					'description' => __( 'ID of the mcp_ai_member this allergy record belongs to.', 'mcp-ai-wpoos-pro' ),
				),
				'_allergy_allergen'           => array(
					'meta_key'    => '_allergy_allergen',
					'label'       => __( 'Allergen', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'The substance causing the allergic reaction.', 'mcp-ai-wpoos-pro' ),
				),
				'_allergy_severity'           => array(
					'meta_key'    => '_allergy_severity',
					'label'       => __( 'Severity', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Clinical severity of the allergy.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'mild', 'moderate', 'severe', 'life-threatening' ),
				),
				'_allergy_type'               => array(
					'meta_key'    => '_allergy_type',
					'label'       => __( 'Allergy Type', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'FHIR allergy category.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'food', 'medication', 'environment', 'biologic', 'other' ),
				),
				'_allergy_onset_type'         => array(
					'meta_key'    => '_allergy_onset_type',
					'label'       => __( 'Onset Type', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Speed of allergic reaction onset.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'immediate', 'delayed' ),
				),
				'_allergy_treatment'          => array(
					'meta_key'    => '_allergy_treatment',
					'label'       => __( 'Treatment', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Treatment or management notes for the allergy.', 'mcp-ai-wpoos-pro' ),
				),
				'_allergy_reactions'          => array(
					'meta_key'    => '_allergy_reactions',
					'label'       => __( 'Reactions', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Comma-separated list of observed reaction symptoms.', 'mcp-ai-wpoos-pro' ),
				),
				'_allergy_diagnosed_date'     => array(
					'meta_key'    => '_allergy_diagnosed_date',
					'label'       => __( 'Diagnosed Date', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Date allergy was first diagnosed (YYYY-MM-DD).', 'mcp-ai-wpoos-pro' ),
				),
				'_allergy_last_reaction_date' => array(
					'meta_key'    => '_allergy_last_reaction_date',
					'label'       => __( 'Last Reaction Date', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Date of the most recent allergic reaction (YYYY-MM-DD).', 'mcp-ai-wpoos-pro' ),
				),
			),

			'mcp_ai_prescription' => array(
				'_prescription_member_id'         => array(
					'meta_key'    => '_prescription_member_id',
					'label'       => __( 'Member ID', 'mcp-ai-wpoos-pro' ),
					'type'        => 'integer',
					'description' => __( 'ID of the mcp_ai_member this prescription belongs to.', 'mcp-ai-wpoos-pro' ),
				),
				'_prescription_medication_name'   => array(
					'meta_key'    => '_prescription_medication_name',
					'label'       => __( 'Medication Name', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Brand or generic name of the prescribed medication.', 'mcp-ai-wpoos-pro' ),
				),
				'_prescription_dosage'            => array(
					'meta_key'    => '_prescription_dosage',
					'label'       => __( 'Dosage', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Dosage strength (e.g. "10mg").', 'mcp-ai-wpoos-pro' ),
				),
				'_prescription_frequency'         => array(
					'meta_key'    => '_prescription_frequency',
					'label'       => __( 'Frequency', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Administration frequency (e.g. "twice daily").', 'mcp-ai-wpoos-pro' ),
				),
				'_prescription_status'            => array(
					'meta_key'    => '_prescription_status',
					'label'       => __( 'Status', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Current prescription status.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'active', 'completed', 'discontinued', 'on-hold' ),
				),
				'_prescription_doctor'            => array(
					'meta_key'    => '_prescription_doctor',
					'label'       => __( 'Prescribing Doctor', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Name of the prescribing physician.', 'mcp-ai-wpoos-pro' ),
				),
				'_prescription_start_date'        => array(
					'meta_key'    => '_prescription_start_date',
					'label'       => __( 'Start Date', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Date prescription was started (YYYY-MM-DD).', 'mcp-ai-wpoos-pro' ),
				),
				'_prescription_end_date'          => array(
					'meta_key'    => '_prescription_end_date',
					'label'       => __( 'End Date', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Date prescription ends or ended (YYYY-MM-DD).', 'mcp-ai-wpoos-pro' ),
				),
				'_prescription_refills_remaining' => array(
					'meta_key'    => '_prescription_refills_remaining',
					'label'       => __( 'Refills Remaining', 'mcp-ai-wpoos-pro' ),
					'type'        => 'integer',
					'description' => __( 'Number of refills remaining on the prescription.', 'mcp-ai-wpoos-pro' ),
				),
				'_prescription_rx_number'         => array(
					'meta_key'    => '_prescription_rx_number',
					'label'       => __( 'Rx Number', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Pharmacy prescription number.', 'mcp-ai-wpoos-pro' ),
				),
				'_prescription_ndc_code'          => array(
					'meta_key'    => '_prescription_ndc_code',
					'label'       => __( 'NDC Code', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'National Drug Code (11-digit, format: XXXXX-XXXX-XX).', 'mcp-ai-wpoos-pro' ),
				),
				'_prescription_route'             => array(
					'meta_key'    => '_prescription_route',
					'label'       => __( 'Route', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Route of administration (e.g. oral, topical, injection).', 'mcp-ai-wpoos-pro' ),
				),
				'_prescription_quantity'          => array(
					'meta_key'    => '_prescription_quantity',
					'label'       => __( 'Quantity', 'mcp-ai-wpoos-pro' ),
					'type'        => 'number',
					'description' => __( 'Quantity dispensed per fill.', 'mcp-ai-wpoos-pro' ),
				),
				'_prescription_quantity_unit'     => array(
					'meta_key'    => '_prescription_quantity_unit',
					'label'       => __( 'Quantity Unit', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Unit of the dispensed quantity (e.g. tablets, mL).', 'mcp-ai-wpoos-pro' ),
				),
				'_prescription_indication'        => array(
					'meta_key'    => '_prescription_indication',
					'label'       => __( 'Indication', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Medical reason or diagnosis for which the medication was prescribed.', 'mcp-ai-wpoos-pro' ),
				),
			),

			'mcp_ai_checkup'      => array(
				'_checkup_member_id'        => array(
					'meta_key'    => '_checkup_member_id',
					'label'       => __( 'Member ID', 'mcp-ai-wpoos-pro' ),
					'type'        => 'integer',
					'description' => __( 'ID of the mcp_ai_member this checkup belongs to.', 'mcp-ai-wpoos-pro' ),
				),
				'_checkup_datetime'         => array(
					'meta_key'    => '_checkup_datetime',
					'label'       => __( 'Appointment Date/Time', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'ISO 8601 datetime of the appointment (YYYY-MM-DD HH:MM).', 'mcp-ai-wpoos-pro' ),
				),
				'_checkup_status'           => array(
					'meta_key'    => '_checkup_status',
					'label'       => __( 'Status', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Appointment status.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'scheduled', 'completed', 'cancelled', 'no-show' ),
				),
				'_checkup_provider'         => array(
					'meta_key'    => '_checkup_provider',
					'label'       => __( 'Provider', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Name of the healthcare provider or practice.', 'mcp-ai-wpoos-pro' ),
				),
				'_checkup_location'         => array(
					'meta_key'    => '_checkup_location',
					'label'       => __( 'Location', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Clinic, hospital, or virtual appointment location.', 'mcp-ai-wpoos-pro' ),
				),
				'_checkup_type'             => array(
					'meta_key'    => '_checkup_type',
					'label'       => __( 'Appointment Type', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Kind of medical appointment (e.g. annual, specialist, follow-up).', 'mcp-ai-wpoos-pro' ),
				),
				'_checkup_chief_complaint'  => array(
					'meta_key'    => '_checkup_chief_complaint',
					'label'       => __( 'Chief Complaint', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Primary reason for the visit in the patient\'s own words.', 'mcp-ai-wpoos-pro' ),
				),
				'_checkup_diagnosis'        => array(
					'meta_key'    => '_checkup_diagnosis',
					'label'       => __( 'Diagnosis', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Diagnosis or assessment made during the visit.', 'mcp-ai-wpoos-pro' ),
				),
				'_checkup_duration_minutes' => array(
					'meta_key'    => '_checkup_duration_minutes',
					'label'       => __( 'Duration (minutes)', 'mcp-ai-wpoos-pro' ),
					'type'        => 'integer',
					'description' => __( 'Length of the appointment in minutes.', 'mcp-ai-wpoos-pro' ),
				),
				'_checkup_copay_amount'     => array(
					'meta_key'    => '_checkup_copay_amount',
					'label'       => __( 'Copay Amount', 'mcp-ai-wpoos-pro' ),
					'type'        => 'number',
					'description' => __( 'Patient copay amount paid at the visit.', 'mcp-ai-wpoos-pro' ),
				),
				'_checkup_follow_up_date'   => array(
					'meta_key'    => '_checkup_follow_up_date',
					'label'       => __( 'Follow-Up Date', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Scheduled follow-up date (YYYY-MM-DD).', 'mcp-ai-wpoos-pro' ),
				),
			),

			'mcp_ai_policy'       => array(
				'_policy_member_id'         => array(
					'meta_key'    => '_policy_member_id',
					'label'       => __( 'Member ID', 'mcp-ai-wpoos-pro' ),
					'type'        => 'integer',
					'description' => __( 'ID of the mcp_ai_member this insurance policy belongs to.', 'mcp-ai-wpoos-pro' ),
				),
				'_policy_number'            => array(
					'meta_key'    => '_policy_number',
					'label'       => __( 'Policy Number', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Insurance policy or member ID number.', 'mcp-ai-wpoos-pro' ),
				),
				'_policy_group_number'      => array(
					'meta_key'    => '_policy_group_number',
					'label'       => __( 'Group Number', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Employer or plan group number.', 'mcp-ai-wpoos-pro' ),
				),
				'_policy_plan_type'         => array(
					'meta_key'    => '_policy_plan_type',
					'label'       => __( 'Plan Type', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Insurance plan category (e.g. HMO, PPO, EPO, HDHP).', 'mcp-ai-wpoos-pro' ),
				),
				'_policy_copay_primary'     => array(
					'meta_key'    => '_policy_copay_primary',
					'label'       => __( 'Primary Care Copay', 'mcp-ai-wpoos-pro' ),
					'type'        => 'number',
					'description' => __( 'Copay amount for primary care visits.', 'mcp-ai-wpoos-pro' ),
				),
				'_policy_copay_specialist'  => array(
					'meta_key'    => '_policy_copay_specialist',
					'label'       => __( 'Specialist Copay', 'mcp-ai-wpoos-pro' ),
					'type'        => 'number',
					'description' => __( 'Copay amount for specialist visits.', 'mcp-ai-wpoos-pro' ),
				),
				'_policy_deductible'        => array(
					'meta_key'    => '_policy_deductible',
					'label'       => __( 'Deductible', 'mcp-ai-wpoos-pro' ),
					'type'        => 'number',
					'description' => __( 'Annual deductible amount.', 'mcp-ai-wpoos-pro' ),
				),
				'_policy_out_of_pocket_max' => array(
					'meta_key'    => '_policy_out_of_pocket_max',
					'label'       => __( 'Out-of-Pocket Maximum', 'mcp-ai-wpoos-pro' ),
					'type'        => 'number',
					'description' => __( 'Annual out-of-pocket maximum.', 'mcp-ai-wpoos-pro' ),
				),
				'_policy_rx_bin'            => array(
					'meta_key'    => '_policy_rx_bin',
					'label'       => __( 'Rx BIN', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Pharmacy Benefit Manager bank identification number.', 'mcp-ai-wpoos-pro' ),
				),
				'_policy_rx_pcn'            => array(
					'meta_key'    => '_policy_rx_pcn',
					'label'       => __( 'Rx PCN', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Processor Control Number for pharmacy claims.', 'mcp-ai-wpoos-pro' ),
				),
				'_policy_rx_group'          => array(
					'meta_key'    => '_policy_rx_group',
					'label'       => __( 'Rx Group', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Pharmacy benefit group number.', 'mcp-ai-wpoos-pro' ),
				),
				'_policy_provider'          => array(
					'meta_key'    => '_policy_provider',
					'label'       => __( 'Insurance Provider', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Name of the insurance company.', 'mcp-ai-wpoos-pro' ),
				),
				'_policy_status'            => array(
					'meta_key'    => '_policy_status',
					'label'       => __( 'Status', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Current policy status.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'active', 'inactive', 'pending', 'cancelled' ),
				),
				'_policy_effective_date'    => array(
					'meta_key'    => '_policy_effective_date',
					'label'       => __( 'Effective Date', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Policy effective/start date (YYYY-MM-DD).', 'mcp-ai-wpoos-pro' ),
				),
				'_policy_expiration_date'   => array(
					'meta_key'    => '_policy_expiration_date',
					'label'       => __( 'Expiration Date', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Policy expiration date (YYYY-MM-DD).', 'mcp-ai-wpoos-pro' ),
				),
			),

			// ----------------------------------------------------------------
			// Education / iSAMS Toolkit
			// ----------------------------------------------------------------

			'mcp_ai_student'      => array(
				'_student_first_name'      => array(
					'meta_key'    => '_student_first_name',
					'label'       => __( 'First Name', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Student\'s given (first) name.', 'mcp-ai-wpoos-pro' ),
				),
				'_student_last_name'       => array(
					'meta_key'    => '_student_last_name',
					'label'       => __( 'Last Name', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Student\'s family (last) name.', 'mcp-ai-wpoos-pro' ),
				),
				'_student_year_group'      => array(
					'meta_key'    => '_student_year_group',
					'label'       => __( 'Year Group', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Academic year group or grade level (e.g. "Year 10", "Grade 5").', 'mcp-ai-wpoos-pro' ),
				),
				'_student_house'           => array(
					'meta_key'    => '_student_house',
					'label'       => __( 'House', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'School house or homeroom group.', 'mcp-ai-wpoos-pro' ),
				),
				'_student_email'           => array(
					'meta_key'    => '_student_email',
					'label'       => __( 'Email', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Student\'s school or personal email address.', 'mcp-ai-wpoos-pro' ),
				),
				'_student_isams_id'        => array(
					'meta_key'    => '_student_isams_id',
					'label'       => __( 'iSAMS ID', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Unique identifier from the iSAMS MIS system.', 'mcp-ai-wpoos-pro' ),
				),
				'_student_eca_enrollments' => array(
					'meta_key'    => '_student_eca_enrollments',
					'label'       => __( 'ECA Enrollments', 'mcp-ai-wpoos-pro' ),
					'type'        => 'array',
					'description' => __( 'Serialized array of mcp_ai_eca post IDs the student is enrolled in.', 'mcp-ai-wpoos-pro' ),
				),
			),

			'mcp_ai_eca'          => array(
				'_eca_code'              => array(
					'meta_key'    => '_eca_code',
					'label'       => __( 'ECA Code', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Short code identifier for the extra-curricular activity.', 'mcp-ai-wpoos-pro' ),
				),
				'_eca_type'              => array(
					'meta_key'    => '_eca_type',
					'label'       => __( 'ECA Type', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Category of activity (e.g. sport, arts, academic, service).', 'mcp-ai-wpoos-pro' ),
				),
				'_eca_day'               => array(
					'meta_key'    => '_eca_day',
					'label'       => __( 'Day', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Day of the week the activity runs.', 'mcp-ai-wpoos-pro' ),
				),
				'_eca_start_time'        => array(
					'meta_key'    => '_eca_start_time',
					'label'       => __( 'Start Time', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Activity start time in HH:MM (24-hour) format.', 'mcp-ai-wpoos-pro' ),
				),
				'_eca_end_time'          => array(
					'meta_key'    => '_eca_end_time',
					'label'       => __( 'End Time', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Activity end time in HH:MM (24-hour) format.', 'mcp-ai-wpoos-pro' ),
				),
				'_eca_venue'             => array(
					'meta_key'    => '_eca_venue',
					'label'       => __( 'Venue', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Location where the activity takes place.', 'mcp-ai-wpoos-pro' ),
				),
				'_eca_max_students'      => array(
					'meta_key'    => '_eca_max_students',
					'label'       => __( 'Max Students', 'mcp-ai-wpoos-pro' ),
					'type'        => 'integer',
					'description' => __( 'Maximum number of students allowed to enroll.', 'mcp-ai-wpoos-pro' ),
				),
				'_eca_year_groups'       => array(
					'meta_key'    => '_eca_year_groups',
					'label'       => __( 'Eligible Year Groups', 'mcp-ai-wpoos-pro' ),
					'type'        => 'array',
					'description' => __( 'Serialized array of year groups eligible for this ECA.', 'mcp-ai-wpoos-pro' ),
				),
				'_eca_teachers'          => array(
					'meta_key'    => '_eca_teachers',
					'label'       => __( 'Teachers / Staff', 'mcp-ai-wpoos-pro' ),
					'type'        => 'array',
					'description' => __( 'Serialized array of WordPress user IDs supervising the activity.', 'mcp-ai-wpoos-pro' ),
				),
				'_eca_is_paid'           => array(
					'meta_key'    => '_eca_is_paid',
					'label'       => __( 'Paid Activity', 'mcp-ai-wpoos-pro' ),
					'type'        => 'boolean',
					'description' => __( 'Whether the activity requires a participation fee ("yes"/"no").', 'mcp-ai-wpoos-pro' ),
				),
				'_eca_cost'              => array(
					'meta_key'    => '_eca_cost',
					'label'       => __( 'Cost', 'mcp-ai-wpoos-pro' ),
					'type'        => 'number',
					'description' => __( 'Participation fee amount.', 'mcp-ai-wpoos-pro' ),
				),
				'_eca_cost_period'       => array(
					'meta_key'    => '_eca_cost_period',
					'label'       => __( 'Cost Period', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Billing period for the cost (e.g. per_term, per_year, one_time).', 'mcp-ai-wpoos-pro' ),
				),
				'_eca_requires_audition' => array(
					'meta_key'    => '_eca_requires_audition',
					'label'       => __( 'Requires Audition', 'mcp-ai-wpoos-pro' ),
					'type'        => 'boolean',
					'description' => __( 'Whether students must audition or try out to join ("yes"/"no").', 'mcp-ai-wpoos-pro' ),
				),
				'_eca_booking_type'      => array(
					'meta_key'    => '_eca_booking_type',
					'label'       => __( 'Booking Type', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'How enrollment is handled (e.g. open, audition, invite_only).', 'mcp-ai-wpoos-pro' ),
				),
				'_eca_status'            => array(
					'meta_key'    => '_eca_status',
					'label'       => __( 'Status', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Activity status (e.g. active, inactive, cancelled).', 'mcp-ai-wpoos-pro' ),
				),
			),

			'mcp_ai_quiz'         => array(
				'_mcp_ai_quiz_description'   => array(
					'meta_key'    => '_mcp_ai_quiz_description',
					'label'       => __( 'Description', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Detailed instructions or overview shown to students before the quiz.', 'mcp-ai-wpoos-pro' ),
				),
				'_mcp_ai_quiz_time_limit'    => array(
					'meta_key'    => '_mcp_ai_quiz_time_limit',
					'label'       => __( 'Time Limit (minutes)', 'mcp-ai-wpoos-pro' ),
					'type'        => 'integer',
					'description' => __( 'Maximum time allowed to complete the quiz in minutes (0 = no limit).', 'mcp-ai-wpoos-pro' ),
				),
				'_mcp_ai_quiz_questions'     => array(
					'meta_key'    => '_mcp_ai_quiz_questions',
					'label'       => __( 'Questions', 'mcp-ai-wpoos-pro' ),
					'type'        => 'array',
					'description' => __( 'Serialized array of question objects (text, type, options, correct_answer, points).', 'mcp-ai-wpoos-pro' ),
				),
				'_mcp_ai_quiz_total_points'  => array(
					'meta_key'    => '_mcp_ai_quiz_total_points',
					'label'       => __( 'Total Points', 'mcp-ai-wpoos-pro' ),
					'type'        => 'integer',
					'description' => __( 'Sum of points across all quiz questions.', 'mcp-ai-wpoos-pro' ),
				),
				'_mcp_ai_quiz_passing_score' => array(
					'meta_key'    => '_mcp_ai_quiz_passing_score',
					'label'       => __( 'Passing Score (%)', 'mcp-ai-wpoos-pro' ),
					'type'        => 'integer',
					'description' => __( 'Minimum percentage score required to pass the quiz.', 'mcp-ai-wpoos-pro' ),
				),
			),

			// ----------------------------------------------------------------
			// Media & Content Toolkit
			// ----------------------------------------------------------------

			'mcp_ai_media_tpl'    => array(
				'_template_type'     => array(
					'meta_key'    => '_template_type',
					'label'       => __( 'Template Type', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Media type the template is designed for (e.g. image, video, audio).', 'mcp-ai-wpoos-pro' ),
				),
				'_template_prompts'  => array(
					'meta_key'    => '_template_prompts',
					'label'       => __( 'Prompts', 'mcp-ai-wpoos-pro' ),
					'type'        => 'array',
					'description' => __( 'Serialized array of AI generation prompts used by this template.', 'mcp-ai-wpoos-pro' ),
				),
				'_template_settings' => array(
					'meta_key'    => '_template_settings',
					'label'       => __( 'Settings', 'mcp-ai-wpoos-pro' ),
					'type'        => 'object',
					'description' => __( 'Serialized configuration object (dimensions, style, output format, etc.).', 'mcp-ai-wpoos-pro' ),
				),
			),

			'mcp_ai_media_coll'   => array(
				'_collection_type'  => array(
					'meta_key'    => '_collection_type',
					'label'       => __( 'Collection Type', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Category of media in this collection.', 'mcp-ai-wpoos-pro' ),
				),
				'_collection_items' => array(
					'meta_key'    => '_collection_items',
					'label'       => __( 'Items', 'mcp-ai-wpoos-pro' ),
					'type'        => 'array',
					'description' => __( 'Serialized array of WordPress attachment IDs belonging to this collection.', 'mcp-ai-wpoos-pro' ),
				),
			),

			'mcp_ai_image_tpl'    => array(
				'_image_prompt' => array(
					'meta_key'    => '_image_prompt',
					'label'       => __( 'Image Prompt', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'AI image generation prompt for this template.', 'mcp-ai-wpoos-pro' ),
				),
				'_image_style'  => array(
					'meta_key'    => '_image_style',
					'label'       => __( 'Style', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Visual style directive appended to image generation prompts.', 'mcp-ai-wpoos-pro' ),
				),
				'_image_size'   => array(
					'meta_key'    => '_image_size',
					'label'       => __( 'Size', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Output image dimensions (e.g. 1024x1024, 16:9).', 'mcp-ai-wpoos-pro' ),
				),
			),

			'mcp_ai_doc_tpl'      => array(
				'_doc_template_variables' => array(
					'meta_key'    => '_doc_template_variables',
					'label'       => __( 'Template Variables', 'mcp-ai-wpoos-pro' ),
					'type'        => 'array',
					'description' => __( 'Serialized array of {{variable}} token names used in this document template.', 'mcp-ai-wpoos-pro' ),
				),
				'_doc_template_format'    => array(
					'meta_key'    => '_doc_template_format',
					'label'       => __( 'Output Format', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Target output format when the template is rendered (e.g. html, markdown, pdf).', 'mcp-ai-wpoos-pro' ),
				),
			),

			// ----------------------------------------------------------------
			// CRM / Company Toolkit
			// ----------------------------------------------------------------

			'mcp_ai_company'      => array(
				'_company_industry'    => array(
					'meta_key'    => '_company_industry',
					'label'       => __( 'Industry', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Industry vertical the company operates in.', 'mcp-ai-wpoos-pro' ),
				),
				'_company_size'        => array(
					'meta_key'    => '_company_size',
					'label'       => __( 'Company Size', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Number of employees or size tier (e.g. 1-10, 11-50, 51-200).', 'mcp-ai-wpoos-pro' ),
				),
				'_company_website'     => array(
					'meta_key'    => '_company_website',
					'label'       => __( 'Website', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Company website URL.', 'mcp-ai-wpoos-pro' ),
				),
				'_company_phone'       => array(
					'meta_key'    => '_company_phone',
					'label'       => __( 'Phone', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Primary contact phone number.', 'mcp-ai-wpoos-pro' ),
				),
				'_company_address'     => array(
					'meta_key'    => '_company_address',
					'label'       => __( 'Address', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Company headquarters or main office address.', 'mcp-ai-wpoos-pro' ),
				),
				'_company_status'      => array(
					'meta_key'    => '_company_status',
					'label'       => __( 'Lead Status', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'CRM pipeline status for this company.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'prospect', 'lead', 'qualified', 'customer', 'churned' ),
				),
				'_company_assigned_to' => array(
					'meta_key'    => '_company_assigned_to',
					'label'       => __( 'Assigned To', 'mcp-ai-wpoos-pro' ),
					'type'        => 'integer',
					'description' => __( 'WordPress user ID of the account owner.', 'mcp-ai-wpoos-pro' ),
				),
			),

			// ----------------------------------------------------------------
			// Finance Toolkit
			// ----------------------------------------------------------------

			'mcp_ai_fin_account'  => array(
				'_fin_account_type'        => array(
					'meta_key'    => '_fin_account_type',
					'label'       => __( 'Account Type', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Category of financial account (e.g. checking, savings, credit, investment).', 'mcp-ai-wpoos-pro' ),
				),
				'_fin_account_balance'     => array(
					'meta_key'    => '_fin_account_balance',
					'label'       => __( 'Balance', 'mcp-ai-wpoos-pro' ),
					'type'        => 'number',
					'description' => __( 'Current account balance.', 'mcp-ai-wpoos-pro' ),
				),
				'_fin_account_currency'    => array(
					'meta_key'    => '_fin_account_currency',
					'label'       => __( 'Currency', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'ISO 4217 currency code (e.g. USD, EUR, GBP).', 'mcp-ai-wpoos-pro' ),
				),
				'_fin_account_institution' => array(
					'meta_key'    => '_fin_account_institution',
					'label'       => __( 'Institution', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Name of the financial institution or bank.', 'mcp-ai-wpoos-pro' ),
				),
				'_fin_account_last_sync'   => array(
					'meta_key'    => '_fin_account_last_sync',
					'label'       => __( 'Last Synced', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'ISO 8601 timestamp of the last data sync.', 'mcp-ai-wpoos-pro' ),
				),
			),

			// ----------------------------------------------------------------
			// Architecture Toolkit
			// ----------------------------------------------------------------

			'mcp_ai_arch_proj'    => array(
				'_arch_proj_client'     => array(
					'meta_key'    => '_arch_proj_client',
					'label'       => __( 'Client', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Name of the client or project owner.', 'mcp-ai-wpoos-pro' ),
				),
				'_arch_proj_status'     => array(
					'meta_key'    => '_arch_proj_status',
					'label'       => __( 'Project Status', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Current phase of the architectural project.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'concept', 'schematic', 'design_development', 'construction_docs', 'construction', 'completed' ),
				),
				'_arch_proj_location'   => array(
					'meta_key'    => '_arch_proj_location',
					'label'       => __( 'Location', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Site address or general location.', 'mcp-ai-wpoos-pro' ),
				),
				'_arch_proj_budget'     => array(
					'meta_key'    => '_arch_proj_budget',
					'label'       => __( 'Budget', 'mcp-ai-wpoos-pro' ),
					'type'        => 'number',
					'description' => __( 'Project construction budget.', 'mcp-ai-wpoos-pro' ),
				),
				'_arch_proj_start_date' => array(
					'meta_key'    => '_arch_proj_start_date',
					'label'       => __( 'Start Date', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Project kickoff date (YYYY-MM-DD).', 'mcp-ai-wpoos-pro' ),
				),
				'_arch_proj_end_date'   => array(
					'meta_key'    => '_arch_proj_end_date',
					'label'       => __( 'Completion Date', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Projected or actual completion date (YYYY-MM-DD).', 'mcp-ai-wpoos-pro' ),
				),
			),

			'mcp_ai_arch_draw'    => array(
				'_arch_draw_project_id' => array(
					'meta_key'    => '_arch_draw_project_id',
					'label'       => __( 'Project ID', 'mcp-ai-wpoos-pro' ),
					'type'        => 'integer',
					'description' => __( 'ID of the parent mcp_ai_arch_proj post.', 'mcp-ai-wpoos-pro' ),
				),
				'_arch_draw_type'       => array(
					'meta_key'    => '_arch_draw_type',
					'label'       => __( 'Drawing Type', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Type of architectural drawing (e.g. floor_plan, elevation, section, detail).', 'mcp-ai-wpoos-pro' ),
				),
				'_arch_draw_scale'      => array(
					'meta_key'    => '_arch_draw_scale',
					'label'       => __( 'Scale', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Drawing scale (e.g. 1:100, 1/4"=1\'0").', 'mcp-ai-wpoos-pro' ),
				),
				'_arch_draw_sheet_no'   => array(
					'meta_key'    => '_arch_draw_sheet_no',
					'label'       => __( 'Sheet Number', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Drawing sheet or page number (e.g. A-101).', 'mcp-ai-wpoos-pro' ),
				),
				'_arch_draw_revision'   => array(
					'meta_key'    => '_arch_draw_revision',
					'label'       => __( 'Revision', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Current revision identifier (e.g. A, B, 1, 2).', 'mcp-ai-wpoos-pro' ),
				),
				'_arch_draw_file_id'    => array(
					'meta_key'    => '_arch_draw_file_id',
					'label'       => __( 'File Attachment ID', 'mcp-ai-wpoos-pro' ),
					'type'        => 'integer',
					'description' => __( 'WordPress attachment ID for the drawing file (PDF/DWG/image).', 'mcp-ai-wpoos-pro' ),
				),
			),

			'mcp_ai_arch_spec'    => array(
				'_arch_spec_project_id' => array(
					'meta_key'    => '_arch_spec_project_id',
					'label'       => __( 'Project ID', 'mcp-ai-wpoos-pro' ),
					'type'        => 'integer',
					'description' => __( 'ID of the parent mcp_ai_arch_proj post.', 'mcp-ai-wpoos-pro' ),
				),
				'_arch_spec_division'   => array(
					'meta_key'    => '_arch_spec_division',
					'label'       => __( 'Division', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'MasterFormat division number (e.g. 03 - Concrete, 09 - Finishes).', 'mcp-ai-wpoos-pro' ),
				),
				'_arch_spec_section'    => array(
					'meta_key'    => '_arch_spec_section',
					'label'       => __( 'Section', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'MasterFormat section code (e.g. 03 30 00 - Cast-in-Place Concrete).', 'mcp-ai-wpoos-pro' ),
				),
				'_arch_spec_revision'   => array(
					'meta_key'    => '_arch_spec_revision',
					'label'       => __( 'Revision', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Specification document revision identifier.', 'mcp-ai-wpoos-pro' ),
				),
				'_arch_spec_file_id'    => array(
					'meta_key'    => '_arch_spec_file_id',
					'label'       => __( 'File Attachment ID', 'mcp-ai-wpoos-pro' ),
					'type'        => 'integer',
					'description' => __( 'WordPress attachment ID for the specification document.', 'mcp-ai-wpoos-pro' ),
				),
			),

			// ----------------------------------------------------------------
			// Chat Channels Toolkit (CPT fallback storage)
			// ----------------------------------------------------------------

			'mcp_chan_contact'    => array(
				'_chan_contact_user_id'   => array(
					'meta_key'    => '_chan_contact_user_id',
					'label'       => __( 'WP User ID', 'mcp-ai-wpoos-pro' ),
					'type'        => 'integer',
					'description' => __( 'WordPress user ID associated with this channel contact.', 'mcp-ai-wpoos-pro' ),
				),
				'_chan_contact_channel'   => array(
					'meta_key'    => '_chan_contact_channel',
					'label'       => __( 'Channel', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Communication channel identifier (e.g. sms, email, whatsapp).', 'mcp-ai-wpoos-pro' ),
				),
				'_chan_contact_handle'    => array(
					'meta_key'    => '_chan_contact_handle',
					'label'       => __( 'Handle / Address', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Channel-specific contact handle, phone number, or email.', 'mcp-ai-wpoos-pro' ),
				),
				'_chan_contact_last_seen' => array(
					'meta_key'    => '_chan_contact_last_seen',
					'label'       => __( 'Last Seen', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'ISO 8601 timestamp of last activity for this contact.', 'mcp-ai-wpoos-pro' ),
				),
			),

			'mcp_chan_message'    => array(
				'_chan_msg_contact_id' => array(
					'meta_key'    => '_chan_msg_contact_id',
					'label'       => __( 'Contact ID', 'mcp-ai-wpoos-pro' ),
					'type'        => 'integer',
					'description' => __( 'ID of the mcp_chan_contact this message belongs to.', 'mcp-ai-wpoos-pro' ),
				),
				'_chan_msg_direction'  => array(
					'meta_key'    => '_chan_msg_direction',
					'label'       => __( 'Direction', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Whether the message was inbound or outbound.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'inbound', 'outbound' ),
				),
				'_chan_msg_status'     => array(
					'meta_key'    => '_chan_msg_status',
					'label'       => __( 'Delivery Status', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Message delivery status.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'sent', 'delivered', 'read', 'failed' ),
				),
				'_chan_msg_media_ids'  => array(
					'meta_key'    => '_chan_msg_media_ids',
					'label'       => __( 'Media Attachments', 'mcp-ai-wpoos-pro' ),
					'type'        => 'array',
					'description' => __( 'Serialized array of WordPress attachment IDs included in the message.', 'mcp-ai-wpoos-pro' ),
				),
			),

			// ----------------------------------------------------------------
			// WebChat (P2P) Toolkit
			// ----------------------------------------------------------------

			'mcp_ai_webchat'      => array(
				'_webchat_room_type'     => array(
					'meta_key'    => '_webchat_room_type',
					'label'       => __( 'Room Type', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'Type of chat room (e.g. direct, group, support).', 'mcp-ai-wpoos-pro' ),
				),
				'_webchat_participants'  => array(
					'meta_key'    => '_webchat_participants',
					'label'       => __( 'Participants', 'mcp-ai-wpoos-pro' ),
					'type'        => 'array',
					'description' => __( 'Serialized array of WordPress user IDs participating in the room.', 'mcp-ai-wpoos-pro' ),
				),
				'_webchat_last_activity' => array(
					'meta_key'    => '_webchat_last_activity',
					'label'       => __( 'Last Activity', 'mcp-ai-wpoos-pro' ),
					'type'        => 'string',
					'description' => __( 'ISO 8601 timestamp of the most recent message in the room.', 'mcp-ai-wpoos-pro' ),
				),
				'_webchat_is_archived'   => array(
					'meta_key'    => '_webchat_is_archived',
					'label'       => __( 'Archived', 'mcp-ai-wpoos-pro' ),
					'type'        => 'boolean',
					'description' => __( 'Whether the chat room has been archived.', 'mcp-ai-wpoos-pro' ),
				),
			),
		);
	}
}
