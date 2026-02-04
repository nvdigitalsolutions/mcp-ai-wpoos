<?php
/**
 * Slash Command Workflow Orchestrator
 *
 * Handles chaining and orchestration of multiple slash commands.
 *
 * @package WP_MCP_AI
 * @subpackage Slash_Commands
 * @since 1.3.0
 */

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
class WP_MCP_AI_Slash_Command_Workflow_Orchestrator {

	/**
	 * Command handler instance.
	 *
	 * @var WP_MCP_AI_Slash_Command_Handler
	 */
	protected $handler;

	/**
	 * Workflow definitions.
	 *
	 * @var array
	 */
	protected $workflows = array();

	/**
	 * Constructor.
	 *
	 * @param WP_MCP_AI_Slash_Command_Handler $handler Command handler instance.
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
			'content_pipeline' => array(
				'name'        => __( 'Content Publishing Pipeline', 'mcp-ai-wpoos' ),
				'description' => __( 'Complete workflow for creating and publishing content', 'mcp-ai-wpoos' ),
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
			'ai_tool_setup' => array(
				'name'        => __( 'AI Tool Creation & Setup', 'mcp-ai-wpoos' ),
				'description' => __( 'Create and configure a new AI tool', 'mcp-ai-wpoos' ),
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
			'ecommerce_product_launch' => array(
				'name'        => __( 'E-Commerce Product Launch', 'mcp-ai-wpoos' ),
				'description' => __( 'Complete workflow for launching a new product', 'mcp-ai-wpoos' ),
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
						'params'  => array( 'platform' => 'all', 'content' => '{announcement}' ),
					),
				),
			),
			'abandoned_cart_campaign' => array(
				'name'        => __( 'Abandoned Cart Recovery Campaign', 'mcp-ai-wpoos' ),
				'description' => __( 'Automated workflow to identify and recover abandoned carts', 'mcp-ai-wpoos' ),
				'steps'       => array(
					array(
						'command' => 'abandoned-recover',
						'params'  => array( 'action' => 'identify' ),
					),
					array(
						'command' => 'abandoned-recover',
						'params'  => array( 'action' => 'recover', 'send-email' => true ),
					),
					array(
						'command' => 'ecom-analytics',
						'params'  => array( 'metrics' => 'recovery-rate,revenue' ),
					),
				),
			),
			'social_media_campaign' => array(
				'name'        => __( 'Multi-Platform Social Media Campaign', 'mcp-ai-wpoos' ),
				'description' => __( 'Create and publish content across all social platforms', 'mcp-ai-wpoos' ),
				'steps'       => array(
					array(
						'command' => 'hashtag-suggest',
						'params'  => array( 'content' => '{post_content}', 'count' => 10 ),
					),
					array(
						'command' => 'social-post',
						'params'  => array(
							'content'   => '{post_content}',
							'platforms' => 'facebook,twitter,instagram,linkedin',
						),
					),
					array(
						'command' => 'social-analytics',
						'params'  => array( 'period' => 'today' ),
					),
				),
			),
			'video_marketing_workflow' => array(
				'name'        => __( 'Video Marketing Production', 'mcp-ai-wpoos' ),
				'description' => __( 'Complete video creation and distribution workflow', 'mcp-ai-wpoos' ),
				'steps'       => array(
					array(
						'command' => 'video-template',
						'params'  => array( 'template' => '{template_name}', 'input' => '{video_assets}' ),
					),
					array(
						'command' => 'video-subtitle',
						'params'  => array( 'video-id' => '{previous.video_id}', 'auto-generate' => true ),
					),
					array(
						'command' => 'social-post',
						'params'  => array(
							'content'   => '{video_description}',
							'platforms' => 'youtube,facebook,instagram',
							'media'     => '{previous.video_id}',
						),
					),
				),
			),
			'ecommerce_upsell_optimization' => array(
				'name'        => __( 'E-Commerce Upsell Optimization', 'mcp-ai-wpoos' ),
				'description' => __( 'Analyze and optimize product upsells and cross-sells', 'mcp-ai-wpoos' ),
				'steps'       => array(
					array(
						'command' => 'ecom-analytics',
						'params'  => array( 'metrics' => 'top-products', 'period' => 'month' ),
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
	 *
	 * @param string $workflow_name Workflow name.
	 * @param array  $params Workflow parameters.
	 * @param array  $context Execution context.
	 * @return array Workflow result.
	 */
	public function execute_workflow( $workflow_name, $params = array(), $context = array() ) {
		if ( ! isset( $this->workflows[ $workflow_name ] ) ) {
			return array(
				'success' => false,
				'error'   => 'workflow_not_found',
				'message' => sprintf(
					/* translators: %s: workflow name */
					__( 'Workflow "%s" not found.', 'mcp-ai-wpoos' ),
					$workflow_name
				),
			);
		}

		$workflow = $this->workflows[ $workflow_name ];
		$results  = array();
		$previous_result = null;

		foreach ( $workflow['steps'] as $index => $step ) {
			// Resolve parameters using previous results.
			$resolved_params = $this->resolve_parameters( $step['params'], $params, $previous_result );

			// Build command string.
			$command_string = '/' . $step['command'];
			foreach ( $resolved_params as $key => $value ) {
				if ( is_numeric( $key ) ) {
					// Positional parameter.
					$command_string .= " {$value}";
				} else {
					// Named parameter.
					$command_string .= " --{$key}=\"{$value}\"";
				}
			}

			// Execute command.
			$result = $this->handler->execute( $command_string, $context );

			// Store result.
			$results[] = array(
				'step'    => $index + 1,
				'command' => $step['command'],
				'params'  => $resolved_params,
				'result'  => $result,
			);

			// Check for errors.
			if ( is_array( $result ) && ! $result['success'] ) {
				return array(
					'success'  => false,
					'error'    => 'workflow_step_failed',
					'message'  => sprintf(
						/* translators: 1: step number, 2: command name */
						__( 'Workflow failed at step %1$d (%2$s).', 'mcp-ai-wpoos' ),
						$index + 1,
						$step['command']
					),
					'workflow' => $workflow_name,
					'steps'    => $results,
				);
			}

			$previous_result = $result;
		}

		return array(
			'success'  => true,
			'message'  => sprintf(
				/* translators: 1: workflow name, 2: number of steps */
				__( 'Workflow "%1$s" completed successfully (%2$d steps).', 'mcp-ai-wpoos' ),
				$workflow['name'],
				count( $results )
			),
			'workflow' => $workflow_name,
			'steps'    => $results,
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
		$saved_workflows = get_option( 'wp_mcp_ai_custom_workflows', array() );
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
