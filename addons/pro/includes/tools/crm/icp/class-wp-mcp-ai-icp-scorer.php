<?php
/**
 * ICP (Ideal Customer Profile) Scoring Engine
 *
 * Computes 0–100 ICP fit scores using a 7-dimension model designed for
 * B2B lead qualification.  Each dimension is scored independently against
 * a saved ICP profile and then combined with configurable weights into an
 * overall score, a stability-separated dual score (fit vs intent), and a
 * tier-based recommendation (A / B / C).
 *
 * The engine is cached via WP_Object_Cache (group `wp_mcp_ai_icp_fit`)
 * and supports behavioural-signal decay so engagement and intent scores
 * naturally degrade over time.
 *
 * @package    WP_MCP_AI_Pro
 * @subpackage CRM_Toolkit
 * @since      2.11.0
 * @author     NV Digital Solutions
 * @copyright  Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license    Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ICP scoring engine.
 *
 * @since 2.11.0
 */
class WP_MCP_AI_ICP_Scorer {

	/**
	 * Object-cache group for ICP fit scores.
	 *
	 * @since 2.11.0
	 * @var string
	 */
	const FIT_CACHE_GROUP = 'wp_mcp_ai_icp_fit';

	/**
	 * Behavioural-signal decay rate (15 % per decay period).
	 *
	 * @since 2.11.0
	 * @var float
	 */
	const DECAY_RATE = 0.15;

	/**
	 * Decay interval in days.
	 *
	 * @since 2.11.0
	 * @var int
	 */
	const DECAY_PERIOD_DAYS = 30;

	// -------------------------------------------------------------------------
	// Industry taxonomy (canonical slugs used for fuzzy matching)
	// -------------------------------------------------------------------------

	/**
	 * Industry aliases map — canonical slug → recognised variants.
	 *
	 * Populated once and reused across dimension scorers.
	 *
	 * @since 2.11.0
	 * @var array<string,string[]>
	 */
	private static $industry_aliases = array(
		'saas'              => array( 'saas', 'software as a service', 'cloud software', 'b2b saas', 'software' ),
		'fintech'           => array( 'fintech', 'financial technology', 'financial services', 'banking', 'payments', 'insurance' ),
		'healthtech'        => array( 'healthtech', 'health care', 'healthcare', 'health tech', 'digital health', 'medtech', 'medical' ),
		'ecommerce'         => array( 'ecommerce', 'e-commerce', 'online retail', 'retail', 'd2c', 'direct to consumer' ),
		'manufacturing'     => array( 'manufacturing', 'industrial', 'factory', 'production' ),
		'logistics'         => array( 'logistics', 'supply chain', 'transportation', 'shipping', 'freight', 'warehousing' ),
		'proptech'          => array( 'proptech', 'real estate', 'property', 'property tech', 'real estate tech' ),
		'edtech'            => array( 'edtech', 'education', 'education technology', 'ed tech', 'e-learning', 'online learning' ),
		'legaltech'         => array( 'legaltech', 'legal', 'legal technology', 'law', 'legal tech' ),
		'cybersecurity'     => array( 'cybersecurity', 'cyber', 'cyber security', 'information security', 'infosec' ),
		'marketing_tech'    => array( 'marketing tech', 'martech', 'marketing technology', 'adtech', 'advertising tech' ),
		'hrt'               => array( 'hr tech', 'hrt', 'hr technology', 'human resources', 'people ops' ),
		'energy'            => array( 'energy', 'cleantech', 'clean energy', 'renewable', 'oil and gas', 'utilities' ),
		'government'        => array( 'government', 'public sector', 'govtech', 'federal', 'municipal' ),
		'nonprofit'         => array( 'nonprofit', 'non-profit', 'ngo', 'charity', 'not for profit' ),
		'professional_svcs' => array( 'professional services', 'consulting', 'agency', 'services' ),
		'telecom'           => array( 'telecom', 'telecommunications', 'isp', 'connectivity' ),
		'media'             => array( 'media', 'publishing', 'entertainment', 'broadcasting' ),
		'automotive'        => array( 'automotive', 'auto', 'mobility', 'vehicle' ),
		'agritech'          => array( 'agritech', 'agriculture', 'ag tech', 'farming', 'agritech' ),
	);

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Compute a full ICP fit score for a company or lead.
	 *
	 * This is the main entry point.  It delegates to the seven dimension
	 * scorers, applies configured weights, computes derived aggregates
	 * (fit / intent / economic), determines a tier, and returns a
	 * comprehensive result array.
	 *
	 * @since 2.11.0
	 *
	 * @param array $company_data Company / lead attributes. Expected keys:
	 *                            industry, size, revenue, country, region,
	 *                            tech_stack, funding_stage, employees, etc.
	 * @param array $icp_profile  Saved ICP profile definition (same shape).
	 * @param array $options      Optional overrides – see class docblock.
	 * @return array|WP_Error     Score result or WP_Error on invalid input.
	 */
	public static function compute_score( array $company_data, array $icp_profile, array $options = array() ) {
		if ( empty( $company_data ) || empty( $icp_profile ) ) {
			return new WP_Error(
				'icp_invalid_input',
				__( 'Company data and ICP profile are both required.', 'mcp-ai-wpoos-pro' )
			);
		}

		// --- Options ---
		$skip_cache        = ! empty( $options['skip_cache'] );
		$include_breakdown = ! isset( $options['include_breakdown'] ) || $options['include_breakdown'];
		$now               = isset( $options['now'] ) ? (string) $options['now'] : gmdate( 'c' );

		// --- Weights (profile-defined or custom) ---
		$weights = isset( $options['custom_weights'] ) && is_array( $options['custom_weights'] )
			? self::normalise_weights( $options['custom_weights'] )
			: self::get_profile_weights( $icp_profile );

		// --- Cache key ---
		$cache_key = self::build_cache_key( $company_data, $icp_profile, $weights, $skip_cache );

		if ( ! $skip_cache ) {
			$cached = wp_cache_get( $cache_key, self::FIT_CACHE_GROUP );
			if ( is_array( $cached ) && isset( $cached['total_score'] ) ) {
				// Re-stamp the scored_at on cache hits so consumers see the
				// moment the result was served, not when it was computed.
				$cached['scored_at'] = $now;
				return $cached;
			}
		}

		// --- Score each dimension ---
		$dims                        = array();
		$dims['firmographic_fit']    = self::score_firmographic_fit( $company_data, $icp_profile );
		$dims['technographic_fit']   = self::score_technographic_fit( $company_data, $icp_profile );
		$dims['intent_signals']      = self::score_intent_signals(
			isset( $company_data['intent_signals'] ) ? (array) $company_data['intent_signals'] : array(),
			$icp_profile
		);
		$dims['engagement_activity'] = self::score_engagement_activity(
			isset( $company_data['engagement_activity'] ) ? (array) $company_data['engagement_activity'] : array(),
			$options
		);
		$dims['buying_triggers']     = self::score_buying_triggers(
			isset( $company_data['buying_triggers'] ) ? (array) $company_data['buying_triggers'] : array(),
			$icp_profile
		);
		$dims['economic_outcome']    = self::score_economic_outcome( $company_data, $icp_profile );
		$dims['negative_signals']    = self::score_negative_signals( $company_data, $icp_profile );

		// --- Weighted total ---
		$total_score = 0;
		foreach ( $dims as $slug => $dim ) {
			$w = isset( $weights[ $slug ] ) ? (float) $weights[ $slug ] : 0.0;
			// negative_signals already uses subtractive logic; ensure it
			// doesn't pull the total below zero.
			$total_score += (int) round( ( $dim['score'] / max( $dim['max'], 1 ) * 100 ) * $w );
		}
		$total_score = self::clamp( $total_score, 0, 100 );

		// --- Derived aggregates ---
		// fit_score: stable dimensions (firmographic + technographic + negative)
		$fit_w   = array( 'firmographic_fit', 'technographic_fit', 'negative_signals' );
		$fit_max = 0;
		$fit_raw = 0;
		foreach ( $fit_w as $slug ) {
			$dim      = $dims[ $slug ];
			$w        = isset( $weights[ $slug ] ) ? (float) $weights[ $slug ] : 0.0;
			$fit_max += ( 100 * $w );
			$fit_raw += (int) round( ( $dim['score'] / max( $dim['max'], 1 ) * 100 ) * $w );
		}
		$fit_score = $fit_max > 0 ? self::clamp( (int) round( ( $fit_raw / $fit_max ) * 100 ), 0, 100 ) : 50;

		// intent_score: volatile dimensions (intent + engagement + triggers).
		$int_w   = array( 'intent_signals', 'engagement_activity', 'buying_triggers' );
		$int_max = 0;
		$int_raw = 0;
		foreach ( $int_w as $slug ) {
			$dim      = $dims[ $slug ];
			$w        = isset( $weights[ $slug ] ) ? (float) $weights[ $slug ] : 0.0;
			$int_max += ( 100 * $w );
			$int_raw += (int) round( ( $dim['score'] / max( $dim['max'], 1 ) * 100 ) * $w );
		}
		$intent_score = $int_max > 0 ? self::clamp( (int) round( ( $int_raw / $int_max ) * 100 ), 0, 100 ) : 50;

		// economic_score: lone economic dimension, scaled to 0–100.
		$econ_dim       = $dims['economic_outcome'];
		$economic_score = $econ_dim['max'] > 0
			? self::clamp( (int) round( ( $econ_dim['score'] / $econ_dim['max'] ) * 100 ), 0, 100 )
			: 50;

		// --- Tier ---
		$thresholds = isset( $icp_profile['tier_thresholds'] ) && is_array( $icp_profile['tier_thresholds'] )
			? $icp_profile['tier_thresholds']
			: array(
				'A' => 70,
				'B' => 40,
			);
		$tier       = self::determine_tier( $total_score, $thresholds );

		// --- Recommendation ---
		$result                   = array(
			'total_score'      => $total_score,
			'fit_score'        => $fit_score,
			'intent_score'     => $intent_score,
			'economic_score'   => $economic_score,
			'tier'             => $tier,
			'dimension_scores' => $include_breakdown ? $dims : array(),
			'recommendation'   => '',
			'scored_at'        => $now,
		);
		$result['recommendation'] = self::generate_recommendation( $result );

		// --- Cache (24 h TTL via transient-style key) ---
		if ( ! $skip_cache ) {
			wp_cache_set( $cache_key, $result, self::FIT_CACHE_GROUP, DAY_IN_SECONDS );
		}

		return $result;
	}

