<?php
/**
 * Tool for scoring LinkedIn job postings against the CRM's ideal-client profile.
 *
 * Evaluates a LinkedIn job posting (provided as a URL or raw text) against
 * the configured qualification framework and returns a structured score
 * with a breakdown by dimension.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage CRM_Toolkit
 * @since 2.10.0
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Scores a LinkedIn job posting.
 *
 * @since 2.10.0
 */
class WP_MCP_AI_Tool_Score_LinkedIn_Job implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Determine whether CRM toolkit is enabled.
	 *
	 * @since 2.10.0
	 * @return bool
	 */
	public static function is_available() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_crm_toolkit'] );
	}

	/**
	 * Message explaining why the tool is unavailable.
	 *
	 * @since 2.10.0
	 * @return string
	 */
	public static function get_unavailable_reason() {
		return __( 'The Score LinkedIn Job tool requires the CRM Toolkit to be enabled in plugin settings.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'score_linkedin_job';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Score LinkedIn Job', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Evaluate a LinkedIn job posting against your ideal-client profile and return a score with breakdown by qualification dimension.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'job_url'         => array(
					'type'        => 'string',
					'description' => __( 'URL of the LinkedIn job posting to score.', 'mcp-ai-wpoos-pro' ),
				),
				'job_description' => array(
					'type'        => 'string',
					'description' => __( 'Full text of the job description (used when URL is not available).', 'mcp-ai-wpoos-pro' ),
				),
				'job_title'       => array(
					'type'        => 'string',
					'description' => __( 'Job title for scoring context.', 'mcp-ai-wpoos-pro' ),
				),
				'skills'          => array(
					'type'        => 'array',
					'description' => __( 'List of skills relevant to your firm for weighing the match.', 'mcp-ai-wpoos-pro' ),
					'items'       => array( 'type' => 'string' ),
				),
				'budget_range'    => array(
					'type'        => 'string',
					'description' => __( 'Your preferred project budget range for scoring (e.g. "$5k–$15k").', 'mcp-ai-wpoos-pro' ),
				),
			),
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
			'consumes-tokens',
			'model-dependent',
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
		$user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id || ! user_can( $user_id, $this->get_required_capability() ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to score LinkedIn jobs.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Build a description of the job for the AI to evaluate.
		$job_description = '';
		if ( ! empty( $arguments['job_description'] ) ) {
			$job_description = sanitize_textarea_field( $arguments['job_description'] );
		} elseif ( ! empty( $arguments['job_url'] ) ) {
			$job_url = esc_url_raw( $arguments['job_url'] );
			// Attempt to scrape the job URL.
			$response = wp_remote_get(
				$job_url,
				array(
					'timeout' => 15,
					'headers' => array( 'User-Agent' => 'WP_MCP_AI_CRM/2.10.0' ),
				)
			);

			if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
				$body            = wp_remote_retrieve_body( $response );
				$job_description = wp_strip_all_tags( $body );
				$job_description = substr( $job_description, 0, 5000 );
			} else {
				$job_description = sprintf(
					/* translators: %s: the job URL */
					__( 'Job posting at URL: %s (unable to fetch content — please provide job_description argument instead)', 'mcp-ai-wpoos-pro' ),
					$job_url
				);
			}
		}

		$job_title = ! empty( $arguments['job_title'] ) ? sanitize_text_field( $arguments['job_title'] ) : '';

		if ( empty( $job_description ) && empty( $job_title ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_input',
				__( 'Please provide either a job_url, job_description, or job_title to score.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Determine the qualification framework.
		$framework = 'bant';
		if ( class_exists( 'WP_MCP_AI_CRM_Engine' ) ) {
			$settings  = WP_MCP_AI_CRM_Engine::get_toolkit_settings();
			$framework = isset( $settings['qualification_framework'] ) ? $settings['qualification_framework'] : 'bant';
		}

		$skills_text = '';
		if ( ! empty( $arguments['skills'] ) && is_array( $arguments['skills'] ) ) {
			$skills_text = implode( ', ', array_map( 'sanitize_text_field', $arguments['skills'] ) );
		}

		$budget_text = ! empty( $arguments['budget_range'] ) ? sanitize_text_field( $arguments['budget_range'] ) : '';

		// Build the prompt for AI scoring.
		$prompt = $this->build_scoring_prompt( $job_title, $job_description, $skills_text, $budget_text, $framework );

		// Execute the AI evaluation.
		return $this->run_scoring( $prompt, $framework, $context );
	}

	/**
	 * Build a scoring prompt for the AI provider.
	 *
	 * @param string $job_title       Job title.
	 * @param string $job_description Job description.
	 * @param string $skills_text     Comma-separated skills list.
	 * @param string $budget_text     Budget range string.
	 * @param string $framework       Qualification framework slug.
	 * @return string Prompt text.
	 */
	protected function build_scoring_prompt( $job_title, $job_description, $skills_text, $budget_text, $framework ) {
		$prompt  = "You are a CRM lead-scoring assistant. Evaluate the following LinkedIn job posting and return a JSON score object.\n\n";
		$prompt .= "Job Title: {$job_title}\n";
		$prompt .= "Job Description: {$job_description}\n";

		if ( ! empty( $skills_text ) ) {
			$prompt .= "Relevant Skills: {$skills_text}\n";
		}
		if ( ! empty( $budget_text ) ) {
			$prompt .= "Preferred Budget Range: {$budget_text}\n";
		}

		$prompt .= "\nQualification Framework: " . strtoupper( $framework ) . "\n\n";
		$prompt .= "Return a JSON object with these fields:\n";
		$prompt .= "- overall_score: integer 0-100\n";
		$prompt .= "- score_label: one of 'hot', 'warm', 'cold'\n";
		$prompt .= "- breakdown: object with framework-specific dimensions, each having a score (0-100) and a brief rationale\n";
		$prompt .= "- summary: one-paragraph executive summary\n";
		$prompt .= "- recommended_action: one of 'apply', 'research_more', 'skip', 'save_for_later'\n";

		$prompt .= "\nOnly return valid JSON. Do not include any other text.\n";

		return $prompt;
	}

	/**
	 * Execute AI scoring via the configured provider.
	 *
	 * @param string $prompt    Scoring prompt.
	 * @param string $framework Qualification framework.
	 * @param array  $context   Execution context.
	 * @return array|WP_Error Score data or WP_Error.
	 */
	protected function run_scoring( $prompt, $framework, $context ) {
		// Use the CRM classifier if available.
		if ( class_exists( 'WP_MCP_AI_CRM_Classifier' ) ) {
			$classifier = new WP_MCP_AI_CRM_Classifier();
			$result     = $classifier->classify( $prompt );

			if ( ! is_wp_error( $result ) ) {
				return array(
					'success'   => true,
					'framework' => $framework,
					'score'     => $result,
				);
			}
		}

		// Fallback: return a structured scoring template for manual review.
		return array(
			'success'   => true,
			'framework' => $framework,
			'score'     => array(
				'overall_score'      => 50,
				'score_label'        => 'warm',
				'breakdown'          => array(
					'compatibility' => array(
						'score'     => 50,
						'rationale' => __( 'Manual review required — no AI provider available.', 'mcp-ai-wpoos-pro' ),
					),
				),
				'summary'            => __( 'Unable to auto-score this job. Please review manually or configure an AI provider.', 'mcp-ai-wpoos-pro' ),
				'recommended_action' => 'research_more',
			),
			'message'   => __( 'Scoring completed with fallback (no AI provider available). Connect an AI model for full scoring.', 'mcp-ai-wpoos-pro' ),
		);
	}
}
