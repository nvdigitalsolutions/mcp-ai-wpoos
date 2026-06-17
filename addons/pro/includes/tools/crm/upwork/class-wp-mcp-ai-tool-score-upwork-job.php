<?php
/**
 * Tool for algorithmically scoring an Upwork job posting.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage CRM_Toolkit
 * @since 1.0.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Scores an Upwork job posting against a freelancer's profile and preferences.
 *
 * Scoring dimensions (each 0-100, then weighted):
 *  - Budget score:     how the job budget compares to the freelancer's minimum.
 *  - Skill match:      overlap between job required skills and freelancer's skills.
 *  - Client quality:   payment verification, total spent, and feedback rating.
 *  - Competition:      inverse of total applicants (fewer = better).
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Tool_Score_Upwork_Job implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Determine whether CRM toolkit is enabled.
	 *
	 * @since 2.3.0
	 * @return bool
	 */
	public static function is_available() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_crm_toolkit'] ) && class_exists( 'WP_MCP_AI_Upwork_Client' );
	}

	/**
	 * Message explaining why the tool is unavailable.
	 *
	 * @since 2.3.0
	 * @return string
	 */
	public static function get_unavailable_reason() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_crm_toolkit'] ) ) {
			return __( 'The Score Upwork Job tool requires the CRM Toolkit to be enabled in plugin settings.', 'mcp-ai-wpoos-pro' );
		}
		return __( 'The Score Upwork Job tool requires the Upwork client integration to be configured.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * GraphQL query used to fetch a single job by ID.
	 *
	 * @var string
	 */
	const JOB_QUERY = '
		query GetUpworkJob($marketPlaceJobFilter: MarketplaceJobPostingsSearchFilter, $paging: Paging) {
			marketplaceJobPostingsSearch(
				marketPlaceJobFilter: $marketPlaceJobFilter,
				paging: $paging
			) {
				edges {
					node {
						id
						title
						description
						jobType
						engagement
						duration
						contractorTier
						budget { amount currency }
						hourlyBudget { min max currency }
						skills { prettyName }
						client {
							totalFeedback
							totalHires
							totalJobsPosted
							totalSpent { amount currency }
							paymentVerificationStatus
							location { country }
						}
						category { name }
						subcategory { name }
						totalApplicants
						tierText
					}
				}
			}
		}
	';

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'score_upwork_job';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Score Upwork Job', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Algorithmically scores an Upwork job posting (0-100) based on budget fit, skill match, client quality, and competition level. Returns a score breakdown and an apply/skip/maybe recommendation. When no Upwork connection is configured, accepts a job description and title as text for offline scoring.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'connection_id'       => array(
					'type'        => 'string',
					'description' => __( 'Remote Sites Upwork connection ID. Optional — when omitted, falls back to text-based scoring.', 'mcp-ai-wpoos-pro' ),
				),
				'job_id'              => array(
					'type'        => 'string',
					'description' => __( 'Upwork job posting ID to score (required when using the API).', 'mcp-ai-wpoos-pro' ),
				),
				'job_title'           => array(
					'type'        => 'string',
					'description' => __( 'Job title text (used for fallback scoring when no connection is configured).', 'mcp-ai-wpoos-pro' ),
				),
				'job_description'     => array(
					'type'        => 'string',
					'description' => __( 'Full job description text (used for fallback scoring when no connection is configured).', 'mcp-ai-wpoos-pro' ),
				),
				'job_skills_list'     => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => __( 'Skills required by the job (used for fallback scoring).', 'mcp-ai-wpoos-pro' ),
				),
				'job_budget_amount'   => array(
					'type'        => 'number',
					'description' => __( 'Job budget or hourly rate amount (used for fallback scoring).', 'mcp-ai-wpoos-pro' ),
				),
				'freelancer_skills'   => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => __( 'Your skill set for matching against job requirements.', 'mcp-ai-wpoos-pro' ),
				),
				'freelancer_profile'  => array(
					'type'        => 'string',
					'description' => __( 'Your profile description or bio.', 'mcp-ai-wpoos-pro' ),
				),
				'min_budget'          => array(
					'type'        => 'number',
					'description' => __( 'Minimum acceptable budget or hourly rate.', 'mcp-ai-wpoos-pro' ),
				),
				'preferred_job_types' => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => __( 'Preferred job types, e.g. ["hourly", "fixed"].', 'mcp-ai-wpoos-pro' ),
				),
				'scoring_criteria'    => array(
					'type'        => 'object',
					'description' => __( 'Weights for scoring dimensions (each 0-1, should sum to 1).', 'mcp-ai-wpoos-pro' ),
					'properties'  => array(
						'budget_weight'         => array(
							'type'    => 'number',
							'default' => 0.3,
						),
						'skill_match_weight'    => array(
							'type'    => 'number',
							'default' => 0.3,
						),
						'client_quality_weight' => array(
							'type'    => 'number',
							'default' => 0.2,
						),
						'competition_weight'    => array(
							'type'    => 'number',
							'default' => 0.2,
						),
					),
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
	public function requires_base_pro() {
		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'read-only',
			'requires-capability',
			'external-api',
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or WP_Error on failure.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id || ! user_can( $user_id, $this->get_required_capability() ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to score Upwork jobs.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Determine whether the Upwork API is available.
		$use_api = $this->has_valid_connection( $arguments );

		// Fall back to text-based scoring when the Upwork connection is not configured.
		if ( ! $use_api ) {
			return $this->execute_fallback( $arguments );
		}

		if ( empty( $arguments['job_id'] ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_job_id', __( 'job_id is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$connection_id = sanitize_text_field( $arguments['connection_id'] );
		$job_id        = sanitize_text_field( $arguments['job_id'] );

		// Fetch job details.
		require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-upwork-client.php';
		$client = new WP_MCP_AI_Upwork_Client( $connection_id );

		$variables = array(
			'marketPlaceJobFilter' => array(
				'jobIds' => array( $job_id ),
			),
			'paging'               => array( 'first' => 1 ),
		);

		$result = $client->graphql( self::JOB_QUERY, $variables );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$edges = isset( $result['data']['marketplaceJobPostingsSearch']['edges'] )
			? $result['data']['marketplaceJobPostingsSearch']['edges']
			: array();

		if ( empty( $edges ) ) {
			return new WP_Error(
				'wp_mcp_ai_job_not_found',
				sprintf(
					/* translators: %s: job ID */
					__( 'Job ID "%s" was not found on Upwork.', 'mcp-ai-wpoos-pro' ),
					$job_id
				)
			);
		}

		$job = isset( $edges[0]['node'] ) ? $edges[0]['node'] : array();

		// Extract scoring inputs.
		$freelancer_skills   = isset( $arguments['freelancer_skills'] ) && is_array( $arguments['freelancer_skills'] )
			? array_map( 'strtolower', array_map( 'sanitize_text_field', $arguments['freelancer_skills'] ) )
			: array();
		$min_budget          = isset( $arguments['min_budget'] ) ? (float) $arguments['min_budget'] : 0;
		$preferred_job_types = isset( $arguments['preferred_job_types'] ) && is_array( $arguments['preferred_job_types'] )
			? array_map( 'strtolower', $arguments['preferred_job_types'] )
			: array();

		$criteria = isset( $arguments['scoring_criteria'] ) && is_array( $arguments['scoring_criteria'] )
			? $arguments['scoring_criteria']
			: array();

		$w_budget      = isset( $criteria['budget_weight'] ) ? (float) $criteria['budget_weight'] : 0.3;
		$w_skill       = isset( $criteria['skill_match_weight'] ) ? (float) $criteria['skill_match_weight'] : 0.3;
		$w_client      = isset( $criteria['client_quality_weight'] ) ? (float) $criteria['client_quality_weight'] : 0.2;
		$w_competition = isset( $criteria['competition_weight'] ) ? (float) $criteria['competition_weight'] : 0.2;

		// Clamp weights to [0, 1].
		$w_budget      = max( 0.0, min( 1.0, $w_budget ) );
		$w_skill       = max( 0.0, min( 1.0, $w_skill ) );
		$w_client      = max( 0.0, min( 1.0, $w_client ) );
		$w_competition = max( 0.0, min( 1.0, $w_competition ) );

		// ---------- Budget score ----------
		$budget_score  = 0;
		$budget_detail = __( 'Budget not specified', 'mcp-ai-wpoos-pro' );
		$job_budget    = 0;

		if ( ! empty( $job['budget']['amount'] ) ) {
			$job_budget    = (float) $job['budget']['amount'];
			$budget_detail = sprintf(
				/* translators: 1: amount, 2: currency */
				__( 'Fixed budget: %1$s %2$s', 'mcp-ai-wpoos-pro' ),
				number_format( $job_budget, 2 ),
				isset( $job['budget']['currency'] ) ? $job['budget']['currency'] : 'USD'
			);
		} elseif ( ! empty( $job['hourlyBudget']['max'] ) ) {
			$job_budget    = (float) $job['hourlyBudget']['max'];
			$hourly_min    = isset( $job['hourlyBudget']['min'] ) ? (float) $job['hourlyBudget']['min'] : 0;
			$budget_detail = sprintf(
				/* translators: 1: min rate, 2: max rate, 3: currency */
				__( 'Hourly rate: %1$s-%2$s %3$s/hr', 'mcp-ai-wpoos-pro' ),
				number_format( $hourly_min, 2 ),
				number_format( $job_budget, 2 ),
				isset( $job['hourlyBudget']['currency'] ) ? $job['hourlyBudget']['currency'] : 'USD'
			);
		}

		if ( $job_budget > 0 && $min_budget > 0 ) {
			if ( $job_budget >= $min_budget * 2 ) {
				$budget_score = 100;
			} elseif ( $job_budget >= $min_budget ) {
				$budget_score = (int) round( ( ( $job_budget - $min_budget ) / $min_budget ) * 100 );
				$budget_score = min( 100, max( 0, $budget_score ) );
			} else {
				$budget_score = 0;
			}
		} elseif ( $job_budget > 0 ) {
			// No minimum set — any budget scores 70.
			$budget_score = 70;
		}

		// ---------- Skill match score ----------
		$skill_score  = 0;
		$skill_detail = __( 'No skills data available for matching', 'mcp-ai-wpoos-pro' );
		$job_skills   = array();

		if ( ! empty( $job['skills'] ) ) {
			foreach ( $job['skills'] as $skill ) {
				if ( isset( $skill['prettyName'] ) ) {
					$job_skills[] = strtolower( $skill['prettyName'] );
				}
			}
		}

		if ( ! empty( $job_skills ) && ! empty( $freelancer_skills ) ) {
			$matched   = array_intersect( $freelancer_skills, $job_skills );
			$match_pct = count( $matched ) / count( $job_skills );
			// 1.5× multiplier rewards over-qualification: 67% match → 100 score. Clamped to 100.
			$skill_score  = (int) round( min( 1.0, $match_pct * 1.5 ) * 100 );
			$skill_detail = sprintf(
				/* translators: 1: matched count, 2: total required */
				__( 'Matched %1$d of %2$d required skills', 'mcp-ai-wpoos-pro' ),
				count( $matched ),
				count( $job_skills )
			);
		} elseif ( empty( $job_skills ) ) {
			$skill_score  = 60; // No skills listed — neutral score.
			$skill_detail = __( 'No specific skills required', 'mcp-ai-wpoos-pro' );
		} elseif ( empty( $freelancer_skills ) ) {
			$skill_score  = 50;
			$skill_detail = __( 'No freelancer skills provided for comparison', 'mcp-ai-wpoos-pro' );
		}

		// ---------- Client quality score ----------
		$client_score  = 50; // Default neutral.
		$client_detail = __( 'Limited client history available', 'mcp-ai-wpoos-pro' );
		$client        = isset( $job['client'] ) ? $job['client'] : array();

		if ( ! empty( $client ) ) {
			$points = 0;
			$max_p  = 0;

			// Payment verification.
			$max_p += 30;
			if ( isset( $client['paymentVerificationStatus'] ) && 'VERIFIED' === strtoupper( (string) $client['paymentVerificationStatus'] ) ) {
				$points += 30;
			}

			// Total spent.
			$max_p += 30;
			$spent  = isset( $client['totalSpent']['amount'] ) ? (float) $client['totalSpent']['amount'] : 0;
			if ( $spent >= 10000 ) {
				$points += 30;
			} elseif ( $spent >= 1000 ) {
				$points += 20;
			} elseif ( $spent > 0 ) {
				$points += 10;
			}

			// Feedback rating (0-5 scale).
			$max_p += 25;
			$rating = isset( $client['totalFeedback'] ) ? (float) $client['totalFeedback'] : 0;
			if ( $rating >= 4.5 ) {
				$points += 25;
			} elseif ( $rating >= 4.0 ) {
				$points += 18;
			} elseif ( $rating >= 3.0 ) {
				$points += 10;
			}

			// Previous hires.
			$max_p += 15;
			$hires  = isset( $client['totalHires'] ) ? (int) $client['totalHires'] : 0;
			if ( $hires >= 10 ) {
				$points += 15;
			} elseif ( $hires >= 3 ) {
				$points += 10;
			} elseif ( $hires >= 1 ) {
				$points += 5;
			}

			$client_score  = $max_p > 0 ? (int) round( ( $points / $max_p ) * 100 ) : 50;
			$client_detail = sprintf(
				/* translators: 1: feedback, 2: hires, 3: spent, 4: currency */
				__( 'Rating: %1$s/5 | Hires: %2$d | Spent: %3$s %4$s', 'mcp-ai-wpoos-pro' ),
				number_format( $rating, 1 ),
				$hires,
				number_format( $spent, 0 ),
				isset( $client['totalSpent']['currency'] ) ? $client['totalSpent']['currency'] : 'USD'
			);
		}

		// ---------- Competition score ----------
		$competition_score  = 70; // Default when unknown.
		$competition_detail = __( 'Competition level unknown', 'mcp-ai-wpoos-pro' );
		$applicants         = isset( $job['totalApplicants'] ) ? (int) $job['totalApplicants'] : -1;

		if ( $applicants >= 0 ) {
			if ( $applicants <= 5 ) {
				$competition_score = 100;
			} elseif ( $applicants <= 15 ) {
				$competition_score = 80;
			} elseif ( $applicants <= 30 ) {
				$competition_score = 60;
			} elseif ( $applicants <= 50 ) {
				$competition_score = 40;
			} else {
				$competition_score = 20;
			}
			$competition_detail = sprintf(
				/* translators: %d: number of applicants */
				__( '%d applicants so far', 'mcp-ai-wpoos-pro' ),
				$applicants
			);
		}

		// ---------- Weighted overall score ----------
		$total_weight = $w_budget + $w_skill + $w_client + $w_competition;
		if ( $total_weight <= 0 ) {
			$total_weight = 1.0;
		}

		$overall_score = (int) round(
			(
				( $budget_score * $w_budget ) +
				( $skill_score * $w_skill ) +
				( $client_score * $w_client ) +
				( $competition_score * $w_competition )
			) / $total_weight
		);

		// ---------- Recommendation ----------
		$recommendation = 'maybe';
		if ( $overall_score >= 70 ) {
			$recommendation = 'apply';
		} elseif ( $overall_score < 40 ) {
			$recommendation = 'skip';
		}

		// Build reasoning.
		$reasons = array();
		if ( $budget_score < 30 && $min_budget > 0 ) {
			$reasons[] = __( 'Budget is below your minimum rate.', 'mcp-ai-wpoos-pro' );
		}
		if ( $skill_score < 40 && ! empty( $freelancer_skills ) && ! empty( $job_skills ) ) {
			$reasons[] = __( 'Low skill match — consider expanding your skill set or skipping.', 'mcp-ai-wpoos-pro' );
		}
		if ( $competition_score <= 20 ) {
			$reasons[] = __( 'High competition — many applicants already submitted.', 'mcp-ai-wpoos-pro' );
		}
		if ( $client_score >= 80 ) {
			$reasons[] = __( 'Excellent client history — payment verified and high spend.', 'mcp-ai-wpoos-pro' );
		}

		return array(
			'success'        => true,
			'mode'           => 'api',
			'job_id'         => $job_id,
			'job_title'      => isset( $job['title'] ) ? $job['title'] : '',
			'overall_score'  => $overall_score,
			'recommendation' => $recommendation,
			'breakdown'      => array(
				'budget'      => array(
					'score'  => $budget_score,
					'weight' => $w_budget,
					'detail' => $budget_detail,
				),
				'skill_match' => array(
					'score'  => $skill_score,
					'weight' => $w_skill,
					'detail' => $skill_detail,
				),
				'client'      => array(
					'score'  => $client_score,
					'weight' => $w_client,
					'detail' => $client_detail,
				),
				'competition' => array(
					'score'  => $competition_score,
					'weight' => $w_competition,
					'detail' => $competition_detail,
				),
			),
			'reasoning'      => implode( ' ', $reasons ),
			'job_skills'     => $job_skills,
		);
	}

	/**
	 * Check whether the arguments include a valid, enabled Upwork connection.
	 *
	 * @param array $arguments Tool arguments.
	 * @return bool True when the Upwork API can be used.
	 */
	private function has_valid_connection( array $arguments ) {
		if ( empty( $arguments['connection_id'] ) ) {
			return false;
		}

		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			return false;
		}

		$connection_id = sanitize_text_field( $arguments['connection_id'] );
		$connection    = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );

		if ( ! $connection ) {
			return false;
		}
		if ( 'upwork' !== ( isset( $connection['connection_type'] ) ? $connection['connection_type'] : '' ) ) {
			return false;
		}
		if ( empty( $connection['enabled'] ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Execute text-based fallback scoring when no Upwork connection is available.
	 *
	 * Uses job_title, job_description, job_skills_list, and job_budget_amount
	 * parameters instead of fetching data from the Upwork API.  Client quality
	 * and competition dimensions receive neutral default scores.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array|WP_Error Scoring results.
	 */
	private function execute_fallback( array $arguments ) {
		// Require at least a job description or title for fallback scoring.
		if ( empty( $arguments['job_description'] ) && empty( $arguments['job_title'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_fallback_missing_data',
				__( 'No Upwork connection configured. For fallback scoring, provide at least job_title or job_description. Alternatively, configure an Upwork connection in Remote Sites and supply connection_id + job_id.', 'mcp-ai-wpoos-pro' )
			);
		}

		$job_title = isset( $arguments['job_title'] ) ? sanitize_text_field( $arguments['job_title'] ) : '';

		// Extract scoring inputs.
		$freelancer_skills = isset( $arguments['freelancer_skills'] ) && is_array( $arguments['freelancer_skills'] )
			? array_map( 'strtolower', array_map( 'sanitize_text_field', $arguments['freelancer_skills'] ) )
			: array();
		$min_budget        = isset( $arguments['min_budget'] ) ? (float) $arguments['min_budget'] : 0;

		$criteria = isset( $arguments['scoring_criteria'] ) && is_array( $arguments['scoring_criteria'] )
			? $arguments['scoring_criteria']
			: array();

		$w_budget      = isset( $criteria['budget_weight'] ) ? max( 0.0, min( 1.0, (float) $criteria['budget_weight'] ) ) : 0.3;
		$w_skill       = isset( $criteria['skill_match_weight'] ) ? max( 0.0, min( 1.0, (float) $criteria['skill_match_weight'] ) ) : 0.3;
		$w_client      = isset( $criteria['client_quality_weight'] ) ? max( 0.0, min( 1.0, (float) $criteria['client_quality_weight'] ) ) : 0.2;
		$w_competition = isset( $criteria['competition_weight'] ) ? max( 0.0, min( 1.0, (float) $criteria['competition_weight'] ) ) : 0.2;

		// ---------- Budget score (from provided amount) ----------
		$budget_score  = 0;
		$budget_detail = __( 'Budget not specified', 'mcp-ai-wpoos-pro' );
		$job_budget    = isset( $arguments['job_budget_amount'] ) ? (float) $arguments['job_budget_amount'] : 0;

		if ( $job_budget > 0 ) {
			$budget_detail = sprintf(
				/* translators: %s: budget amount */
				__( 'Provided budget: $%s', 'mcp-ai-wpoos-pro' ),
				number_format( $job_budget, 2 )
			);
		}

		if ( $job_budget > 0 && $min_budget > 0 ) {
			if ( $job_budget >= $min_budget * 2 ) {
				$budget_score = 100;
			} elseif ( $job_budget >= $min_budget ) {
				$budget_score = (int) round( ( ( $job_budget - $min_budget ) / $min_budget ) * 100 );
				$budget_score = min( 100, max( 0, $budget_score ) );
			} else {
				$budget_score = 0;
			}
		} elseif ( $job_budget > 0 ) {
			$budget_score = 70;
		}

		// ---------- Skill match score ----------
		$skill_score  = 0;
		$skill_detail = __( 'No skills data available for matching', 'mcp-ai-wpoos-pro' );
		$job_skills   = array();

		if ( ! empty( $arguments['job_skills_list'] ) && is_array( $arguments['job_skills_list'] ) ) {
			$job_skills = array_map( 'strtolower', array_map( 'sanitize_text_field', $arguments['job_skills_list'] ) );
		}

		if ( ! empty( $job_skills ) && ! empty( $freelancer_skills ) ) {
			$matched      = array_intersect( $freelancer_skills, $job_skills );
			$match_pct    = count( $matched ) / count( $job_skills );
			$skill_score  = (int) round( min( 1.0, $match_pct * 1.5 ) * 100 );
			$skill_detail = sprintf(
				/* translators: 1: matched count, 2: total required */
				__( 'Matched %1$d of %2$d required skills', 'mcp-ai-wpoos-pro' ),
				count( $matched ),
				count( $job_skills )
			);
		} elseif ( empty( $job_skills ) ) {
			$skill_score  = 60;
			$skill_detail = __( 'No specific skills required', 'mcp-ai-wpoos-pro' );
		} elseif ( empty( $freelancer_skills ) ) {
			$skill_score  = 50;
			$skill_detail = __( 'No freelancer skills provided for comparison', 'mcp-ai-wpoos-pro' );
		}

		// ---------- Client quality (neutral — not available in fallback) ----------
		$client_score  = 50;
		$client_detail = __( 'Client quality data unavailable in fallback mode — neutral score applied', 'mcp-ai-wpoos-pro' );

		// ---------- Competition (neutral — not available in fallback) ----------
		$competition_score  = 50;
		$competition_detail = __( 'Competition data unavailable in fallback mode — neutral score applied', 'mcp-ai-wpoos-pro' );

		// ---------- Weighted overall score ----------
		$total_weight = $w_budget + $w_skill + $w_client + $w_competition;
		if ( $total_weight <= 0 ) {
			$total_weight = 1.0;
		}

		$overall_score = (int) round(
			(
				( $budget_score * $w_budget ) +
				( $skill_score * $w_skill ) +
				( $client_score * $w_client ) +
				( $competition_score * $w_competition )
			) / $total_weight
		);

		// ---------- Recommendation ----------
		$recommendation = 'maybe';
		if ( $overall_score >= 70 ) {
			$recommendation = 'apply';
		} elseif ( $overall_score < 40 ) {
			$recommendation = 'skip';
		}

		$reasons = array();
		if ( $budget_score < 30 && $min_budget > 0 ) {
			$reasons[] = __( 'Budget is below your minimum rate.', 'mcp-ai-wpoos-pro' );
		}
		if ( $skill_score < 40 && ! empty( $freelancer_skills ) && ! empty( $job_skills ) ) {
			$reasons[] = __( 'Low skill match — consider expanding your skill set or skipping.', 'mcp-ai-wpoos-pro' );
		}

		return array(
			'success'        => true,
			'mode'           => 'fallback',
			'job_id'         => isset( $arguments['job_id'] ) ? sanitize_text_field( $arguments['job_id'] ) : '',
			'job_title'      => $job_title,
			'overall_score'  => $overall_score,
			'recommendation' => $recommendation,
			'breakdown'      => array(
				'budget'      => array(
					'score'  => $budget_score,
					'weight' => $w_budget,
					'detail' => $budget_detail,
				),
				'skill_match' => array(
					'score'  => $skill_score,
					'weight' => $w_skill,
					'detail' => $skill_detail,
				),
				'client'      => array(
					'score'  => $client_score,
					'weight' => $w_client,
					'detail' => $client_detail,
				),
				'competition' => array(
					'score'  => $competition_score,
					'weight' => $w_competition,
					'detail' => $competition_detail,
				),
			),
			'reasoning'      => implode( ' ', $reasons ),
			'job_skills'     => $job_skills,
			'notice'         => __( 'Scored in fallback mode without Upwork API data. Client quality and competition dimensions use neutral defaults. Configure an Upwork connection in Remote Sites for full scoring accuracy.', 'mcp-ai-wpoos-pro' ),
		);
	}
}