	/**
	 * Score company against every saved ICP profile, returning results
	 * sorted by total_score descending.
	 *
	 * @since 2.11.0
	 *
	 * @param array $company_data Company / lead attributes.
	 * @param array $options      Passed through to compute_score.
	 * @return array[] Array of result arrays keyed by profile slug.
	 */
	public static function score_against_all_profiles( array $company_data, array $options = array() ) {
		$profiles = self::get_all_icp_profiles();

		if ( empty( $profiles ) ) {
			return array();
		}

		$results = array();
		foreach ( $profiles as $slug => $profile ) {
			$score = self::compute_score( $company_data, $profile, $options );
			if ( is_wp_error( $score ) ) {
				continue;
			}
			$results[ $slug ] = $score;
		}

		uasort(
			$results,
			function ( $a, $b ) {
				return (int) $b['total_score'] - (int) $a['total_score'];
			}
		);

		return $results;
	}

	/**
	 * Combine multiple contact-level scores into a single account-level
	 * score.  Takes max fit_score and max intent_score (not averages),
	 * since the strongest signal is what matters at account level.
	 *
	 * @since 2.11.0
	 *
	 * @param array[] $individual_scores Array of compute_score result arrays.
	 * @return array Aggregated result (same shape as compute_score).
	 */
	public static function aggregate_account_scores( array $individual_scores ) {
		if ( empty( $individual_scores ) ) {
			return array(
				'total_score'      => 0,
				'fit_score'        => 0,
				'intent_score'     => 0,
				'economic_score'   => 0,
				'tier'             => 'C',
				'dimension_scores' => array(),
				'recommendation'   => __( 'No contacts scored for this account.', 'mcp-ai-wpoos-pro' ),
				'scored_at'        => gmdate( 'c' ),
			);
		}

		$max_fit       = 0;
		$max_intent    = 0;
		$max_economic  = 0;
		$best_overall  = 0;
		$contact_count = count( $individual_scores );

		foreach ( $individual_scores as $score ) {
			if ( ! is_array( $score ) || ! isset( $score['total_score'] ) ) {
				continue;
			}

			$fit      = isset( $score['fit_score'] ) ? (int) $score['fit_score'] : 0;
			$intent   = isset( $score['intent_score'] ) ? (int) $score['intent_score'] : 0;
			$economic = isset( $score['economic_score'] ) ? (int) $score['economic_score'] : 0;
			$overall  = (int) $score['total_score'];

			if ( $fit > $max_fit ) {
				$max_fit = $fit;
			}
			if ( $intent > $max_intent ) {
				$max_intent = $intent;
			}
			if ( $economic > $max_economic ) {
				$max_economic = $economic;
			}
			if ( $overall > $best_overall ) {
				$best_overall = $overall;
			}
		}

		// Composite: weighted blend of max fit (60 %) + max intent (40 %).
		$total = (int) round( ( $max_fit * 0.60 ) + ( $max_intent * 0.40 ) );
		$total = self::clamp( $total, 0, 100 );

		$thresholds = array(
			'A' => 70,
			'B' => 40,
		);
		$tier       = self::determine_tier( $total, $thresholds );

		$result                   = array(
			'total_score'      => $total,
			'fit_score'        => $max_fit,
			'intent_score'     => $max_intent,
			'economic_score'   => $max_economic,
			'tier'             => $tier,
			'dimension_scores' => array(),
			'recommendation'   => '',
			'contact_count'    => $contact_count,
			'scored_at'        => gmdate( 'c' ),
		);
		$result['recommendation'] = self::generate_recommendation( $result );

		return $result;
	}

	// -------------------------------------------------------------------------
	// Dimension 1 – Firmographic Fit (25 %)
	// -------------------------------------------------------------------------

