<?php
/**
 * Tool Manage Icp Profile (ecosystem port — Wave F2, CRM icp slice).
 *
 * Ported from the base Pro addon's
 * `addons/pro/includes/tools/crm/icp/class-wp-mcp-ai-tool-manage-icp-profile.php` for the standalone `nvoos-content-graph-pro` addon.
 * Kept byte-identical. The base Pro addon owns the class in monolith
 * installs — the addon's autoloader skips its copy when
 * `WP_MCP_AI_PRO_PATH` is defined (see the plugin entry).
 *
 * Tool: Manage ICP Profile
 *
 * Provides CRUD operations for Ideal Customer Profile (ICP) definitions
 * used in B2B lead qualification, company evaluation, and pipeline
 * prioritisation. Profiles are stored as a serialised array in the
 * `wp_mcp_ai_icp_profiles` option.
 *
 * Actions: list, get, create, update, delete, set_default.
 *
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain
 * `nvoos-content-graph-pro`.
 *
 * @package NvoosContentGraphPro
 * @subpackage CRM_Toolkit
 * @since      2.11.0
 * @author     NV Digital Solutions
 * @copyright  Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license    Proprietary
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manage ICP Profile tool.
 *
 * @since 2.11.0
 */
class WP_MCP_AI_Tool_Manage_ICP_Profile implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Option key that holds all ICP profile definitions.
	 *
	 * @since 2.11.0
	 * @var string
	 */
	const OPTION_KEY = 'wp_mcp_ai_icp_profiles';

	// -------------------------------------------------------------------------
	// Availability
	// -------------------------------------------------------------------------

	/**
	 * Determine whether this tool is available.
	 *
	 * Requires the CRM toolkit to be enabled and the ICP Profile model
	 * class to be loaded.
	 *
	 * @since 2.11.0
	 * @return bool
	 */
	public static function is_available() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );

		if ( empty( $settings['enable_crm_toolkit'] ) ) {
			return false;
		}

		if ( ! class_exists( 'WP_MCP_AI_ICP_Profile' ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Human-readable reason why the tool is not available.
	 *
	 * @since 2.11.0
	 * @return string
	 */
	public static function get_unavailable_reason() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );

		if ( empty( $settings['enable_crm_toolkit'] ) ) {
			return __( 'The Manage ICP Profile tool requires the CRM Toolkit to be enabled in plugin settings.', 'nvoos-content-graph-pro' );
		}

		if ( ! class_exists( 'WP_MCP_AI_ICP_Profile' ) ) {
			return __( 'The Manage ICP Profile tool requires the ICP Profile model class (WP_MCP_AI_ICP_Profile).', 'nvoos-content-graph-pro' );
		}

		return __( 'The Manage ICP Profile tool is currently unavailable.', 'nvoos-content-graph-pro' );
	}

	// -------------------------------------------------------------------------
	// Tool Identity
	// -------------------------------------------------------------------------

	/**
	 * {@inheritdoc}
	 *
	 * @since 2.11.0
	 */
	public function get_slug() {
		return 'manage_icp_profile';
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 2.11.0
	 */
	public function get_name() {
		return __( 'Manage ICP Profile', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 2.11.0
	 */
	public function get_description() {
		return __( 'Create, read, update, or delete Ideal Customer Profile definitions. ICP profiles define the characteristics of your best customers and are used for lead scoring, company evaluation, and pipeline prioritization.', 'nvoos-content-graph-pro' );
	}

	// -------------------------------------------------------------------------
	// Extended Definition
	// -------------------------------------------------------------------------

	/**
	 * Extended tool definition with toolkit and orchestration metadata.
	 *
	 * @since 2.11.0
	 * @return array
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'crm',
			'pattern_compatibility' => array( 'orchestrator', 'peer_to_peer' ),
			'profession_tags'       => array( 'sales_manager', 'sales_ops', 'marketing_manager' ),
			'risk_level'            => 'warning',
		);
	}

	// -------------------------------------------------------------------------
	// Parameters Schema
	// -------------------------------------------------------------------------

	/**
	 * {@inheritdoc}
	 *
	 * @since 2.11.0
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => $this->build_properties_schema(),
			'required'             => array( 'action' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Build the full properties schema for the tool parameters.
	 *
	 * Extracted to a dedicated method so that AI tool builders,
	 * introspection routines, and the parameters schema endpoint
	 * receive a consistent, self-documenting structure.
	 *
	 * @since 2.11.0
	 * @return array
	 */
	private function build_properties_schema() {
		return array(
			'action'       => array(
				'type'        => 'string',
				'enum'        => array( 'list', 'get', 'create', 'update', 'delete', 'set_default' ),
				'description' => __( 'The action to perform.', 'nvoos-content-graph-pro' ),
			),
			'profile_slug' => array(
				'type'        => 'string',
				'description' => __( 'Profile slug (required for get, update, delete, set_default actions).', 'nvoos-content-graph-pro' ),
			),
			'profile_data' => array(
				'type'        => 'object',
				'description' => __( 'Profile data (required for create, optional for update). See schema below.', 'nvoos-content-graph-pro' ),
				'properties'  => $this->build_profile_data_schema(),
			),
		);
	}

	/**
	 * Build the profile_data sub-schema defining every ICP dimension.
	 *
	 * @since 2.11.0
	 * @return array
	 */
	private function build_profile_data_schema() {
		return array(
			'name'             => array(
				'type'        => 'string',
				'description' => __( 'Display name for the profile.', 'nvoos-content-graph-pro' ),
			),
			'description'      => array(
				'type'        => 'string',
				'description' => __( 'Description of the ideal customer.', 'nvoos-content-graph-pro' ),
			),
			'firmographics'    => array(
				'type'       => 'object',
				'properties' => array(
					'industries'       => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
					'company_size_min' => array( 'type' => 'integer' ),
					'company_size_max' => array( 'type' => 'integer' ),
					'revenue_min'      => array( 'type' => 'integer' ),
					'revenue_max'      => array( 'type' => 'integer' ),
					'geographies'      => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
					'funding_stages'   => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
					'business_models'  => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
				),
			),
			'technographics'   => array(
				'type'       => 'object',
				'properties' => array(
					'required_tools'   => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
					'preferred_tools'  => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
					'competitor_tools' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
				),
			),
			'triggers'         => array(
				'type'       => 'object',
				'properties' => array(
					'funding_rounds'      => array( 'type' => 'boolean' ),
					'leadership_changes'  => array( 'type' => 'boolean' ),
					'hiring_growth'       => array( 'type' => 'boolean' ),
					'tech_stack_changes'  => array( 'type' => 'boolean' ),
					'compliance_mandates' => array( 'type' => 'boolean' ),
					'custom_triggers'     => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
				),
			),
			'macro_trends'     => array(
				'type'       => 'object',
				'properties' => array(
					'description'   => array( 'type' => 'string' ),
					'market_shifts' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
				),
			),
			'negative_signals' => array(
				'type'       => 'object',
				'properties' => array(
					'excluded_industries'      => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
					'excluded_geographies'     => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
					'max_company_size'         => array( 'type' => 'integer' ),
					'min_revenue'              => array( 'type' => 'integer' ),
					'competitor_relationships' => array( 'type' => 'boolean' ),
					'custom_disqualifiers'     => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
				),
			),
			'scoring_weights'  => array(
				'type'        => 'object',
				'description' => __( 'Custom scoring weights for the 7 dimensions. Must sum to 100.', 'nvoos-content-graph-pro' ),
				'properties'  => array(
					'firmographic_fit'    => array(
						'type'    => 'integer',
						'minimum' => 0,
						'maximum' => 100,
					),
					'technographic_fit'   => array(
						'type'    => 'integer',
						'minimum' => 0,
						'maximum' => 100,
					),
					'intent_signals'      => array(
						'type'    => 'integer',
						'minimum' => 0,
						'maximum' => 100,
					),
					'engagement_activity' => array(
						'type'    => 'integer',
						'minimum' => 0,
						'maximum' => 100,
					),
					'buying_triggers'     => array(
						'type'    => 'integer',
						'minimum' => 0,
						'maximum' => 100,
					),
					'economic_outcome'    => array(
						'type'    => 'integer',
						'minimum' => 0,
						'maximum' => 100,
					),
					'negative_signals'    => array(
						'type'    => 'integer',
						'minimum' => 0,
						'maximum' => 100,
					),
				),
			),
			'score_thresholds' => array(
				'type'       => 'object',
				'properties' => array(
					'tier_a' => array(
						'type'    => 'integer',
						'minimum' => 0,
						'maximum' => 100,
					),
					'tier_b' => array(
						'type'    => 'integer',
						'minimum' => 0,
						'maximum' => 100,
					),
				),
			),
		);
	}

	// -------------------------------------------------------------------------
	// Capability
	// -------------------------------------------------------------------------

	/**
	 * {@inheritdoc}
	 *
	 * ICP profile management is an admin-level configuration task.
	 *
	 * @since 2.11.0
	 * @return string
	 */
	public function get_required_capability() {
		return 'manage_options';
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 2.11.0
	 * @return array
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'requires-capability',
			'write',
			'state-changing',
			'local-only',
		);
	}

	// -------------------------------------------------------------------------
	// Execute – Router
	// -------------------------------------------------------------------------

	/**
	 * {@inheritdoc}
	 *
	 * Routes to the appropriate sub-handler based on the `action` argument.
	 *
	 * @since 2.11.0
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error  Canonical success envelope or WP_Error on failure.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// --- Sanitise action at entry (two-gate rule gate 1) ---
		$action = isset( $arguments['action'] )
			? sanitize_text_field( $arguments['action'] )
			: '';

		if ( '' === $action ) {
			return new WP_Error(
				'icp_missing_action',
				__( 'The "action" parameter is required.', 'nvoos-content-graph-pro' )
			);
		}

		switch ( $action ) {
			case 'list':
				return $this->handle_list();

			case 'get':
				return $this->handle_get( $arguments );

			case 'create':
				return $this->handle_create( $arguments );

			case 'update':
				return $this->handle_update( $arguments );

			case 'delete':
				return $this->handle_delete( $arguments );

			case 'set_default':
				return $this->handle_set_default( $arguments );

			default:
				return new WP_Error(
					'icp_invalid_action',
					sprintf(
						/* translators: %s: received action value */
						__( 'Invalid action "%s". Must be one of: list, get, create, update, delete, set_default.', 'nvoos-content-graph-pro' ),
						esc_html( $action )
					)
				);
		}
	}

	// -------------------------------------------------------------------------
	// Action Handlers
	// -------------------------------------------------------------------------

	/**
	 * List all ICP profiles with summary information.
	 *
	 * @since 2.11.0
	 * @return array
	 */
	private function handle_list() {
		$profiles  = $this->get_all_profiles();
		$summaries = array();
		$default   = $this->get_default_slug();

		foreach ( $profiles as $slug => $profile ) {
			$summaries[] = array(
				'slug'        => esc_html( $slug ),
				'name'        => isset( $profile['name'] )
					? esc_html( $profile['name'] )
					: esc_html( $slug ),
				'description' => isset( $profile['description'] )
					? esc_html( $profile['description'] )
					: '',
				'is_default'  => ( $slug === $default ),
				'created_at'  => isset( $profile['created_at'] )
					? esc_html( $profile['created_at'] )
					: '',
			);
		}

		return array(
			'profiles' => $summaries,
			'count'    => count( $summaries ),
			'default'  => '' !== $default ? esc_html( $default ) : null,
		);
	}

	/**
	 * Get full details for a single ICP profile.
	 *
	 * Includes human-readable dimension descriptions alongside the
	 * raw profile data.
	 *
	 * @since 2.11.0
	 *
	 * @param array $arguments Tool arguments.
	 * @return array|WP_Error
	 */
	private function handle_get( $arguments ) {
		$slug = $this->sanitise_profile_slug( $arguments );

		if ( is_wp_error( $slug ) ) {
			return $slug;
		}

		$profiles = $this->get_all_profiles();

		if ( ! isset( $profiles[ $slug ] ) ) {
			return new WP_Error(
				'icp_profile_not_found',
				sprintf(
					/* translators: %s: profile slug */
					__( 'ICP profile "%s" was not found.', 'nvoos-content-graph-pro' ),
					esc_html( $slug )
				)
			);
		}

		$profile = $profiles[ $slug ];

		return array(
			'slug'                   => esc_html( $slug ),
			'profile'                => $this->escape_profile( $profile ),
			'dimension_descriptions' => $this->get_dimension_descriptions(),
		);
	}

	/**
	 * Create a new ICP profile.
	 *
	 * Generates a slug from the provided name, validates the profile
	 * data against the ICP Profile model, and persists.
	 *
	 * @since 2.11.0
	 *
	 * @param array $arguments Tool arguments.
	 * @return array|WP_Error
	 */
	private function handle_create( $arguments ) {
		$profile_data = isset( $arguments['profile_data'] ) && is_array( $arguments['profile_data'] )
			? $arguments['profile_data']
			: null;

		if ( empty( $profile_data ) ) {
			return new WP_Error(
				'icp_missing_profile_data',
				__( 'The "profile_data" parameter is required for the create action.', 'nvoos-content-graph-pro' )
			);
		}

		if ( empty( $profile_data['name'] ) ) {
			return new WP_Error(
				'icp_missing_name',
				__( 'The profile "name" field is required.', 'nvoos-content-graph-pro' )
			);
		}

		// Generate slug from name.
		$slug = sanitize_title( sanitize_text_field( $profile_data['name'] ) );

		if ( '' === $slug ) {
			return new WP_Error(
				'icp_invalid_name',
				__( 'The profile name resulted in an empty slug. Please provide a meaningful name.', 'nvoos-content-graph-pro' )
			);
		}

		// Prevent duplicate slugs.
		$profiles = $this->get_all_profiles();
		if ( isset( $profiles[ $slug ] ) ) {
			return new WP_Error(
				'icp_duplicate_slug',
				sprintf(
					/* translators: %s: profile slug */
					__( 'An ICP profile with the slug "%s" already exists. Use a different name.', 'nvoos-content-graph-pro' ),
					esc_html( $slug )
				)
			);
		}

		// Prevent duplicate display names (case-insensitive comparison).
		foreach ( $profiles as $existing_slug => $existing ) {
			if (
				isset( $existing['name'] )
				&& strtolower( sanitize_text_field( $profile_data['name'] ) ) === strtolower( $existing['name'] )
			) {
				return new WP_Error(
					'icp_duplicate_name',
					sprintf(
						/* translators: %1$s: profile name, %2$s: existing profile slug */
						__( 'An ICP profile with the name "%1$s" already exists (slug: %2$s).', 'nvoos-content-graph-pro' ),
						esc_html( sanitize_text_field( $profile_data['name'] ) ),
						esc_html( $existing_slug )
					)
				);
			}
		}

		// Sanitise profile data deeply.
		$sanitised_data = $this->sanitise_profile_data( $profile_data );

		// Validate via the ICP Profile model if available.
		if ( class_exists( 'WP_MCP_AI_ICP_Profile' ) ) {
			$validation = WP_MCP_AI_ICP_Profile::validate_profile( $sanitised_data );
			if ( is_wp_error( $validation ) ) {
				return $validation;
			}
			$sanitised_data = $validation;
		}

		// Stamp creation metadata.
		$sanitised_data['created_at'] = gmdate( 'c' );
		$sanitised_data['updated_at'] = $sanitised_data['created_at'];

		$profiles[ $slug ] = $sanitised_data;

		$saved = $this->save_all_profiles( $profiles );

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return array(
			'slug'    => esc_html( $slug ),
			'profile' => $this->escape_profile( $sanitised_data ),
			'message' => __( 'ICP profile created successfully.', 'nvoos-content-graph-pro' ),
		);
	}

	/**
	 * Update an existing ICP profile.
	 *
	 * Loads the current profile, merges the provided fields, validates,
	 * and persists.
	 *
	 * @since 2.11.0
	 *
	 * @param array $arguments Tool arguments.
	 * @return array|WP_Error
	 */
	private function handle_update( $arguments ) {
		$slug = $this->sanitise_profile_slug( $arguments );

		if ( is_wp_error( $slug ) ) {
			return $slug;
		}

		$profiles = $this->get_all_profiles();

		if ( ! isset( $profiles[ $slug ] ) ) {
			return new WP_Error(
				'icp_profile_not_found',
				sprintf(
					/* translators: %s: profile slug */
					__( 'ICP profile "%s" was not found.', 'nvoos-content-graph-pro' ),
					esc_html( $slug )
				)
			);
		}

		$profile_data = isset( $arguments['profile_data'] ) && is_array( $arguments['profile_data'] )
			? $arguments['profile_data']
			: array();

		if ( empty( $profile_data ) ) {
			return new WP_Error(
				'icp_missing_profile_data',
				__( 'The "profile_data" parameter is required for the update action.', 'nvoos-content-graph-pro' )
			);
		}

		$existing = $profiles[ $slug ];

		// If name is being changed, check for duplicates.
		if ( isset( $profile_data['name'] ) && '' !== $profile_data['name'] ) {
			$new_name = sanitize_text_field( $profile_data['name'] );
			foreach ( $profiles as $existing_slug => $existing_profile ) {
				if (
					$existing_slug !== $slug
					&& isset( $existing_profile['name'] )
					&& strtolower( $new_name ) === strtolower( $existing_profile['name'] )
				) {
					return new WP_Error(
						'icp_duplicate_name',
						sprintf(
							/* translators: %1$s: profile name, %2$s: existing profile slug */
							__( 'An ICP profile with the name "%1$s" already exists (slug: %2$s).', 'nvoos-content-graph-pro' ),
							esc_html( $new_name ),
							esc_html( $existing_slug )
						)
					);
				}
			}
		}

		// Sanitise incoming data and merge with existing.
		$sanitised_incoming = $this->sanitise_profile_data( $profile_data );
		$merged             = array_merge( $existing, $sanitised_incoming );

		// Validate via the ICP Profile model if available.
		if ( class_exists( 'WP_MCP_AI_ICP_Profile' ) ) {
			$validation = WP_MCP_AI_ICP_Profile::validate_profile( $merged );
			if ( is_wp_error( $validation ) ) {
				return $validation;
			}
			$merged = $validation;
		}

		// Stamp update metadata.
		$merged['updated_at'] = gmdate( 'c' );

		// Preserve original creation time.
		if ( ! isset( $merged['created_at'] ) && isset( $existing['created_at'] ) ) {
			$merged['created_at'] = $existing['created_at'];
		}

		$profiles[ $slug ] = $merged;

		$saved = $this->save_all_profiles( $profiles );

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return array(
			'slug'    => esc_html( $slug ),
			'profile' => $this->escape_profile( $merged ),
			'message' => __( 'ICP profile updated successfully.', 'nvoos-content-graph-pro' ),
		);
	}

	/**
	 * Delete an ICP profile by slug.
	 *
	 * Refuses to delete the last remaining profile to prevent a state
	 * where lead scoring has no profile to compare against.
	 *
	 * @since 2.11.0
	 *
	 * @param array $arguments Tool arguments.
	 * @return array|WP_Error
	 */
	private function handle_delete( $arguments ) {
		$slug = $this->sanitise_profile_slug( $arguments );

		if ( is_wp_error( $slug ) ) {
			return $slug;
		}

		$profiles = $this->get_all_profiles();

		if ( ! isset( $profiles[ $slug ] ) ) {
			return new WP_Error(
				'icp_profile_not_found',
				sprintf(
					/* translators: %s: profile slug */
					__( 'ICP profile "%s" was not found.', 'nvoos-content-graph-pro' ),
					esc_html( $slug )
				)
			);
		}

		// Prevent deletion of the last profile.
		if ( count( $profiles ) <= 1 ) {
			return new WP_Error(
				'icp_last_profile',
				__( 'Cannot delete the last ICP profile. At least one profile must exist for lead scoring to function.', 'nvoos-content-graph-pro' )
			);
		}

		$deleted_name = isset( $profiles[ $slug ]['name'] )
			? $profiles[ $slug ]['name']
			: $slug;

		unset( $profiles[ $slug ] );

		// If the deleted profile was the default, clear the default marker.
		$default = $this->get_default_slug();
		if ( $slug === $default ) {
			delete_option( 'wp_mcp_ai_icp_default_profile' );
		}

		$saved = $this->save_all_profiles( $profiles );

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return array(
			'slug'    => esc_html( $slug ),
			'message' => sprintf(
				/* translators: %s: deleted profile name */
				__( 'ICP profile "%s" has been deleted.', 'nvoos-content-graph-pro' ),
				esc_html( $deleted_name )
			),
		);
	}

	/**
	 * Set a profile as the default ICP.
	 *
	 * @since 2.11.0
	 *
	 * @param array $arguments Tool arguments.
	 * @return array|WP_Error
	 */
	private function handle_set_default( $arguments ) {
		$slug = $this->sanitise_profile_slug( $arguments );

		if ( is_wp_error( $slug ) ) {
			return $slug;
		}

		$profiles = $this->get_all_profiles();

		if ( ! isset( $profiles[ $slug ] ) ) {
			return new WP_Error(
				'icp_profile_not_found',
				sprintf(
					/* translators: %s: profile slug */
					__( 'ICP profile "%s" was not found.', 'nvoos-content-graph-pro' ),
					esc_html( $slug )
				)
			);
		}

		$updated = update_option( 'wp_mcp_ai_icp_default_profile', $slug, false );

		if ( ! $updated ) {
			return new WP_Error(
				'icp_default_update_failed',
				__( 'Failed to update the default ICP profile. Please try again.', 'nvoos-content-graph-pro' )
			);
		}

		$profile_name = isset( $profiles[ $slug ]['name'] )
			? $profiles[ $slug ]['name']
			: $slug;

		return array(
			'slug'    => esc_html( $slug ),
			'message' => sprintf(
				/* translators: %s: profile name set as default */
				__( 'ICP profile "%s" is now the default.', 'nvoos-content-graph-pro' ),
				esc_html( $profile_name )
			),
		);
	}

	// -------------------------------------------------------------------------
	// Data Access Helpers
	// -------------------------------------------------------------------------

	/**
	 * Retrieve all stored ICP profiles.
	 *
	 * @since 2.11.0
	 * @return array<string,array>
	 */
	private function get_all_profiles() {
		$profiles = get_option( self::OPTION_KEY, array() );

		if ( ! is_array( $profiles ) ) {
			return array();
		}

		return $profiles;
	}

	/**
	 * Persist the full profiles array to the option.
	 *
	 * @since 2.11.0
	 *
	 * @param array $profiles The complete profiles array.
	 * @return true|WP_Error
	 */
	private function save_all_profiles( array $profiles ) {
		$updated = update_option( self::OPTION_KEY, $profiles, false );

		if ( ! $updated ) {
			return new WP_Error(
				'icp_save_failed',
				__( 'Failed to save ICP profiles. Please try again.', 'nvoos-content-graph-pro' )
			);
		}

		return true;
	}

	/**
	 * Retrieve the slug of the default ICP profile.
	 *
	 * @since 2.11.0
	 * @return string Empty string if no default is set.
	 */
	private function get_default_slug() {
		$default = get_option( 'wp_mcp_ai_icp_default_profile', '' );

		return is_string( $default ) ? $default : '';
	}

	// -------------------------------------------------------------------------
	// Sanitisation
	// -------------------------------------------------------------------------

	/**
	 * Extract and sanitise the profile_slug argument.
	 *
	 * Returns a WP_Error if the slug is missing or empty.
	 *
	 * @since 2.11.0
	 *
	 * @param array $arguments Tool arguments.
	 * @return string|WP_Error Sanitised slug or WP_Error.
	 */
	private function sanitise_profile_slug( array $arguments ) {
		if ( empty( $arguments['profile_slug'] ) ) {
			return new WP_Error(
				'icp_missing_slug',
				__( 'The "profile_slug" parameter is required for this action.', 'nvoos-content-graph-pro' )
			);
		}

		$slug = sanitize_title( sanitize_text_field( $arguments['profile_slug'] ) );

		if ( '' === $slug ) {
			return new WP_Error(
				'icp_invalid_slug',
				__( 'The provided profile slug is invalid.', 'nvoos-content-graph-pro' )
			);
		}

		return $slug;
	}

	/**
	 * Deep-sanitise an incoming profile_data array.
	 *
	 * Applies sanitize_text_field to strings, absint to integers,
	 * and recursively sanitises nested arrays.
	 *
	 * @since 2.11.0
	 *
	 * @param array $data Raw profile data.
	 * @return array Sanitised profile data.
	 */
	private function sanitise_profile_data( array $data ) {
		$sanitised = array();

		// Top-level string fields.
		if ( isset( $data['name'] ) ) {
			$sanitised['name'] = sanitize_text_field( $data['name'] );
		}

		if ( isset( $data['description'] ) ) {
			$sanitised['description'] = sanitize_text_field( $data['description'] );
		}

		// Nested objects — sanitise each known dimension.
		$nested_dimensions = array(
			'firmographics',
			'technographics',
			'triggers',
			'macro_trends',
			'negative_signals',
		);

		foreach ( $nested_dimensions as $dim ) {
			if ( isset( $data[ $dim ] ) && is_array( $data[ $dim ] ) ) {
				$sanitised[ $dim ] = $this->sanitise_dimension( $data[ $dim ] );
			}
		}

		// Scoring weights — absint each key.
		if ( isset( $data['scoring_weights'] ) && is_array( $data['scoring_weights'] ) ) {
			$sanitised['scoring_weights'] = $this->sanitise_scoring_weights( $data['scoring_weights'] );
		}

		// Score thresholds — absint each key.
		if ( isset( $data['score_thresholds'] ) && is_array( $data['score_thresholds'] ) ) {
			$sanitised['score_thresholds'] = array();
			foreach ( $data['score_thresholds'] as $key => $value ) {
				if ( in_array( $key, array( 'tier_a', 'tier_b' ), true ) ) {
					$sanitised['score_thresholds'][ $key ] = absint( $value );
				}
			}
		}

		return $sanitised;
	}

	/**
	 * Recursively sanitise a dimension sub-object.
	 *
	 * Strings become sanitize_text_field, integers become absint,
	 * arrays are recursively processed.
	 *
	 * @since 2.11.0
	 *
	 * @param array $dimension Dimension data.
	 * @return array
	 */
	private function sanitise_dimension( array $dimension ) {
		$clean = array();

		foreach ( $dimension as $key => $value ) {
			if ( is_array( $value ) ) {
				$clean[ $key ] = array_map( 'sanitize_text_field', $value );
			} elseif ( is_int( $value ) ) {
				$clean[ $key ] = absint( $value );
			} elseif ( is_bool( $value ) ) {
				$clean[ $key ] = (bool) $value;
			} elseif ( is_string( $value ) ) {
				$clean[ $key ] = sanitize_text_field( $value );
			} else {
				$clean[ $key ] = $value;
			}
		}

		return $clean;
	}

	/**
	 * Sanitise scoring weights, converting each to an integer.
	 *
	 * @since 2.11.0
	 *
	 * @param array $weights Raw weights.
	 * @return array
	 */
	private function sanitise_scoring_weights( array $weights ) {
		$valid_keys = array(
			'firmographic_fit',
			'technographic_fit',
			'intent_signals',
			'engagement_activity',
			'buying_triggers',
			'economic_outcome',
			'negative_signals',
		);

		$clean = array();

		foreach ( $valid_keys as $key ) {
			if ( isset( $weights[ $key ] ) ) {
				$clean[ $key ] = absint( $weights[ $key ] );
			}
		}

		return $clean;
	}

	// -------------------------------------------------------------------------
	// Output Escaping (two-gate rule gate 2)
	// -------------------------------------------------------------------------

	/**
	 * Escape a full profile array for safe output.
	 *
	 * Every string value is passed through esc_html().  Nested arrays
	 * are processed recursively.
	 *
	 * @since 2.11.0
	 *
	 * @param array $profile Raw profile data.
	 * @return array Escaped profile data.
	 */
	private function escape_profile( array $profile ) {
		$escaped = array();

		foreach ( $profile as $key => $value ) {
			if ( is_array( $value ) ) {
				$escaped[ $key ] = $this->escape_profile( $value );
			} elseif ( is_string( $value ) ) {
				$escaped[ $key ] = esc_html( $value );
			} else {
				$escaped[ $key ] = $value;
			}
		}

		return $escaped;
	}

	// -------------------------------------------------------------------------
	// Dimension Descriptions
	// -------------------------------------------------------------------------

	/**
	 * Provide human-readable descriptions for each scoring dimension.
	 *
	 * Returned alongside full profile details so that AI assistants
	 * and human readers can understand what each dimension measures.
	 *
	 * @since 2.11.0
	 * @return array
	 */
	private function get_dimension_descriptions() {
		return array(
			'firmographic_fit'    => array(
				'label'       => __( 'Firmographic Fit', 'nvoos-content-graph-pro' ),
				'description' => __( 'How closely the company matches your ideal industry, size, revenue, geography, and funding stage.', 'nvoos-content-graph-pro' ),
				'examples'    => array(
					__( 'Industry match (exact or adjacent)', 'nvoos-content-graph-pro' ),
					__( 'Company size within target range', 'nvoos-content-graph-pro' ),
					__( 'Revenue within target band', 'nvoos-content-graph-pro' ),
					__( 'Geography alignment', 'nvoos-content-graph-pro' ),
				),
			),
			'technographic_fit'   => array(
				'label'       => __( 'Technographic Fit', 'nvoos-content-graph-pro' ),
				'description' => __( 'Alignment between the company\'s tech stack and your required, preferred, and competitor tools.', 'nvoos-content-graph-pro' ),
				'examples'    => array(
					__( 'Required tools present in their stack', 'nvoos-content-graph-pro' ),
					__( 'Preferred tools indicating sophistication', 'nvoos-content-graph-pro' ),
					__( 'Absence from competitor ecosystems', 'nvoos-content-graph-pro' ),
				),
			),
			'intent_signals'      => array(
				'label'       => __( 'Intent Signals', 'nvoos-content-graph-pro' ),
				'description' => __( 'Active research behaviour indicating buying intent — page visits, content downloads, and search activity.', 'nvoos-content-graph-pro' ),
				'examples'    => array(
					__( 'Visited pricing page', 'nvoos-content-graph-pro' ),
					__( 'Downloaded case study or whitepaper', 'nvoos-content-graph-pro' ),
					__( 'Searched for competitor comparisons', 'nvoos-content-graph-pro' ),
				),
			),
			'engagement_activity' => array(
				'label'       => __( 'Engagement Activity', 'nvoos-content-graph-pro' ),
				'description' => __( 'Depth and recency of interaction with your brand — emails, meetings, calls, and website visits.', 'nvoos-content-graph-pro' ),
				'examples'    => array(
					__( 'Email open and click-through rates', 'nvoos-content-graph-pro' ),
					__( 'Meeting or demo attendance', 'nvoos-content-graph-pro' ),
					__( 'Website visit frequency and depth', 'nvoos-content-graph-pro' ),
				),
			),
			'buying_triggers'     => array(
				'label'       => __( 'Buying Triggers', 'nvoos-content-graph-pro' ),
				'description' => __( 'External events that typically precede a purchase — funding rounds, leadership changes, hiring surges.', 'nvoos-content-graph-pro' ),
				'examples'    => array(
					__( 'Recent funding round announced', 'nvoos-content-graph-pro' ),
					__( 'New C-level hire in relevant function', 'nvoos-content-graph-pro' ),
					__( 'Rapid team expansion in target department', 'nvoos-content-graph-pro' ),
				),
			),
			'economic_outcome'    => array(
				'label'       => __( 'Economic Outcome', 'nvoos-content-graph-pro' ),
				'description' => __( 'Projected deal value — includes estimated ACV, LTV, upsell potential, and the strategic value of the account.', 'nvoos-content-graph-pro' ),
				'examples'    => array(
					__( 'Estimated annual contract value (ACV)', 'nvoos-content-graph-pro' ),
					__( 'Lifetime value (LTV) projection', 'nvoos-content-graph-pro' ),
					__( 'Upsell and expansion potential', 'nvoos-content-graph-pro' ),
				),
			),
			'negative_signals'    => array(
				'label'       => __( 'Negative Signals', 'nvoos-content-graph-pro' ),
				'description' => __( 'Disqualifying factors that reduce fit — excluded industries, competing relationships, and size mismatches.', 'nvoos-content-graph-pro' ),
				'examples'    => array(
					__( 'Industry on the exclusion list', 'nvoos-content-graph-pro' ),
					__( 'Existing relationship with a competitor', 'nvoos-content-graph-pro' ),
					__( 'Company size outside acceptable range', 'nvoos-content-graph-pro' ),
				),
			),
		);
	}
}
