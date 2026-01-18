<?php
/**
 * Executor Agent Role
 *
 * Performs specific operations using available tools.
 * Inspired by DeepSeek V4's specialized execution patterns.
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Executor Agent Role class
 *
 * Responsible for:
 * - Executing assigned tasks and subtasks
 * - Using specialized tools effectively
 * - Returning structured results
 * - Handling errors gracefully
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Agent_Role_Executor extends WP_MCP_AI_Agent_Role_Base {

	/**
	 * Tool registry instance
	 *
	 * @var WP_MCP_AI_Tool_Registry
	 */
	protected $tool_registry;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->role_type        = 'executor';
		$this->role_name        = __( 'Executor', 'mcp-ai-wpoos' );
		$this->role_description = __( 'Executes specific tasks using available tools and returns structured results.', 'mcp-ai-wpoos' );

		$this->capabilities = array(
			'requires-tools',
			'autonomous',
		);

		// Executor agents benefit from all available tools.
		$this->recommended_tools = array(
			'web_search',
			'crawl4ai',
			'get_recent_posts',
			'create_post',
			'save_post',
		);

		// Initialize tool registry.
		$this->tool_registry = WP_MCP_AI_Tool_Registry::instance();
	}

	/**
	 * Get recommended system prompt additions for this role
	 *
	 * @return string Additional system prompt text.
	 */
	public function get_system_prompt_additions() {
		return __( 'You are an Executor agent responsible for performing specific tasks using the tools available to you. When assigned a task, focus on executing it efficiently and accurately. Use the appropriate tools for the job and return structured, detailed results. If you encounter errors, handle them gracefully and provide clear error information.', 'mcp-ai-wpoos' );
	}

	/**
	 * Execute an assigned task
	 *
	 * Performs the task using available tools and returns results.
	 *
	 * @param array $task Task data including description, type, and parameters.
	 * @param array $context Execution context including assistant_id, user_id, etc.
	 * @return array|WP_Error Task result or error.
	 */
	public function execute_role_task( $task, $context ) {
		// Validate inputs.
		$task_validation = $this->validate_task( $task );
		if ( is_wp_error( $task_validation ) ) {
			return $task_validation;
		}

		$context_validation = $this->validate_context( $context );
		if ( is_wp_error( $context_validation ) ) {
			return $context_validation;
		}

		$this->log(
			'Executor agent executing task',
			'info',
			array(
				'task_description' => $task['description'],
				'task_type'        => isset( $task['type'] ) ? $task['type'] : 'unknown',
				'assistant_id'     => $context['assistant_id'],
			)
		);

		$start_time = microtime( true );

		// Execute the task.
		$result = $this->execute_task_logic( $task, $context );

		$execution_time = microtime( true ) - $start_time;

		// Wrap result with metadata.
		$execution_result = array(
			'task_id'        => isset( $task['id'] ) ? $task['id'] : uniqid( 'exec_', true ),
			'status'         => is_wp_error( $result ) ? 'failed' : 'completed',
			'result'         => $result,
			'execution_time' => $execution_time,
			'completed_at'   => current_time( 'mysql' ),
		);

		if ( is_wp_error( $result ) ) {
			$this->log(
				'Task execution failed',
				'error',
				array(
					'task_id'   => $execution_result['task_id'],
					'error'     => $result->get_error_message(),
					'exec_time' => $execution_time,
				)
			);
		} else {
			$this->log(
				'Task execution completed',
				'info',
				array(
					'task_id'   => $execution_result['task_id'],
					'exec_time' => $execution_time,
				)
			);
		}

		return $execution_result;
	}

	/**
	 * Execute the core task logic
	 *
	 * Override this method in subclasses for specialized execution.
	 *
	 * @param array $task Task data.
	 * @param array $context Execution context.
	 * @return mixed|WP_Error Execution result or error.
	 */
	protected function execute_task_logic( $task, $context ) {
		// Default implementation - in production this would intelligently
		// select and execute appropriate tools based on task type.

		$task_type = isset( $task['type'] ) ? $task['type'] : 'generic';

		switch ( $task_type ) {
			case 'research':
				return $this->execute_research_task( $task, $context );

			case 'analysis':
				return $this->execute_analysis_task( $task, $context );

			case 'creation':
				return $this->execute_creation_task( $task, $context );

			default:
				return array(
					'message'     => __( 'Task received and acknowledged', 'mcp-ai-wpoos' ),
					'task_type'   => $task_type,
					'description' => $task['description'],
				);
		}
	}

	/**
	 * Execute a research task
	 *
	 * Executes research using web_search or crawl4ai, analyzes results, and saves findings.
	 *
	 * @param array $task Task data.
	 * @param array $context Execution context.
	 * @return array Research results with gathered data and saved content.
	 */
	protected function execute_research_task( $task, $context ) {
		$description = isset( $task['description'] ) ? $task['description'] : '';
		$parameters  = isset( $task['parameters'] ) ? $task['parameters'] : array();
		$query       = isset( $parameters['query'] ) ? $parameters['query'] : $description;

		$results = array(
			'type'        => 'research',
			'description' => $description,
			'query'       => $query,
			'steps'       => array(),
		);

		// Step 1: Search for information.
		$search_tool = $this->tool_registry->is_tool_registered( 'web_search' ) ? 'web_search' : 'search_content';
		
		$search_result = $this->execute_tool_with_context(
			$search_tool,
			array(
				'query' => $query,
				'limit' => isset( $parameters['limit'] ) ? $parameters['limit'] : 10,
			),
			$context
		);

		$results['steps'][] = array(
			'step'   => 1,
			'action' => 'search_and_gather',
			'tool'   => $search_tool,
			'status' => is_wp_error( $search_result ) ? 'failed' : 'completed',
			'result' => $search_result,
		);

		if ( is_wp_error( $search_result ) ) {
			$results['status'] = 'partial';
			$results['error']  = $search_result->get_error_message();
			return $results;
		}

		// Step 2: Analyze sources (extract key information).
		$sources = array();
		if ( isset( $search_result['results'] ) && is_array( $search_result['results'] ) ) {
			foreach ( $search_result['results'] as $result ) {
				$sources[] = array(
					'title'   => isset( $result['title'] ) ? $result['title'] : '',
					'url'     => isset( $result['url'] ) ? $result['url'] : '',
					'snippet' => isset( $result['snippet'] ) ? $result['snippet'] : '',
				);
			}
		}

		$results['steps'][] = array(
			'step'    => 2,
			'action'  => 'analyze_sources',
			'status'  => 'completed',
			'sources' => $sources,
			'count'   => count( $sources ),
		);

		// Step 3: Synthesize findings (optionally save as post).
		$synthesis = array(
			'query'         => $query,
			'sources_found' => count( $sources ),
			'sources'       => $sources,
			'summary'       => sprintf(
				/* translators: 1: query, 2: number of sources */
				__( 'Research on "%1$s" yielded %2$d sources.', 'mcp-ai-wpoos' ),
				$query,
				count( $sources )
			),
		);

		// Save results if requested.
		if ( ! empty( $parameters['save_results'] ) && count( $sources ) > 0 ) {
			$post_title   = isset( $parameters['title'] ) ? $parameters['title'] : sprintf( __( 'Research: %s', 'mcp-ai-wpoos' ), $query );
			$post_content = $this->format_research_content( $query, $sources );

			$save_result = $this->execute_tool_with_context(
				'save_post',
				array(
					'title'   => $post_title,
					'content' => $post_content,
					'status'  => 'draft',
				),
				$context
			);

			$synthesis['saved'] = ! is_wp_error( $save_result );
			if ( ! is_wp_error( $save_result ) && isset( $save_result['post_id'] ) ) {
				$synthesis['post_id'] = $save_result['post_id'];
			}
		}

		$results['steps'][] = array(
			'step'      => 3,
			'action'    => 'synthesize',
			'status'    => 'completed',
			'synthesis' => $synthesis,
		);

		$results['status'] = 'completed';
		return $results;
	}

	/**
	 * Format research content for saving
	 *
	 * @param string $query Research query.
	 * @param array  $sources Array of sources.
	 * @return string Formatted HTML content.
	 */
	protected function format_research_content( $query, $sources ) {
		$content = '<h2>' . esc_html( sprintf( __( 'Research Results: %s', 'mcp-ai-wpoos' ), $query ) ) . '</h2>';
		$content .= '<p>' . esc_html( sprintf( __( 'Found %d relevant sources:', 'mcp-ai-wpoos' ), count( $sources ) ) ) . '</p>';
		$content .= '<ol>';

		foreach ( $sources as $source ) {
			$title   = isset( $source['title'] ) ? $source['title'] : __( 'Untitled', 'mcp-ai-wpoos' );
			$url     = isset( $source['url'] ) ? $source['url'] : '';
			$snippet = isset( $source['snippet'] ) ? $source['snippet'] : '';

			$content .= '<li>';
			if ( $url ) {
				$content .= '<strong><a href="' . esc_url( $url ) . '">' . esc_html( $title ) . '</a></strong>';
			} else {
				$content .= '<strong>' . esc_html( $title ) . '</strong>';
			}
			if ( $snippet ) {
				$content .= '<p>' . esc_html( $snippet ) . '</p>';
			}
			$content .= '</li>';
		}

		$content .= '</ol>';
		return $content;
	}

	/**
	 * Execute an analysis task
	 *
	 * Executes data analysis using get_recent_posts or search_content, creates visualizations.
	 *
	 * @param array $task Task data.
	 * @param array $context Execution context.
	 * @return array Analysis results with data and visualizations.
	 */
	protected function execute_analysis_task( $task, $context ) {
		$description = isset( $task['description'] ) ? $task['description'] : '';
		$parameters  = isset( $task['parameters'] ) ? $task['parameters'] : array();

		$results = array(
			'type'        => 'analysis',
			'description' => $description,
			'steps'       => array(),
		);

		// Step 1: Gather data to analyze.
		$data_source = isset( $parameters['data_source'] ) ? $parameters['data_source'] : 'get_recent_posts';
		
		if ( 'get_recent_posts' === $data_source ) {
			$data_result = $this->execute_tool_with_context(
				'get_recent_posts',
				array(
					'post_type' => isset( $parameters['post_type'] ) ? $parameters['post_type'] : 'post',
					'limit'     => isset( $parameters['limit'] ) ? $parameters['limit'] : 20,
				),
				$context
			);
		} else {
			$data_result = $this->execute_tool_with_context(
				'search_content',
				array(
					'query'     => isset( $parameters['query'] ) ? $parameters['query'] : '',
					'post_type' => isset( $parameters['post_type'] ) ? $parameters['post_type'] : 'post',
				),
				$context
			);
		}

		$results['steps'][] = array(
			'step'   => 1,
			'action' => 'gather_data',
			'tool'   => $data_source,
			'status' => is_wp_error( $data_result ) ? 'failed' : 'completed',
			'result' => $data_result,
		);

		if ( is_wp_error( $data_result ) ) {
			$results['status'] = 'partial';
			$results['error']  = $data_result->get_error_message();
			return $results;
		}

		// Step 2: Analyze the data.
		$posts = isset( $data_result['posts'] ) ? $data_result['posts'] : array();
		$analysis = $this->analyze_data( $posts, $parameters );

		$results['steps'][] = array(
			'step'     => 2,
			'action'   => 'analyze_data',
			'status'   => 'completed',
			'analysis' => $analysis,
		);

		// Step 3: Create visualization (if chart tool available).
		if ( $this->tool_registry->is_tool_registered( 'create_chart' ) && ! empty( $analysis['chart_data'] ) ) {
			$chart_result = $this->execute_tool_with_context(
				'create_chart',
				array(
					'type' => isset( $parameters['chart_type'] ) ? $parameters['chart_type'] : 'bar',
					'data' => $analysis['chart_data'],
					'options' => array(
						'title' => isset( $parameters['chart_title'] ) ? $parameters['chart_title'] : __( 'Analysis Results', 'mcp-ai-wpoos' ),
					),
				),
				$context
			);

			$results['steps'][] = array(
				'step'   => 3,
				'action' => 'create_visualization',
				'tool'   => 'create_chart',
				'status' => is_wp_error( $chart_result ) ? 'failed' : 'completed',
				'result' => $chart_result,
			);

			if ( ! is_wp_error( $chart_result ) ) {
				$analysis['chart'] = $chart_result;
			}
		} else {
			$results['steps'][] = array(
				'step'   => 3,
				'action' => 'create_visualization',
				'status' => 'skipped',
				'reason' => __( 'Chart tool not available or no chart data', 'mcp-ai-wpoos' ),
			);
		}

		$results['analysis'] = $analysis;
		$results['status'] = 'completed';
		return $results;
	}

	/**
	 * Analyze data from posts
	 *
	 * @param array $posts Array of post data.
	 * @param array $parameters Analysis parameters.
	 * @return array Analysis results.
	 */
	protected function analyze_data( $posts, $parameters ) {
		$analysis = array(
			'total_posts' => count( $posts ),
			'post_types'  => array(),
			'date_range'  => array(),
			'summary'     => '',
		);

		if ( empty( $posts ) ) {
			$analysis['summary'] = __( 'No posts found for analysis.', 'mcp-ai-wpoos' );
			return $analysis;
		}

		// Analyze post types distribution.
		foreach ( $posts as $post ) {
			$post_type = isset( $post['post_type'] ) ? $post['post_type'] : 'unknown';
			if ( ! isset( $analysis['post_types'][ $post_type ] ) ) {
				$analysis['post_types'][ $post_type ] = 0;
			}
			++$analysis['post_types'][ $post_type ];
		}

		// Prepare chart data.
		$analysis['chart_data'] = array(
			'labels'   => array_keys( $analysis['post_types'] ),
			'datasets' => array(
				array(
					'label' => __( 'Posts by Type', 'mcp-ai-wpoos' ),
					'data'  => array_values( $analysis['post_types'] ),
				),
			),
		);

		// Generate summary.
		$analysis['summary'] = sprintf(
			/* translators: %d: number of posts */
			__( 'Analyzed %d posts across %d post types.', 'mcp-ai-wpoos' ),
			$analysis['total_posts'],
			count( $analysis['post_types'] )
		);

		return $analysis;
	}

	/**
	 * Execute a creation task
	 *
	 * Provides execution plan for content/resource creation tasks.
	 *
	 * @param array $task Task data.
	 * @param array $context Execution context.
	 * @return array Creation execution plan with tool recommendations.
	 */
	protected function execute_creation_task( $task, $context ) {
		$description = isset( $task['description'] ) ? $task['description'] : '';
		$parameters  = isset( $task['parameters'] ) ? $task['parameters'] : array();

		// Build execution plan for creation.
		$execution_plan = array(
			'type'        => 'creation',
			'description' => $description,
			'plan'        => array(
				'steps'                => array(
					array(
						'step'        => 1,
						'action'      => 'research_content',
						'tools'       => array( 'web_search', 'get_recent_posts' ),
						'description' => __( 'Research and gather information for creation', 'mcp-ai-wpoos' ),
					),
					array(
						'step'        => 2,
						'action'      => 'create_draft',
						'tools'       => array( 'create_post' ),
						'description' => __( 'Create initial draft or prototype', 'mcp-ai-wpoos' ),
					),
					array(
						'step'        => 3,
						'action'      => 'refine_and_publish',
						'tools'       => array( 'save_post' ),
						'description' => __( 'Refine and finalize the created content', 'mcp-ai-wpoos' ),
					),
				),
				'estimated_tool_calls' => 3,
				'parallel_execution'   => false,
			),
		);

		// Add task-specific parameters.
		if ( ! empty( $parameters['content_type'] ) ) {
			$execution_plan['content_type'] = $parameters['content_type'];
		}
		if ( ! empty( $parameters['requirements'] ) ) {
			$execution_plan['requirements'] = $parameters['requirements'];
		}

		return $execution_plan;
	}

	/**
	 * Execute a tool with proper context and error handling
	 *
	 * @param string $tool_slug Tool identifier.
	 * @param array  $arguments Tool arguments.
	 * @param array  $context Execution context.
	 * @return array|WP_Error Tool execution result or error.
	 */
	protected function execute_tool_with_context( $tool_slug, $arguments, $context ) {
		// Ensure tool registry is available.
		if ( ! $this->tool_registry ) {
			return new WP_Error(
				'wp_mcp_ai_no_tool_registry',
				__( 'Tool registry not available for executor agent.', 'mcp-ai-wpoos' )
			);
		}

		// Check if tool exists.
		if ( ! $this->tool_registry->is_tool_registered( $tool_slug ) ) {
			return new WP_Error(
				'wp_mcp_ai_tool_not_found',
				sprintf(
					/* translators: %s: tool slug */
					__( 'Tool "%s" not found in registry.', 'mcp-ai-wpoos' ),
					$tool_slug
				)
			);
		}

		$this->log(
			sprintf( 'Executing tool: %s', $tool_slug ),
			'debug',
			array(
				'tool'      => $tool_slug,
				'arguments' => $arguments,
			)
		);

		// Execute the tool.
		$result = $this->tool_registry->execute_tool( $tool_slug, $arguments, $context );

		// Log result.
		if ( is_wp_error( $result ) ) {
			$this->log(
				sprintf( 'Tool execution failed: %s', $tool_slug ),
				'error',
				array(
					'tool'  => $tool_slug,
					'error' => $result->get_error_message(),
				)
			);
		} else {
			$this->log(
				sprintf( 'Tool execution succeeded: %s', $tool_slug ),
				'debug',
				array(
					'tool'   => $tool_slug,
					'result' => is_array( $result ) && isset( $result['message'] ) ? $result['message'] : 'Success',
				)
			);
		}

		return $result;
	}
}