	/**
	 * Score firmographic alignment between company and ICP.
	 *
	 * Sub-dimensions (raw points, normalised to 0–100):
	 *  – Industry match    (max 40)
	 *  – Size / employees  (max 25)
	 *  – Revenue range     (max 20)
	 *  – Geography         (max 15)
	 *
	 * @since 2.11.0
	 *
	 * @param array $company Company data.
	 * @param array $profile ICP profile.
	 * @return array Dimension score array.
	 */
	public static function score_firmographic_fit( array $company, array $profile ) {
		$max_total = 100;
		$score     = 0;
		$matches   = array();
		$misses    = array();

		// --- Industry (max 40) ---
		$company_industry = isset( $company['industry'] ) ? sanitize_text_field( $company['industry'] ) : '';
		$profile_industry = isset( $profile['industry'] ) ? sanitize_text_field( $profile['industry'] ) : '';

		$industry_score = 0;
		if ( '' !== $company_industry && '' !== $profile_industry ) {
			$company_canon = self::canonical_industry( $company_industry );
			$profile_canon = self::canonical_industry( $profile_industry );

			if ( $company_canon === $profile_canon ) {
				$industry_score = 40;
				$matches[]      = sprintf(
					/* translators: %s: industry name */
					__( 'Industry match: %s', 'mcp-ai-wpoos-pro' ),
					$company_industry
				);
			} else {
				// Partial / adjacent-industry bonus.
				$adjacency      = self::industry_adjacency_score( $company_canon, $profile_canon );
				$industry_score = (int) round( 40 * $adjacency );
				if ( $industry_score >= 20 ) {
					$matches[] = sprintf(
						/* translators: 1: company industry, 2: profile industry */
						__( 'Adjacent industry: %1$s → %2$s', 'mcp-ai-wpoos-pro' ),
						$company_industry,
						$profile_industry
					);
				} else {
					$misses[] = sprintf(
						/* translators: 1: company industry, 2: target industry */
						__( 'Industry mismatch: %1$s is not %2$s', 'mcp-ai-wpoos-pro' ),
						$company_industry,
						$profile_industry
					);
				}
			}
		} elseif ( '' === $company_industry ) {
			$misses[] = __( 'Company industry is unknown.', 'mcp-ai-wpoos-pro' );
		}
		$score += $industry_score;

		// --- Company size / employees (max 25) ---
		$emp     = isset( $company['employees'] ) ? (int) $company['employees'] : 0;
		$emp_min = isset( $profile['employees_min'] ) ? (int) $profile['employees_min'] : 0;
		$emp_max = isset( $profile['employees_max'] ) ? (int) $profile['employees_max'] : 0;

		if ( $emp > 0 && ( $emp_min > 0 || $emp_max > 0 ) ) {
			if ( $emp_max <= 0 ) {
				$emp_max = PHP_INT_MAX;
			}
			if ( $emp >= $emp_min && $emp <= $emp_max ) {
				$size_score = 25;
				$matches[]  = sprintf(
					/* translators: %d: employee count */
					__( 'Employee count within target range (%d)', 'mcp-ai-wpoos-pro' ),
					$emp
				);
			} else {
				// Score based on proximity to range.
				$midpoint   = ( $emp_min + $emp_max ) / 2;
				$distance   = $emp < $emp_min ? ( $emp_min - $emp ) : ( $emp - $emp_max );
				$proximity  = max( 0, 1 - ( $distance / max( $midpoint, 1 ) ) );
				$size_score = (int) round( 25 * $proximity );
				$misses[]   = sprintf(
					/* translators: 1: actual, 2: min, 3: max */
					__( 'Employee count %1$d outside range %2$d–%3$d', 'mcp-ai-wpoos-pro' ),
					$emp,
					$emp_min,
					$emp_max
				);
			}
		} else {
			$size_score = 13; // Neutral when unknown — don't penalise missing data.
		}
		$score += $size_score;

		// --- Revenue (max 20) ---
		$rev     = isset( $company['revenue'] ) ? (float) $company['revenue'] : 0.0;
		$rev_min = isset( $profile['revenue_min'] ) ? (float) $profile['revenue_min'] : 0.0;
		$rev_max = isset( $profile['revenue_max'] ) ? (float) $profile['revenue_max'] : 0.0;

		if ( $rev > 0 && ( $rev_min > 0 || $rev_max > 0 ) ) {
			if ( $rev_max <= 0 ) {
				$rev_max = PHP_FLOAT_MAX;
			}
			if ( $rev >= $rev_min && $rev <= $rev_max ) {
				$revenue_score = 20;
				$matches[]     = __( 'Revenue within target range.', 'mcp-ai-wpoos-pro' );
			} else {
				$midpoint      = ( $rev_min + $rev_max ) / 2;
				$distance      = $rev < $rev_min ? ( $rev_min - $rev ) : ( $rev - $rev_max );
				$proximity     = max( 0, 1 - ( $distance / max( $midpoint, 1 ) ) );
				$revenue_score = (int) round( 20 * $proximity );
				$misses[]      = __( 'Revenue outside target range.', 'mcp-ai-wpoos-pro' );
			}
		} else {
			$revenue_score = 10; // Neutral.
		}
		$score += $revenue_score;

		// --- Geography (max 15) ---
		$co_country = isset( $company['country'] ) ? sanitize_text_field( $company['country'] ) : '';
		$pr_country = isset( $profile['country'] ) ? sanitize_text_field( $profile['country'] ) : '';
		$co_region  = isset( $company['region'] ) ? sanitize_text_field( $company['region'] ) : '';

		if ( '' !== $co_country && '' !== $pr_country ) {
			$co_norm = mb_strtolower( trim( $co_country ) );
			$pr_norm = mb_strtolower( trim( $pr_country ) );

			// Support comma-separated country allowlist in profile.
			$pr_countries = array_map( 'trim', explode( ',', $pr_norm ) );

			if ( in_array( $co_norm, $pr_countries, true ) ) {
				$geo_score = 15;
				$matches[] = sprintf(
					/* translators: %s: country name */
					__( 'Geography match: %s', 'mcp-ai-wpoos-pro' ),
					$co_country
				);
			} else {
				// Same-region bonus.
				$pr_region = isset( $profile['region'] ) ? sanitize_text_field( $profile['region'] ) : '';
				if ( '' !== $pr_region && '' !== $co_region && mb_strtolower( $co_region ) === mb_strtolower( $pr_region ) ) {
					$geo_score = 8;
					$matches[] = __( 'Same region, different country.', 'mcp-ai-wpoos-pro' );
				} else {
					$geo_score = 0;
					$misses[]  = sprintf(
						/* translators: 1: company country, 2: target */
						__( 'Geography mismatch: %1$s not in %2$s', 'mcp-ai-wpoos-pro' ),
						$co_country,
						implode( ', ', $pr_countries )
					);
				}
			}
		} elseif ( '' === $co_country ) {
			$geo_score = 8; // Neutral.
		} else {
			$geo_score = 8;
		}
		$score += $geo_score;

		$score = self::clamp( $score, 0, $max_total );

		return array(
			'score'   => $score,
			'max'     => $max_total,
			'weight'  => 25,
			'matches' => $matches,
			'misses'  => $misses,
		);
	}

	// -------------------------------------------------------------------------
	// Dimension 2 – Technographic Fit (20 %)
	// -------------------------------------------------------------------------

