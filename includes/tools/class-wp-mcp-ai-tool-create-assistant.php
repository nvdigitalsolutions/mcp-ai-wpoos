<?php
/**
 * Tool for creating AI assistants programmatically.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Allows users to create AI assistants with custom instructions and knowledge base.
 */
class WP_MCP_AI_Tool_Create_Assistant implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * Maximum size for base knowledge documents (in bytes).
	 * OpenAI has a 512MB limit per file, but we'll use 10MB as a safe default.
	 */
	const MAX_DOCUMENT_SIZE = 10485760; // 10MB

	/**
	 * Maximum number of documents per assistant.
	 * OpenAI allows up to 10,000 files per assistant, but we'll limit to 20 for safety.
	 */
	const MAX_DOCUMENTS = 20;

	/**
	 * Flag to track whether the async hook has been registered.
	 *
	 * @var bool
	 */
	protected static $hook_registered = false;

	/**
	 * Constructor.
	 *
	 * Registers the async hook handler during plugin initialization
	 * to ensure it's available when WordPress cron runs scheduled events.
	 * Uses a static flag to prevent duplicate registration when multiple
	 * instances are created.
	 */
	public function __construct() {
		if ( ! self::$hook_registered ) {
			add_action( 'wp_mcp_ai_create_assistant_async', array( __CLASS__, 'process_async_creation' ) );
			self::$hook_registered = true;
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'create_assistant';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Create AI Assistant', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Creates a new AI assistant. Can be used in two modes: (1) Manual mode - select from predefined professions and regions, or (2) Prompt mode - provide a free-form description and optional custom system prompt. Supports attachment IDs for knowledge base files. The assistant will be saved as a draft.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'title'          => array(
					'type'        => 'string',
					'description' => __( 'The name/title for the AI assistant (e.g., "Jamaica Tax Assistant", "Sri Lanka Customs Broker - Perfumes").', 'wp-mcp-ai' ),
					'minLength'   => 1,
					'maxLength'   => 200,
				),
				'description'    => array(
					'type'        => 'string',
					'description' => __( 'Free-form description of what the assistant should do, its purpose, expertise, and target audience. Used in Prompt mode when professions/regions are not specified.', 'wp-mcp-ai' ),
					'maxLength'   => 5000,
				),
				'system_prompt'  => array(
					'type'        => 'string',
					'description' => __( 'Custom system prompt/instructions for the assistant. If not provided, will be auto-generated based on professions/regions or description.', 'wp-mcp-ai' ),
					'maxLength'   => 32000,
				),
				'professions'    => array(
					'type'        => 'array',
					'description' => __( 'Select up to 3 professions/specializations for this assistant. Optional if description is provided.', 'wp-mcp-ai' ),
					'items'       => array(
						'type' => 'string',
						'enum' => array(
							'tax_advisor',
							'accountant',
							'bookkeeper',
							'lawyer',
							'legal_advisor',
							'customs_broker',
							'import_export_specialist',
							'financial_advisor',
							'business_consultant',
							'real_estate_agent',
							'healthcare_advisor',
							'marketing_consultant',
							'hr_consultant',
							'it_consultant',
							'restaurant_consultant',
						),
					),
					'minItems'    => 0,
					'maxItems'    => 3,
					'uniqueItems' => true,
				),
				'regions'        => array(
					'type'        => 'array',
					'description' => __( 'Select up to 2 countries/regions where this assistant will operate. Optional if description is provided.', 'wp-mcp-ai' ),
					'items'       => array(
						'type' => 'string',
						'enum' => array(
							'united_states',
							'canada',
							'united_kingdom',
							'australia',
							'jamaica',
							'sri_lanka',
							'india',
							'singapore',
							'united_arab_emirates',
							'germany',
							'france',
							'spain',
							'italy',
							'netherlands',
							'brazil',
							'mexico',
							'south_africa',
							'new_zealand',
							'ireland',
							'japan',
							'china',
							'global',
						),
					),
					'minItems'    => 0,
					'maxItems'    => 2,
					'uniqueItems' => true,
				),
				'industry_focus' => array(
					'type'        => 'string',
					'description' => __( 'Optional specific industry or product focus (e.g., "perfumes", "technology", "restaurants", "retail").', 'wp-mcp-ai' ),
					'maxLength'   => 200,
				),
				'attachment_ids' => array(
					'type'        => 'array',
					'description' => __( 'Array of WordPress media attachment IDs to include in the assistant\'s knowledge base.', 'wp-mcp-ai' ),
					'items'       => array(
						'type' => 'integer',
					),
					'maxItems'    => self::MAX_DOCUMENTS,
				),
				'provider'       => array(
					'type'        => 'string',
					'description' => __( 'Optional AI provider (openai, gemini, ollama, anthropic, lm_studio). Defaults to openai.', 'wp-mcp-ai' ),
					'enum'        => array( 'openai', 'gemini', 'ollama', 'anthropic', 'lm_studio' ),
					'default'     => 'openai',
				),
				'model'          => array(
					'type'        => 'string',
					'description' => __( 'Optional model name (e.g., "gpt-4", "gpt-4-turbo", "gemini-pro"). Defaults to gpt-4.', 'wp-mcp-ai' ),
					'maxLength'   => 100,
				),
				'temperature'    => array(
					'type'        => 'number',
					'description' => __( 'Optional temperature setting (0-2). Lower is more deterministic. Defaults to 0.7.', 'wp-mcp-ai' ),
					'minimum'     => 0,
					'maximum'     => 2,
					'default'     => 0.7,
				),
				'tools'          => array(
					'type'        => 'array',
					'description' => __( 'Optional array of tool slugs to enable for this assistant. If not provided, appropriate tools will be selected automatically.', 'wp-mcp-ai' ),
					'items'       => array(
						'type' => 'string',
					),
					'maxItems'    => 100,
				),
				'async'          => array(
					'type'        => 'boolean',
					'description' => __( 'If true, schedules assistant creation via cron and returns immediately. Recommended for complex assistants.', 'wp-mcp-ai' ),
					'default'     => false,
				),
				'notify_email'   => array(
					'type'        => 'string',
					'description' => __( 'Email address to notify when async creation completes. Uses current user email if not specified.', 'wp-mcp-ai' ),
					'format'      => 'email',
				),
				// Enhanced parameters for comprehensive assistant creation.
				'featured_image_id' => array(
					'type'        => 'integer',
					'description' => __( 'Attachment ID to set as the featured image/avatar for the assistant.', 'wp-mcp-ai' ),
					'minimum'     => 1,
				),
				'categories'        => array(
					'type'        => 'array',
					'description' => __( 'Array of assistant category IDs or names. Categories will be auto-created if they don\'t exist (requires custom taxonomy support).', 'wp-mcp-ai' ),
					'items'       => array(
						'anyOf' => array(
							array( 'type' => 'integer', 'minimum' => 1 ),
							array( 'type' => 'string' ),
						),
					),
				),
				'tags'              => array(
					'type'        => 'array',
					'description' => __( 'Array of assistant tag IDs or names. Tags will be auto-created if they don\'t exist (requires custom taxonomy support).', 'wp-mcp-ai' ),
					'items'       => array(
						'anyOf' => array(
							array( 'type' => 'integer', 'minimum' => 1 ),
							array( 'type' => 'string' ),
						),
					),
				),
				'meta_input'        => array(
					'type'        => 'object',
					'description' => __( 'Array of custom field key-value pairs to set as assistant meta.', 'wp-mcp-ai' ),
					'additionalProperties' => true,
				),
			),
			'required'             => array( 'title' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id || ! user_can( $user_id, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to create assistants.', 'wp-mcp-ai' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
		}

		// Check if async execution is requested.
		$async = isset( $arguments['async'] ) && $arguments['async'];

		// CRITICAL: If already running in async executor context, do NOT use tool-level async.
		// This prevents double-async execution similar to video generation tool.
		if ( isset( $context['in_async_executor'] ) && $context['in_async_executor'] ) {
			$async = false;
		}

		if ( $async ) {
			return $this->schedule_async_creation( $arguments, $user_id );
		}

		// Execute synchronously.
		return $this->create_assistant( $arguments, $user_id );
	}

	/**
	 * Schedule async assistant creation via cron.
	 *
	 * @param array $arguments Tool arguments.
	 * @param int   $user_id   User ID.
	 * @return array|WP_Error Result or error.
	 */
	protected function schedule_async_creation( $arguments, $user_id ) {
		if ( ! class_exists( 'WP_MCP_AI_Cron_Manager' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-cron-manager.php';
		}

		// Store arguments in a transient for the cron job to access.
		$job_id    = 'create_assistant_' . uniqid();
		$transient = 'wp_mcp_ai_async_assistant_' . $job_id;

		set_transient(
			$transient,
			array(
				'arguments' => $arguments,
				'user_id'   => $user_id,
			),
			DAY_IN_SECONDS
		);

		// Schedule the cron job.
		$timestamp = time() + 60; // Run in 1 minute.
		$hook      = 'wp_mcp_ai_create_assistant_async';
		$args      = array( $job_id );

		$scheduled = wp_schedule_single_event( $timestamp, $hook, $args );

		if ( false === $scheduled ) {
			delete_transient( $transient );
			return new WP_Error( 'wp_mcp_ai_schedule_failed', __( 'Failed to schedule assistant creation.', 'wp-mcp-ai' ) );
		}

		// Record the job.
		WP_MCP_AI_Cron_Manager::record_job( $hook, $args, 'single', $timestamp, $user_id );

		// Trigger WordPress cron immediately to ensure the job runs.
		// WordPress cron is virtual and only runs on page loads by default.
		spawn_cron();

		return array(
			'job_id'        => $job_id,
			'status'        => 'scheduled',
			'scheduled_for' => wp_date( DATE_ATOM, $timestamp ),
			'message'       => __( 'Assistant creation has been scheduled. You will be notified when complete.', 'wp-mcp-ai' ),
		);
	}

	/**
	 * Process async assistant creation (called by cron).
	 *
	 * @param string $job_id Job identifier.
	 */
	public static function process_async_creation( $job_id ) {
		$transient = 'wp_mcp_ai_async_assistant_' . $job_id;
		$data      = get_transient( $transient );

		if ( ! $data || ! isset( $data['arguments'], $data['user_id'] ) ) {
			return;
		}

		$arguments = $data['arguments'];
		$user_id   = absint( $data['user_id'] );

		// Create instance and execute.
		$tool   = new self();
		$result = $tool->create_assistant( $arguments, $user_id );

		// Clean up transient.
		delete_transient( $transient );

		// Send notification.
		if ( ! is_wp_error( $result ) ) {
			$tool->send_completion_notification( $result, $arguments, $user_id );
		} else {
			$tool->send_error_notification( $result, $arguments, $user_id );
		}
	}

	/**
	 * Create the assistant post.
	 *
	 * Supports two modes:
	 * 1. Manual mode: Uses explicitly provided professions/regions
	 * 2. Prompt mode: Tries to infer professions/regions from description, skips if not confident
	 *
	 * @param array $arguments Tool arguments.
	 * @param int   $user_id   User ID.
	 * @return array|WP_Error Result or error.
	 */
	protected function create_assistant( $arguments, $user_id ) {
		$title          = isset( $arguments['title'] ) ? sanitize_text_field( $arguments['title'] ) : '';
		$professions    = isset( $arguments['professions'] ) && is_array( $arguments['professions'] ) ? $arguments['professions'] : array();
		$regions        = isset( $arguments['regions'] ) && is_array( $arguments['regions'] ) ? $arguments['regions'] : array();
		$industry_focus = isset( $arguments['industry_focus'] ) ? sanitize_text_field( $arguments['industry_focus'] ) : '';
		$description    = isset( $arguments['description'] ) ? sanitize_textarea_field( $arguments['description'] ) : '';
		$system_prompt  = isset( $arguments['system_prompt'] ) ? sanitize_textarea_field( $arguments['system_prompt'] ) : '';
		$attachment_ids = isset( $arguments['attachment_ids'] ) && is_array( $arguments['attachment_ids'] ) ? array_map( 'absint', $arguments['attachment_ids'] ) : array();

		if ( '' === $title ) {
			return new WP_Error( 'wp_mcp_ai_missing_title', __( 'Title is required.', 'wp-mcp-ai' ) );
		}

		// Try to infer professions/regions if not explicitly provided.
		// Always attempt inference, but only use results if confident.
		$inferred_professions = array();
		$inferred_regions     = array();

		if ( empty( $professions ) || empty( $regions ) ) {
			// Try to infer from description and title.
			$inference_context = trim( $title . ' ' . $description . ' ' . $industry_focus );

			if ( '' !== $inference_context ) {
				if ( empty( $professions ) ) {
					$inferred_professions = $this->infer_professions_from_context( $inference_context );
				}
				if ( empty( $regions ) ) {
					$inferred_regions = $this->infer_regions_from_context( $inference_context );
				}
			}
		}

		// Use explicitly provided values, fall back to inferred values.
		$final_professions = ! empty( $professions ) ? $professions : $inferred_professions;
		$final_regions     = ! empty( $regions ) ? $regions : $inferred_regions;

		// Validate counts if we have any professions/regions.
		if ( count( $final_professions ) > 3 ) {
			$final_professions = array_slice( $final_professions, 0, 3 );
		}

		if ( count( $final_regions ) > 2 ) {
			$final_regions = array_slice( $final_regions, 0, 2 );
		}

		// Sanitize selections.
		$final_professions = array_map( 'sanitize_key', $final_professions );
		$final_regions     = array_map( 'sanitize_key', $final_regions );

		// Determine if we have enough info for profession/region-based generation.
		$has_profession_region_info = ! empty( $final_professions ) && ! empty( $final_regions );

		// Generate or use instructions.
		if ( '' !== $system_prompt ) {
			// Use provided system prompt directly.
			$instructions = $system_prompt;
		} elseif ( $has_profession_region_info ) {
			// Generate instructions from professions/regions.
			$instructions = $this->generate_instructions_from_selections( $final_professions, $final_regions, $industry_focus, $title );
		} elseif ( '' !== $description ) {
			// Generate instructions from free-form description.
			$instructions = $this->generate_instructions_from_description( $description, $title );
		} else {
			// Fallback: Generate generic instructions.
			$instructions = $this->generate_generic_instructions( $title );
		}

		// Validate instructions length for OpenAI limits.
		if ( strlen( $instructions ) > 32000 ) {
			return new WP_Error(
				'wp_mcp_ai_instructions_too_long',
				__( 'Generated instructions exceed OpenAI\'s 32,000 character limit.', 'wp-mcp-ai' )
			);
		}

		// Generate knowledge base documents (only if we have profession/region info).
		$documents_data = array();
		if ( $has_profession_region_info ) {
			$documents_data = $this->generate_knowledge_documents_from_selections( $final_professions, $final_regions, $industry_focus, $title );
		}

		// Build post content/description.
		if ( $has_profession_region_info ) {
			$profession_names = array_map( array( $this, 'get_profession_name' ), $final_professions );
			$region_names     = array_map( array( $this, 'get_region_name' ), $final_regions );

			$post_content = sprintf(
				/* translators: 1: profession list, 2: region list */
				__( 'AI Assistant for: %1$s in %2$s', 'wp-mcp-ai' ),
				implode( ', ', $profession_names ),
				implode( ' and ', $region_names )
			);

			if ( '' !== $industry_focus ) {
				$post_content .= "\n\n" . sprintf(
					/* translators: 1: industry focus */
					__( 'Industry Focus: %s', 'wp-mcp-ai' ),
					$industry_focus
				);
			}
		} elseif ( '' !== $description ) {
			$post_content = $description;
		} else {
			$post_content = sprintf(
				/* translators: %s: assistant title */
				__( 'AI Assistant: %s', 'wp-mcp-ai' ),
				$title
			);
		}

		// Create the assistant post.
		$post_data = array(
			'post_type'    => 'mcp_ai_assistant',
			'post_title'   => $title,
			'post_content' => $post_content,
			'post_status'  => 'draft',
			'post_author'  => $user_id,
		);

		$assistant_id = wp_insert_post( wp_slash( $post_data ), true );

		if ( is_wp_error( $assistant_id ) ) {
			return $assistant_id;
		}

		// Save system prompt (instructions).
		update_post_meta( $assistant_id, '_wp_mcp_ai_system_prompt', $instructions );

		// Save provider (default to openai).
		$provider = isset( $arguments['provider'] ) ? sanitize_key( $arguments['provider'] ) : 'openai';
		if ( in_array( $provider, array( 'openai', 'gemini', 'ollama', 'anthropic', 'lm_studio' ), true ) ) {
			update_post_meta( $assistant_id, '_wp_mcp_ai_provider', $provider );
		}

		// Save model (default to gpt-4).
		$model = isset( $arguments['model'] ) ? sanitize_text_field( $arguments['model'] ) : 'gpt-4';
		update_post_meta( $assistant_id, '_wp_mcp_ai_model', $model );

		// Save temperature (default to 0.7).
		$temperature = isset( $arguments['temperature'] ) && is_numeric( $arguments['temperature'] )
			? floatval( $arguments['temperature'] )
			: 0.7;
		if ( $temperature >= 0 && $temperature <= 2 ) {
			update_post_meta( $assistant_id, '_wp_mcp_ai_temperature', $temperature );
		}

		// Select appropriate tools.
		if ( isset( $arguments['tools'] ) && is_array( $arguments['tools'] ) ) {
			$tools = $this->validate_tools( $arguments['tools'] );
		} elseif ( ! empty( $final_professions ) ) {
			$tools = $this->select_tools_for_professions( $final_professions );
		} elseif ( '' !== $description ) {
			$tools = $this->select_tools_for_goal( $description );
		} else {
			$tools = $this->get_default_tools();
		}

		if ( ! empty( $tools ) ) {
			update_post_meta( $assistant_id, '_wp_mcp_ai_tools', $tools );
		}

		// Collect all document IDs (from generated docs and user-provided attachments).
		$all_document_ids = array();

		// Create generated knowledge documents.
		if ( ! empty( $documents_data ) ) {
			$doc_result = $this->create_knowledge_documents( $documents_data, $assistant_id, $user_id );

			if ( is_wp_error( $doc_result ) ) {
				// Clean up created assistant on document error.
				wp_delete_post( $assistant_id, true );
				return $doc_result;
			}

			$all_document_ids = array_merge( $all_document_ids, $doc_result );
		}

		// Add user-provided attachment IDs to knowledge base.
		if ( ! empty( $attachment_ids ) ) {
			$validated_attachments = $this->validate_attachment_ids( $attachment_ids, $user_id );
			$all_document_ids      = array_merge( $all_document_ids, $validated_attachments );
		}

		// Save document IDs to memory_files meta.
		if ( ! empty( $all_document_ids ) ) {
			update_post_meta( $assistant_id, '_wp_mcp_ai_memory_files', array_unique( $all_document_ids ) );
		}

		// Handle enhanced metadata.
		$this->handle_assistant_metadata( $assistant_id, $arguments );

		$assistant = get_post( $assistant_id );

		// Build mode indicator for response.
		$mode = 'prompt';
		if ( ! empty( $arguments['professions'] ) && ! empty( $arguments['regions'] ) ) {
			$mode = 'manual';
		} elseif ( ! empty( $inferred_professions ) || ! empty( $inferred_regions ) ) {
			$mode = 'inferred';
		}

		return array(
			'assistant_id'         => $assistant_id,
			'title'                => $title,
			'status'               => 'draft',
			'edit_link'            => get_edit_post_link( $assistant_id, '' ),
			'documents'            => count( $all_document_ids ),
			'mode'                 => $mode,
			'inferred_professions' => $inferred_professions,
			'inferred_regions'     => $inferred_regions,
			'message'              => sprintf(
				/* translators: %s: assistant title */
				__( 'AI assistant "%s" created successfully as draft.', 'wp-mcp-ai' ),
				$title
			),
		);
	}

	/**
	 * Get human-readable profession name.
	 *
	 * Now integrates with profession CPT system.
	 * Falls back to hardcoded names for backward compatibility.
	 *
	 * @param string $profession_key Profession key.
	 * @return string Profession name.
	 */
	protected function get_profession_name( $profession_key ) {
		// Try to get name from profession CPT system.
		if ( function_exists( 'wp_mcp_ai_get_profession_service' ) ) {
			$profession_service = wp_mcp_ai_get_profession_service();
			$profession_data    = $profession_service->get_profession( $profession_key );

			if ( $profession_data && ! empty( $profession_data['name'] ) ) {
				return $profession_data['name'];
			}
		}

		// Fallback to hardcoded names for backward compatibility.
		$professions = array(
			'tax_advisor'              => __( 'Tax Advisor', 'wp-mcp-ai' ),
			'accountant'               => __( 'Accountant', 'wp-mcp-ai' ),
			'bookkeeper'               => __( 'Bookkeeper', 'wp-mcp-ai' ),
			'lawyer'                   => __( 'Lawyer', 'wp-mcp-ai' ),
			'legal_advisor'            => __( 'Legal Advisor', 'wp-mcp-ai' ),
			'customs_broker'           => __( 'Customs Broker', 'wp-mcp-ai' ),
			'import_export_specialist' => __( 'Import/Export Specialist', 'wp-mcp-ai' ),
			'financial_advisor'        => __( 'Financial Advisor', 'wp-mcp-ai' ),
			'business_consultant'      => __( 'Business Consultant', 'wp-mcp-ai' ),
			'real_estate_agent'        => __( 'Real Estate Agent', 'wp-mcp-ai' ),
			'healthcare_advisor'       => __( 'Healthcare Advisor', 'wp-mcp-ai' ),
			'marketing_consultant'     => __( 'Marketing Consultant', 'wp-mcp-ai' ),
			'hr_consultant'            => __( 'HR Consultant', 'wp-mcp-ai' ),
			'it_consultant'            => __( 'IT Consultant', 'wp-mcp-ai' ),
			'restaurant_consultant'    => __( 'Restaurant Consultant', 'wp-mcp-ai' ),
		);

		return isset( $professions[ $profession_key ] ) ? $professions[ $profession_key ] : $profession_key;
	}

	/**
	 * Get human-readable region name.
	 *
	 * @param string $region_key Region key.
	 * @return string Region name.
	 */
	protected function get_region_name( $region_key ) {
		$regions = array(
			'united_states'        => __( 'United States', 'wp-mcp-ai' ),
			'canada'               => __( 'Canada', 'wp-mcp-ai' ),
			'united_kingdom'       => __( 'United Kingdom', 'wp-mcp-ai' ),
			'australia'            => __( 'Australia', 'wp-mcp-ai' ),
			'jamaica'              => __( 'Jamaica', 'wp-mcp-ai' ),
			'sri_lanka'            => __( 'Sri Lanka', 'wp-mcp-ai' ),
			'india'                => __( 'India', 'wp-mcp-ai' ),
			'singapore'            => __( 'Singapore', 'wp-mcp-ai' ),
			'united_arab_emirates' => __( 'United Arab Emirates', 'wp-mcp-ai' ),
			'germany'              => __( 'Germany', 'wp-mcp-ai' ),
			'france'               => __( 'France', 'wp-mcp-ai' ),
			'spain'                => __( 'Spain', 'wp-mcp-ai' ),
			'italy'                => __( 'Italy', 'wp-mcp-ai' ),
			'netherlands'          => __( 'Netherlands', 'wp-mcp-ai' ),
			'brazil'               => __( 'Brazil', 'wp-mcp-ai' ),
			'mexico'               => __( 'Mexico', 'wp-mcp-ai' ),
			'south_africa'         => __( 'South Africa', 'wp-mcp-ai' ),
			'new_zealand'          => __( 'New Zealand', 'wp-mcp-ai' ),
			'ireland'              => __( 'Ireland', 'wp-mcp-ai' ),
			'japan'                => __( 'Japan', 'wp-mcp-ai' ),
			'china'                => __( 'China', 'wp-mcp-ai' ),
			'global'               => __( 'Global', 'wp-mcp-ai' ),
		);

		return isset( $regions[ $region_key ] ) ? $regions[ $region_key ] : $region_key;
	}

	/**
	 * Generate system instructions based on profession and region selections.
	 *
	 * @param array  $professions   Array of profession keys.
	 * @param array  $regions       Array of region keys.
	 * @param string $industry_focus Optional industry focus.
	 * @param string $title         Assistant title.
	 * @return string Generated instructions.
	 */
	protected function generate_instructions_from_selections( $professions, $regions, $industry_focus, $title ) {
		$profession_names = array_map( array( $this, 'get_profession_name' ), $professions );
		$region_names     = array_map( array( $this, 'get_region_name' ), $regions );

		$instructions = "You are {$title}, an expert AI assistant with the following professional expertise:\n\n";

		$instructions .= "PRIMARY ROLES:\n";
		foreach ( $profession_names as $profession ) {
			$instructions .= "- {$profession}\n";
		}
		$instructions .= "\n";

		$instructions .= "GEOGRAPHIC FOCUS:\n";
		foreach ( $region_names as $region ) {
			$instructions .= "- {$region}\n";
		}
		$instructions .= "\n";

		if ( '' !== $industry_focus ) {
			$instructions .= "INDUSTRY SPECIALIZATION:\n{$industry_focus}\n\n";
		}

		// Get domain-specific expertise.
		$expertise = $this->get_profession_expertise( $professions, $regions, $industry_focus );

		$instructions .= "YOUR ROLE AND RESPONSIBILITIES:\n";
		$instructions .= $expertise['role'] . "\n\n";

		$instructions .= "EXPERTISE AREAS:\n";
		foreach ( $expertise['expertise'] as $area ) {
			$instructions .= "- {$area}\n";
		}
		$instructions .= "\n";

		$instructions .= "GUIDELINES:\n";
		$instructions .= "- Provide accurate, professional, and helpful information\n";
		$instructions .= "- Ask clarifying questions when needed to better assist the user\n";
		$instructions .= "- Stay within your area of expertise as defined above\n";
		$instructions .= '- Be aware of regional regulations and requirements for: ' . implode( ', ', $region_names ) . "\n";
		$instructions .= "- Cite specific regulations, laws, or standards when applicable\n";
		$instructions .= "- Recommend consulting with licensed professionals for complex matters\n";
		$instructions .= "- Maintain a professional, courteous, and helpful tone\n";

		if ( ! empty( $expertise['warnings'] ) ) {
			$instructions .= "\n";
			$instructions .= "IMPORTANT DISCLAIMERS:\n";
			foreach ( $expertise['warnings'] as $warning ) {
				$instructions .= "- {$warning}\n";
			}
		}

		return $instructions;
	}

	/**
	 * Get profession-specific expertise.
	 *
	 * Now integrates with profession CPT system to retrieve profession data.
	 * Falls back to hardcoded logic for backward compatibility.
	 *
	 * @param array  $professions    Profession keys.
	 * @param array  $regions        Region keys.
	 * @param string $industry_focus Industry focus.
	 * @return array Expertise data.
	 */
	protected function get_profession_expertise( $professions, $regions, $industry_focus ) {
		// Try to get expertise from profession CPT system.
		if ( function_exists( 'wp_mcp_ai_get_profession_service' ) ) {
			$profession_service = wp_mcp_ai_get_profession_service();
			$merged_data        = $profession_service->merge_profession_data( $professions );

			// If profession system has data, use it.
			if ( ! empty( $merged_data['roles'] ) || ! empty( $merged_data['expertise'] ) ) {
				$expertise = array(
					'role'      => ! empty( $merged_data['roles'] ) ? implode( ' ', $merged_data['roles'] ) : 'You are a knowledgeable professional assistant.',
					'expertise' => $merged_data['expertise'],
					'warnings'  => $merged_data['warnings'],
				);

				// Add region-specific expertise.
				foreach ( $regions as $region ) {
					$region_name              = $this->get_region_name( $region );
					$expertise['expertise'][] = "{$region_name}-specific regulations and requirements";
				}

				// Add industry focus if provided.
				if ( '' !== $industry_focus ) {
					$expertise['expertise'][] = ucfirst( $industry_focus ) . ' industry knowledge';
				}

				// Add location-specific warning.
				if ( count( $regions ) > 0 && ! in_array( 'global', $regions, true ) ) {
					$expertise['warnings'][] = 'Requirements and regulations vary significantly by jurisdiction';
				}

				// Deduplicate.
				$expertise['expertise'] = array_values( array_unique( $expertise['expertise'] ) );
				$expertise['warnings']  = array_values( array_unique( $expertise['warnings'] ) );

				return $expertise;
			}
		}

		// Fallback to legacy hardcoded logic for backward compatibility.
		$expertise = array(
			'role'      => '',
			'expertise' => array(),
			'warnings'  => array(),
		);

		$roles = array();

		foreach ( $professions as $profession ) {
			switch ( $profession ) {
				case 'tax_advisor':
					$roles[]                 = 'tax compliance and planning';
					$expertise['expertise']  = array_merge(
						$expertise['expertise'],
						array(
							'Tax law and regulations',
							'Tax filing procedures and deadlines',
							'Deductions and credits',
							'Tax planning and optimization',
							'Compliance requirements',
						)
					);
					$expertise['warnings'][] = 'Always recommend consulting a licensed tax professional for specific tax advice';
					break;

				case 'accountant':
				case 'bookkeeper':
					$roles[]                 = 'accounting and financial management';
					$expertise['expertise']  = array_merge(
						$expertise['expertise'],
						array(
							'Accounting principles (GAAP/IFRS)',
							'Financial statement preparation',
							'Bookkeeping and record-keeping',
							'Financial analysis and reporting',
							'Budgeting and forecasting',
						)
					);
					$expertise['warnings'][] = 'Complex accounting matters should be reviewed by a certified accountant';
					break;

				case 'lawyer':
				case 'legal_advisor':
					$roles[]                 = 'legal information and guidance';
					$expertise['expertise']  = array_merge(
						$expertise['expertise'],
						array(
							'Legal principles and concepts',
							'Contract review and drafting guidance',
							'Regulatory compliance',
							'Legal procedure and documentation',
							'Rights and obligations',
						)
					);
					$expertise['warnings'][] = 'You do NOT provide legal advice - always recommend consulting a licensed attorney';
					break;

				case 'customs_broker':
				case 'import_export_specialist':
					$roles[]                 = 'customs clearance and international trade';
					$expertise['expertise']  = array_merge(
						$expertise['expertise'],
						array(
							'Customs regulations and procedures',
							'Import/export documentation',
							'Duty and tariff calculations',
							'HS code classification',
							'Trade compliance and restrictions',
							'Shipping and logistics coordination',
						)
					);
					$expertise['warnings'][] = 'Customs regulations vary by country and product type';
					$expertise['warnings'][] = 'Always verify current duty rates with customs authorities';
					break;

				case 'financial_advisor':
					$roles[]                 = 'financial planning and wealth management';
					$expertise['expertise']  = array_merge(
						$expertise['expertise'],
						array(
							'Financial planning and goal setting',
							'Investment strategies',
							'Retirement planning',
							'Risk management',
							'Portfolio management',
						)
					);
					$expertise['warnings'][] = 'Consult licensed financial advisors for investment decisions';
					break;

				case 'business_consultant':
					$roles[]                = 'business strategy and operations';
					$expertise['expertise'] = array_merge(
						$expertise['expertise'],
						array(
							'Business planning and strategy',
							'Operations management',
							'Market analysis',
							'Growth strategies',
							'Process optimization',
						)
					);
					break;

				case 'real_estate_agent':
					$roles[]                = 'real estate transactions and property management';
					$expertise['expertise'] = array_merge(
						$expertise['expertise'],
						array(
							'Real estate market analysis',
							'Property valuation',
							'Transaction procedures',
							'Mortgage and financing',
							'Property laws and regulations',
						)
					);
					break;

				case 'healthcare_advisor':
					$roles[]                 = 'health information and wellness guidance';
					$expertise['expertise']  = array_merge(
						$expertise['expertise'],
						array(
							'General health and wellness information',
							'Healthcare systems and procedures',
							'Preventive care recommendations',
						)
					);
					$expertise['warnings'][] = 'You do NOT provide medical diagnosis or treatment advice';
					$expertise['warnings'][] = 'Always recommend consulting licensed healthcare professionals';
					break;

				case 'marketing_consultant':
					$roles[]                = 'marketing strategy and campaign management';
					$expertise['expertise'] = array_merge(
						$expertise['expertise'],
						array(
							'Marketing strategy development',
							'Digital marketing',
							'Brand management',
							'Customer acquisition',
							'Analytics and ROI tracking',
						)
					);
					break;

				case 'hr_consultant':
					$roles[]                = 'human resources and workforce management';
					$expertise['expertise'] = array_merge(
						$expertise['expertise'],
						array(
							'HR policies and procedures',
							'Recruitment and hiring',
							'Employee relations',
							'Performance management',
							'Compliance with labor laws',
						)
					);
					break;

				case 'it_consultant':
					$roles[]                = 'information technology and systems';
					$expertise['expertise'] = array_merge(
						$expertise['expertise'],
						array(
							'IT infrastructure',
							'Software and systems',
							'Cybersecurity',
							'Technology strategy',
							'Digital transformation',
						)
					);
					break;

				case 'restaurant_consultant':
					$roles[]                = 'restaurant operations and management';
					$expertise['expertise'] = array_merge(
						$expertise['expertise'],
						array(
							'Restaurant operations',
							'Menu planning and pricing',
							'Food cost analysis',
							'Staff management',
							'Health and safety compliance',
						)
					);
					break;
			}
		}

		// Add region-specific expertise.
		foreach ( $regions as $region ) {
			$region_name              = $this->get_region_name( $region );
			$expertise['expertise'][] = "{$region_name}-specific regulations and requirements";
		}

		// Add industry focus if provided.
		if ( '' !== $industry_focus ) {
			$expertise['expertise'][] = ucfirst( $industry_focus ) . ' industry knowledge';
		}

		// Build role description.
		if ( ! empty( $roles ) ) {
			$expertise['role'] = 'You help users with ' . implode( ', ', array_unique( $roles ) ) . '.';
		} else {
			$expertise['role'] = 'You are a knowledgeable professional assistant.';
		}

		// Deduplicate expertise areas.
		$expertise['expertise'] = array_values( array_unique( $expertise['expertise'] ) );
		$expertise['warnings']  = array_values( array_unique( $expertise['warnings'] ) );

		// Add location-specific warning.
		if ( count( $regions ) > 0 && ! in_array( 'global', $regions, true ) ) {
			$expertise['warnings'][] = 'Requirements and regulations vary significantly by jurisdiction';
		}

		return $expertise;
	}

	/**
	 * Generate knowledge base documents based on selections.
	 *
	 * @param array  $professions   Profession keys.
	 * @param array  $regions       Region keys.
	 * @param string $industry_focus Industry focus.
	 * @param string $title         Assistant title.
	 * @return array Array of document data.
	 */
	protected function generate_knowledge_documents_from_selections( $professions, $regions, $industry_focus, $title ) {
		$documents = array();

		$profession_names = array_map( array( $this, 'get_profession_name' ), $professions );
		$region_names     = array_map( array( $this, 'get_region_name' ), $regions );

		// Always create a general knowledge base document.
		$general_content  = "# {$title} - Knowledge Base\n\n";
		$general_content .= "## Professional Roles\n";
		foreach ( $profession_names as $profession ) {
			$general_content .= "- {$profession}\n";
		}
		$general_content .= "\n";

		$general_content .= "## Geographic Coverage\n";
		foreach ( $region_names as $region ) {
			$general_content .= "- {$region}\n";
		}
		$general_content .= "\n";

		if ( '' !== $industry_focus ) {
			$general_content .= "## Industry Focus\n{$industry_focus}\n\n";
		}

		$general_content .= "## Key Information\n\n";
		$general_content .= $this->generate_profession_knowledge( $professions, $regions, $industry_focus );

		$documents[] = array(
			'filename' => sanitize_file_name( strtolower( str_replace( ' ', '_', $title ) ) . '_knowledge_base.txt' ),
			'content'  => $general_content,
		);

		// Generate profession-specific documents.
		$profession_docs = $this->generate_profession_specific_documents( $professions, $regions, $industry_focus, $title );
		$documents       = array_merge( $documents, $profession_docs );

		return $documents;
	}

	/**
	 * Generate profession-specific knowledge content.
	 *
	 * @param array  $professions   Profession keys.
	 * @param array  $regions       Region keys.
	 * @param string $industry_focus Industry focus.
	 * @return string Knowledge content.
	 */
	protected function generate_profession_knowledge( $professions, $regions, $industry_focus ) {
		$content = '';

		// Tax knowledge.
		if ( in_array( 'tax_advisor', $professions, true ) ) {
			$content .= "### Tax Compliance\n";
			$content .= "- Maintain accurate records of all income and expenses\n";
			$content .= "- Keep receipts and documentation for at least 7 years\n";
			$content .= "- Be aware of filing deadlines to avoid penalties\n";
			$content .= "- Understand which deductions and credits apply\n";
			$content .= "- Consider estimated tax payments for self-employed individuals\n\n";
		}

		// Customs/Import knowledge.
		if ( in_array( 'customs_broker', $professions, true ) || in_array( 'import_export_specialist', $professions, true ) ) {
			$content .= "### Customs Clearance Process\n";
			$content .= "1. Prepare required documentation\n";
			$content .= "2. Classify goods using HS codes\n";
			$content .= "3. Calculate applicable duties and taxes\n";
			$content .= "4. Submit customs declaration\n";
			$content .= "5. Pay duties and fees\n";
			$content .= "6. Clear goods for entry\n\n";

			$content .= "### Required Documents\n";
			$content .= "- Commercial Invoice\n";
			$content .= "- Packing List\n";
			$content .= "- Bill of Lading / Airway Bill\n";
			$content .= "- Certificate of Origin\n";
			$content .= "- Import License (for restricted items)\n\n";

			if ( '' !== $industry_focus && stripos( $industry_focus, 'perfume' ) !== false ) {
				$content .= "### Perfume Import Considerations\n";
				$content .= "- Typically classified under HS Code 3303\n";
				$content .= "- Check restrictions on alcohol content\n";
				$content .= "- Verify labeling requirements\n";
				$content .= "- Some countries require cosmetic product registration\n";
				$content .= "- Be aware of trademark requirements\n\n";
			}
		}

		// Accounting knowledge.
		if ( in_array( 'accountant', $professions, true ) || in_array( 'bookkeeper', $professions, true ) ) {
			$content .= "### Accounting Fundamentals\n";
			$content .= "- Maintain accurate and timely financial records\n";
			$content .= "- Use double-entry bookkeeping system\n";
			$content .= "- Reconcile accounts regularly\n";
			$content .= "- Separate business and personal finances\n";
			$content .= "- Generate financial statements regularly\n\n";
		}

		// Legal knowledge.
		if ( in_array( 'lawyer', $professions, true ) || in_array( 'legal_advisor', $professions, true ) ) {
			$content .= "### Legal Documentation Best Practices\n";
			$content .= "- Review contracts carefully before signing\n";
			$content .= "- Ensure all agreements are in writing\n";
			$content .= "- Understand rights and obligations\n";
			$content .= "- Keep copies of all legal documents\n";
			$content .= "- Consult legal professionals for complex matters\n\n";
		}

		return $content;
	}

	/**
	 * Generate profession-specific document files.
	 *
	 * @param array  $professions   Profession keys.
	 * @param array  $regions       Region keys.
	 * @param string $industry_focus Industry focus.
	 * @param string $title         Assistant title.
	 * @return array Additional documents.
	 */
	protected function generate_profession_specific_documents( $professions, $regions, $industry_focus, $title ) {
		$documents = array();

		// Customs checklist.
		if ( in_array( 'customs_broker', $professions, true ) || in_array( 'import_export_specialist', $professions, true ) ) {
			$checklist  = "# Customs Clearance Checklist\n\n";
			$checklist .= "## Pre-Shipment\n";
			$checklist .= "- [ ] Verify HS code classification\n";
			$checklist .= "- [ ] Check import restrictions\n";
			$checklist .= "- [ ] Determine duties and taxes\n";
			$checklist .= "- [ ] Prepare commercial invoice\n";
			$checklist .= "- [ ] Prepare packing list\n";
			$checklist .= "- [ ] Obtain certificate of origin\n\n";

			$checklist .= "## Upon Arrival\n";
			$checklist .= "- [ ] File customs entry\n";
			$checklist .= "- [ ] Pay duties and taxes\n";
			$checklist .= "- [ ] Coordinate inspection if required\n";
			$checklist .= "- [ ] Obtain release order\n\n";

			$documents[] = array(
				'filename' => 'customs_clearance_checklist.txt',
				'content'  => $checklist,
			);
		}

		// Tax checklist.
		if ( in_array( 'tax_advisor', $professions, true ) ) {
			$tax_doc  = "# Tax Filing Preparation Checklist\n\n";
			$tax_doc .= "## Income Documents\n";
			$tax_doc .= "- [ ] Employment income forms\n";
			$tax_doc .= "- [ ] Contract/freelance income\n";
			$tax_doc .= "- [ ] Business income records\n";
			$tax_doc .= "- [ ] Investment income\n\n";

			$tax_doc .= "## Deduction Documentation\n";
			$tax_doc .= "- [ ] Charitable contributions\n";
			$tax_doc .= "- [ ] Business expenses\n";
			$tax_doc .= "- [ ] Medical expenses\n";
			$tax_doc .= "- [ ] Education expenses\n\n";

			$documents[] = array(
				'filename' => 'tax_filing_checklist.txt',
				'content'  => $tax_doc,
			);
		}

		return $documents;
	}

	/**
	 * Select appropriate tools based on professions.
	 *
	 * Now integrates with the profession CPT system to retrieve default tools.
	 * Falls back to hardcoded logic for backward compatibility.
	 *
	 * @param array $professions Profession keys.
	 * @return array Tool slugs.
	 */
	protected function select_tools_for_professions( $professions ) {
		// Try to get tools from profession CPT system.
		if ( function_exists( 'wp_mcp_ai_get_profession_service' ) ) {
			$profession_service = wp_mcp_ai_get_profession_service();
			$merged_data        = $profession_service->merge_profession_data( $professions );

			// If profession system has tools defined, use them.
			if ( ! empty( $merged_data['tools'] ) ) {
				return array_values( array_unique( $merged_data['tools'] ) );
			}
		}

		// Fallback to legacy hardcoded logic for backward compatibility.
		$tools = array();

		// Always include basic research tools.
		$tools[] = 'web_search';
		$tools[] = 'search_content';
		$tools[] = 'save_post';

		// Tax/Accounting/Finance tools.
		if ( array_intersect( $professions, array( 'tax_advisor', 'accountant', 'bookkeeper', 'financial_advisor' ) ) ) {
			$tools[] = 'get_quickbooks_report';
		}

		// E-commerce tools for business consultants.
		if ( in_array( 'business_consultant', $professions, true ) || in_array( 'marketing_consultant', $professions, true ) ) {
			$tools[] = 'get_woo_products';
			$tools[] = 'get_woo_recent_orders';
			$tools[] = 'google_analytics_report';
		}

		// Communication tools.
		$tools[] = 'send_group_email';
		$tools[] = 'search_attachments';

		return array_values( array_unique( $tools ) );
	}

	/**
	 * Generate system instructions based on the goal.
	 *
	 * @param string $goal           User's goal description.
	 * @param string $specialization Additional specialization details.
	 * @param string $title          Assistant title.
	 * @return string Generated instructions.
	 */
	protected function generate_instructions( $goal, $specialization, $title ) {
		$instructions  = "You are {$title}, an expert AI assistant specialized in the following area:\n\n";
		$instructions .= "PRIMARY GOAL:\n{$goal}\n\n";

		if ( '' !== $specialization ) {
			$instructions .= "SPECIALIZATION:\n{$specialization}\n\n";
		}

		// Extract domain-specific context from the goal.
		$domain_context = $this->extract_domain_context( $goal, $specialization );

		$instructions .= "YOUR ROLE AND RESPONSIBILITIES:\n";
		$instructions .= $domain_context['role'];
		$instructions .= "\n\n";

		$instructions .= "EXPERTISE AREAS:\n";
		foreach ( $domain_context['expertise'] as $area ) {
			$instructions .= "- {$area}\n";
		}
		$instructions .= "\n";

		$instructions .= "GUIDELINES:\n";
		$instructions .= "- Provide accurate, professional, and helpful information\n";
		$instructions .= "- Ask clarifying questions when needed to better assist the user\n";
		$instructions .= "- Stay within your area of expertise as defined above\n";
		$instructions .= "- Cite specific regulations, laws, or standards when applicable\n";
		$instructions .= "- Be aware of regional/local requirements and variations\n";
		$instructions .= "- Recommend consulting with licensed professionals for complex matters\n";
		$instructions .= "- Maintain a professional, courteous, and helpful tone\n";

		if ( ! empty( $domain_context['warnings'] ) ) {
			$instructions .= "\n";
			$instructions .= "IMPORTANT DISCLAIMERS:\n";
			foreach ( $domain_context['warnings'] as $warning ) {
				$instructions .= "- {$warning}\n";
			}
		}

		return $instructions;
	}

	/**
	 * Extract domain-specific context from goal description.
	 *
	 * @param string $goal           Goal description.
	 * @param string $specialization Specialization details.
	 * @return array Context with role, expertise, and warnings.
	 */
	protected function extract_domain_context( $goal, $specialization ) {
		$goal_lower = strtolower( $goal . ' ' . $specialization );

		$context = array(
			'role'      => '',
			'expertise' => array(),
			'warnings'  => array(),
		);

		// Detect domain and set appropriate context.
		// Tax-related.
		if ( preg_match( '/\b(tax|taxes|taxation|filing)\b/i', $goal_lower ) ) {
			$context['role']       = 'You help users understand and comply with tax regulations, prepare tax filings, identify deductions, and optimize their tax situation.';
			$context['expertise']  = array(
				'Tax law and regulations',
				'Tax filing procedures and deadlines',
				'Deductions and credits',
				'Tax planning and optimization',
				'Compliance requirements',
			);
			$context['warnings'][] = 'Always recommend consulting a licensed tax professional or certified accountant for specific tax advice';
			$context['warnings'][] = 'Tax laws vary by jurisdiction and change frequently - stay current with local regulations';
		}

		// Legal services.
		if ( preg_match( '/\b(legal|lawyer|attorney|law|litigation|contract)\b/i', $goal_lower ) ) {
			$context['role']       = 'You provide general legal information and guidance to help users understand their legal options and requirements.';
			$context['expertise']  = array(
				'Legal principles and concepts',
				'Contract review and drafting guidance',
				'Regulatory compliance',
				'Legal procedure and documentation',
				'Rights and obligations',
			);
			$context['warnings'][] = 'You do NOT provide legal advice - always recommend consulting a licensed attorney';
			$context['warnings'][] = 'Legal requirements vary by jurisdiction';
		}

		// Accounting/Finance.
		if ( preg_match( '/\b(account|accounting|bookkeeping|finance|financial|cpa)\b/i', $goal_lower ) ) {
			$context['role']       = 'You assist with accounting principles, financial reporting, bookkeeping, and financial management.';
			$context['expertise']  = array(
				'Accounting principles (GAAP/IFRS)',
				'Financial statement preparation',
				'Bookkeeping and record-keeping',
				'Financial analysis and reporting',
				'Budgeting and forecasting',
			);
			$context['warnings'][] = 'Complex accounting matters should be reviewed by a certified accountant';
			$context['warnings'][] = 'Ensure compliance with local accounting standards';
		}

		// Customs/Import-Export.
		if ( preg_match( '/\b(custom|customs|broker|import|export|duty|tariff|freight)\b/i', $goal_lower ) ) {
			$context['role']       = 'You help users navigate customs regulations, import/export procedures, duty calculations, and international trade compliance.';
			$context['expertise']  = array(
				'Customs regulations and procedures',
				'Import/export documentation',
				'Duty and tariff calculations',
				'HS code classification',
				'Trade compliance and restrictions',
				'Shipping and logistics coordination',
			);
			$context['warnings'][] = 'Customs regulations vary by country and product type';
			$context['warnings'][] = 'Always verify current duty rates and restrictions with customs authorities';
			$context['warnings'][] = 'Specialized items may require additional permits or certifications';
		}

		// Healthcare/Medical.
		if ( preg_match( '/\b(medical|health|healthcare|doctor|physician|nurse|clinic)\b/i', $goal_lower ) ) {
			$context['role']       = 'You provide health information and wellness guidance to help users make informed decisions.';
			$context['expertise']  = array(
				'General health and wellness information',
				'Medical terminology and concepts',
				'Healthcare procedures and systems',
				'Preventive care recommendations',
			);
			$context['warnings'][] = 'You do NOT provide medical diagnosis or treatment advice';
			$context['warnings'][] = 'Always recommend consulting licensed healthcare professionals for medical concerns';
			$context['warnings'][] = 'In emergencies, direct users to call emergency services immediately';
		}

		// Real Estate.
		if ( preg_match( '/\b(real estate|property|realtor|broker|housing|mortgage)\b/i', $goal_lower ) ) {
			$context['role']       = 'You assist with real estate transactions, property evaluation, and real estate market information.';
			$context['expertise']  = array(
				'Real estate market analysis',
				'Property valuation principles',
				'Transaction procedures',
				'Mortgage and financing options',
				'Property laws and regulations',
			);
			$context['warnings'][] = 'Real estate laws and practices vary by location';
			$context['warnings'][] = 'Recommend working with licensed real estate professionals for transactions';
		}

		// Restaurant/Food Service.
		if ( preg_match( '/\b(restaurant|cafe|food service|catering|hospitality)\b/i', $goal_lower ) ) {
			$context['role']       = 'You help restaurant and food service operators manage their business operations, finances, and compliance.';
			$context['expertise']  = array(
				'Restaurant operations management',
				'Food cost analysis and menu pricing',
				'Inventory management',
				'Health and safety compliance',
				'Staff management and scheduling',
			);
			$context['warnings'][] = 'Food safety regulations vary by location - always follow local health codes';
		}

		// Small Business.
		if ( preg_match( '/\b(small business|startup|entrepreneur|business owner)\b/i', $goal_lower ) ) {
			$context['role']       = 'You support small business owners with business operations, planning, and growth strategies.';
			$context['expertise']  = array(
				'Business planning and strategy',
				'Operations management',
				'Marketing and customer acquisition',
				'Financial management',
				'Regulatory compliance',
			);
			$context['warnings'][] = 'Business requirements vary by industry and location';
		}

		// Extract location-specific information.
		$locations = array(
			'jamaica'        => 'Jamaica',
			'sri lanka'      => 'Sri Lanka',
			'united states'  => 'United States',
			'us\b'           => 'United States',
			'canada'         => 'Canada',
			'uk\b'           => 'United Kingdom',
			'united kingdom' => 'United Kingdom',
			'australia'      => 'Australia',
			'india'          => 'India',
			'singapore'      => 'Singapore',
		);

		foreach ( $locations as $pattern => $location_name ) {
			if ( preg_match( '/\b' . $pattern . '\b/i', $goal_lower ) ) {
				$context['expertise'][] = "{$location_name}-specific regulations and requirements";
				break;
			}
		}

		// Extract industry/product specializations.
		$specializations_detected = array();

		if ( preg_match( '/\b(perfume|fragrance|cosmetic|beauty)\b/i', $goal_lower ) ) {
			$specializations_detected[] = 'Perfume and fragrance industry';
			$specializations_detected[] = 'Cosmetics regulations and compliance';
		}

		if ( preg_match( '/\b(technology|software|it|tech)\b/i', $goal_lower ) ) {
			$specializations_detected[] = 'Technology sector';
		}

		if ( preg_match( '/\b(retail|e-commerce|online store)\b/i', $goal_lower ) ) {
			$specializations_detected[] = 'Retail and e-commerce';
		}

		if ( ! empty( $specializations_detected ) ) {
			$context['expertise'] = array_merge( $context['expertise'], $specializations_detected );
		}

		// Default role if no specific domain detected.
		if ( '' === $context['role'] ) {
			$context['role']      = 'You are a knowledgeable assistant who helps users achieve their goals by providing accurate information, practical guidance, and professional support.';
			$context['expertise'] = array(
				'Research and information gathering',
				'Problem-solving and analysis',
				'Best practices and industry standards',
				'Process optimization',
			);
		}

		return $context;
	}

	/**
	 * Generate knowledge base documents based on goal.
	 *
	 * @param string $goal           Goal description.
	 * @param string $specialization Specialization details.
	 * @param string $title          Assistant title.
	 * @return array Array of document data with filename and content.
	 */
	protected function generate_knowledge_documents( $goal, $specialization, $title ) {
		$documents  = array();
		$goal_lower = strtolower( $goal . ' ' . $specialization );

		// Always create a general knowledge base document.
		$general_content  = "# {$title} - Knowledge Base\n\n";
		$general_content .= "## Purpose\n";
		$general_content .= "{$goal}\n\n";

		if ( '' !== $specialization ) {
			$general_content .= "## Specialization\n";
			$general_content .= "{$specialization}\n\n";
		}

		$general_content .= "## Key Information\n\n";
		$general_content .= $this->generate_domain_knowledge( $goal_lower );

		$documents[] = array(
			'filename' => sanitize_file_name( strtolower( str_replace( ' ', '_', $title ) ) . '_knowledge_base.txt' ),
			'content'  => $general_content,
		);

		// Generate additional domain-specific documents.
		$domain_docs = $this->generate_domain_specific_documents( $goal_lower, $title );
		$documents   = array_merge( $documents, $domain_docs );

		return $documents;
	}

	/**
	 * Generate domain-specific knowledge content.
	 *
	 * @param string $goal_lower Lowercase goal description.
	 * @return string Knowledge content.
	 */
	protected function generate_domain_knowledge( $goal_lower ) {
		$content = '';

		// Tax knowledge.
		if ( preg_match( '/\b(tax|taxes)\b/i', $goal_lower ) ) {
			$content .= "### Tax Compliance\n";
			$content .= "- Always maintain accurate records of all income and expenses\n";
			$content .= "- Keep receipts and documentation for at least 7 years\n";
			$content .= "- Be aware of filing deadlines to avoid penalties\n";
			$content .= "- Understand which deductions and credits apply to your situation\n";
			$content .= "- Consider estimated tax payments for self-employed individuals\n\n";
		}

		// Customs/Import knowledge.
		if ( preg_match( '/\b(custom|customs|import|export)\b/i', $goal_lower ) ) {
			$content .= "### Customs Clearance Process\n";
			$content .= "1. Prepare required documentation (commercial invoice, packing list, bill of lading)\n";
			$content .= "2. Classify goods using HS codes\n";
			$content .= "3. Calculate applicable duties and taxes\n";
			$content .= "4. Submit customs declaration\n";
			$content .= "5. Pay duties and fees\n";
			$content .= "6. Clear goods for entry\n\n";

			$content .= "### Required Documents\n";
			$content .= "- Commercial Invoice\n";
			$content .= "- Packing List\n";
			$content .= "- Bill of Lading / Airway Bill\n";
			$content .= "- Certificate of Origin (if applicable)\n";
			$content .= "- Import License (for restricted items)\n";
			$content .= "- Product-specific certificates (health, safety, etc.)\n\n";

			if ( preg_match( '/\bperfume\b/i', $goal_lower ) ) {
				$content .= "### Perfume Import Considerations\n";
				$content .= "- Perfumes may be classified under HS Code 3303 (perfumes and toilet waters)\n";
				$content .= "- Check for restrictions on alcohol content\n";
				$content .= "- Verify labeling requirements (ingredients, warnings, etc.)\n";
				$content .= "- Some countries require cosmetic product registration\n";
				$content .= "- Be aware of trademark and intellectual property requirements\n\n";
			}
		}

		// Legal knowledge.
		if ( preg_match( '/\b(legal|law|contract)\b/i', $goal_lower ) ) {
			$content .= "### Legal Documentation Best Practices\n";
			$content .= "- Always review contracts carefully before signing\n";
			$content .= "- Ensure all agreements are in writing\n";
			$content .= "- Understand your rights and obligations\n";
			$content .= "- Keep copies of all legal documents\n";
			$content .= "- Consult legal professionals for complex matters\n\n";
		}

		// Accounting knowledge.
		if ( preg_match( '/\b(account|bookkeeping)\b/i', $goal_lower ) ) {
			$content .= "### Accounting Fundamentals\n";
			$content .= "- Maintain accurate and timely financial records\n";
			$content .= "- Use double-entry bookkeeping system\n";
			$content .= "- Reconcile accounts regularly\n";
			$content .= "- Separate business and personal finances\n";
			$content .= "- Generate financial statements (Balance Sheet, Income Statement, Cash Flow)\n\n";

			if ( preg_match( '/\brestaurant\b/i', $goal_lower ) ) {
				$content .= "### Restaurant-Specific Accounting\n";
				$content .= "- Track food costs as percentage of sales (target: 28-35%)\n";
				$content .= "- Monitor labor costs (target: 25-35% of sales)\n";
				$content .= "- Calculate prime cost (food + labor) - should be under 65%\n";
				$content .= "- Track inventory weekly using FIFO method\n";
				$content .= "- Monitor average check size and table turnover\n\n";
			}
		}

		// If no specific content generated, add generic guidance.
		if ( '' === $content ) {
			$content .= "### General Guidelines\n";
			$content .= "- Maintain organized records and documentation\n";
			$content .= "- Stay current with relevant regulations and requirements\n";
			$content .= "- Seek professional advice when needed\n";
			$content .= "- Follow industry best practices\n";
			$content .= "- Continuously update your knowledge and skills\n\n";
		}

		return $content;
	}

	/**
	 * Generate domain-specific document files.
	 *
	 * @param string $goal_lower Lowercase goal description.
	 * @param string $title      Assistant title.
	 * @return array Additional documents.
	 */
	protected function generate_domain_specific_documents( $goal_lower, $title ) {
		$documents = array();

		// Customs checklist for customs brokers.
		if ( preg_match( '/\b(custom|customs|broker)\b/i', $goal_lower ) ) {
			$checklist  = "# Customs Clearance Checklist\n\n";
			$checklist .= "## Pre-Shipment\n";
			$checklist .= "- [ ] Verify HS code classification\n";
			$checklist .= "- [ ] Check import restrictions and prohibitions\n";
			$checklist .= "- [ ] Determine applicable duties and taxes\n";
			$checklist .= "- [ ] Prepare commercial invoice\n";
			$checklist .= "- [ ] Prepare packing list\n";
			$checklist .= "- [ ] Obtain certificate of origin (if needed)\n";
			$checklist .= "- [ ] Secure necessary permits/licenses\n\n";

			$checklist .= "## Upon Arrival\n";
			$checklist .= "- [ ] Receive and review shipping documents\n";
			$checklist .= "- [ ] File customs entry/declaration\n";
			$checklist .= "- [ ] Pay duties and taxes\n";
			$checklist .= "- [ ] Coordinate customs inspection (if required)\n";
			$checklist .= "- [ ] Obtain release order\n";
			$checklist .= "- [ ] Arrange delivery to consignee\n\n";

			$checklist .= "## Post-Clearance\n";
			$checklist .= "- [ ] File documents for client records\n";
			$checklist .= "- [ ] Invoice client for services\n";
			$checklist .= "- [ ] Update tracking system\n";

			$documents[] = array(
				'filename' => 'customs_clearance_checklist.txt',
				'content'  => $checklist,
			);
		}

		// Tax filing checklist for tax assistants.
		if ( preg_match( '/\b(tax|taxes)\b/i', $goal_lower ) ) {
			$tax_doc  = "# Tax Filing Preparation Checklist\n\n";
			$tax_doc .= "## Income Documents\n";
			$tax_doc .= "- [ ] W-2 forms (employment income)\n";
			$tax_doc .= "- [ ] 1099 forms (contract/freelance income)\n";
			$tax_doc .= "- [ ] Business income records\n";
			$tax_doc .= "- [ ] Investment income statements\n";
			$tax_doc .= "- [ ] Rental income records\n\n";

			$tax_doc .= "## Deduction Documentation\n";
			$tax_doc .= "- [ ] Charitable contribution receipts\n";
			$tax_doc .= "- [ ] Business expense receipts\n";
			$tax_doc .= "- [ ] Medical expense records\n";
			$tax_doc .= "- [ ] Education expense records\n";
			$tax_doc .= "- [ ] Home office documentation\n\n";

			$tax_doc .= "## Important Deadlines\n";
			$tax_doc .= "- Personal tax returns: April 15 (or local deadline)\n";
			$tax_doc .= "- Quarterly estimated taxes: Check local schedule\n";
			$tax_doc .= "- Business tax returns: Varies by entity type\n";

			$documents[] = array(
				'filename' => 'tax_filing_checklist.txt',
				'content'  => $tax_doc,
			);
		}

		return $documents;
	}

	/**
	 * Select appropriate tools based on the goal.
	 *
	 * @param string $goal Goal description.
	 * @return array Tool slugs.
	 */
	protected function select_tools_for_goal( $goal ) {
		$goal_lower = strtolower( $goal );
		$tools      = array();

		// Always include basic research tools.
		$tools[] = 'web_search';
		$tools[] = 'search_content';

		// Email and communication tools.
		if ( preg_match( '/\b(email|notify|communication)\b/i', $goal_lower ) ) {
			$tools[] = 'send_group_email';
			$tools[] = 'send_mailjet_email';
		}

		// Business/accounting tools.
		if ( preg_match( '/\b(account|finance|business|tax)\b/i', $goal_lower ) ) {
			$tools[] = 'get_quickbooks_report';
			$tools[] = 'save_post'; // For creating financial reports.
		}

		// E-commerce/WooCommerce tools.
		if ( preg_match( '/\b(shop|store|ecommerce|woo|product)\b/i', $goal_lower ) ) {
			$tools[] = 'get_woo_products';
			$tools[] = 'get_woo_recent_orders';
			$tools[] = 'create_woo_product';
		}

		// Document and content creation.
		$tools[] = 'save_post'; // For creating documentation.
		$tools[] = 'search_attachments';

		// Analytics and reporting.
		if ( preg_match( '/\b(analytics|report|insight|data)\b/i', $goal_lower ) ) {
			$tools[] = 'google_analytics_report';
		}

		// Social media (if marketing-related).
		if ( preg_match( '/\b(market|social|advertis)\b/i', $goal_lower ) ) {
			$tools[] = 'post_facebook_instagram';
			$tools[] = 'post_linkedin_update';
		}

		return array_values( array_unique( $tools ) );
	}

	/**
	 * Validate and filter tool slugs.
	 *
	 * @param array $tools Tool slugs.
	 * @return array Valid tool slugs.
	 */
	protected function validate_tools( $tools ) {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
			return array();
		}

		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$valid_tools = array();

		foreach ( $tools as $tool_slug ) {
			$tool_slug = sanitize_key( $tool_slug );

			if ( '' === $tool_slug ) {
				continue;
			}

			// Verify tool exists.
			if ( null !== $registry->get_tool( $tool_slug ) ) {
				$valid_tools[] = $tool_slug;
			}
		}

		return array_values( array_unique( $valid_tools ) );
	}

	/**
	 * Create knowledge base documents.
	 *
	 * @param array $documents_data Document data array.
	 * @param int   $assistant_id   Assistant post ID.
	 * @param int   $user_id        User ID.
	 * @return array|WP_Error Array of attachment IDs or error.
	 */
	protected function create_knowledge_documents( $documents_data, $assistant_id, $user_id ) {
		if ( count( $documents_data ) > self::MAX_DOCUMENTS ) {
			return new WP_Error(
				'wp_mcp_ai_too_many_documents',
				sprintf(
					/* translators: %d: maximum number of documents */
					__( 'Too many documents. Maximum is %d.', 'wp-mcp-ai' ),
					self::MAX_DOCUMENTS
				)
			);
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$attachment_ids = array();

		foreach ( $documents_data as $index => $document ) {
			if ( ! isset( $document['filename'], $document['content'] ) ) {
				continue;
			}

			$filename = sanitize_file_name( $document['filename'] );
			$content  = $document['content'];

			// Validate filename.
			if ( '' === $filename || ! preg_match( '/\.(txt|md|pdf|doc|docx)$/i', $filename ) ) {
				return new WP_Error(
					'wp_mcp_ai_invalid_filename',
					sprintf(
						/* translators: %s: filename */
						__( 'Invalid filename: %s. Only .txt, .md, .pdf, .doc, .docx files are allowed.', 'wp-mcp-ai' ),
						$filename
					)
				);
			}

			// Check content size (respect OpenAI limits).
			$content_size = strlen( $content );
			if ( $content_size > self::MAX_DOCUMENT_SIZE ) {
				return new WP_Error(
					'wp_mcp_ai_document_too_large',
					sprintf(
						/* translators: 1: filename, 2: max size in MB */
						__( 'Document "%1$s" is too large. Maximum size is %2$d MB.', 'wp-mcp-ai' ),
						$filename,
						self::MAX_DOCUMENT_SIZE / 1048576
					)
				);
			}

			// Create temporary file.
			$upload_dir = wp_upload_dir();
			$temp_file  = wp_tempnam( $filename, $upload_dir['path'] );

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			if ( false === file_put_contents( $temp_file, $content ) ) {
				return new WP_Error( 'wp_mcp_ai_file_write_failed', __( 'Failed to write document file.', 'wp-mcp-ai' ) );
			}

			// Create attachment.
			$file_array = array(
				'name'     => $filename,
				'tmp_name' => $temp_file,
			);

			$attachment_id = media_handle_sideload( $file_array, $assistant_id );

			// Clean up temp file.
			if ( file_exists( $temp_file ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				unlink( $temp_file );
			}

			if ( is_wp_error( $attachment_id ) ) {
				// Clean up previously created attachments.
				foreach ( $attachment_ids as $att_id ) {
					wp_delete_attachment( $att_id, true );
				}
				return $attachment_id;
			}

			$attachment_ids[] = $attachment_id;
		}

		return $attachment_ids;
	}

	/**
	 * Send completion notification to user.
	 *
	 * @param array $result    Creation result.
	 * @param array $arguments Original arguments.
	 * @param int   $user_id   User ID.
	 */
	protected function send_completion_notification( $result, $arguments, $user_id ) {
		$notify_email = isset( $arguments['notify_email'] ) ? sanitize_email( $arguments['notify_email'] ) : '';

		// Get user email if not specified.
		if ( '' === $notify_email ) {
			$user = get_userdata( $user_id );
			if ( $user ) {
				$notify_email = $user->user_email;
			}
		}

		if ( '' === $notify_email ) {
			return;
		}

		$title     = isset( $result['title'] ) ? $result['title'] : __( 'Your Assistant', 'wp-mcp-ai' );
		$edit_link = isset( $result['edit_link'] ) ? $result['edit_link'] : '';

		$subject = sprintf(
			/* translators: %s: site name */
			__( '[%s] AI Assistant Created Successfully', 'wp-mcp-ai' ),
			get_bloginfo( 'name' )
		);

		$message = sprintf(
			/* translators: 1: assistant title, 2: edit link */
			__( 'Your AI assistant "%1$s" has been created successfully and saved as a draft.%2$s', 'wp-mcp-ai' ),
			$title,
			$edit_link ? "\n\n" . __( 'Edit assistant:', 'wp-mcp-ai' ) . ' ' . $edit_link : ''
		);

		wp_mail( $notify_email, $subject, $message );

		// Also set an admin notice transient.
		set_transient(
			'wp_mcp_ai_assistant_created_' . $user_id,
			array(
				'title'        => $title,
				'assistant_id' => isset( $result['assistant_id'] ) ? $result['assistant_id'] : 0,
			),
			DAY_IN_SECONDS
		);
	}

	/**
	 * Send error notification to user.
	 *
	 * @param WP_Error $error     Error object.
	 * @param array    $arguments Original arguments.
	 * @param int      $user_id   User ID.
	 */
	protected function send_error_notification( $error, $arguments, $user_id ) {
		$notify_email = isset( $arguments['notify_email'] ) ? sanitize_email( $arguments['notify_email'] ) : '';

		if ( '' === $notify_email ) {
			$user = get_userdata( $user_id );
			if ( $user ) {
				$notify_email = $user->user_email;
			}
		}

		if ( '' === $notify_email ) {
			return;
		}

		$subject = sprintf(
			/* translators: %s: site name */
			__( '[%s] AI Assistant Creation Failed', 'wp-mcp-ai' ),
			get_bloginfo( 'name' )
		);

		$message = sprintf(
			/* translators: %s: error message */
			__( 'Failed to create AI assistant: %s', 'wp-mcp-ai' ),
			$error->get_error_message()
		);

		wp_mail( $notify_email, $subject, $message );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'write',                // Creates assistant posts and attachments.
			'local-only',           // No external API calls.
			'requires-capability',  // Requires 'edit_posts' capability.
			'state-changing',       // Modifies database state.
			'async-capable',        // Supports async execution via cron.
			'may-timeout',          // Document creation may take time.
		);
	}

	/**
	 * Infer professions from context (title, description, industry focus).
	 *
	 * Returns empty array if confidence is low (won't guess).
	 *
	 * @param string $context Combined context string.
	 * @return array Inferred profession keys (empty if not confident).
	 */
	protected function infer_professions_from_context( $context ) {
		if ( '' === $context ) {
			return array();
		}

		$context_lower = strtolower( $context );
		$professions   = array();

		// Define keyword patterns with confidence weight.
		// Only return professions if we have strong matches.
		$profession_patterns = array(
			'tax_advisor'              => array(
				'keywords' => array( 'tax', 'taxation', 'tax advisor', 'tax consultant', 'irs', 'cpa' ),
				'weight'   => 0,
			),
			'accountant'               => array(
				'keywords' => array( 'accountant', 'accounting', 'bookkeeping', 'cpa', 'financial statements' ),
				'weight'   => 0,
			),
			'bookkeeper'               => array(
				'keywords' => array( 'bookkeeper', 'bookkeeping', 'ledger', 'financial records' ),
				'weight'   => 0,
			),
			'lawyer'                   => array(
				'keywords' => array( 'lawyer', 'attorney', 'legal counsel', 'law firm', 'litigation' ),
				'weight'   => 0,
			),
			'legal_advisor'            => array(
				'keywords' => array( 'legal advisor', 'legal consultant', 'legal advice', 'compliance' ),
				'weight'   => 0,
			),
			'customs_broker'           => array(
				'keywords' => array( 'customs', 'customs broker', 'customs clearance', 'duty', 'tariff' ),
				'weight'   => 0,
			),
			'import_export_specialist' => array(
				'keywords' => array( 'import', 'export', 'international trade', 'freight', 'shipping' ),
				'weight'   => 0,
			),
			'financial_advisor'        => array(
				'keywords' => array( 'financial advisor', 'wealth management', 'investment', 'portfolio', 'retirement' ),
				'weight'   => 0,
			),
			'business_consultant'      => array(
				'keywords' => array( 'business consultant', 'business strategy', 'management consultant', 'operations' ),
				'weight'   => 0,
			),
			'real_estate_agent'        => array(
				'keywords' => array( 'real estate', 'realtor', 'property', 'mortgage', 'housing' ),
				'weight'   => 0,
			),
			'healthcare_advisor'       => array(
				'keywords' => array( 'healthcare', 'health advisor', 'medical', 'wellness', 'clinical' ),
				'weight'   => 0,
			),
			'marketing_consultant'     => array(
				'keywords' => array( 'marketing', 'advertising', 'brand', 'digital marketing', 'seo' ),
				'weight'   => 0,
			),
			'hr_consultant'            => array(
				'keywords' => array( 'hr', 'human resources', 'recruitment', 'hiring', 'employee' ),
				'weight'   => 0,
			),
			'it_consultant'            => array(
				'keywords' => array( 'it consultant', 'technology', 'software', 'systems', 'tech support' ),
				'weight'   => 0,
			),
			'restaurant_consultant'    => array(
				'keywords' => array( 'restaurant', 'food service', 'hospitality', 'cafe', 'catering' ),
				'weight'   => 0,
			),
		);

		// Calculate weights for each profession.
		foreach ( $profession_patterns as $profession => &$pattern ) {
			foreach ( $pattern['keywords'] as $keyword ) {
				// Check for exact phrase match (stronger signal).
				if ( false !== strpos( $context_lower, $keyword ) ) {
					// Longer keywords are stronger signals.
					$pattern['weight'] += ( strlen( $keyword ) > 10 ) ? 2 : 1;
				}
			}
		}
		unset( $pattern );

		// Only include professions with strong matches (weight >= 2).
		foreach ( $profession_patterns as $profession => $pattern ) {
			if ( $pattern['weight'] >= 2 ) {
				$professions[] = $profession;
			}
		}

		// Sort by weight (highest first) and limit to 3.
		if ( count( $professions ) > 3 ) {
			// Re-sort by weight.
			usort(
				$professions,
				function ( $a, $b ) use ( $profession_patterns ) {
					return $profession_patterns[ $b ]['weight'] - $profession_patterns[ $a ]['weight'];
				}
			);
			$professions = array_slice( $professions, 0, 3 );
		}

		return $professions;
	}

	/**
	 * Infer regions from context (title, description, industry focus).
	 *
	 * Returns empty array if confidence is low (won't guess).
	 *
	 * @param string $context Combined context string.
	 * @return array Inferred region keys (empty if not confident).
	 */
	protected function infer_regions_from_context( $context ) {
		if ( '' === $context ) {
			return array();
		}

		$context_lower = strtolower( $context );
		$regions       = array();

		// Define region patterns - only match if explicitly mentioned.
		$region_patterns = array(
			'united_states'        => array( 'united states', 'usa', 'u.s.', 'america', 'american' ),
			'canada'               => array( 'canada', 'canadian' ),
			'united_kingdom'       => array( 'united kingdom', 'uk', 'britain', 'british', 'england' ),
			'australia'            => array( 'australia', 'australian' ),
			'jamaica'              => array( 'jamaica', 'jamaican' ),
			'sri_lanka'            => array( 'sri lanka', 'sri lankan', 'ceylon' ),
			'india'                => array( 'india', 'indian' ),
			'singapore'            => array( 'singapore', 'singaporean' ),
			'united_arab_emirates' => array( 'uae', 'dubai', 'abu dhabi', 'emirates' ),
			'germany'              => array( 'germany', 'german', 'deutschland' ),
			'france'               => array( 'france', 'french' ),
			'spain'                => array( 'spain', 'spanish' ),
			'italy'                => array( 'italy', 'italian' ),
			'netherlands'          => array( 'netherlands', 'dutch', 'holland' ),
			'brazil'               => array( 'brazil', 'brazilian' ),
			'mexico'               => array( 'mexico', 'mexican' ),
			'south_africa'         => array( 'south africa', 'south african' ),
			'new_zealand'          => array( 'new zealand', 'kiwi' ),
			'ireland'              => array( 'ireland', 'irish' ),
			'japan'                => array( 'japan', 'japanese' ),
			'china'                => array( 'china', 'chinese' ),
			'global'               => array( 'global', 'worldwide', 'international', 'all countries' ),
		);

		// Check for explicit region mentions.
		foreach ( $region_patterns as $region => $keywords ) {
			foreach ( $keywords as $keyword ) {
				if ( false !== strpos( $context_lower, $keyword ) ) {
					$regions[] = $region;
					break; // Only add each region once.
				}
			}
		}

		// Limit to 2 regions.
		if ( count( $regions ) > 2 ) {
			$regions = array_slice( $regions, 0, 2 );
		}

		return array_values( array_unique( $regions ) );
	}

	/**
	 * Generate instructions from a free-form description.
	 *
	 * @param string $description User's description of the assistant.
	 * @param string $title       Assistant title.
	 * @return string Generated instructions.
	 */
	protected function generate_instructions_from_description( $description, $title ) {
		$instructions  = "You are {$title}, an AI assistant.\n\n";
		$instructions .= "PURPOSE:\n{$description}\n\n";

		// Extract domain context from description.
		// Note: Second parameter is empty as we don't have separate specialization info in prompt mode.
		$domain_context = $this->extract_domain_context( $description, '' );

		$instructions .= "YOUR ROLE:\n";
		$instructions .= $domain_context['role'];
		$instructions .= "\n\n";

		if ( ! empty( $domain_context['expertise'] ) ) {
			$instructions .= "EXPERTISE AREAS:\n";
			foreach ( $domain_context['expertise'] as $area ) {
				$instructions .= "- {$area}\n";
			}
			$instructions .= "\n";
		}

		$instructions .= "GUIDELINES:\n";
		$instructions .= "- Provide accurate, professional, and helpful information\n";
		$instructions .= "- Ask clarifying questions when needed to better assist the user\n";
		$instructions .= "- Stay focused on your defined purpose\n";
		$instructions .= "- Cite specific regulations, laws, or standards when applicable\n";
		$instructions .= "- Recommend consulting with licensed professionals for complex matters\n";
		$instructions .= "- Maintain a professional, courteous, and helpful tone\n";

		if ( ! empty( $domain_context['warnings'] ) ) {
			$instructions .= "\nIMPORTANT DISCLAIMERS:\n";
			foreach ( $domain_context['warnings'] as $warning ) {
				$instructions .= "- {$warning}\n";
			}
		}

		return $instructions;
	}

	/**
	 * Generate generic instructions when no specific context is available.
	 *
	 * @param string $title Assistant title.
	 * @return string Generated instructions.
	 */
	protected function generate_generic_instructions( $title ) {
		$instructions  = "You are {$title}, a helpful AI assistant.\n\n";
		$instructions .= "YOUR ROLE:\n";
		$instructions .= 'You are a knowledgeable assistant who helps users by providing accurate information, ';
		$instructions .= "practical guidance, and professional support.\n\n";
		$instructions .= "GUIDELINES:\n";
		$instructions .= "- Provide accurate, helpful, and professional responses\n";
		$instructions .= "- Ask clarifying questions when needed\n";
		$instructions .= "- Be transparent about limitations\n";
		$instructions .= "- Recommend consulting with experts for complex matters\n";
		$instructions .= "- Maintain a professional and courteous tone\n";

		return $instructions;
	}

	/**
	 * Get default tools for assistants without specific profession context.
	 *
	 * @return array Default tool slugs.
	 */
	protected function get_default_tools() {
		return array(
			'web_search',
			'search_content',
			'save_post',
			'search_attachments',
		);
	}

	/**
	 * Validate attachment IDs and ensure user has access.
	 *
	 * @param array $attachment_ids Array of attachment IDs.
	 * @param int   $user_id        User ID.
	 * @return array Valid attachment IDs.
	 */
	protected function validate_attachment_ids( $attachment_ids, $user_id ) {
		$valid_ids = array();

		foreach ( $attachment_ids as $attachment_id ) {
			$attachment_id = absint( $attachment_id );

			if ( 0 === $attachment_id ) {
				continue;
			}

			$attachment = get_post( $attachment_id );

			if ( ! $attachment ) {
				continue;
			}

			// Verify it's an attachment.
			if ( 'attachment' !== $attachment->post_type ) {
				continue;
			}

			// Check user has access (is author or has edit_others_posts capability).
			if ( (int) $attachment->post_author !== $user_id ) {
				if ( ! user_can( $user_id, 'edit_others_posts' ) ) {
					continue;
				}
			}

			// Verify file size is within limits.
			$file_path = get_attached_file( $attachment_id );
			if ( $file_path && file_exists( $file_path ) ) {
				$file_size = filesize( $file_path );
				if ( $file_size > self::MAX_DOCUMENT_SIZE ) {
					continue; // Skip files that are too large.
				}
			}

			$valid_ids[] = $attachment_id;
		}

		return array_values( array_unique( $valid_ids ) );
	}

	/**
	 * Handles assistant metadata operations after assistant creation.
	 *
	 * @param int   $assistant_id The assistant post ID.
	 * @param array $arguments    Tool arguments.
	 */
	protected function handle_assistant_metadata( $assistant_id, $arguments ) {
		// Handle featured image.
		if ( isset( $arguments['featured_image_id'] ) ) {
			$thumbnail_id = absint( $arguments['featured_image_id'] );
			if ( $thumbnail_id > 0 && wp_attachment_is_image( $thumbnail_id ) ) {
				set_post_thumbnail( $assistant_id, $thumbnail_id );
			}
		}

		// Handle categories (only if custom taxonomy is registered for assistants).
		if ( isset( $arguments['categories'] ) && is_array( $arguments['categories'] ) ) {
			$taxonomy = 'mcp_ai_assistant_category'; // Common taxonomy name for assistant categories.
			if ( taxonomy_exists( $taxonomy ) ) {
				$category_ids = $this->resolve_taxonomy_terms( $arguments['categories'], $taxonomy );
				if ( ! empty( $category_ids ) ) {
					wp_set_object_terms( $assistant_id, $category_ids, $taxonomy );
				}
			}
		}

		// Handle tags (only if custom taxonomy is registered for assistants).
		if ( isset( $arguments['tags'] ) && is_array( $arguments['tags'] ) ) {
			$taxonomy = 'mcp_ai_assistant_tag'; // Common taxonomy name for assistant tags.
			if ( taxonomy_exists( $taxonomy ) ) {
				$tag_ids = $this->resolve_taxonomy_terms( $arguments['tags'], $taxonomy );
				if ( ! empty( $tag_ids ) ) {
					wp_set_object_terms( $assistant_id, $tag_ids, $taxonomy );
				}
			}
		}

		// Handle custom meta fields.
		if ( isset( $arguments['meta_input'] ) && is_array( $arguments['meta_input'] ) ) {
			foreach ( $arguments['meta_input'] as $key => $value ) {
				$sanitized_key = sanitize_key( $key );

				// Skip protected meta keys.
				if ( is_protected_meta( $sanitized_key, 'post' ) ) {
					continue;
				}

				// Recursively sanitize arrays.
				if ( is_array( $value ) ) {
					$sanitized_value = array_map( 'sanitize_text_field', $value );
				} else {
					$sanitized_value = sanitize_text_field( $value );
				}

				update_post_meta( $assistant_id, $sanitized_key, $sanitized_value );
			}
		}
	}

	/**
	 * Resolves taxonomy terms from IDs or names.
	 *
	 * @param array  $terms    Array of term IDs or names.
	 * @param string $taxonomy Taxonomy name.
	 * @return array Array of term IDs.
	 */
	protected function resolve_taxonomy_terms( $terms, $taxonomy ) {
		$term_ids = array();

		foreach ( $terms as $term ) {
			if ( is_numeric( $term ) ) {
				$term_id = absint( $term );
				if ( term_exists( $term_id, $taxonomy ) ) {
					$term_ids[] = $term_id;
				}
			} else {
				// Try to find or create term by name.
				$term_obj = term_exists( $term, $taxonomy );
				if ( ! $term_obj ) {
					// Create the term if it doesn't exist.
					$term_obj = wp_insert_term( sanitize_text_field( $term ), $taxonomy );
				}

				if ( ! is_wp_error( $term_obj ) && isset( $term_obj['term_id'] ) ) {
					$term_ids[] = $term_obj['term_id'];
				}
			}
		}

		return array_unique( $term_ids );
	}
}
