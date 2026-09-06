<?php
/**
 * Slash Command Workflow Orchestrator
 *
 * Handles chaining and orchestration of multiple slash commands.
 *
 * @package WP_MCP_AI
 * @subpackage Slash_Commands
 * @since 1.3.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\SlashCommands;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Workflow Orchestrator Class
 *
 * Enables execution of multiple commands in sequence with
 * conditional logic, error handling, and result passing.
 *
 * @since 1.3.0
 */
class SlashCommandWorkflowOrchestrator {
	/**
	 * Log an event through the base plugin's logger when present
	 * (monolith mode), falling back to error_log in standalone mode.
	 *
	 * @since 2.0.0
	 *
	 * @param string $level   Log level.
	 * @param string $message Log message.
	 */
	private static function log_event( $level, $message ) {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Logger' ) ) {
			\WP_MCP_AI_Logger::log_event( $level, $message );
			return;
		}
		error_log( sprintf( '[nvoos-content-graph-ai-platform] %s: %s', $level, $message ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Standalone fallback when the base logger is absent.
	}

	/**
	 * Command handler instance.
	 *
	 * @var SlashCommandHandler
	 */
	protected $handler;

	/**
	 * Workflow definitions.
	 *
	 * @var array
	 */
	protected $workflows = array();

	/**
	 * Workflow execution state.
	 *
	 * @var array
	 */
	protected $execution_state = array();

	/**
	 * Maximum retry attempts for failed steps.
	 *
	 * @var int
	 */
	protected $max_retries = 3;

	/**
	 * Retry delay in seconds.
	 *
	 * @var int
	 */
	protected $retry_delay = 2;

	/**
	 * Constructor.
	 *
	 * @param SlashCommandHandler $handler Command handler instance.
	 */
	public function __construct( $handler = null ) {
		$this->handler = $handler ? $handler : wp_mcp_ai_get_slash_command_handler();
		$this->load_workflows();
	}

	/**
	 * Load predefined workflows.
	 *
	 * @since 1.3.0
	 */
	protected function load_workflows() {
		$this->workflows = array(
			'content_pipeline'               => array(
				'name'        => __( 'Content Publishing Pipeline', 'nvoos-content-graph-ai-platform' ),
				'description' => __( 'Complete workflow for creating and publishing content', 'nvoos-content-graph-ai-platform' ),
				'steps'       => array(
					array(
						'command' => 'content-draft',
						'params'  => array( 'topic', 'type', 'tone' ),
					),
					array(
						'command' => 'content-enhance',
						'params'  => array( 'post_id' => '{previous.post_id}' ),
					),
					array(
						'command' => 'seo-optimize',
						'params'  => array( 'post_id' => '{previous.post_id}' ),
					),
					array(
						'command' => 'publish-review',
						'params'  => array( 'post_id' => '{previous.post_id}' ),
					),
				),
			),
			'ai_tool_setup'                  => array(
				'name'        => __( 'AI Tool Creation & Setup', 'nvoos-content-graph-ai-platform' ),
				'description' => __( 'Create and configure a new AI tool', 'nvoos-content-graph-ai-platform' ),
				'steps'       => array(
					array(
						'command' => 'prompt-library',
						'params'  => array( 'search' => '{search_term}' ),
					),
					array(
						'command' => 'aitool-create',
						'params'  => array( 'name', 'type', 'description' ),
					),
				),
			),
			'ecommerce_product_launch'       => array(
				'name'        => __( 'E-Commerce Product Launch', 'nvoos-content-graph-ai-platform' ),
				'description' => __( 'Complete workflow for launching a new product', 'nvoos-content-graph-ai-platform' ),
				'steps'       => array(
					array(
						'command' => 'doc-create',
						'params'  => array( 'template' => 'product-description' ),
					),
					array(
						'command' => 'product-recommend',
						'params'  => array( 'product_id' => '{product_id}' ),
					),
					array(
						'command' => 'social-post',
						'params'  => array(
							'platform' => 'all',
							'content'  => '{announcement}',
						),
					),
				),
			),
			'abandoned_cart_campaign'        => array(
				'name'        => __( 'Abandoned Cart Recovery Campaign', 'nvoos-content-graph-ai-platform' ),
				'description' => __( 'Automated workflow to identify and recover abandoned carts', 'nvoos-content-graph-ai-platform' ),
				'steps'       => array(
					array(
						'command' => 'abandoned-recover',
						'params'  => array( 'action' => 'identify' ),
					),
					array(
						'command' => 'abandoned-recover',
						'params'  => array(
							'action'     => 'recover',
							'send-email' => true,
						),
					),
					array(
						'command' => 'ecom-analytics',
						'params'  => array( 'metrics' => 'recovery-rate, revenue' ),
					),
				),
			),
			'social_media_campaign'          => array(
				'name'        => __( 'Multi-Platform Social Media Campaign', 'nvoos-content-graph-ai-platform' ),
				'description' => __( 'Create and publish content across all social platforms', 'nvoos-content-graph-ai-platform' ),
				'steps'       => array(
					array(
						'command' => 'hashtag-suggest',
						'params'  => array(
							'content' => '{post_content}',
							'count'   => 10,
						),
					),
					array(
						'command' => 'social-post',
						'params'  => array(
							'content'   => '{post_content}',
							'platforms' => 'facebook, twitter, instagram, linkedin',
						),
					),
					array(
						'command' => 'social-analytics',
						'params'  => array( 'period' => 'today' ),
					),
				),
			),
			'video_marketing_workflow'       => array(
				'name'        => __( 'Video Marketing Production', 'nvoos-content-graph-ai-platform' ),
				'description' => __( 'Complete video creation and distribution workflow', 'nvoos-content-graph-ai-platform' ),
				'steps'       => array(
					array(
						'command' => 'video-template',
						'params'  => array(
							'template' => '{template_name}',
							'input'    => '{video_assets}',
						),
					),
					array(
						'command' => 'video-subtitle',
						'params'  => array(
							'video-id'      => '{previous.video_id}',
							'auto-generate' => true,
						),
					),
					array(
						'command' => 'social-post',
						'params'  => array(
							'content'   => '{video_description}',
							'platforms' => 'youtube, facebook, instagram',
							'media'     => '{previous.video_id}',
						),
					),
				),
			),
			'ecommerce_upsell_optimization'  => array(
				'name'        => __( 'E-Commerce Upsell Optimization', 'nvoos-content-graph-ai-platform' ),
				'description' => __( 'Analyze and optimize product upsells and cross-sells', 'nvoos-content-graph-ai-platform' ),
				'steps'       => array(
					array(
						'command' => 'ecom-analytics',
						'params'  => array(
							'metrics' => 'top-products',
							'period'  => 'month',
						),
					),
					array(
						'command' => 'upsell-suggest',
						'params'  => array(
							'product-id'          => '{previous.top_product_id}',
							'recommendation-type' => 'frequently_bought',
							'limit'               => 10,
						),
					),
				),
			),
			'ecommerce_inventory_management' => array(
				'name'        => __( 'E-Commerce Inventory Management', 'nvoos-content-graph-ai-platform' ),
				'description' => __( 'Comprehensive inventory forecasting and management workflow', 'nvoos-content-graph-ai-platform' ),
				'steps'       => array(
					array(
						'command' => 'inventory-forecast',
						'params'  => array(
							'period'           => 30,
							'include-seasonal' => true,
						),
					),
					array(
						'command' => 'ecom-analytics',
						'params'  => array(
							'metrics' => 'low-stock, stock-out-risk',
							'format'  => 'json',
						),
					),
					array(
						'command' => 'customer-segment',
						'params'  => array(
							'criteria'   => 'rfm',
							'min-orders' => 3,
						),
					),
				),
			),
			'social_content_planning'        => array(
				'name'        => __( 'Social Content Planning', 'nvoos-content-graph-ai-platform' ),
				'description' => __( 'Strategic social media content planning workflow', 'nvoos-content-graph-ai-platform' ),
				'steps'       => array(
					array(
						'command' => 'competitor-track',
						'params'  => array(
							'competitor' => '{competitor_handle}',
							'platform'   => '{platform}',
						),
					),
					array(
						'command' => 'content-calendar',
						'params'  => array(
							'action' => 'create',
							'period' => 30,
						),
					),
					array(
						'command' => 'social-schedule',
						'params'  => array(
							'content'   => '{post_content}',
							'platforms' => '{platforms}',
							'time'      => '{schedule_time}',
						),
					),
				),
			),
			'video_post_production'          => array(
				'name'        => __( 'Video Post Production Workflow', 'nvoos-content-graph-ai-platform' ),
				'description' => __( 'Complete video post-production pipeline', 'nvoos-content-graph-ai-platform' ),
				'steps'       => array(
					array(
						'command' => 'video-merge',
						'params'  => array(
							'videos'      => '{video_clips}',
							'transitions' => true,
						),
					),
					array(
						'command' => 'video-thumbnail',
						'params'  => array(
							'video-id' => '{previous.video_id}',
							'count'    => 5,
						),
					),
					array(
						'command' => 'video-compress',
						'params'  => array(
							'video-id' => '{previous.video_id}',
							'quality'  => 'medium',
						),
					),
				),
			),
			'product_launch_complete'        => array(
				'name'        => __( 'Complete Product Launch Workflow', 'nvoos-content-graph-ai-platform' ),
				'description' => __( 'End-to-end product launch automation', 'nvoos-content-graph-ai-platform' ),
				'steps'       => array(
					array(
						'command' => 'bundle-create',
						'params'  => array(
							'name'     => '{product_name} Launch Bundle',
							'products' => '{product_ids}',
							'discount' => 15,
						),
					),
					array(
						'command' => 'discount-optimize',
						'params'  => array(
							'campaign-name' => '{product_name} Launch Sale',
							'discount-type' => 'percentage',
							'amount'        => 20,
							'products'      => '{product_ids}',
						),
					),
					array(
						'command' => 'campaign-create',
						'params'  => array(
							'name'     => '{product_name} Launch Campaign',
							'goal'     => 'conversions',
							'duration' => 14,
						),
					),
					array(
						'command' => 'inventory-forecast',
						'params'  => array(
							'product-id' => '{previous.product_id}',
							'period'     => 30,
						),
					),
				),
			),
			'social_campaign_automation'     => array(
				'name'        => __( 'Social Campaign Automation', 'nvoos-content-graph-ai-platform' ),
				'description' => __( 'Automated social media campaign from planning to execution', 'nvoos-content-graph-ai-platform' ),
				'steps'       => array(
					array(
						'command' => 'influencer-find',
						'params'  => array(
							'niche'         => '{campaign_niche}',
							'min-followers' => 5000,
						),
					),
					array(
						'command' => 'post-optimize',
						'params'  => array(
							'content'  => '{campaign_content}',
							'platform' => '{target_platforms}',
							'goal'     => 'engagement',
						),
					),
					array(
						'command' => 'campaign-create',
						'params'  => array(
							'name'     => '{campaign_name}',
							'goal'     => '{campaign_goal}',
							'duration' => 30,
						),
					),
					array(
						'command' => 'social-schedule',
						'params'  => array(
							'content'   => '{previous.optimized_content}',
							'platforms' => '{target_platforms}',
							'time'      => '{schedule_time}',
						),
					),
				),
			),
			'comprehensive_ecommerce_suite'  => array(
				'name'        => __( 'Comprehensive E-Commerce Suite', 'nvoos-content-graph-ai-platform' ),
				'description' => __( 'Complete e-commerce automation workflow from analytics to customer engagement', 'nvoos-content-graph-ai-platform' ),
				'steps'       => array(
					array(
						'command' => 'ecom-analytics',
						'params'  => array(
							'period'  => 'month',
							'metrics' => 'all',
						),
					),
					array(
						'command' => 'inventory-forecast',
						'params'  => array( 'period' => 30 ),
					),
					array(
						'command' => 'customer-segment',
						'params'  => array( 'criteria' => 'rfm' ),
					),
					array(
						'command' => 'discount-optimize',
						'params'  => array(
							'campaign-name' => 'Automated Campaign',
							'discount-type' => 'percentage',
							'amount'        => 15,
						),
					),
					array(
						'command' => 'upsell-suggest',
						'params'  => array( 'limit' => 10 ),
					),
				),
			),
			'video_production_complete'      => array(
				'name'        => __( 'Complete Video Production Pipeline', 'nvoos-content-graph-ai-platform' ),
				'description' => __( 'Full video production workflow from editing to publishing', 'nvoos-content-graph-ai-platform' ),
				'steps'       => array(
					array(
						'command' => 'video-edit',
						'params'  => array(
							'video-id'  => '{video_id}',
							'operation' => 'basic',
						),
					),
					array(
						'command' => 'video-trim',
						'params'  => array(
							'video-id' => '{video_id}',
							'start'    => 0,
							'duration' => '{target_duration}',
						),
					),
					array(
						'command' => 'video-effect',
						'params'  => array(
							'video-id' => '{video_id}',
							'effect'   => '{desired_effect}',
						),
					),
					array(
						'command' => 'video-music',
						'params'  => array(
							'video-id' => '{video_id}',
							'track'    => '{music_track}',
							'volume'   => 70,
						),
					),
					array(
						'command' => 'video-subtitle',
						'params'  => array(
							'video-id'      => '{video_id}',
							'auto-generate' => true,
						),
					),
					array(
						'command' => 'video-render',
						'params'  => array(
							'project-id' => '{video_id}',
							'quality'    => 'high',
							'format'     => 'mp4',
						),
					),
					array(
						'command' => 'video-publish',
						'params'  => array(
							'video-id'  => '{video_id}',
							'platforms' => '{target_platforms}',
						),
					),
				),
			),
			// Phase 5: Advanced workflows with conditional logic and error handling.
			'smart_inventory_replenishment'  => array(
				'name'        => __( 'Smart Inventory Replenishment (Advanced)', 'nvoos-content-graph-ai-platform' ),
				'description' => __( 'Intelligent inventory management with conditional stock ordering', 'nvoos-content-graph-ai-platform' ),
				'steps'       => array(
					array(
						'command' => 'inventory-forecast',
						'params'  => array( 'period' => 30 ),
					),
					array(
						'command'   => 'supplier-sync',
						'params'    => array( 'action' => 'check-prices' ),
						'condition' => array( 'if_success' => true ),
					),
					array(
						'command'   => 'wholesale-pricing',
						'params'    => array(
							'action'   => 'calculate',
							'quantity' => '{previous.recommended_quantity}',
						),
						'condition' => array(
							'field'     => 'stock_level',
							'less_than' => 50,
						),
						'on_error'  => array(
							'fallback' => 'ecom-analytics',
						),
					),
					array(
						'command'   => 'customer-segment',
						'params'    => array( 'criteria' => 'high-value' ),
						'condition' => array( 'if_success' => true ),
					),
				),
			),
			'adaptive_content_publishing'    => array(
				'name'        => __( 'Adaptive Content Publishing (Advanced)', 'nvoos-content-graph-ai-platform' ),
				'description' => __( 'Intelligent content publishing with quality checks and fallbacks', 'nvoos-content-graph-ai-platform' ),
				'steps'       => array(
					array(
						'command' => 'post-optimize',
						'params'  => array(
							'content' => '{content}',
							'goal'    => 'engagement',
						),
					),
					array(
						'command'   => 'hashtag-suggest',
						'params'    => array(
							'content' => '{previous.optimized_content}',
							'count'   => 15,
						),
						'condition' => array( 'if_success' => true ),
					),
					array(
						'command'   => 'social-post',
						'params'    => array(
							'content'   => '{previous.optimized_content}',
							'platforms' => 'all',
						),
						'condition' => array(
							'field'        => 'engagement_score',
							'greater_than' => 70,
						),
						'on_error'  => array(
							'fallback' => 'social-schedule',
						),
					),
					array(
						'command'   => 'social-analytics',
						'params'    => array( 'period' => 'realtime' ),
						'condition' => array( 'if_success' => true ),
					),
				),
			),
			'intelligent_video_distribution' => array(
				'name'        => __( 'Intelligent Video Distribution (Advanced)', 'nvoos-content-graph-ai-platform' ),
				'description' => __( 'Smart video processing with quality validation and multi-platform distribution', 'nvoos-content-graph-ai-platform' ),
				'steps'       => array(
					array(
						'command' => 'video-edit',
						'params'  => array(
							'video-id'  => '{video_id}',
							'operation' => 'advanced',
						),
					),
					array(
						'command'   => 'video-compress',
						'params'    => array(
							'video-id' => '{video_id}',
							'quality'  => 'high',
						),
						'condition' => array( 'if_success' => true ),
						'on_error'  => array(
							'fallback' => 'video-render',
						),
					),
					array(
						'command'   => 'video-thumbnail',
						'params'    => array(
							'video-id' => '{video_id}',
							'count'    => 5,
						),
						'condition' => array( 'if_success' => true ),
					),
					array(
						'command'   => 'video-publish',
						'params'    => array(
							'video-id'  => '{video_id}',
							'platforms' => 'youtube,vimeo',
						),
						'condition' => array(
							'field'     => 'video_size_mb',
							'less_than' => 500,
						),
					),
					array(
						'command'   => 'video-analytics',
						'params'    => array( 'video-id' => '{video_id}' ),
						'condition' => array( 'if_success' => true ),
					),
				),
			),
		);

		/**
		 * Filter workflow definitions.
		 *
		 * Allows plugins to add custom workflows.
		 *
		 * @since 1.3.0
		 *
		 * @param array $workflows Workflow definitions.
		 */
		$this->workflows = apply_filters( 'wp_mcp_ai_slash_command_workflows', $this->workflows );
	}

	/**
	 * Execute a workflow.
	 *
	 * @since 1.3.0
	 * @since 1.4.0 Added conditional logic, retry mechanism, and error handling.
	 *
	 * @param string $workflow_name Workflow name.
	 * @param array  $params Workflow parameters.
	 * @param array  $context Execution context.
	 * @param array  $options Execution options (retry, continue_on_error, save_state).
	 * @return array Workflow result.
	 */
	public function execute_workflow( $workflow_name, $params = array(), $context = array(), $options = array() ) {
		if ( ! isset( $this->workflows[ $workflow_name ] ) ) {
			return new \WP_Error(
				'wp_mcp_ai_error',
				sprintf(
					/* translators: %s: workflow name */
					__( 'Workflow "%s" not found.', 'nvoos-content-graph-ai-platform' ),
					$workflow_name
				)
			);
		}

		$workflow        = $this->workflows[ $workflow_name ];
		$results         = array();
		$previous_result = null;

		// Use existing execution_id or generate new one with better uniqueness.
		$execution_id = isset( $options['execution_id'] ) && $options['execution_id']
			? $options['execution_id']
			: wp_generate_uuid4();

		// Parse options.
		$continue_on_error = isset( $options['continue_on_error'] ) ? $options['continue_on_error'] : false;
		$save_state        = isset( $options['save_state'] ) ? $options['save_state'] : false;
		$max_retries       = isset( $options['max_retries'] ) ? absint( $options['max_retries'] ) : $this->max_retries;
		$resume_from_step  = isset( $options['resume_from_step'] ) ? absint( $options['resume_from_step'] ) : 1;

		// Initialize execution state.
		if ( $save_state ) {
			$this->init_execution_state( $execution_id, $workflow_name, $params );
		}

		foreach ( $workflow['steps'] as $index => $step ) {
			$step_number = $index + 1;

			// Skip steps if resuming from a later step.
			if ( $step_number < $resume_from_step ) {
				continue;
			}

			// Check if step should be skipped based on condition.
			if ( $this->should_skip_step( $step, $previous_result, $results ) ) {
				$results[] = array(
					'step'    => $step_number,
					'command' => $step['command'],
					'skipped' => true,
					'reason'  => isset( $step['condition'] ) ? 'condition_not_met' : 'unknown',
				);
				continue;
			}

			// Execute step with retry logic.
			$step_result = $this->execute_step_with_retry(
				$step,
				$params,
				$previous_result,
				$context,
				$max_retries
			);

			// Store result.
			$step_index = count( $results );
			$results[]  = array(
				'step'    => $step_number,
				'command' => $step['command'],
				'params'  => $step_result['params'],
				'result'  => $step_result['result'],
				'retries' => isset( $step_result['retries'] ) ? $step_result['retries'] : 0,
			);

			// Update execution state (batched - only on errors or every 5 steps).
			if ( $save_state && ( 0 === $step_number % 5 || is_wp_error( $step_result['result'] ) || ! $step_result['result']['success'] ) ) {
				$this->update_execution_state( $execution_id, $step_number, $step_result );
			}

			// Check for errors.
			if ( is_wp_error( $step_result['result'] ) || ( is_array( $step_result['result'] ) && ! $step_result['result']['success'] ) ) {
				// Check for fallback step.
				if ( isset( $step['on_error'] ) && isset( $step['on_error']['fallback'] ) ) {
					$fallback_result = $this->execute_fallback_step(
						$step['on_error']['fallback'],
						$params,
						$previous_result,
						$step_result['result'],
						$context
					);
					$results[]       = array(
						'step'     => $step_number,
						'command'  => $step['on_error']['fallback'],
						'fallback' => true,
						'result'   => $fallback_result,
					);
					$previous_result = $fallback_result;
					continue;
				}

				// If not continuing on error, return failure.
				if ( ! $continue_on_error ) {
					return new \WP_Error(
						'wp_mcp_ai_error',
						sprintf(
							/* translators: 1: step number, 2: command name */
							__( 'Workflow failed at step %1$d (%2$s).', 'nvoos-content-graph-ai-platform' ),
							$step_number,
							$step['command']
						),
						array(
							'workflow'     => $workflow_name,
							'execution_id' => $execution_id,
							'steps'        => $results,
						)
					);
				}

				// Continue on error - mark step as failed but continue.
				$results[ $step_index ]['continued_on_error'] = true;
			}

			$previous_result = $step_result['result'];
		}

		// Clear execution state if saved.
		if ( $save_state ) {
			$this->clear_execution_state( $execution_id );
		}

		return array(
			'success'      => true,
			'message'      => sprintf(
				/* translators: 1: workflow name, 2: number of steps */
				__( 'Workflow "%1$s" completed successfully (%2$d steps).', 'nvoos-content-graph-ai-platform' ),
				$workflow['name'],
				count( $results )
			),
			'workflow'     => $workflow_name,
			'execution_id' => $execution_id,
			'steps'        => $results,
		);
	}

	/**
	 * Resolve workflow parameters.
	 *
	 * Replaces placeholders like {previous.post_id} with actual values.
	 *
	 * @since 1.3.0
	 *
	 * @param array $step_params Step parameter definitions.
	 * @param array $workflow_params Workflow input parameters.
	 * @param array $previous_result Previous step result.
	 * @return array Resolved parameters.
	 */
	protected function resolve_parameters( $step_params, $workflow_params, $previous_result ) {
		$resolved = array();

		foreach ( $step_params as $key => $value ) {
			// If value is an array key (positional param).
			if ( is_numeric( $key ) ) {
				// Value is the parameter name, get from workflow params.
				$resolved[] = isset( $workflow_params[ $value ] ) ? $workflow_params[ $value ] : '';
				continue;
			}

			// Named parameter.
			$resolved_value = $value;

			// Resolve placeholders.
			if ( is_string( $value ) && strpos( $value, '{' ) !== false ) {
				// Replace {previous.field} placeholders.
				if ( preg_match( '/\{previous\.(\w+)\}/', $value, $matches ) ) {
					$field = $matches[1];
					if ( $previous_result && isset( $previous_result['data'][ $field ] ) ) {
						$resolved_value = $previous_result['data'][ $field ];
					}
				}

				// Replace {field} placeholders.
				if ( preg_match( '/\{(\w+)\}/', $value, $matches ) ) {
					$field = $matches[1];
					if ( isset( $workflow_params[ $field ] ) ) {
						$resolved_value = $workflow_params[ $field ];
					}
				}
			}

			$resolved[ $key ] = $resolved_value;
		}

		return $resolved;
	}

	/**
	 * Execute a workflow step with retry logic.
	 *
	 * @since 1.4.0
	 *
	 * @param array $step Step definition.
	 * @param array $workflow_params Workflow parameters.
	 * @param array $previous_result Previous step result.
	 * @param array $context Execution context.
	 * @param int   $max_retries Maximum retry attempts.
	 * @return array Step result with retry count.
	 */
	protected function execute_step_with_retry( $step, $workflow_params, $previous_result, $context, $max_retries ) {
		$retries         = 0;
		$resolved_params = $this->resolve_parameters( $step['params'], $workflow_params, $previous_result );
		$result          = null;
		$retry_delay     = apply_filters( 'wp_mcp_ai_workflow_retry_delay', $this->retry_delay, $step );

		while ( $retries <= $max_retries ) {
			// Build command string.
			$command_string = '/' . $step['command'];
			foreach ( $resolved_params as $key => $value ) {
				if ( is_numeric( $key ) ) {
					$command_string .= " {$value}";
				} else {
					$command_string .= " --{$key}=\"{$value}\"";
				}
			}

			// Execute command.
			$result = $this->handler->execute( $command_string, $context );

			// Check if successful.
			if ( is_array( $result ) && isset( $result['success'] ) && $result['success'] ) {
				return array(
					'result'  => $result,
					'params'  => $resolved_params,
					'retries' => $retries,
				);
			}

			// Retry if not max attempts yet.
			if ( $retries < $max_retries ) {
				++$retries;
				// Use exponential backoff if enabled.
				$delay = apply_filters( 'wp_mcp_ai_workflow_use_exponential_backoff', false )
					? $retry_delay * pow( 2, $retries - 1 )
					: $retry_delay;
				sleep( $delay );
				continue;
			}

			// Max retries reached.
			break;
		}

		// Return failed result with retry information.
		if ( ! $result ) {
			$result = new \WP_Error( 'wp_mcp_ai_error', __( 'Command execution failed after retries.', 'nvoos-content-graph-ai-platform' ) );
		}

		return array(
			'result'        => $result,
			'params'        => $resolved_params,
			'retries'       => $retries,
			'retries_maxed' => true,
		);
	}

	/**
	 * Check if a workflow step should be skipped based on conditions.
	 *
	 * @since 1.4.0
	 *
	 * @param array $step Step definition.
	 * @param array $previous_result Previous step result.
	 * @param array $all_results All previous results.
	 * @return bool True if step should be skipped.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Signature contract: not every slash-command consumes every execute() parameter.
	protected function should_skip_step( $step, $previous_result, $all_results ) {
		if ( ! isset( $step['condition'] ) ) {
			return false;
		}

		$condition = $step['condition'];

		// If condition: check if previous step was successful.
		if ( isset( $condition['if_success'] ) && $condition['if_success'] ) {
			if ( ! $previous_result || is_wp_error( $previous_result ) || ! isset( $previous_result['success'] ) || ! $previous_result['success'] ) {
				return true;
			}
		}

		// If not condition: check if previous step failed.
		if ( isset( $condition['if_failure'] ) && $condition['if_failure'] ) {
			if ( $previous_result && ! is_wp_error( $previous_result ) && isset( $previous_result['success'] ) && $previous_result['success'] ) {
				return true;
			}
		}

		// Field equals condition.
		if ( isset( $condition['field'] ) && isset( $condition['equals'] ) ) {
			$field = $condition['field'];
			if ( $previous_result && isset( $previous_result['data'][ $field ] ) ) {
				if ( $previous_result['data'][ $field ] !== $condition['equals'] ) {
					return true;
				}
			}
		}

		// Field not equals condition.
		if ( isset( $condition['field'] ) && isset( $condition['not_equals'] ) ) {
			$field = $condition['field'];
			if ( $previous_result && isset( $previous_result['data'][ $field ] ) ) {
				if ( $previous_result['data'][ $field ] === $condition['not_equals'] ) {
					return true;
				}
			}
		}

		// Greater than condition.
		if ( isset( $condition['field'] ) && isset( $condition['greater_than'] ) ) {
			$field = $condition['field'];
			if ( $previous_result && isset( $previous_result['data'][ $field ] ) ) {
				if ( $previous_result['data'][ $field ] <= $condition['greater_than'] ) {
					return true;
				}
			}
		}

		// Less than condition.
		if ( isset( $condition['field'] ) && isset( $condition['less_than'] ) ) {
			$field = $condition['field'];
			if ( $previous_result && isset( $previous_result['data'][ $field ] ) ) {
				if ( $previous_result['data'][ $field ] >= $condition['less_than'] ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Execute a fallback step on error.
	 *
	 * @since 1.4.0
	 *
	 * @param string $command Fallback command.
	 * @param array  $workflow_params Workflow parameters.
	 * @param array  $previous_result Previous step result.
	 * @param array  $error_info Error information from failed step.
	 * @param array  $context Execution context.
	 * @return array Fallback result.
	 */
	protected function execute_fallback_step( $command, $workflow_params, $previous_result, $error_info, $context ) {
		// Build command with error context.
		$command_string = '/' . $command;

		// Pass error information to fallback command if it can use it.
		if ( isset( $error_info['error'] ) ) {
			$command_string .= " --error=\"{$error_info['error']}\"";
		}

		return $this->handler->execute( $command_string, $context );
	}

	/**
	 * Initialize execution state for a workflow.
	 *
	 * @since 1.4.0
	 *
	 * @param string $execution_id Execution ID.
	 * @param string $workflow_name Workflow name.
	 * @param array  $params Workflow parameters.
	 * @return void
	 */
	protected function init_execution_state( $execution_id, $workflow_name, $params ) {
		$this->execution_state[ $execution_id ] = array(
			'workflow'   => $workflow_name,
			'params'     => $params,
			'started_at' => current_time( 'mysql' ),
			'steps'      => array(),
		);

		// Save to database for persistence across requests.
		$saved_states                  = get_option( 'wp_mcp_ai_workflow_states', array() );
		$saved_states[ $execution_id ] = $this->execution_state[ $execution_id ];
		update_option( 'wp_mcp_ai_workflow_states', $saved_states );
	}

	/**
	 * Update execution state for a workflow step.
	 *
	 * @since 1.4.0
	 *
	 * @param string $execution_id Execution ID.
	 * @param int    $step_number Step number.
	 * @param array  $step_result Step result.
	 * @return void
	 */
	protected function update_execution_state( $execution_id, $step_number, $step_result ) {
		if ( ! isset( $this->execution_state[ $execution_id ] ) ) {
			return;
		}

		$this->execution_state[ $execution_id ]['steps'][ $step_number ] = array(
			'completed_at' => current_time( 'mysql' ),
			'success'      => ! is_wp_error( $step_result['result'] ) && isset( $step_result['result']['success'] ) ? $step_result['result']['success'] : false,
			'retries'      => isset( $step_result['retries'] ) ? $step_result['retries'] : 0,
		);

		// Update database.
		$saved_states                  = get_option( 'wp_mcp_ai_workflow_states', array() );
		$saved_states[ $execution_id ] = $this->execution_state[ $execution_id ];
		update_option( 'wp_mcp_ai_workflow_states', $saved_states );
	}

	/**
	 * Clear execution state for a completed workflow.
	 *
	 * @since 1.4.0
	 *
	 * @param string $execution_id Execution ID.
	 * @return void
	 */
	protected function clear_execution_state( $execution_id ) {
		unset( $this->execution_state[ $execution_id ] );

		// Remove from database.
		$saved_states = get_option( 'wp_mcp_ai_workflow_states', array() );
		unset( $saved_states[ $execution_id ] );
		update_option( 'wp_mcp_ai_workflow_states', $saved_states );
	}

	/**
	 * Resume a workflow from saved state.
	 *
	 * @since 1.4.0
	 *
	 * @param string $execution_id Execution ID.
	 * @param array  $context Execution context.
	 * @return array Workflow result.
	 */
	public function resume_workflow( $execution_id, $context = array() ) {
		$saved_states = get_option( 'wp_mcp_ai_workflow_states', array() );

		if ( ! isset( $saved_states[ $execution_id ] ) ) {
			return new \WP_Error( 'wp_mcp_ai_error', __( 'Workflow execution not found.', 'nvoos-content-graph-ai-platform' ) );
		}

		$state               = $saved_states[ $execution_id ];
		$last_completed_step = count( $state['steps'] );

		// Resume from next step.
		return $this->execute_workflow(
			$state['workflow'],
			$state['params'],
			$context,
			array(
				'resume_from_step' => $last_completed_step + 1,
				'execution_id'     => $execution_id,
			)
		);
	}

	/**
	 * Get available workflows.
	 *
	 * @since 1.3.0
	 *
	 * @return array Available workflows.
	 */
	public function get_workflows() {
		$workflows = array();

		foreach ( $this->workflows as $slug => $workflow ) {
			$workflows[ $slug ] = array(
				'slug'        => $slug,
				'name'        => $workflow['name'],
				'description' => $workflow['description'],
				'steps'       => count( $workflow['steps'] ),
			);
		}

		return $workflows;
	}

	/**
	 * Get workflow definition.
	 *
	 * @since 1.3.0
	 *
	 * @param string $workflow_name Workflow name.
	 * @return array|null Workflow definition or null if not found.
	 */
	public function get_workflow( $workflow_name ) {
		return isset( $this->workflows[ $workflow_name ] ) ? $this->workflows[ $workflow_name ] : null;
	}

	/**
	 * Create custom workflow.
	 *
	 * @since 1.3.0
	 *
	 * @param string $name Workflow name.
	 * @param array  $definition Workflow definition.
	 * @return bool True on success, false on failure.
	 */
	public function create_workflow( $name, $definition ) {
		// Validate workflow definition.
		if ( empty( $definition['steps'] ) || ! is_array( $definition['steps'] ) ) {
			return false;
		}

		$slug = sanitize_key( $name );

		$this->workflows[ $slug ] = array(
			'name'        => $definition['name'] ?? $name,
			'description' => $definition['description'] ?? '',
			'steps'       => $definition['steps'],
		);

		// Save to database for persistence.
		$saved_workflows          = get_option( 'wp_mcp_ai_custom_workflows', array() );
		$saved_workflows[ $slug ] = $this->workflows[ $slug ];
		update_option( 'wp_mcp_ai_custom_workflows', $saved_workflows );

		return true;
	}

	/**
	 * Delete custom workflow.
	 *
	 * @since 1.3.0
	 *
	 * @param string $workflow_name Workflow name.
	 * @return bool True on success, false on failure.
	 */
	public function delete_workflow( $workflow_name ) {
		$saved_workflows = get_option( 'wp_mcp_ai_custom_workflows', array() );

		if ( ! isset( $saved_workflows[ $workflow_name ] ) ) {
			return false;
		}

		unset( $saved_workflows[ $workflow_name ] );
		update_option( 'wp_mcp_ai_custom_workflows', $saved_workflows );

		if ( isset( $this->workflows[ $workflow_name ] ) ) {
			unset( $this->workflows[ $workflow_name ] );
		}

		return true;
	}
}