	/**
	 * Score technographic alignment.
	 *
	 *  – Required-tool matches   (max 50)
	 *  – Preferred-tool bonus    (max 30)
	 *  – Competitor-tool penalty (up to -20)
	 *
	 * @since 2.11.0
	 *
	 * @param array $company Company data (tech_stack array or string).
	 * @param array $profile ICP profile.
	 * @return array Dimension score array.
	 */
	public static function score_technographic_fit( array $company, array $profile ) {
		$max_total = 100;
		$score     = 0;
		$matches   = array();
		$misses    = array();

		$co_tech = isset( $company['tech_stack'] ) ? self::normalise_tech_list( $company['tech_stack'] ) : array();
		$pr_req  = isset( $profile['required_tech'] ) ? self::normalise_tech_list( $profile['required_tech'] ) : array();
		$pr_pref = isset( $profile['preferred_tech'] ) ? self::normalise_tech_list( $profile['preferred_tech'] ) : array();
		$pr_comp = isset( $profile['competitor_tech'] ) ? self::normalise_tech_list( $profile['competitor_tech'] ) : array();

		// --- Required tools (max 50) ---
		if ( ! empty( $pr_req ) ) {
			$req_matches = 0;
			foreach ( $pr_req as $tool ) {
				if ( in_array( $tool, $co_tech, true ) ) {
					++$req_matches;
				} else {
					$misses[] = sprintf(
						/* translators: %s: tool name */
						__( 'Missing required tool: %s', 'mcp-ai-wpoos-pro' ),
						$tool
					);
				}
			}
			if ( $req_matches > 0 ) {
				$score    += min( 50, (int) round( 50 * ( $req_matches / count( $pr_req ) ) ) );
				$matches[] = sprintf(
					/* translators: 1: matched, 2: total */
					__( 'Required tools: %1$d/%2$d matched', 'mcp-ai-wpoos-pro' ),
					$req_matches,
					count( $pr_req )
				);
			}
		} else {
			$score += 25; // No required tools defined → neutral half-credit.
		}

		// --- Preferred tools (max 30) ---
		if ( ! empty( $pr_pref ) ) {
			$pref_matches = 0;
			foreach ( $pr_pref as $tool ) {
				if ( in_array( $tool, $co_tech, true ) ) {
					++$pref_matches;
				}
			}
			if ( $pref_matches > 0 ) {
				$addition  = min( 30, (int) round( 30 * ( $pref_matches / count( $pr_pref ) ) ) );
				$score    += $addition;
				$matches[] = sprintf(
					/* translators: 1: matched, 2: total */
					__( 'Preferred tools: %1$d/%2$d matched', 'mcp-ai-wpoos-pro' ),
					$pref_matches,
					count( $pr_pref )
				);
			}
		}

		// --- Competitor tools (penalty up to -20) ---
		if ( ! empty( $pr_comp ) ) {
			$comp_found = 0;
			foreach ( $pr_comp as $tool ) {
				if ( in_array( $tool, $co_tech, true ) ) {
					++$comp_found;
				}
			}
			if ( $comp_found > 0 ) {
				$penalty  = min( 20, $comp_found * 5 );
				$score    = max( 0, $score - $penalty );
				$misses[] = sprintf(
					/* translators: %d: count */
					_n(
						'Competitor tool detected: %d instance',
						'Competitor tools detected: %d instances',
						$comp_found,
						'mcp-ai-wpoos-pro'
					),
					$comp_found
				);
			}
		}

		$score = self::clamp( $score, 0, $max_total );

		return array(
			'score'   => $score,
			'max'     => $max_total,
			'weight'  => 20,
			'matches' => $matches,
			'misses'  => $misses,
		);
	}

	// -------------------------------------------------------------------------
	// Dimension 3 – Intent Signals (15 %)
	// -------------------------------------------------------------------------

	/**
	 * Score external intent signals (research activity, buying signals).
	 *
	 *  – Review-site activity     (G2, Gartner, TrustRadius) (max 35)
	 *  – Search / keyword signals                            (max 30)
	 *  – Job-postings indicating purchase intent             (max 20)
	 *  – Conference / event presence                         (max 15)
	 *
	 * @since 2.11.0
	 *
	 * @param array $signals Intent signal data (keyed by signal type).
	 * @param array $profile ICP profile (used for target-keyword matching).
	 * @return array Dimension score array.
	 */
	public static function score_intent_signals( array $signals, array $profile ) {
		$max_total = 100;
		$score     = 0;
		$matches   = array();
		$misses    = array();

		// --- Review-site activity (max 35) ---
		$review_sites = array( 'g2', 'gartner', 'trustradius', 'capterra', 'getapp', 'peer_insights' );
		$review_hits  = 0;
		foreach ( $review_sites as $site ) {
			$key = 'review_activity_' . $site;
			if ( ! empty( $signals[ $key ] ) ) {
				++$review_hits;
			}
		}
		if ( $review_hits > 0 ) {
			$score    += min( 35, $review_hits * 12 );
			$matches[] = sprintf(
				/* translators: %d: count */
				__( 'Active on %d review platform(s)', 'mcp-ai-wpoos-pro' ),
				$review_hits
			);
		}

		// --- Search / keyword signals (max 30) ---
		$target_kw = isset( $profile['target_keywords'] ) ? (array) $profile['target_keywords'] : array();
		$found_kw  = isset( $signals['matched_keywords'] ) ? (array) $signals['matched_keywords'] : array();

		if ( ! empty( $found_kw ) && ! empty( $target_kw ) ) {
			$kw_hits = count(
				array_intersect(
					array_map( 'mb_strtolower', $found_kw ),
					array_map( 'mb_strtolower', $target_kw )
				)
			);
			if ( $kw_hits > 0 ) {
				$score    += min( 30, $kw_hits * 6 );
				$matches[] = sprintf(
					/* translators: %d: count */
					__( '%d target keyword(s) detected', 'mcp-ai-wpoos-pro' ),
					$kw_hits
				);
			}
		}

		// Generic intent keywords if no profile keywords defined.
		$generic_searches = isset( $signals['generic_search_intent'] ) ? (int) $signals['generic_search_intent'] : 0;
		if ( $generic_searches > 0 && empty( $target_kw ) ) {
			$score    += min( 30, $generic_searches * 10 );
			$matches[] = __( 'Generic buying-intent searches detected.', 'mcp-ai-wpoos-pro' );
		}

		// --- Job postings (max 20) ---
		$job_titles        = isset( $signals['recent_job_postings'] ) ? (array) $signals['recent_job_postings'] : array();
		$buying_job_titles = array(
			'vp of sales',
			'head of sales',
			'sales director',
			'vp of marketing',
			'head of growth',
			'revenue operations',
			'revops',
			'sales operations',
			'crm administrator',
			'crm manager',
			'demand generation',
			'sdr manager',
			'account executive',
			'customer success manager',
		);
		$job_matches       = 0;
		foreach ( $job_titles as $title ) {
			$lower = mb_strtolower( trim( $title ) );
			foreach ( $buying_job_titles as $buying ) {
				if ( false !== strpos( $lower, $buying ) ) {
					++$job_matches;
					break;
				}
			}
		}
		if ( $job_matches > 0 ) {
			$score    += min( 20, $job_matches * 7 );
			$matches[] = sprintf(
				/* translators: %d: count */
				__( '%d buying-role job posting(s) found', 'mcp-ai-wpoos-pro' ),
				$job_matches
			);
		}

		// --- Conference / events (max 15) ---
		$events = isset( $signals['event_presence'] ) ? (array) $signals['event_presence'] : array();
		if ( ! empty( $events ) ) {
			$score    += min( 15, count( $events ) * 5 );
			$matches[] = sprintf(
				/* translators: %d: count */
				__( '%d relevant event(s) attended or sponsoring', 'mcp-ai-wpoos-pro' ),
				count( $events )
			);
		}

		if ( empty( $matches ) ) {
			$misses[] = __( 'No detectable intent signals.', 'mcp-ai-wpoos-pro' );
		}

		$score = self::clamp( $score, 0, $max_total );

		return array(
			'score'   => $score,
			'max'     => $max_total,
			'weight'  => 15,
			'matches' => $matches,
			'misses'  => $misses,
		);
	}

