<?php
/**
 * Tool: import_fhir_bundle
 *
 * Parses a FHIR R4 Bundle JSON document and upserts the resources it
 * contains into the local healthcare CPTs (`mcp_ai_member`,
 * `mcp_ai_med_record`, `mcp_ai_allergy`, `mcp_ai_prescription`,
 * `mcp_ai_vaccination_record`).  Resources whose handler is not
 * registered are skipped (and reported back) — sites can register
 * additional handlers via the `wp_mcp_ai_healthcare_fhir_resource_handlers`
 * filter.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.4.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Import FHIR bundle tool.
 */
class WP_MCP_AI_Tool_Import_FHIR_Bundle implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Whether the tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_health_wellness_management'] );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'import_fhir_bundle';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Import FHIR Bundle', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Parse a FHIR R4 Bundle and upsert its Patient / AllergyIntolerance / Condition / MedicationStatement / Immunization resources into the local healthcare records.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'bundle_json' => array(
					'type'        => 'string',
					'description' => __( 'The FHIR Bundle as a JSON string.', 'mcp-ai-wpoos-pro' ),
				),
				'bundle'      => array(
					'type'        => 'object',
					'description' => __( 'The FHIR Bundle as an object (alternative to bundle_json).', 'mcp-ai-wpoos-pro' ),
				),
				'dry_run'     => array(
					'type'        => 'boolean',
					'description' => __( 'When true, parse and report what would be imported without persisting.', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
			),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'write', 'state-changing', 'phi-data' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Execute.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_others_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to import FHIR bundles.', 'mcp-ai-wpoos-pro' ) );
		}

		$bundle = null;
		if ( isset( $arguments['bundle'] ) && is_array( $arguments['bundle'] ) ) {
			$bundle = $arguments['bundle'];
		} elseif ( isset( $arguments['bundle_json'] ) && is_string( $arguments['bundle_json'] ) ) {
			$bundle = json_decode( $arguments['bundle_json'], true );
		}
		if ( ! is_array( $bundle ) ) {
			return new WP_Error( 'wp_mcp_ai_fhir_invalid_bundle', __( 'A valid FHIR Bundle JSON is required.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( ! isset( $bundle['resourceType'] ) || 'Bundle' !== $bundle['resourceType'] ) {
			return new WP_Error( 'wp_mcp_ai_fhir_not_bundle', __( 'Document is not a FHIR Bundle.', 'mcp-ai-wpoos-pro' ) );
		}

		$dry_run = ! empty( $arguments['dry_run'] );
		$entries = isset( $bundle['entry'] ) && is_array( $bundle['entry'] ) ? $bundle['entry'] : array();

		$handlers = $this->get_handlers();

		$imported  = array();
		$skipped   = array();
		$errors    = array();
		$member_id = 0;

		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) || ! isset( $entry['resource']['resourceType'] ) ) {
				continue;
			}
			$resource = $entry['resource'];
			$type     = (string) $resource['resourceType'];
			if ( ! isset( $handlers[ $type ] ) ) {
				$skipped[] = $type;
				continue;
			}
			$result = call_user_func( $handlers[ $type ], $resource, $member_id, $dry_run );
			if ( is_wp_error( $result ) ) {
				$errors[] = array(
					'resource' => $type,
					'error'    => $result->get_error_message(),
				);
				continue;
			}
			if ( 'Patient' === $type && isset( $result['post_id'] ) ) {
				$member_id = (int) $result['post_id'];
			}
			$imported[] = array_merge( array( 'resource' => $type ), (array) $result );
		}

		if ( ! $dry_run && class_exists( 'WP_MCP_AI_Healthcare_Audit' ) ) {
			WP_MCP_AI_Healthcare_Audit::record(
				'import',
				'fhir_bundle',
				$member_id,
				array(
					'user_id'        => $current_user_id,
					'tool'           => $this->get_slug(),
					'imported_count' => count( $imported ),
					'skipped_types'  => array_values( array_unique( $skipped ) ),
				)
			);
		}

		return array(
			'success'   => empty( $errors ),
			'dry_run'   => $dry_run,
			'member_id' => $member_id,
			'imported'  => $imported,
			'skipped'   => array_values( array_unique( $skipped ) ),
			'errors'    => $errors,
		);
	}

	/**
	 * Resolve resource handler callbacks.
	 *
	 * @return array
	 */
	private function get_handlers() {
		$handlers = array(
			'Patient'             => array( $this, 'handle_patient' ),
			'AllergyIntolerance'  => array( $this, 'handle_allergy' ),
			'Condition'           => array( $this, 'handle_condition' ),
			'MedicationStatement' => array( $this, 'handle_medication' ),
			'MedicationRequest'   => array( $this, 'handle_medication' ),
			'Immunization'        => array( $this, 'handle_immunization' ),
		);

		/**
		 * Filter FHIR resource handlers.
		 *
		 * Handlers receive ( $resource, $member_id, $dry_run ) and must
		 * return either an array (success) or a WP_Error.
		 *
		 * @since 1.4.0
		 *
		 * @param array $handlers Map of resourceType => callable.
		 */
		return apply_filters( 'wp_mcp_ai_healthcare_fhir_resource_handlers', $handlers );
	}

	/**
	 * Upsert a Patient resource into `mcp_ai_member`.
	 *
	 * @param array $resource FHIR Patient resource.
	 * @param int   $member_id Existing member id (unused).
	 * @param bool  $dry_run   Dry-run flag.
	 * @return array|WP_Error
	 */
	public function handle_patient( $resource, $member_id, $dry_run ) {
		$identifier = '';
		if ( ! empty( $resource['identifier'][0]['value'] ) ) {
			$identifier = sanitize_text_field( $resource['identifier'][0]['value'] );
		}
		$first = '';
		$last  = '';
		if ( ! empty( $resource['name'][0]['given'][0] ) ) {
			$first = sanitize_text_field( $resource['name'][0]['given'][0] );
		}
		if ( ! empty( $resource['name'][0]['family'] ) ) {
			$last = sanitize_text_field( $resource['name'][0]['family'] );
		}
		$dob    = isset( $resource['birthDate'] ) ? sanitize_text_field( $resource['birthDate'] ) : '';
		$gender = isset( $resource['gender'] ) ? sanitize_text_field( $resource['gender'] ) : '';

		if ( $dry_run ) {
			return array(
				'identifier' => $identifier,
				'first_name' => $first,
				'last_name'  => $last,
			);
		}

		// Try to match by MRN.
		$existing = 0;
		if ( '' !== $identifier ) {
			$query = new WP_Query(
				array(
					'post_type'      => 'mcp_ai_member',
					'posts_per_page' => 1,
					'fields'         => 'ids',
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'meta_query'     => array(
						'relation' => 'OR',
						array(
							'key'   => '_member_mrn',
							'value' => $identifier,
						),
						array(
							'key'   => '_fhir_patient_identifier',
							'value' => $identifier,
						),
					),
				)
			);
			if ( ! empty( $query->posts ) ) {
				$existing = (int) $query->posts[0];
			}
		}

		$title = trim( $first . ' ' . $last );
		if ( '' === $title ) {
			$title = '' !== $identifier ? $identifier : __( '(unnamed)', 'mcp-ai-wpoos-pro' );
		}

		if ( $existing > 0 ) {
			wp_update_post(
				array(
					'ID'         => $existing,
					'post_title' => $title,
				)
			);
			$post_id = $existing;
		} else {
			$post_id = wp_insert_post(
				array(
					'post_type'   => 'mcp_ai_member',
					'post_title'  => $title,
					'post_status' => 'publish',
				),
				true
			);
			if ( is_wp_error( $post_id ) ) {
				return $post_id;
			}
		}

		if ( '' !== $identifier ) {
			update_post_meta( $post_id, '_member_mrn', $identifier );
			update_post_meta( $post_id, '_fhir_patient_identifier', $identifier );
		}
		if ( '' !== $first ) {
			update_post_meta( $post_id, '_member_first_name', $first );
		}
		if ( '' !== $last ) {
			update_post_meta( $post_id, '_member_last_name', $last );
		}
		if ( '' !== $dob ) {
			update_post_meta( $post_id, '_member_date_of_birth', $dob );
		}
		if ( '' !== $gender ) {
			update_post_meta( $post_id, '_member_gender', $gender );
		}

		return array(
			'post_id'    => (int) $post_id,
			'identifier' => $identifier,
		);
	}

	/**
	 * Upsert an AllergyIntolerance resource into `mcp_ai_allergy`.
	 *
	 * @param array $resource  Resource.
	 * @param int   $member_id Linked member.
	 * @param bool  $dry_run   Dry-run flag.
	 * @return array|WP_Error
	 */
	public function handle_allergy( $resource, $member_id, $dry_run ) {
		$substance = '';
		if ( ! empty( $resource['code']['text'] ) ) {
			$substance = sanitize_text_field( $resource['code']['text'] );
		} elseif ( ! empty( $resource['code']['coding'][0]['display'] ) ) {
			$substance = sanitize_text_field( $resource['code']['coding'][0]['display'] );
		}
		if ( '' === $substance ) {
			return new WP_Error( 'wp_mcp_ai_fhir_allergy_no_substance', __( 'AllergyIntolerance is missing a substance code.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( $dry_run ) {
			return array( 'substance' => $substance );
		}
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_allergy',
				'post_title'  => $substance,
				'post_status' => 'publish',
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}
		update_post_meta( $post_id, '_allergy_substance', $substance );
		if ( $member_id ) {
			update_post_meta( $post_id, '_allergy_member_id', $member_id );
		}
		if ( ! empty( $resource['reaction'][0]['manifestation'][0]['text'] ) ) {
			update_post_meta( $post_id, '_allergy_reaction', sanitize_text_field( $resource['reaction'][0]['manifestation'][0]['text'] ) );
		}
		return array(
			'post_id'   => (int) $post_id,
			'substance' => $substance,
		);
	}

	/**
	 * Upsert a Condition resource into `mcp_ai_med_record`.
	 *
	 * @param array $resource  Resource.
	 * @param int   $member_id Linked member.
	 * @param bool  $dry_run   Dry-run flag.
	 * @return array|WP_Error
	 */
	public function handle_condition( $resource, $member_id, $dry_run ) {
		$label = '';
		if ( ! empty( $resource['code']['text'] ) ) {
			$label = sanitize_text_field( $resource['code']['text'] );
		} elseif ( ! empty( $resource['code']['coding'][0]['display'] ) ) {
			$label = sanitize_text_field( $resource['code']['coding'][0]['display'] );
		}
		if ( '' === $label ) {
			return new WP_Error( 'wp_mcp_ai_fhir_condition_empty', __( 'Condition is missing a code.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( $dry_run ) {
			return array( 'condition' => $label );
		}
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_med_record',
				'post_title'  => $label,
				'post_status' => 'publish',
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}
		update_post_meta( $post_id, '_record_type', 'condition' );
		if ( $member_id ) {
			update_post_meta( $post_id, '_medical_record_member_id', $member_id );
		}
		return array(
			'post_id'   => (int) $post_id,
			'condition' => $label,
		);
	}

	/**
	 * Upsert a Medication{Statement,Request} into `mcp_ai_prescription`.
	 *
	 * @param array $resource  Resource.
	 * @param int   $member_id Linked member.
	 * @param bool  $dry_run   Dry-run flag.
	 * @return array|WP_Error
	 */
	public function handle_medication( $resource, $member_id, $dry_run ) {
		$name = '';
		foreach ( array( 'medicationCodeableConcept', 'medication' ) as $key ) {
			if ( ! empty( $resource[ $key ]['text'] ) ) {
				$name = sanitize_text_field( $resource[ $key ]['text'] );
				break;
			}
			if ( ! empty( $resource[ $key ]['coding'][0]['display'] ) ) {
				$name = sanitize_text_field( $resource[ $key ]['coding'][0]['display'] );
				break;
			}
		}
		if ( '' === $name ) {
			return new WP_Error( 'wp_mcp_ai_fhir_med_empty', __( 'Medication entry is missing a code.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( $dry_run ) {
			return array( 'medication' => $name );
		}
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_prescription',
				'post_title'  => $name,
				'post_status' => 'publish',
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}
		update_post_meta( $post_id, '_prescription_medication', $name );
		if ( $member_id ) {
			update_post_meta( $post_id, '_prescription_member_id', $member_id );
		}
		return array(
			'post_id'    => (int) $post_id,
			'medication' => $name,
		);
	}

	/**
	 * Upsert an Immunization into `mcp_ai_vaccination_record`.
	 *
	 * @param array $resource  Resource.
	 * @param int   $member_id Linked member.
	 * @param bool  $dry_run   Dry-run flag.
	 * @return array|WP_Error
	 */
	public function handle_immunization( $resource, $member_id, $dry_run ) {
		$vaccine = '';
		if ( ! empty( $resource['vaccineCode']['text'] ) ) {
			$vaccine = sanitize_text_field( $resource['vaccineCode']['text'] );
		} elseif ( ! empty( $resource['vaccineCode']['coding'][0]['display'] ) ) {
			$vaccine = sanitize_text_field( $resource['vaccineCode']['coding'][0]['display'] );
		}
		if ( '' === $vaccine ) {
			return new WP_Error( 'wp_mcp_ai_fhir_imm_empty', __( 'Immunization is missing a code.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( $dry_run ) {
			return array( 'vaccine' => $vaccine );
		}
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_vaccination_record',
				'post_title'  => $vaccine,
				'post_status' => 'publish',
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}
		update_post_meta( $post_id, '_record_vaccine', $vaccine );
		if ( $member_id ) {
			update_post_meta( $post_id, '_record_member_id', $member_id );
		}
		return array(
			'post_id' => (int) $post_id,
			'vaccine' => $vaccine,
		);
	}
}
