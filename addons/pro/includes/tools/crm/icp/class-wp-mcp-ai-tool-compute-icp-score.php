<?php
/**
 * Tool: compute_icp_score
 *
 * Computes an Ideal Customer Profile (ICP) fit score for a company or lead
 * using the 7-dimension scoring model.  Supports scoring CPT-backed companies
 * and leads, manual company data, and external intent/engagement/trigger signals.
 *
 * Returns a 0–100 total score with per-dimension breakdown, A/B/C tier
 * classification, and actionable recommendations.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage CRM_Toolkit
 * @since 2.11.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Compute ICP Score tool.
 *
 * Scores a company or lead against a defined Ideal Customer Profile using
 * the 7-dimension model (firmographics, technographics, intent signals,
 * engagement, buying triggers, budget, timeline) and returns a tiered
 * recommendation.
 *
 * @since 2.11.0
 */
class WP_MCP_AI_Tool_Compute_ICP_Score implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * Company CPT post type slug.
	 *
	 * @since 2.11.0
	 * @var string
	 */
	const COMPANY_POST_TYPE = 'mcp_ai_company';

	/**
	 * Lead CPT post type slug.
	 *
	 * @since 2.11.0
	 * @var string
	 */
	const LEAD_POST_TYPE = 'mcp_ai_lead';

	/**
	 * Minimum number of data points required to produce a meaningful score.
	 *
	 * @since 2.11.0
	 * @var int
	 */
	const MIN_DATA_POINTS = 3;

	// ------------------------------------------------------------------ //
	// Availability                                                        //
	// ------------------------------------------------------------------ //

	/**
	 * Whether the tool is available.
	 *
	 * @since 2.11.0
	 *
	 * @return bool True when the CRM toolkit is enabled, Company CPT exists,
	 *              and the ICP Profile class is loaded.
	 */
	public static function is_available() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );

		if ( empty( $settings['enable_crm_toolkit'] ) ) {
			return false;
		}

		if ( ! post_type_exists( self::COMPANY_POST_TYPE ) ) {
			return false;
		}

		return class_exists( 'WP_MCP_AI_ICP_Profile' );
	}

	/**
	 * Reason the tool is unavailable.
	 *
	 * @since 2.11.0
	 *
	 * @return string Localised description.
	 */
	public static function get_unavailable_reason() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );

		if ( empty( $settings['enable_crm_toolkit'] ) ) {
			return __( 'The CRM & Email Marketing Toolkit is not enabled. Enable it in Settings → NV oOS → Tools & Features.', 'mcp-ai-wpoos-pro' );
		}

		if ( ! post_type_exists( self::COMPANY_POST_TYPE ) ) {
			return __( 'The Company custom post type is not registered. Ensure the CRM Toolkit is fully initialised.', 'mcp-ai-wpoos-pro' );
		}

		if ( ! class_exists( 'WP_MCP_AI_ICP_Profile' ) ) {
			return __( 'ICP Profile scoring engine is not available.', 'mcp-ai-wpoos-pro' );
		}

		return __( 'Tool is currently unavailable.', 'mcp-ai-wpoos-pro' );
	}

	// ------------------------------------------------------------------ //
	// Identity                                                            //
	// ------------------------------------------------------------------ //

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'compute_icp_score';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Compute ICP Score', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Calculate an Ideal Customer Profile (ICP) fit score for a company or lead using the 7-dimension scoring model. Returns a 0-100 total score with detailed breakdown by dimension, tier classification (A/B/C), and actionable recommendations.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'company_id'              => array(
					'type'        => 'integer',
					'description' => __( 'ID of a Company CPT post to score. Mutually exclusive with lead_id and manual data.', 'mcp-ai-wpoos-pro' ),
				),
				'lead_id'                 => array(
					'type'        => 'integer',
					'description' => __( 'ID of a Lead CPT post to score. Mutually exclusive with company_id and manual data.', 'mcp-ai-wpoos-pro' ),
				),
				'profile_slug'            => array(
					'type'        => 'string',
					'description' => __( 'ICP profile slug to score against. Defaults to the default profile if omitted.', 'mcp-ai-wpoos-pro' ),
				),
				'company_data'            => array(
					'type'        => 'object',
					'description' => __( 'Manual company data to score (when not using a CPT). Required fields: company_name. Optional: industry, company_size, revenue, city, state, country, website, tech_stack, funding_stage, business_model.', 'mcp-ai-wpoos-pro' ),
				),
				'intent_signals'          => array(
					'type'        => 'object',
					'description' => __( 'External intent signals to score. Optional: g2_activity, gartner_activity, keyword_searches, job_postings.', 'mcp-ai-wpoos-pro' ),
				),
				'engagement_data'         => array(
					'type'        => 'object',
					'description' => __( 'Engagement activity data with timestamps. Optional: demo_requests, pricing_visits, content_downloads, webinar_attendance, email_replies, page_views.', 'mcp-ai-wpoos-pro' ),
				),
				'buying_triggers'         => array(
					'type'        => 'object',
					'description' => __( 'Buying trigger events with dates. Optional: funding_round, new_leadership, rapid_hiring, compliance_mandate, office_expansion.', 'mcp-ai-wpoos-pro' ),
				),
				'include_breakdown'       => array(
					'type'        => 'boolean',
					'description' => __( 'Include per-dimension score breakdown. Default true.', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'include_recommendations' => array(
					'type'        => 'boolean',
					'description' => __( 'Include actionable recommendations. Default true.', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
			),
			'required'             => array(),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'requires-capability',
			'read-only',
			'local-only',
		);
	}

	/**
	 * Get extended tool definition including toolkit metadata.
	 *
	 * @since 2.11.0
	 *
	 * @return array Tool definition with metadata.
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'crm',
			'pattern_compatibility' => array( 'orchestrator', 'peer_to_peer' ),
			'profession_tags'       => array( 'sales_manager', 'business_development', 'marketing_manager' ),
			'risk_level'            => 'info',
		);
	}

	// ------------------------------------------------------------------ //
	// Execution                                                           //
	// ------------------------------------------------------------------ //

	/**
	 * Execute the tool.
	 *
	 * @since 2.11.0
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id, assistant_id.
	 * @return array|WP_Error  Canonical success envelope or WP_Error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// ---- Pre-flight: availability ---------------------------------
		if ( ! self::is_available() ) {
			return new WP_Error(
				'wp_mcp_ai_icp_unavailable',
				self::get_unavailable_reason()
			);
		}

		// ---- Authorisation --------------------------------------------
		$user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id || ! user_can( $user_id, 'edit_posts' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to compute ICP scores.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error(
				'wp_mcp_ai_wrong_site',
				__( 'You do not have access to this site.', 'mcp-ai-wpoos-pro' )
			);
		}

		// ---- Resolve data source --------------------------------------
		$company_id  = isset( $arguments['company_id'] ) ? absint( $arguments['company_id'] ) : 0;
		$lead_id     = isset( $arguments['lead_id'] ) ? absint( $arguments['lead_id'] ) : 0;
		$manual_data = isset( $arguments['company_data'] ) ? (array) $arguments['company_data'] : array();

		// Mutually exclusive check.
		$sources = array_filter( array( $company_id, $lead_id, ! empty( $manual_data ) ) );
		if ( count( $sources ) > 1 ) {
			return new WP_Error(
				'wp_mcp_ai_ambiguous_source',
				__( 'Provide only one data source: company_id, lead_id, or company_data.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( 0 === count( $sources ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_source',
				__( 'A data source is required. Provide company_id, lead_id, or company_data.', 'mcp-ai-wpoos-pro' )
			);
		}

		// ---- Resolve ICP profile --------------------------------------
		$profile_slug = isset( $arguments['profile_slug'] ) ? sanitize_key( $arguments['profile_slug'] ) : '';

		$profile = $this->resolve_profile( $profile_slug );
		if ( is_wp_error( $profile ) ) {
			return $profile;
		}

		// ---- Collect company data -------------------------------------
		$company_data = array();

		if ( $company_id > 0 ) {
			$company_data = $this->collect_company_data_from_cpt( $company_id );
			if ( is_wp_error( $company_data ) ) {
				return $company_data;
			}
		} elseif ( $lead_id > 0 ) {
			$lead_data = $this->collect_lead_data( $lead_id );
			if ( is_wp_error( $lead_data ) ) {
				return $lead_data;
			}
			$company_data = $lead_data;
		} else {
			// Manual data — sanitise at entry.
			$company_data = $this->sanitise_manual_company_data( $manual_data );
		}

		// ---- Merge signal / engagement / trigger data -----------------
		$intent_signals  = isset( $arguments['intent_signals'] ) ? (array) $arguments['intent_signals'] : array();
		$engagement_data = isset( $arguments['engagement_data'] ) ? (array) $arguments['engagement_data'] : array();
		$buying_triggers = isset( $arguments['buying_triggers'] ) ? (array) $arguments['buying_triggers'] : array();

		$company_data = $this->merge_manual_data( $company_data, $intent_signals, $engagement_data, $buying_triggers );

		// ---- Validate data completeness -------------------------------
		$validation = $this->validate_data_completeness( $company_data );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		// ---- Compute score --------------------------------------------
		if ( ! class_exists( 'WP_MCP_AI_ICP_Scorer' ) ) {
			return new WP_Error(
				'wp_mcp_ai_scorer_missing',
				__( 'ICP scoring engine is not available.', 'mcp-ai-wpoos-pro' )
			);
		}

		$include_breakdown       = ! isset( $arguments['include_breakdown'] ) || (bool) $arguments['include_breakdown'];
		$include_recommendations = ! isset( $arguments['include_recommendations'] ) || (bool) $arguments['include_recommendations'];

		$result = WP_MCP_AI_ICP_Scorer::compute_score(
			$company_data,
			$profile['id'],
			array(
				'include_breakdown'       => $include_breakdown,
				'include_recommendations' => $include_recommendations,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// ---- Build canonical envelope --------------------------------
		$response = array(
			'success'      => true,
			'profile_used' => $profile['slug'],
			'profile_name' => $profile['name'],
			'total_score'  => isset( $result['total_score'] ) ? (int) $result['total_score'] : 0,
			'fit_score'    => isset( $result['fit_score'] ) ? (int) $result['fit_score'] : 0,
			'intent_score' => isset( $result['intent_score'] ) ? (int) $result['intent_score'] : 0,
			'tier'         => isset( $result['tier'] ) ? sanitize_text_field( $result['tier'] ) : 'C',
			'tier_label'   => isset( $result['tier_label'] ) ? sanitize_text_field( $result['tier_label'] ) : '',
			'scored_at'    => gmdate( 'c' ),
		);

		if ( $include_breakdown && isset( $result['dimension_scores'] ) ) {
			$response['dimension_scores'] = $result['dimension_scores'];
		}

		if ( $include_recommendations && isset( $result['recommendation'] ) ) {
			$response['recommendation'] = wp_kses_post( $result['recommendation'] );
		}

		// Include additional scorer output when available.
		if ( isset( $result['scoring_version'] ) ) {
			$response['scoring_version'] = sanitize_text_field( $result['scoring_version'] );
		}

		/**
		 * Filter the computed ICP score response before returning.
		 *
		 * @since 2.11.0
		 *
		 * @param array $response      The response envelope.
		 * @param array $company_data  The company data that was scored.
		 * @param array $profile       The ICP profile used (slug, name, id).
		 * @param array $context       The execution context.
		 */
		return apply_filters( 'wp_mcp_ai_icp_score_response', $response, $company_data, $profile, $context );
	}

	// ------------------------------------------------------------------ //
	// Profile Resolution                                                  //
	// ------------------------------------------------------------------ //

	/**
	 * Resolve the ICP profile to use.
	 *
	 * If a slug is provided it is looked up; otherwise the default profile
	 * is returned.
	 *
	 * @since 2.11.0
	 *
	 * @param string $slug Optional profile slug.
	 * @return array{slug:string, name:string, id:int}|WP_Error
	 */
	private function resolve_profile( $slug ) {
		$profile = null;

		if ( '' !== $slug ) {
			$profile = WP_MCP_AI_ICP_Profile::get_by_slug( $slug );
			if ( ! $profile ) {
				return new WP_Error(
					'wp_mcp_ai_profile_not_found',
					sprintf(
						/* translators: %s: profile slug */
						__( 'ICP profile "%s" was not found.', 'mcp-ai-wpoos-pro' ),
						$slug
					)
				);
			}
		} else {
			$profile = WP_MCP_AI_ICP_Profile::get_default();
			if ( ! $profile ) {
				return new WP_Error(
					'wp_mcp_ai_no_default_profile',
					__( 'No default ICP profile is configured. Create one in the CRM settings.', 'mcp-ai-wpoos-pro' )
				);
			}
		}

		return array(
			'slug' => $profile['slug'],
			'name' => $profile['name'],
			'id'   => (int) $profile['id'],
		);
	}

	// ------------------------------------------------------------------ //
	// Data Collectors                                                     //
	// ------------------------------------------------------------------ //

	/**
	 * Extract company data from a Company CPT post.
	 *
	 * @since 2.11.0
	 *
	 * @param int $post_id Company post ID.
	 * @return array|WP_Error Associative array of company data or WP_Error.
	 */
	private function collect_company_data_from_cpt( $post_id ) {
		$post = get_post( $post_id );

		if ( ! $post || self::COMPANY_POST_TYPE !== $post->post_type ) {
			return new WP_Error(
				'wp_mcp_ai_company_not_found',
				sprintf(
					/* translators: %d: post ID */
					__( 'Company with ID %d was not found.', 'mcp-ai-wpoos-pro' ),
					$post_id
				)
			);
		}

		if ( 'publish' !== $post->post_status ) {
			return new WP_Error(
				'wp_mcp_ai_company_not_published',
				__( 'The specified company is not published.', 'mcp-ai-wpoos-pro' )
			);
		}

		$all_meta = get_post_meta( $post_id );

		/**
		 * Helper: pull a meta value, optionally with a default.
		 *
		 * @param string $key     Meta key.
		 * @param mixed  $default Fallback value.
		 * @return mixed
		 */
		$meta = function ( $key, $default = '' ) use ( $all_meta ) {
			if ( isset( $all_meta[ $key ] ) ) {
				$value = is_array( $all_meta[ $key ] ) ? $all_meta[ $key ][0] : $all_meta[ $key ];
				return maybe_unserialize( $value );
			}
			return $default;
		};

		$data = array(
			'company_name'  => sanitize_text_field( $post->post_title ),
			'industry'      => sanitize_text_field( $meta( '_company_industry' ) ),
			'company_size'  => sanitize_text_field( $meta( '_company_size' ) ),
			'website'       => esc_url_raw( $meta( '_company_website' ) ),
			'city'          => sanitize_text_field( $meta( '_company_city' ) ),
			'state'         => sanitize_text_field( $meta( '_company_state' ) ),
			'country'       => sanitize_text_field( $meta( '_company_country' ) ),
			'revenue'       => $this->normalise_numeric( $meta( '_company_revenue' ) ),
			'linkedin'      => esc_url_raw( $meta( '_company_linkedin' ) ),
			'twitter'       => sanitize_text_field( $meta( '_company_twitter' ) ),
			'target_status' => sanitize_key( $meta( '_company_target_status' ) ),
			'phone'         => sanitize_text_field( $meta( '_company_phone' ) ),
			'postal_code'   => sanitize_text_field( $meta( '_company_zip' ) ),
			'description'   => wp_kses_post( $post->post_content ),
		);

		// Pull any custom ICP-related meta stored on the company.
		$icp_meta_keys = array(
			'tech_stack'     => '_company_tech_stack',
			'funding_stage'  => '_company_funding_stage',
			'business_model' => '_company_business_model',
			'employee_count' => '_company_employee_count',
		);

		foreach ( $icp_meta_keys as $field => $meta_key ) {
			$value = $meta( $meta_key );
			if ( '' !== $value ) {
				$data[ $field ] = sanitize_text_field( $value );
			}
		}

		// Pull the raw employee-count field if present in size meta.
		$size = $meta( '_company_size' );
		if ( '' !== $size && ! isset( $data['employee_count'] ) ) {
			$data['employee_count_range'] = sanitize_text_field( $size );
		}

		/**
		 * Filter the company data extracted from a CPT before scoring.
		 *
		 * @since 2.11.0
		 *
		 * @param array $data    Extracted company data.
		 * @param int   $post_id Company post ID.
		 */
		return apply_filters( 'wp_mcp_ai_icp_company_cpt_data', $data, $post_id );
	}

	/**
	 * Extract lead data from a Lead CPT post, optionally pulling associated
	 * company data.
	 *
	 * @since 2.11.0
	 *
	 * @param int $post_id Lead post ID.
	 * @return array|WP_Error Associative array of lead/company data or WP_Error.
	 */
	private function collect_lead_data( $post_id ) {
		$post = get_post( $post_id );

		if ( ! $post || self::LEAD_POST_TYPE !== $post->post_type ) {
			return new WP_Error(
				'wp_mcp_ai_lead_not_found',
				sprintf(
					/* translators: %d: post ID */
					__( 'Lead with ID %d was not found.', 'mcp-ai-wpoos-pro' ),
					$post_id
				)
			);
		}

		if ( 'publish' !== $post->post_status ) {
			return new WP_Error(
				'wp_mcp_ai_lead_not_published',
				__( 'The specified lead is not published.', 'mcp-ai-wpoos-pro' )
			);
		}

		$all_meta = get_post_meta( $post_id );

		$meta = function ( $key, $default = '' ) use ( $all_meta ) {
			if ( isset( $all_meta[ $key ] ) ) {
				$value = is_array( $all_meta[ $key ] ) ? $all_meta[ $key ][0] : $all_meta[ $key ];
				return maybe_unserialize( $value );
			}
			return $default;
		};

		$data = array(
			'company_name'    => sanitize_text_field( $meta( 'company_name', $meta( 'company' ) ) ),
			'first_name'      => sanitize_text_field( $meta( 'first_name' ) ),
			'last_name'       => sanitize_text_field( $meta( 'last_name' ) ),
			'email'           => sanitize_email( $meta( 'email' ) ),
			'phone'           => sanitize_text_field( $meta( 'phone' ) ),
			'job_title'       => sanitize_text_field( $meta( 'job_title' ) ),
			'lead_status'     => sanitize_key( $meta( 'lead_status' ) ),
			'lifecycle_stage' => sanitize_key( $meta( 'lifecycle_stage' ) ),
			'lead_score'      => $this->normalise_numeric( $meta( 'lead_score' ) ),
			'source'          => sanitize_text_field( $meta( 'source' ) ),
			'contact_owner'   => absint( $meta( 'contact_owner' ) ),
		);

		// If the lead has an associated Company CPT post, merge its data.
		$company_id = $meta( '_company_id' );
		if ( $company_id ) {
			$company_data = $this->collect_company_data_from_cpt( absint( $company_id ) );
			if ( ! is_wp_error( $company_data ) ) {
				// Lead-level values take precedence; company values fill gaps.
				$data = array_merge( $company_data, array_filter( $data ) );
				// Restore lead-specific overrides that shouldn't be clobbered.
				$lead_fields = array( 'first_name', 'last_name', 'email', 'phone', 'job_title', 'lead_status', 'lifecycle_stage', 'lead_score', 'source', 'contact_owner' );
				foreach ( $lead_fields as $field ) {
					$raw = $meta( $field );
					if ( '' !== $raw ) {
						$data[ $field ] = sanitize_text_field( $raw );
					}
				}
			}
		}

		// Inline the company_name from the post title if still empty.
		if ( empty( $data['company_name'] ) ) {
			$data['company_name'] = sanitize_text_field( $post->post_title );
		}

		/**
		 * Filter the lead data before scoring.
		 *
		 * @since 2.11.0
		 *
		 * @param array $data    Extracted lead data.
		 * @param int   $post_id Lead post ID.
		 */
		return apply_filters( 'wp_mcp_ai_icp_lead_cpt_data', $data, $post_id );
	}

	// ------------------------------------------------------------------ //
	// Data Helpers                                                        //
	// ------------------------------------------------------------------ //

	/**
	 * Sanitise manual company data at entry.
	 *
	 * @since 2.11.0
	 *
	 * @param array $raw Raw manual data from arguments.
	 * @return array Sanitised associative array.
	 */
	private function sanitise_manual_company_data( array $raw ) {
		$allowed_fields = array(
			'company_name',
			'industry',
			'company_size',
			'revenue',
			'city',
			'state',
			'country',
			'website',
			'tech_stack',
			'funding_stage',
			'business_model',
			'employee_count',
		);

		$clean = array();

		foreach ( $allowed_fields as $field ) {
			if ( ! isset( $raw[ $field ] ) || '' === $raw[ $field ] ) {
				continue;
			}

			switch ( $field ) {
				case 'website':
					$clean[ $field ] = esc_url_raw( $raw[ $field ] );
					break;

				case 'revenue':
				case 'employee_count':
					$clean[ $field ] = $this->normalise_numeric( $raw[ $field ] );
					break;

				default:
					$clean[ $field ] = sanitize_text_field( $raw[ $field ] );
					break;
			}
		}

		return $clean;
	}

	/**
	 * Merge external intent, engagement, and trigger data into the company
	 * data array using prefixed keys to avoid collisions.
	 *
	 * @since 2.11.0
	 *
	 * @param array $company_data    Base company data.
	 * @param array $intent_signals  Intent signal data.
	 * @param array $engagement_data Engagement activity data.
	 * @param array $buying_triggers Buying trigger event data.
	 * @return array Merged data array.
	 */
	private function merge_manual_data( array $company_data, array $intent_signals, array $engagement_data, array $buying_triggers ) {
		// Intent signals — prefix with intent_.
		$intent_keys = array( 'g2_activity', 'gartner_activity', 'keyword_searches', 'job_postings' );
		foreach ( $intent_keys as $key ) {
			if ( isset( $intent_signals[ $key ] ) && '' !== $intent_signals[ $key ] ) {
				$company_data[ 'intent_' . $key ] = sanitize_text_field( $intent_signals[ $key ] );
			}
		}

		// Engagement data — prefix with engagement_.
		$engagement_keys = array( 'demo_requests', 'pricing_visits', 'content_downloads', 'webinar_attendance', 'email_replies', 'page_views' );
		foreach ( $engagement_keys as $key ) {
			if ( isset( $engagement_data[ $key ] ) && '' !== $engagement_data[ $key ] ) {
				$company_data[ 'engagement_' . $key ] = $this->normalise_numeric( $engagement_data[ $key ] );
			}
		}

		// Buying triggers — prefix with trigger_.
		$trigger_keys = array( 'funding_round', 'new_leadership', 'rapid_hiring', 'compliance_mandate', 'office_expansion' );
		foreach ( $trigger_keys as $key ) {
			if ( isset( $buying_triggers[ $key ] ) && '' !== $buying_triggers[ $key ] ) {
				$company_data[ 'trigger_' . $key ] = sanitize_text_field( $buying_triggers[ $key ] );
			}
		}

		return $company_data;
	}

	/**
	 * Validate that minimum data is available to produce a meaningful score.
	 *
	 * @since 2.11.0
	 *
	 * @param array $data Company data array.
	 * @return true|WP_Error True when valid, WP_Error otherwise.
	 */
	private function validate_data_completeness( array $data ) {
		// At minimum we need a company name.
		if ( empty( $data['company_name'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_company_name',
				__( 'A company name is required to compute an ICP score.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Count populated fields (excluding the company name itself and empty strings).
		$populated = 0;
		foreach ( $data as $key => $value ) {
			if ( 'company_name' === $key ) {
				continue;
			}
			if ( is_scalar( $value ) && '' !== $value ) {
				++$populated;
			} elseif ( is_array( $value ) && ! empty( $value ) ) {
				++$populated;
			}
		}

		if ( $populated < self::MIN_DATA_POINTS ) {
			return new WP_Error(
				'wp_mcp_ai_insufficient_data',
				sprintf(
					/* translators: 1: number of data points found, 2: minimum required */
					__( 'Insufficient data for scoring. Found %1$d data point(s); a minimum of %2$d are required. Provide company_size, industry, revenue, or other dimensions.', 'mcp-ai-wpoos-pro' ),
					$populated,
					self::MIN_DATA_POINTS
				)
			);
		}

		return true;
	}

	/**
	 * Normalise a value to a float or int, returning 0 for non-numeric input.
	 *
	 * @since 2.11.0
	 *
	 * @param mixed $value Raw value.
	 * @return float|int Normalised numeric value.
	 */
	private function normalise_numeric( $value ) {
		if ( is_numeric( $value ) ) {
			return $value + 0; // Cast to int or float.
		}

		// Try to extract a number from a string like "$1.2M" or "500 employees".
		if ( is_string( $value ) ) {
			$cleaned = preg_replace( '/[^0-9.\-]/', '', $value );
			if ( is_numeric( $cleaned ) ) {
				return $cleaned + 0;
			}
		}

		return 0;
	}
}