	// -------------------------------------------------------------------------
	// Dimension 4 – Engagement Activity (15 %) — with time decay
	// -------------------------------------------------------------------------

	/**
	 * Score engagement activity with time-based decay.
	 *
	 * Base points per activity type (before decay):
	 *   Demo request       30
	 *   Pricing page visit  12
	 *   Content download     8
	 *   Webinar attendance   8
	 *   Email click          5
	 *   Site visit           3
	 *   Email open           2
	 *
	 * Decay: 15 % reduction per 30 days of inactivity (configurable).
	 *
	 * @since 2.11.0
	 *
	 * @param array $activities List of activity records (each with type + timestamp).
	 * @param array $options    May contain 'now' to override reference time.
	 * @return array Dimension score array.
	 */
	public static function score_engagement_activity( array $activities, array $options = array() ) {
		$max_total = 100;
		$score     = 0;
		$matches   = array();
		$misses    = array();

		if ( empty( $activities ) ) {
			$misses[] = __( 'No engagement activity recorded.', 'mcp-ai-wpoos-pro' );
			return array(
				'score'   => 0,
				'max'     => $max_total,
				'weight'  => 15,
				'matches' => $matches,
				'misses'  => $misses,
			);
		}

		// Base scores per activity type.
		$base_scores = array(
			'demo_request'     => 30,
			'pricing_visit'    => 12,
			'content_download' => 8,
			'webinar'          => 8,
			'email_click'      => 5,
			'site_visit'       => 3,
			'email_open'       => 2,
			'form_submission'  => 10,
			'chat_interaction' => 6,
			'trial_signup'     => 25,
		);

		$now_ts  = isset( $options['now'] ) ? strtotime( $options['now'] ) : time();
		$raw_sum = 0;
		$counts  = array();

		foreach ( $activities as $activity ) {
			if ( ! is_array( $activity ) || empty( $activity['type'] ) ) {
				continue;
			}

			$type     = sanitize_key( $activity['type'] );
			$base     = isset( $base_scores[ $type ] ) ? $base_scores[ $type ] : 3; // Generic fallback.
			$ts       = isset( $activity['timestamp'] ) ? (string) $activity['timestamp'] : '';
			$decayed  = self::compute_decay( $base, $ts, $now_ts );
			$raw_sum += $decayed;

			if ( ! isset( $counts[ $type ] ) ) {
				$counts[ $type ] = 0;
			}
			++$counts[ $type ];
		}

		// Normalise: sum of decayed scores, capped at 100.
		// A company with a demo request + a few others should be near 60–80.
		$score = self::clamp( (int) round( min( $raw_sum, 100 ) ), 0, $max_total );

		foreach ( $counts as $type => $count ) {
			$label     = isset( $base_scores[ $type ] )
				? $type
				: __( 'other', 'mcp-ai-wpoos-pro' );
			$matches[] = sprintf(
				/* translators: 1: count, 2: activity type */
				__( '%1$d × %2$s', 'mcp-ai-wpoos-pro' ),
				$count,
				$label
			);
		}

		return array(
			'score'   => $score,
			'max'     => $max_total,
			'weight'  => 15,
			'matches' => $matches,
			'misses'  => $misses,
		);
	}

	// -------------------------------------------------------------------------
	// Dimension 5 – Buying Triggers (10 %)
	// -------------------------------------------------------------------------

	/**
	 * Score buying triggers and organisational events.
	 *
	 *   Recent funding round      10
	 *   New leadership / C-suite   8
	 *   Rapid hiring growth        5
	 *   Compliance / regulatory    7
	 *   M&A activity               6
	 *   Product launch             4
	 *   Office expansion           3
	 *
	 * @since 2.11.0
	 *
	 * @param array $triggers Trigger records.
	 * @param array $profile  ICP profile.
	 * @return array Dimension score array.
	 */
	public static function score_buying_triggers( array $triggers, array $profile ) {
		$max_total = 100;
		$score     = 0;
		$matches   = array();
		$misses    = array();

		if ( empty( $triggers ) ) {
			$misses[] = __( 'No buying triggers detected.', 'mcp-ai-wpoos-pro' );
			return array(
				'score'   => 0,
				'max'     => $max_total,
				'weight'  => 10,
				'matches' => $matches,
				'misses'  => $misses,
			);
		}

		// Base scores per trigger type.
		$base_scores = array(
			'funding_round'      => 10,
			'new_leadership'     => 8,
			'rapid_hiring'       => 5,
			'compliance_mandate' => 7,
			'ma_activity'        => 6,
			'product_launch'     => 4,
			'office_expansion'   => 3,
			'partnership'        => 5,
			'rebrand'            => 2,
		);

		foreach ( $triggers as $trigger ) {
			if ( ! is_array( $trigger ) || empty( $trigger['type'] ) ) {
				continue;
			}

			$type  = sanitize_key( $trigger['type'] );
			$base  = isset( $base_scores[ $type ] ) ? $base_scores[ $type ] : 2;
			$label = isset( $trigger['label'] ) ? sanitize_text_field( $trigger['label'] ) : $type;

			// Recency bonus: triggers within last 90 days get full credit,
			// 90–180 days get half, older get quarter.
			$recency_mult = 1.0;
			if ( isset( $trigger['date'] ) ) {
				$ts  = strtotime( $trigger['date'] );
				$age = $ts ? ( time() - $ts ) / DAY_IN_SECONDS : 0;
				if ( $age > 180 ) {
					$recency_mult = 0.25;
				} elseif ( $age > 90 ) {
					$recency_mult = 0.5;
				}
			}

			$score    += (int) round( $base * $recency_mult );
			$matches[] = $label;
		}

		$score = self::clamp( $score, 0, $max_total );

		return array(
			'score'   => $score,
			'max'     => $max_total,
			'weight'  => 10,
			'matches' => $matches,
			'misses'  => $misses,
		);
	}

	// -------------------------------------------------------------------------
	// Dimension 6 – Economic Outcome (10 %)
	// -------------------------------------------------------------------------

