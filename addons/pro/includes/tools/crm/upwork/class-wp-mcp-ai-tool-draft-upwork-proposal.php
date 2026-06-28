<?php
/**
 * Tool for drafting an Upwork proposal using AI.
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
 * Drafts a personalised Upwork proposal for a job posting using AI.
 *
 * Workflow:
 *  1. Fetches job details from Upwork via the GraphQL API.
 *  2. Builds a detailed AI prompt that includes the job description,
 *     required skills, and the freelancer's profile.
 *  3. Calls the configured AI provider (OpenAI / Gemini / Anthropic) to
 *     generate a tailored proposal draft.
 *  4. Returns the draft text along with submission guidance.
 *
 * NOTE: Proposals must be submitted manually on Upwork.
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Tool_Draft_Upwork_Proposal implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
			return __( 'The Draft Upwork Proposal tool requires the CRM Toolkit to be enabled in plugin settings.', 'mcp-ai-wpoos-pro' );
		}
		return __( 'The Draft Upwork Proposal tool requires the Upwork client integration to be configured.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * GraphQL query used to fetch job details for proposal drafting.
	 *
	 * @var string
	 */
	const JOB_QUERY = '
		query GetUpworkJobForProposal($marketPlaceJobFilter: MarketplaceJobPostingsSearchFilter, $paging: Paging) {
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
						budget { amount currency }
						hourlyBudget { min max currency }
						skills { prettyName }
						category { name }
						subcategory { name }
						client {
							paymentVerificationStatus
							location { country }
						}
					}
				}
			}
		}
	';

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'draft_upwork_proposal';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Draft Upwork Proposal', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Fetches an Upwork job posting and uses AI to draft a personalised proposal tailored to the job requirements and your freelancer profile. Proposals must be submitted manually on Upwork. When no Upwork connection is configured, accepts job_title and job_description text directly.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'connection_id'        => array(
					'type'        => 'string',
					'description' => __( 'Remote Sites Upwork connection ID. Optional — when omitted, provide job_title and job_description for fallback mode.', 'mcp-ai-wpoos-pro' ),
				),
				'job_id'               => array(
					'type'        => 'string',
					'description' => __( 'Upwork job posting ID to write a proposal for (required when using the API).', 'mcp-ai-wpoos-pro' ),
				),
				'job_title'            => array(
					'type'        => 'string',
					'description' => __( 'Job title text (used for fallback proposal generation when no connection is configured).', 'mcp-ai-wpoos-pro' ),
				),
				'job_description'      => array(
					'type'        => 'string',
					'description' => __( 'Full job description text (used for fallback proposal generation when no connection is configured).', 'mcp-ai-wpoos-pro' ),
				),
				'job_skills_list'      => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => __( 'Skills required by the job (used for fallback proposal generation).', 'mcp-ai-wpoos-pro' ),
				),
				'job_category'         => array(
					'type'        => 'string',
					'description' => __( 'Job category name (used for fallback proposal generation).', 'mcp-ai-wpoos-pro' ),
				),
				'freelancer_profile'   => array(
					'type'        => 'string',
					'description' => __( "Freelancer's bio / profile description (required for AI generation).", 'mcp-ai-wpoos-pro' ),
				),
				'freelancer_skills'    => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => __( "Freelancer's skill list.", 'mcp-ai-wpoos-pro' ),
				),
				'portfolio_highlights' => array(
					'type'        => 'string',
					'description' => __( 'Relevant past work or portfolio highlights to include in the proposal.', 'mcp-ai-wpoos-pro' ),
				),
				'bid_amount'           => array(
					'type'        => 'number',
					'description' => __( 'Proposed bid amount (rate or fixed price).', 'mcp-ai-wpoos-pro' ),
				),
				'bid_type'             => array(
					'type'        => 'string',
					'enum'        => array( 'hourly', 'fixed' ),
					'description' => __( 'Bid type: hourly or fixed.', 'mcp-ai-wpoos-pro' ),
				),
				'tone'                 => array(
					'type'        => 'string',
					'enum'        => array( 'professional', 'friendly', 'confident' ),
					'description' => __( 'Writing tone for the proposal (default: professional).', 'mcp-ai-wpoos-pro' ),
					'default'     => 'professional',
				),
				'max_length'           => array(
					'type'        => 'integer',
					'description' => __( 'Maximum word count for the proposal body (default: 500).', 'mcp-ai-wpoos-pro' ),
					'default'     => 500,
				),
			),
			'required'             => array( 'freelancer_profile' ),
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
		$user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id || ! user_can( $user_id, $this->get_required_capability() ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to draft Upwork proposals.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( empty( $arguments['freelancer_profile'] ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_profile', __( 'freelancer_profile is required.', 'mcp-ai-wpoos-pro' ) );
		}

		// Determine whether the Upwork API is available.
		$use_api = $this->has_valid_connection( $arguments );

		// Fall back to text-based proposal generation when no connection is configured.
		if ( ! $use_api ) {
			return $this->execute_fallback( $arguments );
		}

		if ( empty( $arguments['job_id'] ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_job_id', __( 'job_id is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$connection_id      = sanitize_text_field( $arguments['connection_id'] );
		$job_id             = sanitize_text_field( $arguments['job_id'] );
		$freelancer_profile = sanitize_textarea_field( $arguments['freelancer_profile'] );

		// Fetch job details from Upwork.
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

		// Build AI prompt.
		$prompt = $this->build_proposal_prompt( $job, $arguments );

		// Generate proposal via AI.
		$ai_result = $this->generate_proposal( $prompt );

		if ( is_wp_error( $ai_result ) ) {
			return $ai_result;
		}

		// Extract bid details.
		$bid_amount = isset( $arguments['bid_amount'] ) ? (float) $arguments['bid_amount'] : null;
		$bid_type   = isset( $arguments['bid_type'] ) ? sanitize_text_field( $arguments['bid_type'] ) : ( isset( $job['jobType'] ) ? strtolower( $job['jobType'] ) : '' );

		return array(
			'success'       => true,
			'mode'          => 'api',
			'job_id'        => $job_id,
			'job_title'     => isset( $job['title'] ) ? $job['title'] : '',
			'proposal_text' => $ai_result,
			'cover_letter'  => $ai_result,
			'bid_amount'    => $bid_amount,
			'bid_type'      => $bid_type,
			'notes'         => __( 'IMPORTANT: This proposal draft must be submitted manually on Upwork. Copy the proposal text above and paste it into the Upwork job application form. The Upwork API does not support automated proposal submission.', 'mcp-ai-wpoos-pro' ),
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

		// If explicitly set to web_search mode, never use the API.
		$mode = isset( $connection['upwork_mode'] ) ? $connection['upwork_mode'] : 'api';
		if ( 'web_search' === $mode ) {
			return false;
		}

		// API mode: require OAuth credentials.
		if ( empty( $connection['client_id'] ) || empty( $connection['client_secret'] ) || empty( $connection['refresh_token'] ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Generate a proposal from text-based job data when Upwork API is unavailable.
	 *
	 * Accepts job_title, job_description, job_skills_list, and job_category
	 * parameters directly, skipping the API fetch step while still using the
	 * same AI-powered proposal generation pipeline.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array|WP_Error Proposal results or error.
	 */
	private function execute_fallback( array $arguments ) {
		if ( empty( $arguments['job_description'] ) && empty( $arguments['job_title'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_fallback_missing_data',
				__( 'No Upwork connection configured. For fallback proposal generation, provide at least job_title or job_description. Alternatively, configure an Upwork connection in Remote Sites and supply connection_id + job_id.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Build a synthetic job array from the provided text parameters.
		$job = array(
			'title'       => isset( $arguments['job_title'] ) ? sanitize_text_field( $arguments['job_title'] ) : __( 'Untitled Job', 'mcp-ai-wpoos-pro' ),
			'description' => isset( $arguments['job_description'] ) ? sanitize_textarea_field( $arguments['job_description'] ) : '',
			'jobType'     => isset( $arguments['bid_type'] ) ? strtoupper( sanitize_text_field( $arguments['bid_type'] ) ) : '',
			'skills'      => array(),
			'category'    => array( 'name' => isset( $arguments['job_category'] ) ? sanitize_text_field( $arguments['job_category'] ) : '' ),
		);

		// Convert skills list into the same structure used by the API.
		if ( ! empty( $arguments['job_skills_list'] ) && is_array( $arguments['job_skills_list'] ) ) {
			foreach ( $arguments['job_skills_list'] as $skill ) {
				$job['skills'][] = array( 'prettyName' => sanitize_text_field( $skill ) );
			}
		}

		// Build AI prompt using the existing method.
		$prompt = $this->build_proposal_prompt( $job, $arguments );

		// Generate proposal via AI.
		$ai_result = $this->generate_proposal( $prompt );

		if ( is_wp_error( $ai_result ) ) {
			return $ai_result;
		}

		$bid_amount = isset( $arguments['bid_amount'] ) ? (float) $arguments['bid_amount'] : null;
		$bid_type   = isset( $arguments['bid_type'] ) ? sanitize_text_field( $arguments['bid_type'] ) : '';

		return array(
			'success'       => true,
			'mode'          => 'fallback',
			'job_id'        => isset( $arguments['job_id'] ) ? sanitize_text_field( $arguments['job_id'] ) : '',
			'job_title'     => $job['title'],
			'proposal_text' => $ai_result,
			'cover_letter'  => $ai_result,
			'bid_amount'    => $bid_amount,
			'bid_type'      => $bid_type,
			'notes'         => __( 'IMPORTANT: This proposal draft must be submitted manually on Upwork. Copy the proposal text above and paste it into the Upwork job application form.', 'mcp-ai-wpoos-pro' ),
			'notice'        => __( 'Proposal generated in fallback mode from provided text. Configure an Upwork connection in Remote Sites for automatic job data retrieval.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Build the AI prompt for proposal generation.
	 *
	 * @param array $job       Job data from Upwork API.
	 * @param array $arguments Tool arguments.
	 * @return string Prompt string.
	 */
	private function build_proposal_prompt( $job, $arguments ) {
		$job_title  = isset( $job['title'] ) ? $job['title'] : 'Untitled Job';
		$job_desc   = isset( $job['description'] ) ? $job['description'] : '';
		$job_type   = isset( $job['jobType'] ) ? $job['jobType'] : '';
		$category   = isset( $job['category']['name'] ) ? $job['category']['name'] : '';
		$max_length = isset( $arguments['max_length'] ) ? max( 100, (int) $arguments['max_length'] ) : 500;

		$tone_instructions = array(
			'professional' => 'Write in a professional, concise, and business-appropriate tone.',
			'friendly'     => 'Write in a warm, friendly, and approachable tone while remaining professional.',
			'confident'    => 'Write in a bold, confident tone that demonstrates deep expertise and enthusiasm.',
		);
		$tone              = isset( $arguments['tone'] ) && isset( $tone_instructions[ $arguments['tone'] ] )
			? $arguments['tone']
			: 'professional';
		$tone_instr        = $tone_instructions[ $tone ];

		$freelancer_profile = isset( $arguments['freelancer_profile'] ) ? sanitize_textarea_field( $arguments['freelancer_profile'] ) : '';
		$freelancer_skills  = isset( $arguments['freelancer_skills'] ) && is_array( $arguments['freelancer_skills'] )
			? array_map( 'sanitize_text_field', $arguments['freelancer_skills'] )
			: array();
		$portfolio          = isset( $arguments['portfolio_highlights'] ) ? sanitize_textarea_field( $arguments['portfolio_highlights'] ) : '';

		// Build job skills list.
		$job_skills = array();
		if ( ! empty( $job['skills'] ) ) {
			foreach ( $job['skills'] as $skill ) {
				if ( isset( $skill['prettyName'] ) ) {
					$job_skills[] = $skill['prettyName'];
				}
			}
		}

		// Build budget context.
		$budget_context = '';
		if ( isset( $arguments['bid_amount'] ) && $arguments['bid_amount'] > 0 ) {
			$bid_type       = isset( $arguments['bid_type'] ) ? $arguments['bid_type'] : $job_type;
			$budget_context = sprintf(
				/* translators: 1: bid amount, 2: bid type */
				__( 'The freelancer is bidding %1$s (%2$s).', 'mcp-ai-wpoos-pro' ),
				number_format( (float) $arguments['bid_amount'], 2 ),
				sanitize_text_field( $bid_type )
			);
		}

		$prompt  = "You are an expert Upwork proposal writer helping a freelancer win a job.\n\n";
		$prompt .= "## Job Details\n";
		$prompt .= "Title: {$job_title}\n";
		if ( $category ) {
			$prompt .= "Category: {$category}\n";
		}
		if ( $job_type ) {
			$prompt .= "Type: {$job_type}\n";
		}
		if ( ! empty( $job_skills ) ) {
			$prompt .= 'Required Skills: ' . implode( ', ', $job_skills ) . "\n";
		}
		$prompt .= "\nJob Description:\n{$job_desc}\n";

		$prompt .= "\n## Freelancer Profile\n{$freelancer_profile}\n";

		if ( ! empty( $freelancer_skills ) ) {
			$prompt .= "\nFreelancer Skills: " . implode( ', ', $freelancer_skills ) . "\n";
		}

		if ( $portfolio ) {
			$prompt .= "\nPortfolio / Past Work:\n{$portfolio}\n";
		}

		if ( $budget_context ) {
			$prompt .= "\n{$budget_context}\n";
		}

		$prompt .= "\n## Instructions\n";
		$prompt .= "{$tone_instr}\n";
		$prompt .= "Write a compelling Upwork proposal of no more than {$max_length} words.\n";
		$prompt .= "Structure: brief opening hook → directly address the client's specific needs → highlight the most relevant experience/skills → concrete next steps.\n";
		$prompt .= "Do NOT use generic phrases like 'I am a seasoned professional' or 'I am the perfect fit'.\n";
		$prompt .= "Reference specific details from the job description to show you read it carefully.\n";
		$prompt .= "Output only the proposal text — no headings, no preamble, no explanatory notes.\n";

		return $prompt;
	}

	/**
	 * Generate proposal text using the configured AI provider.
	 *
	 * @param string $prompt AI prompt.
	 * @return string|WP_Error Generated proposal text or WP_Error.
	 */
	private function generate_proposal( $prompt ) {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		$provider = $this->get_ai_provider( $settings );

		if ( is_wp_error( $provider ) ) {
			return $provider;
		}

		$model  = $this->get_ai_model( $provider, $settings );
		$client = $this->get_ai_client( $provider );

		if ( is_wp_error( $client ) ) {
			return $client;
		}

		$messages = array(
			array(
				'role'    => 'system',
				'content' => 'You are an expert Upwork proposal writer who crafts compelling, personalised proposals that win jobs. You write concisely and always address the specific requirements of each job posting.',
			),
			array(
				'role'    => 'user',
				'content' => $prompt,
			),
		);

		$result = $client->create_chat_completion(
			$messages,
			array(
				'model'       => $model,
				'temperature' => 0.7,
				'max_tokens'  => 1000,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! isset( $result['choices'][0]['message']['content'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_ai_response',
				__( 'Invalid response from AI provider.', 'mcp-ai-wpoos-pro' )
			);
		}

		return trim( $result['choices'][0]['message']['content'] );
	}

	/**
	 * Get the best available AI provider from plugin settings.
	 *
	 * @param array $settings Plugin settings.
	 * @return string|WP_Error Provider name or WP_Error.
	 */
	private function get_ai_provider( $settings ) {
		// $settings kept for backward compatibility with subclasses.
		unset( $settings );

		if ( WP_MCP_AI_Credential_Resolver::has_credentials( 'openai' ) ) {
			return 'openai';
		}
		if ( WP_MCP_AI_Credential_Resolver::has_credentials( 'gemini' ) ) {
			return 'gemini';
		}
		if ( WP_MCP_AI_Credential_Resolver::has_credentials( 'anthropic' ) ) {
			return 'anthropic';
		}
		return new WP_Error(
			'wp_mcp_ai_no_ai_provider',
			__( 'No AI provider configured. Please add an OpenAI, Gemini, or Anthropic API key in plugin settings.', 'mcp-ai-wpoos-pro' )
		);
	}

	/**
	 * Get a suitable AI model identifier for the provider.
	 *
	 * @param string $provider Provider name.
	 * @param array  $settings Plugin settings.
	 * @return string Model identifier.
	 */
	private function get_ai_model( $provider, $settings ) {
		switch ( $provider ) {
			case 'openai':
				return ! empty( $settings['openai_default_model'] ) ? $settings['openai_default_model'] : 'gpt-4o';
			case 'gemini':
				return ! empty( $settings['gemini_default_model'] ) ? $settings['gemini_default_model'] : 'gemini-2.5-flash';
			case 'anthropic':
				return 'claude-sonnet-4-5-20250929';
			default:
				return 'gpt-4o';
		}
	}

	/**
	 * Instantiate the AI client for the given provider.
	 *
	 * @param string $provider Provider name.
	 * @return object|WP_Error AI client instance or WP_Error.
	 */
	private function get_ai_client( $provider ) {
		switch ( $provider ) {
			case 'openai':
				if ( ! class_exists( 'WP_MCP_AI_OpenAI_Client' ) ) {
					return new WP_Error( 'wp_mcp_ai_client_unavailable', __( 'OpenAI client not available.', 'mcp-ai-wpoos-pro' ) );
				}
				return new WP_MCP_AI_OpenAI_Client();

			case 'gemini':
				if ( ! class_exists( 'WP_MCP_AI_Gemini_Client' ) ) {
					return new WP_Error( 'wp_mcp_ai_client_unavailable', __( 'Gemini client not available.', 'mcp-ai-wpoos-pro' ) );
				}
				return new WP_MCP_AI_Gemini_Client();

			case 'anthropic':
				if ( ! class_exists( 'WP_MCP_AI_Anthropic_Client' ) ) {
					return new WP_Error( 'wp_mcp_ai_client_unavailable', __( 'Anthropic client not available.', 'mcp-ai-wpoos-pro' ) );
				}
				return new WP_MCP_AI_Anthropic_Client();

			default:
				return new WP_Error( 'wp_mcp_ai_unsupported_provider', __( 'Unsupported AI provider.', 'mcp-ai-wpoos-pro' ) );
		}
	}
}
