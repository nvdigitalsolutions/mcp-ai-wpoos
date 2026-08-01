<?php
/**
 * ICP Profile Manager
 *
 * Manages Ideal Customer Profile (ICP) definitions for the NV oOS Pro CRM Toolkit.
 * Provides CRUD operations for structured ICP data stored as WordPress options.
 *
 * @package   WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright 2025-2026 NV Digital Solutions
 * @license   Proprietary
 * @since     2.11.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_MCP_AI_ICP_Profile
 *
 * Manages Ideal Customer Profile (ICP) definitions with 7-dimensional scoring.
 * Profiles are stored in a single WordPress option keyed by slug.
 *
 * @since 2.11.0
 */
class WP_MCP_AI_ICP_Profile {

	const OPTION_KEY = 'wp_mcp_ai_icp_profiles';

	const DEFAULT_WEIGHTS = array(
		'firmographic_fit'    => 25,
		'technographic_fit'   => 20,
		'intent_signals'      => 15,
		'engagement_activity' => 15,
		'buying_triggers'     => 10,
		'economic_outcome'    => 10,
		'negative_signals'    => 5,
	);

	/**
	 * Cached scoring dimensions array to avoid repeated construction.
	 *
	 * @since 2.11.0
	 * @var array|null
	 */
	private static $dimensions_cache = null;