	/**
	 * Score projected economic value (ACV / LTV) based on company attributes.
	 *
	 * Factors:
	 *  – Company-size tier  (max 40)
	 *  – Industry multiplier (max 30)
	 *  – Funding stage       (max 20)
	 *  – Role seniority      (max 10) — contact-level bonus.
	 *
	 * @since 2.11.0
	 *
	 * @param array $company Company data.
	 * @param array $profile ICP profile.
	 * @return array Dimension score array.
	 */
	public static function score_economic_outcome( array $company, array $profile ) {
		$max_total = 100;
		$score     = 0;
		$matches   = array();
		$misses    = array();

		// --- Company-size tier (max 40) ---
		$emp = isset( $company['employees'] ) ? (int) $company['employees'] : 0;

		if ( $emp > 1000 ) {
			$size_score = 40;
			$matches[]  = __( 'Enterprise size — high ACV potential', 'mcp-ai-wpoos-pro' );
		} elseif ( $emp >= 250 ) {
			$size_score = 30;
			$matches[]  = __( 'Mid-market — strong ACV potential', 'mcp-ai-wpoos-pro' );
		} elseif ( $emp >= 50 ) {
			$size_score = 20;
			$matches[]  = __( 'SMB — moderate ACV', 'mcp-ai-wpoos-pro' );
		} elseif ( $emp >= 10 ) {
			$size_score = 10;
			$matches[]  = __( 'Small business — limited ACV', 'mcp-ai-wpoos-pro' );
		} else {
			$size_score = 5;
			$matches[]  = __( 'Micro business or unknown size.', 'mcp-ai-wpoos-pro' );
		}
		$score += $size_score;

		// --- Industry multiplier (max 30) ---
		$industry = isset( $company['industry'] ) ? sanitize_text_field( $company['industry'] ) : '';
		$canon    = '' !== $industry ? self::canonical_industry( $industry ) : '';

		// Industry value tiers — higher = more willingness to pay for software.
		$industry_multipliers = array(
			'fintech'           => 30,
			'healthtech'        => 28,
			'cybersecurity'     => 30,
			'saas'              => 25,
			'legaltech'         => 25,
			'telecom'           => 22,
			'energy'            => 22,
			'manufacturing'     => 20,
			'logistics'         => 18,
			'proptech'          => 18,
			'marketing_tech'    => 15,
			'edtech'            => 12,
			'ecommerce'         => 15,
			'hrt'               => 15,
			'professional_svcs' => 20,
			'government'        => 25,
			'automotive'        => 18,
			'media'             => 15,
			'agritech'          => 12,
			'nonprofit'         => 5,
		);

		$industry_score = isset( $industry_multipliers[ $canon ] )
			? $industry_multipliers[ $canon ]
			: 15; // Unknown → neutral.
		$score         += $industry_score;

		// --- Funding stage (max 20) ---
		$stage       = isset( $company['funding_stage'] ) ? sanitize_key( $company['funding_stage'] ) : '';
		$stage_score = 0;
		switch ( $stage ) {
			case 'series_c_plus':
			case 'public':
			case 'private_equity':
				$stage_score = 20;
				break;
			case 'series_b':
				$stage_score = 16;
				break;
			case 'series_a':
				$stage_score = 12;
				break;
			case 'seed':
				$stage_score = 8;
				break;
			case 'bootstrapped':
				$stage_score = 10;
				break;
			case 'pre_seed':
				$stage_score = 5;
				break;
			default:
				$stage_score = 10; // Neutral.
				break;
		}
		if ( $stage_score >= 16 ) {
			$matches[] = sprintf(
				/* translators: %s: funding stage */
				__( 'Funding stage %s — high budget potential', 'mcp-ai-wpoos-pro' ),
				$stage
			);
		}
		$score += $stage_score;

		// --- Role seniority (max 10) – contact-level ---
		$roles           = isset( $company['contact_roles'] ) ? (array) $company['contact_roles'] : array();
		$senior_keywords = array( 'ceo', 'cto', 'cfo', 'coo', 'vp', 'director', 'head of', 'chief', 'president', 'svp', 'evp' );
		$senior_found    = false;
		foreach ( $roles as $role ) {
			$lower = mb_strtolower( trim( $role ) );
			foreach ( $senior_keywords as $kw ) {
				if ( false !== strpos( $lower, $kw ) ) {
					$senior_found = true;
					break 2;
				}
			}
		}
		if ( $senior_found ) {
			$score    += 10;
			$matches[] = __( 'Senior-level contact — faster deal velocity.', 'mcp-ai-wpoos-pro' );
		}

		$score = self::clamp( $score, 0, $max_total );

		return array(
			'score'   => $score,
			'max'     => $max_total,
			'weight'  => 10,
			'matches' => $matches,
			'misses'  => $misses,
		);
	}

	// -------------------------------------------------------------------------
	// Dimension 7 – Negative Signals (5 %, subtractive)
	// -------------------------------------------------------------------------

	/**
	 * Score negative signals (disqualifiers and red flags).
	 *
	 * Penalties:
	 *   Wrong / excluded industry    -15
	 *   Free / personal email domain -10
	 *   Known competitor relationship -20
	 *   Company size too small       -10
	 *   Company size too large        -5
	 *   Domain age < 1 year          -8
	 *   No online presence            -5
	 *   High churn-risk industry      -5
	 *
	 * Returns 100 for a clean profile (no penalties), lower for each hit.
	 *
	 * @since 2.11.0
	 *
	 * @param array $company Company data.
	 * @param array $profile ICP profile.
	 * @return array Dimension score array.
	 */
	public static function score_negative_signals( array $company, array $profile ) {
		$max_total = 100;
		$score     = 100; // Start clean.
		$matches   = array();
		$misses    = array();

		// --- Excluded industries ---
		$excluded = isset( $profile['excluded_industries'] ) ? (array) $profile['excluded_industries'] : array();
		$industry = isset( $company['industry'] ) ? sanitize_text_field( $company['industry'] ) : '';

		if ( ! empty( $excluded ) && '' !== $industry ) {
			$canon = self::canonical_industry( $industry );
			foreach ( $excluded as $ex ) {
				if ( self::canonical_industry( $ex ) === $canon ) {
					$score   -= 15;
					$misses[] = sprintf(
						/* translators: %s: industry name */
						__( 'Excluded industry: %s', 'mcp-ai-wpoos-pro' ),
						$industry
					);
					break;
				}
			}
		}

		// --- Free / personal email domain ---
		$email = isset( $company['email'] ) ? sanitize_email( $company['email'] ) : '';
		if ( '' !== $email ) {
			$free_domains = array(
				'gmail.com',
				'yahoo.com',
				'hotmail.com',
				'outlook.com',
				'aol.com',
				'protonmail.com',
				'mail.com',
				'yandex.com',
				'icloud.com',
				'me.com',
				'live.com',
				'msn.com',
				'inbox.com',
				'gmx.com',
				'zoho.com',
			);
			$domain       = mb_strtolower( trim( substr( strrchr( $email, '@' ), 1 ) ) );
			if ( in_array( $domain, $free_domains, true ) ) {
				$score   -= 10;
				$misses[] = sprintf(
					/* translators: %s: domain */
					__( 'Free email domain: %s', 'mcp-ai-wpoos-pro' ),
					$domain
				);
			}
		}

		// --- Known competitor relationship ---
		$is_competitor = ! empty( $company['is_competitor'] );
		$competitor_of = isset( $company['competitor_of'] ) ? sanitize_text_field( $company['competitor_of'] ) : '';
		if ( $is_competitor || '' !== $competitor_of ) {
			$score   -= 20;
			$misses[] = __( 'Known competitor relationship.', 'mcp-ai-wpoos-pro' );
		}

		// --- Company size too small ---
		$emp     = isset( $company['employees'] ) ? (int) $company['employees'] : 0;
		$emp_min = isset( $profile['employees_min'] ) ? (int) $profile['employees_min'] : 0;

		if ( $emp > 0 && $emp_min > 0 && $emp < $emp_min ) {
			$ratio = $emp / max( $emp_min, 1 );
			if ( $ratio < 0.5 ) {
				$score   -= 10;
				$misses[] = sprintf(
					/* translators: 1: actual, 2: minimum */
					__( 'Company too small: %1$d employees (min %2$d)', 'mcp-ai-wpoos-pro' ),
					$emp,
					$emp_min
				);
			}
		}

		// --- Company size too large ---
		$emp_max = isset( $profile['employees_max'] ) ? (int) $profile['employees_max'] : 0;

		if ( $emp > 0 && $emp_max > 0 && $emp > $emp_max ) {
			$ratio = $emp / max( $emp_max, 1 );
			if ( $ratio > 2.0 ) {
				$score   -= 5;
				$misses[] = sprintf(
					/* translators: 1: actual, 2: maximum */
					__( 'Company too large: %1$d employees (max %2$d)', 'mcp-ai-wpoos-pro' ),
					$emp,
					$emp_max
				);
			}
		}

		// --- Domain age < 1 year ---
		$domain_age_days = isset( $company['domain_age_days'] ) ? (int) $company['domain_age_days'] : -1;
		if ( $domain_age_days >= 0 && $domain_age_days < 365 ) {
			$score   -= 8;
			$misses[] = sprintf(
				/* translators: %d: days */
				__( 'Domain age only %d days — potential risk.', 'mcp-ai-wpoos-pro' ),
				$domain_age_days
			);
		}

		// --- No online presence ---
		$has_website  = ! empty( $company['website'] );
		$has_linkedin = ! empty( $company['linkedin_url'] );
		if ( ! $has_website && ! $has_linkedin ) {
			$score   -= 5;
			$misses[] = __( 'No online presence detected.', 'mcp-ai-wpoos-pro' );
		}

		// --- High churn-risk industry ---
		$high_churn = array( 'ecommerce', 'media' );
		if ( in_array( self::canonical_industry( $industry ), $high_churn, true ) ) {
			$score   -= 5;
			$misses[] = sprintf(
				/* translators: %s: industry name */
				__( 'Industry %s has historically high churn.', 'mcp-ai-wpoos-pro' ),
				$industry
			);
		}

		if ( $score >= 100 ) {
			$matches[] = __( 'No negative signals detected.', 'mcp-ai-wpoos-pro' );
		}

		$score = self::clamp( $score, 0, $max_total );

		return array(
			'score'   => $score,
			'max'     => $max_total,
			'weight'  => 5,
			'matches' => $matches,
			'misses'  => $misses,
		);
	}

	// -------------------------------------------------------------------------
	// Decay, Tier, Recommendation, Labels
	// -------------------------------------------------------------------------

	/**
	 * Apply behavioural-signal decay based on time elapsed.
	 *
	 * Implements exponential-like decay: score halves every decay period.
	 *
	 * @since 2.11.0
	 *
	 * @param int    $score     Raw (un-decayed) score.
	 * @param string $timestamp ISO-8601 or strtotime()-compatible timestamp.
	 * @param int    $now_ts    Optional reference timestamp (default now).
	 * @return int Decayed score, clamped 0 → original.
	 */
	public static function compute_decay( $score, $timestamp, $now_ts = null ) {
		$score = (int) $score;
		if ( $score <= 0 || '' === $timestamp ) {
			return $score;
		}

		if ( null === $now_ts ) {
			$now_ts = time();
		}

		$activity_ts = strtotime( $timestamp );
		if ( false === $activity_ts || $activity_ts > $now_ts ) {
			return $score;
		}

		$days_since = (int) ( ( $now_ts - $activity_ts ) / DAY_IN_SECONDS );
		$periods    = (int) floor( $days_since / self::DECAY_PERIOD_DAYS );

		if ( $periods <= 0 ) {
			return $score;
		}

		// Exponential-like: score * (1 - decay_rate) ^ periods ≈ score * e^(-λ·t).
		$decayed = $score * pow( 1 - self::DECAY_RATE, $periods );

		return max( 0, (int) round( $decayed ) );
	}

	/**
	 * Determine tier (A / B / C) from a total score.
	 *
	 * Default thresholds: A ≥ 70, B ≥ 40, C < 40.
	 *
	 * @since 2.11.0
	 *
	 * @param int   $total_score 0–100 composite score.
	 * @param array $thresholds  Optional custom thresholds keyed by tier.
	 * @return string 'A', 'B', or 'C'.
	 */
	public static function determine_tier( $total_score, array $thresholds = array() ) {
		if ( empty( $thresholds ) ) {
			$thresholds = array(
				'A' => 70,
				'B' => 40,
			);
		}

		$total_score = (int) $total_score;

		if ( $total_score >= (int) ( isset( $thresholds['A'] ) ? $thresholds['A'] : 70 ) ) {
			return 'A';
		}
		if ( $total_score >= (int) ( isset( $thresholds['B'] ) ? $thresholds['B'] : 40 ) ) {
			return 'B';
		}
		return 'C';
	}

	/**
	 * Generate human-readable recommendation based on scores.
	 *
	 * @since 2.11.0
	 *
	 * @param array $result compute_score result array.
	 * @return string Recommendation text.
	 */
	public static function generate_recommendation( array $result ) {
		$total  = isset( $result['total_score'] ) ? (int) $result['total_score'] : 0;
		$tier   = isset( $result['tier'] ) ? $result['tier'] : 'C';
		$fit    = isset( $result['fit_score'] ) ? (int) $result['fit_score'] : 0;
		$intent = isset( $result['intent_score'] ) ? (int) $result['intent_score'] : 0;

		switch ( $tier ) {
			case 'A':
				if ( $intent >= 70 ) {
					return __( 'High fit with strong buying intent. Prioritise for immediate outreach — this account is ready to engage.', 'mcp-ai-wpoos-pro' );
				}
				if ( $fit >= 80 ) {
					return __( 'Excellent ICP fit. Recommend direct sales outreach with personalised value proposition. Add to high-priority nurture if not ready.', 'mcp-ai-wpoos-pro' );
				}
				return __( 'Strong overall match. Route to sales for qualification call. Monitor for buying triggers to time outreach.', 'mcp-ai-wpoos-pro' );

			case 'B':
				if ( $fit >= 70 && $intent < 40 ) {
					return __( 'Good fit but low intent. Add to nurture campaign and retarget with relevant content. Re-score after 30 days.', 'mcp-ai-wpoos-pro' );
				}
				if ( $intent >= 60 ) {
					return __( 'Moderate fit with active signals. Qualify further — may be a department-level opportunity rather than enterprise.', 'mcp-ai-wpoos-pro' );
				}
				return __( 'Moderate match. Place in automated nurture sequence. Consider if specific use cases align before direct outreach.', 'mcp-ai-wpoos-pro' );

			case 'C':
			default:
				if ( $fit >= 50 ) {
					return __( 'Below threshold but reasonable fit. Keep in database for broad marketing campaigns. Not recommended for direct sales investment.', 'mcp-ai-wpoos-pro' );
				}
				return __( 'Low ICP fit. Not recommended for active pursuit. Review ICP profile definition if many high-value prospects score in this tier.', 'mcp-ai-wpoos-pro' );
		}
	}