	/**
	 * Retrieve all ICP profiles from storage.
	 *
	 * @since 2.11.0
	 *
	 * @return array Array of ICP profile data keyed by slug.
	 */
	public static function get_all() {
		$profiles = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $profiles ) ) {
			return array();
		}
		return $profiles;
	}

	/**
	 * Retrieve a single ICP profile by its slug.
	 *
	 * @since 2.11.0
	 *
	 * @param string $slug The unique profile identifier.
	 * @return array|null The profile data array, or null if not found.
	 */
	public static function get( $slug ) {
		$slug     = sanitize_text_field( $slug );
		$profiles = self::get_all();
		return isset( $profiles[ $slug ] ) ? $profiles[ $slug ] : null;
	}

	/**
	 * Retrieve the default ICP profile.
	 *
	 * Returns the profile marked as default, or the first available profile
	 * if no default is explicitly set.
	 *
	 * @since 2.11.0
	 *
	 * @return array|null The default profile data array, or null if no profiles exist.
	 */
	public static function get_default() {
		$profiles = self::get_all();
		if ( empty( $profiles ) ) {
			return null;
		}
		foreach ( $profiles as $profile ) {
			if ( ! empty( $profile['is_default'] ) ) {
				return $profile;
			}
		}
		reset( $profiles );
		return current( $profiles );
	}

	/**
	 * Create or update an ICP profile.
	 *
	 * Validates and sanitizes the profile data before persisting to the
	 * WordPress options table. Automatically manages the 'is_default' flag
	 * and timestamps.
	 *
	 * @since 2.11.0
	 *
	 * @param array $profile The profile data to save.
	 * @return true|WP_Error True on success, or a WP_Error on failure.
	 */
	public static function save( $profile ) {
		if ( ! is_array( $profile ) ) {
			return new WP_Error( 'icp_invalid_input', __( 'Profile data must be an associative array.', 'mcp-ai-wpoos-pro' ) );
		}
		$validated = self::validate_profile( $profile );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}
		$now      = gmdate( 'c' );
		$slug     = $validated['id'];
		$is_new   = false;
		$profiles = self::get_all();
		if ( ! isset( $profiles[ $slug ] ) ) {
			$is_new                  = true;
			$validated['created_at'] = $now;
		} else {
			$validated['created_at'] = isset( $profiles[ $slug ]['created_at'] ) ? $profiles[ $slug ]['created_at'] : $now;
		}
		$validated['updated_at'] = $now;
		if ( ! empty( $validated['is_default'] ) || ( 1 === count( $profiles ) && $is_new ) ) {
			$validated['is_default'] = true;
			foreach ( $profiles as $existing_slug => &$existing ) {
				$existing['is_default'] = false;
			}
			unset( $existing );
		}
		if ( empty( $profiles ) ) {
			$validated['is_default'] = true;
		}
		$profiles[ $slug ] = $validated;
		$updated           = update_option( self::OPTION_KEY, $profiles, false );
		if ( ! $updated && $is_new ) {
			return new WP_Error( 'icp_save_failed', __( 'Failed to save the ICP profile.', 'mcp-ai-wpoos-pro' ) );
		}
		return true;
	}

	/**
	 * Delete an ICP profile by its slug.
	 *
	 * Prevents deletion of the last remaining profile. If the deleted profile
	 * was the default, the first remaining profile is promoted to default.
	 *
	 * @since 2.11.0
	 *
	 * @param string $slug The unique profile identifier to delete.
	 * @return true|WP_Error True on success, or a WP_Error on failure.
	 */
	public static function delete( $slug ) {
		$slug     = sanitize_text_field( $slug );
		$profiles = self::get_all();
		if ( ! isset( $profiles[ $slug ] ) ) {
			return new WP_Error( 'icp_not_found', sprintf( __( 'ICP profile "%s" was not found.', 'mcp-ai-wpoos-pro' ), $slug ) );
		}
		if ( 1 === count( $profiles ) ) {
			return new WP_Error( 'icp_last_profile', __( 'Cannot delete the last remaining ICP profile.', 'mcp-ai-wpoos-pro' ) );
		}
		$was_default = ! empty( $profiles[ $slug ]['is_default'] );
		unset( $profiles[ $slug ] );
		if ( $was_default && ! empty( $profiles ) ) {
			reset( $profiles );
			$first_key                            = key( $profiles );
			$profiles[ $first_key ]['is_default'] = true;
			$profiles[ $first_key ]['updated_at'] = gmdate( 'c' );
		}
		$updated = update_option( self::OPTION_KEY, $profiles, false );
		if ( ! $updated ) {
			return new WP_Error( 'icp_delete_failed', __( 'Failed to delete the ICP profile.', 'mcp-ai-wpoos-pro' ) );
		}
		return true;
	}

	/**
	 * Mark a specific ICP profile as the default.
	 *
	 * Demotes all other profiles' 'is_default' flag to false.
	 *
	 * @since 2.11.0
	 *
	 * @param string $slug The unique profile identifier to set as default.
	 * @return true|WP_Error True on success, or a WP_Error on failure.
	 */
	public static function set_default( $slug ) {
		$slug     = sanitize_text_field( $slug );
		$profiles = self::get_all();
		if ( ! isset( $profiles[ $slug ] ) ) {
			return new WP_Error( 'icp_not_found', sprintf( __( 'ICP profile "%s" was not found.', 'mcp-ai-wpoos-pro' ), $slug ) );
		}
		$now = gmdate( 'c' );
		foreach ( $profiles as $key => &$profile ) {
			$profile['is_default'] = ( $key === $slug );
			if ( $key === $slug ) {
				$profile['updated_at'] = $now;
			}
		}
		unset( $profile );
		$updated = update_option( self::OPTION_KEY, $profiles, false );
		if ( ! $updated ) {
			return new WP_Error( 'icp_set_default_failed', __( 'Failed to update the default ICP profile.', 'mcp-ai-wpoos-pro' ) );
		}
		return true;
	}

	/**
	 * Retrieve the default scoring dimension weights.
	 *
	 * @since 2.11.0
	 *
	 * @return array<string,int> Default weights keyed by dimension slug.
	 */
	public static function get_default_weights() {
		return self::DEFAULT_WEIGHTS;
	}

	/**
	 * Validate and sanitize ICP profile data.
	 *
	 * Ensures all required fields are present and properly typed,
	 * applies sanitization to every field, and returns a clean
	 * profile array suitable for storage.
	 *
	 * @since 2.11.0
	 *
	 * @param array $profile Raw profile data to validate.
	 * @return array|WP_Error Sanitized profile array, or WP_Error on validation failure.
	 */
	public static function validate_profile( $profile ) {
		if ( ! is_array( $profile ) ) {
			return new WP_Error( 'icp_validation_invalid_type', __( 'Profile must be an associative array.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( empty( $profile['id'] ) || ! is_string( $profile['id'] ) ) {
			return new WP_Error( 'icp_validation_missing_id', __( 'Each ICP profile requires a unique "id" slug.', 'mcp-ai-wpoos-pro' ) );
		}
		$sanitized       = array();
		$sanitized['id'] = sanitize_key( $profile['id'] );
		if ( empty( $profile['name'] ) || ! is_string( $profile['name'] ) ) {
			return new WP_Error( 'icp_validation_missing_name', __( 'Each ICP profile requires a "name".', 'mcp-ai-wpoos-pro' ) );
		}
		$sanitized['name']             = sanitize_text_field( $profile['name'] );
		$sanitized['description']      = isset( $profile['description'] ) && is_string( $profile['description'] ) ? sanitize_textarea_field( $profile['description'] ) : '';
		$sanitized['is_default']       = ! empty( $profile['is_default'] );
		$firmo                         = isset( $profile['firmographics'] ) && is_array( $profile['firmographics'] ) ? $profile['firmographics'] : array();
		$sanitized['firmographics']    = array(
			'industries'       => self::sanitize_string_list( $firmo['industries'] ?? array() ),
			'company_size_min' => absint( $firmo['company_size_min'] ?? 0 ),
			'company_size_max' => absint( $firmo['company_size_max'] ?? 0 ),
			'revenue_min'      => absint( $firmo['revenue_min'] ?? 0 ),
			'revenue_max'      => absint( $firmo['revenue_max'] ?? 0 ),
			'geographies'      => self::sanitize_string_list( $firmo['geographies'] ?? array() ),
			'funding_stages'   => self::sanitize_string_list( $firmo['funding_stages'] ?? array() ),
			'business_models'  => self::sanitize_string_list( $firmo['business_models'] ?? array() ),
		);
		$techno                        = isset( $profile['technographics'] ) && is_array( $profile['technographics'] ) ? $profile['technographics'] : array();
		$sanitized['technographics']   = array(
			'required_tools'   => self::sanitize_string_list( $techno['required_tools'] ?? array() ),
			'preferred_tools'  => self::sanitize_string_list( $techno['preferred_tools'] ?? array() ),
			'competitor_tools' => self::sanitize_string_list( $techno['competitor_tools'] ?? array() ),
		);
		$triggers                      = isset( $profile['triggers'] ) && is_array( $profile['triggers'] ) ? $profile['triggers'] : array();
		$sanitized['triggers']         = array(
			'funding_rounds'      => ! empty( $triggers['funding_rounds'] ),
			'leadership_changes'  => ! empty( $triggers['leadership_changes'] ),
			'hiring_growth'       => ! empty( $triggers['hiring_growth'] ),
			'tech_stack_changes'  => ! empty( $triggers['tech_stack_changes'] ),
			'compliance_mandates' => ! empty( $triggers['compliance_mandates'] ),
			'custom_triggers'     => self::sanitize_string_list( $triggers['custom_triggers'] ?? array() ),
		);
		$trends                        = isset( $profile['macro_trends'] ) && is_array( $profile['macro_trends'] ) ? $profile['macro_trends'] : array();
		$sanitized['macro_trends']     = array(
			'description'   => isset( $trends['description'] ) && is_string( $trends['description'] ) ? sanitize_textarea_field( $trends['description'] ) : '',
			'market_shifts' => self::sanitize_string_list( $trends['market_shifts'] ?? array() ),
		);
		$neg                           = isset( $profile['negative_signals'] ) && is_array( $profile['negative_signals'] ) ? $profile['negative_signals'] : array();
		$sanitized['negative_signals'] = array(
			'excluded_industries'      => self::sanitize_string_list( $neg['excluded_industries'] ?? array() ),
			'excluded_geographies'     => self::sanitize_string_list( $neg['excluded_geographies'] ?? array() ),
			'max_company_size'         => absint( $neg['max_company_size'] ?? 0 ),
			'min_revenue'              => absint( $neg['min_revenue'] ?? 0 ),
			'competitor_relationships' => ! empty( $neg['competitor_relationships'] ),
			'custom_disqualifiers'     => self::sanitize_string_list( $neg['custom_disqualifiers'] ?? array() ),
		);
		$weights                       = isset( $profile['scoring_weights'] ) && is_array( $profile['scoring_weights'] ) ? $profile['scoring_weights'] : self::DEFAULT_WEIGHTS;
		$sanitized['scoring_weights']  = array(
			'firmographic_fit'    => absint( $weights['firmographic_fit'] ?? self::DEFAULT_WEIGHTS['firmographic_fit'] ),
			'technographic_fit'   => absint( $weights['technographic_fit'] ?? self::DEFAULT_WEIGHTS['technographic_fit'] ),
			'intent_signals'      => absint( $weights['intent_signals'] ?? self::DEFAULT_WEIGHTS['intent_signals'] ),
			'engagement_activity' => absint( $weights['engagement_activity'] ?? self::DEFAULT_WEIGHTS['engagement_activity'] ),
			'buying_triggers'     => absint( $weights['buying_triggers'] ?? self::DEFAULT_WEIGHTS['buying_triggers'] ),
			'economic_outcome'    => absint( $weights['economic_outcome'] ?? self::DEFAULT_WEIGHTS['economic_outcome'] ),
			'negative_signals'    => absint( $weights['negative_signals'] ?? self::DEFAULT_WEIGHTS['negative_signals'] ),
		);
		$thresholds                    = isset( $profile['score_thresholds'] ) && is_array( $profile['score_thresholds'] ) ? $profile['score_thresholds'] : array();
		$sanitized['score_thresholds'] = array(
			'tier_a' => absint( $thresholds['tier_a'] ?? 80 ),
			'tier_b' => absint( $thresholds['tier_b'] ?? 60 ),
		);
		$sanitized['created_at']       = isset( $profile['created_at'] ) && is_string( $profile['created_at'] ) ? sanitize_text_field( $profile['created_at'] ) : gmdate( 'c' );
		$sanitized['updated_at']       = isset( $profile['updated_at'] ) && is_string( $profile['updated_at'] ) ? sanitize_text_field( $profile['updated_at'] ) : gmdate( 'c' );
		return $sanitized;
	}

	/**
	 * Retrieve the seven scoring dimensions with default weights.
	 *
	 * Results are cached in a static variable for the lifetime of the request.
	 *
	 * @since 2.11.0
	 *
	 * @return array[] Array of dimension definitions, each containing key, weight, label, and description.
	 */
	public static function get_scoring_dimensions() {
		if ( null !== self::$dimensions_cache ) {
			return self::$dimensions_cache;
		}
		self::$dimensions_cache = array(
			array(
				'key'         => 'firmographic_fit',
				'weight'      => 25,
				'label'       => __( 'Firmographic Fit', 'mcp-ai-wpoos-pro' ),
				'description' => __( 'How closely the company matches your target industry, size, revenue, geography, funding stage, and business model criteria.', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'key'         => 'technographic_fit',
				'weight'      => 20,
				'label'       => __( 'Technographic Fit', 'mcp-ai-wpoos-pro' ),
				'description' => __( 'How well the tech stack aligns with required and preferred tools.', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'key'         => 'intent_signals',
				'weight'      => 15,
				'label'       => __( 'Intent Signals', 'mcp-ai-wpoos-pro' ),
				'description' => __( 'Strength of external signals indicating active buying intent.', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'key'         => 'engagement_activity',
				'weight'      => 15,
				'label'       => __( 'Engagement Activity', 'mcp-ai-wpoos-pro' ),
				'description' => __( 'Level and recency of direct engagement with your brand.', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'key'         => 'buying_triggers',
				'weight'      => 10,
				'label'       => __( 'Buying Triggers', 'mcp-ai-wpoos-pro' ),
				'description' => __( 'Time-sensitive events that create urgency.', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'key'         => 'economic_outcome',
				'weight'      => 10,
				'label'       => __( 'Economic Outcome', 'mcp-ai-wpoos-pro' ),
				'description' => __( 'Projected deal value and lifetime value potential.', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'key'         => 'negative_signals',
				'weight'      => 5,
				'label'       => __( 'Negative Signals', 'mcp-ai-wpoos-pro' ),
				'description' => __( 'Disqualifying factors that subtract points.', 'mcp-ai-wpoos-pro' ),
			),
		);
		return self::$dimensions_cache;
	}

	/**
	 * Retrieve a sample ICP profile for demonstration purposes.
	 *
	 * Provides a complete B2B SaaS Mid-Market example profile with
	 * realistic firmographics, technographics, triggers, and thresholds.
	 *
	 * @since 2.11.0
	 *
	 * @return array A fully populated example ICP profile array.
	 */
	public static function get_example_profile() {
		return array(
			'id'               => 'b2b-saas-midmarket',
			'name'             => __( 'B2B SaaS Mid-Market', 'mcp-ai-wpoos-pro' ),
			'description'      => __( 'Mid-market B2B SaaS companies with 50-500 employees actively scaling go-to-market operations.', 'mcp-ai-wpoos-pro' ),
			'is_default'       => true,
			'firmographics'    => array(
				'industries'       => array( 'SaaS', 'Cloud Infrastructure', 'MarTech', 'SalesTech', 'HR Tech' ),
				'company_size_min' => 50,
				'company_size_max' => 500,
				'revenue_min'      => 5000000,
				'revenue_max'      => 100000000,
				'geographies'      => array( 'United States', 'Canada', 'United Kingdom', 'Germany', 'Australia' ),
				'funding_stages'   => array( 'Series A', 'Series B', 'Series C' ),
				'business_models'  => array( 'B2B SaaS', 'Subscription', 'Platform' ),
			),
			'technographics'   => array(
				'required_tools'   => array( 'HubSpot', 'Salesforce' ),
				'preferred_tools'  => array( 'Slack', 'Stripe', 'AWS', 'Google Workspace' ),
				'competitor_tools' => array(),
			),
			'triggers'         => array(
				'funding_rounds'      => true,
				'leadership_changes'  => true,
				'hiring_growth'       => true,
				'tech_stack_changes'  => false,
				'compliance_mandates' => false,
				'custom_triggers'     => array(),
			),
			'macro_trends'     => array(
				'description'   => __( 'Growing demand for AI-powered sales and marketing automation.', 'mcp-ai-wpoos-pro' ),
				'market_shifts' => array( 'AI/ML adoption', 'Remote-first operations', 'Consolidation of martech stacks' ),
			),
			'negative_signals' => array(
				'excluded_industries'      => array( 'Tobacco', 'Gambling', 'Adult Entertainment' ),
				'excluded_geographies'     => array(),
				'max_company_size'         => 0,
				'min_revenue'              => 0,
				'competitor_relationships' => false,
				'custom_disqualifiers'     => array( __( 'No dedicated sales or marketing team', 'mcp-ai-wpoos-pro' ) ),
			),
			'scoring_weights'  => self::DEFAULT_WEIGHTS,
			'score_thresholds' => array(
				'tier_a' => 80,
				'tier_b' => 60,
			),
			'created_at'       => gmdate( 'c' ),
			'updated_at'       => gmdate( 'c' ),
		);
	}

	/**
	 * Sanitize a list of strings for safe storage.
	 *
	 * Trims whitespace, applies sanitize_text_field(), and removes empty entries.
	 *
	 * @since 2.11.0
	 *
	 * @param array $list Raw list of strings to sanitize.
	 * @return string[] Array of sanitized, non-empty string values.
	 */
	private static function sanitize_string_list( $list ) {
		if ( ! is_array( $list ) ) {
			return array(); }
		$sanitized = array();
		foreach ( $list as $item ) {
			if ( ! is_string( $item ) && ! is_numeric( $item ) ) {
				continue; }
			$value = trim( sanitize_text_field( (string) $item ) );
			if ( '' !== $value ) {
				$sanitized[] = $value; }
		}
		return array_values( $sanitized );
	}
}