	/**
	 * Return a human-readable score label.
	 *
	 * Matching WP_MCP_AI_CRM_Engine::score_label convention:
	 *   70–100 = Hot, 40–69 = Warm, 0–39 = Cold.
	 *
	 * @since 2.11.0
	 *
	 * @param int $score 0–100 score.
	 * @return string 'Hot', 'Warm', or 'Cold'.
	 */
	public static function get_score_label( $score ) {
		$score = (int) $score;
		if ( $score >= 70 ) {
			return __( 'Hot', 'mcp-ai-wpoos-pro' );
		}
		if ( $score >= 40 ) {
			return __( 'Warm', 'mcp-ai-wpoos-pro' );
		}
		return __( 'Cold', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Return a human-readable tier description for use in UIs.
	 *
	 * @since 2.11.0
	 *
	 * @param string $tier 'A', 'B', or 'C'.
	 * @return string Description.
	 */
	public static function get_tier_description( $tier ) {
		switch ( strtoupper( $tier ) ) {
			case 'A':
				return __( 'Tier A — Best-fit accounts. Highest conversion probability. Prioritise for direct sales and account-based marketing.', 'mcp-ai-wpoos-pro' );
			case 'B':
				return __( 'Tier B — Good-fit accounts. Moderate conversion probability. Nurture with targeted content and re-qualify periodically.', 'mcp-ai-wpoos-pro' );
			case 'C':
			default:
				return __( 'Tier C — Low-fit accounts. Low conversion probability. Broad marketing only; revisit if ICP definition evolves.', 'mcp-ai-wpoos-pro' );
		}
	}

	// -------------------------------------------------------------------------
	// Internal Helpers
	// -------------------------------------------------------------------------

	/**
	 * Normalise a company's industry string to its canonical slug.
	 *
	 * @since 2.11.0
	 *
	 * @param string $industry Raw industry string.
	 * @return string Canonical slug or empty string.
	 */
	private static function canonical_industry( $industry ) {
		$industry = mb_strtolower( trim( $industry ) );
		if ( '' === $industry ) {
			return '';
		}

		// Direct slug match first.
		if ( isset( self::$industry_aliases[ $industry ] ) ) {
			return $industry;
		}

		foreach ( self::$industry_aliases as $canon => $aliases ) {
			foreach ( $aliases as $alias ) {
				if ( $industry === $alias || false !== strpos( $industry, $alias ) || false !== strpos( $alias, $industry ) ) {
					return $canon;
				}
			}
		}

		// Fallback: return the slugified input so it's at least comparable.
		return sanitize_title( $industry );
	}

	/**
	 * Return an adjacency score (0–1) for how related two industries are.
	 *
	 * @since 2.11.0
	 *
	 * @param string $canon_a First canonical industry slug.
	 * @param string $canon_b Second canonical industry slug.
	 * @return float 0.0 (unrelated) to 1.0 (identical).
	 */
	private static function industry_adjacency_score( $canon_a, $canon_b ) {
		if ( $canon_a === $canon_b ) {
			return 1.0;
		}

		// Adjacency map: canonical → array of closely-related industries.
		$adjacent = array(
			'saas'              => array( 'marketing_tech', 'fintech', 'hrt', 'cybersecurity' ),
			'fintech'           => array( 'saas', 'insurance', 'banking', 'ecommerce' ),
			'healthtech'        => array( 'saas', 'cybersecurity' ),
			'ecommerce'         => array( 'marketing_tech', 'fintech', 'logistics' ),
			'marketing_tech'    => array( 'saas', 'ecommerce', 'media' ),
			'cybersecurity'     => array( 'saas', 'fintech', 'healthtech', 'telecom' ),
			'hrt'               => array( 'saas', 'edtech' ),
			'edtech'            => array( 'saas', 'hrt' ),
			'legaltech'         => array( 'saas', 'fintech', 'professional_svcs' ),
			'logistics'         => array( 'ecommerce', 'manufacturing' ),
			'manufacturing'     => array( 'logistics', 'automotive' ),
			'proptech'          => array( 'fintech', 'saas' ),
			'agritech'          => array( 'energy', 'logistics' ),
			'automotive'        => array( 'manufacturing', 'logistics', 'energy' ),
			'energy'            => array( 'agritech', 'automotive' ),
			'government'        => array( 'cybersecurity', 'professional_svcs' ),
			'telecom'           => array( 'cybersecurity', 'saas' ),
			'media'             => array( 'marketing_tech', 'saas' ),
			'professional_svcs' => array( 'legaltech', 'saas', 'fintech' ),
		);

		if ( isset( $adjacent[ $canon_a ] ) && in_array( $canon_b, $adjacent[ $canon_a ], true ) ) {
			return 0.6;
		}
		if ( isset( $adjacent[ $canon_b ] ) && in_array( $canon_a, $adjacent[ $canon_b ], true ) ) {
			return 0.6;
		}

		return 0.0;
	}

	/**
	 * Normalise a tech stack input (array or comma-separated string) to a
	 * canonical lowercase, trimmed array.
	 *
	 * @since 2.11.0
	 *
	 * @param string|array $tech Raw tech stack.
	 * @return string[]
	 */
	private static function normalise_tech_list( $tech ) {
		if ( is_string( $tech ) ) {
			$tech = explode( ',', $tech );
		}
		if ( ! is_array( $tech ) ) {
			return array();
		}

		return array_values(
			array_unique(
				array_filter(
					array_map(
						function ( $t ) {
							$t = mb_strtolower( trim( (string) $t ) );
							return '' === $t ? null : $t;
						},
						$tech
					)
				)
			)
		);
	}

	/**
	 * Build a stable cache key from inputs.
	 *
	 * @since 2.11.0
	 *
	 * @param array $company_data Company data.
	 * @param array $icp_profile  ICP profile.
	 * @param array $weights      Resolved weights.
	 * @param bool  $skip_cache   Whether cache is skipped (included to prevent collisions).
	 * @return string Cache key.
	 */
	private static function build_cache_key( array $company_data, array $icp_profile, array $weights, $skip_cache ) {
		$fingerprint = md5(
			wp_json_encode(
				array(
					'c' => $company_data,
					'p' => $icp_profile,
					'w' => $weights,
				)
			)
		);

		return 'icp_score|' . $fingerprint;
	}

	/**
	 * Get resolved dimension weights from an ICP profile, falling back to
	 * industry-standard defaults.
	 *
	 * @since 2.11.0
	 *
	 * @param array $profile ICP profile.
	 * @return array<string,float> Slug → weight (0–1).
	 */
	private static function get_profile_weights( array $profile ) {
		$defaults = array(
			'firmographic_fit'    => 0.25,
			'technographic_fit'   => 0.20,
			'intent_signals'      => 0.15,
			'engagement_activity' => 0.15,
			'buying_triggers'     => 0.10,
			'economic_outcome'    => 0.10,
			'negative_signals'    => 0.05,
		);

		if ( empty( $profile['weights'] ) || ! is_array( $profile['weights'] ) ) {
			return $defaults;
		}

		return self::normalise_weights(
			wp_parse_args( $profile['weights'], $defaults )
		);
	}

	/**
	 * Normalise weights so they sum to 1.0.
	 *
	 * @since 2.11.0
	 *
	 * @param array $weights Raw weights.
	 * @return array<string,float> Normalised weights.
	 */
	private static function normalise_weights( array $weights ) {
		$sum = array_sum( $weights );
		if ( $sum <= 0 ) {
			return $weights;
		}

		foreach ( $weights as $k => $v ) {
			$weights[ $k ] = round( (float) $v / $sum, 4 );
		}

		return $weights;
	}

	/**
	 * Retrieve all saved ICP profiles.
	 *
	 * Stored as a serialised array in wp_options under `wp_mcp_ai_icp_profiles`.
	 *
	 * @since 2.11.0
	 *
	 * @return array<string,array> Slug → profile.
	 */
	private static function get_all_icp_profiles() {
		$profiles = get_option( 'wp_mcp_ai_icp_profiles', array() );
		if ( ! is_array( $profiles ) ) {
			return array();
		}
		return $profiles;
	}

	/**
	 * Clamp a value between min and max.
	 *
	 * @since 2.11.0
	 *
	 * @param int $value Input value.
	 * @param int $min   Minimum bound.
	 * @param int $max   Maximum bound.
	 * @return int Clamped value.
	 */
	private static function clamp( $value, $min, $max ) {
		return max( $min, min( $max, (int) $value ) );
	}
}
