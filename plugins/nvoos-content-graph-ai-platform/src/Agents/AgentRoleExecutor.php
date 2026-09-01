<?php
/**
 * Executor Agent Role
 *
 * Performs specific operations using available tools.
 * Inspired by DeepSeek V4's specialized execution patterns.
 *
 * @package NvoosContentGraphAiPlatform\Agents
 * @since 1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Agents;

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
class AgentRoleExecutor extends AgentRoleBase {

	/**
	 * Tool registry instance
	 *
	 * @var WP_MCP_AI_Tool_Registry
	 */
	protected $tool_registry;

	/**
	 * Tool failure tracking for circuit breaker pattern
	 *
	 * @var array
	 */
	protected $tool_failure_counts = array();

	/**
	 * Circuit breaker threshold
	 *
	 * @var int
	 */
	protected $circuit_breaker_threshold = 3;

	/**
	 * Cache for tool results
	 *
	 * @var array
	 */
	protected $tool_cache = array();

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->role_type        = 'executor';
		$this->role_name        = __( 'Executor', 'nvoos-content-graph-ai-platform' );
		$this->role_description = __( 'Executes specific tasks using available tools and returns structured results.', 'nvoos-content-graph-ai-platform' );

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

		// Initialize tool registry. In standalone mode the base plugin's
		// tool registry is absent, so degrade to null — tool-backed task
		// types then fail fast with a clear error instead of a fatal.
		$this->tool_registry = ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Tool_Registry' ) )
			? \WP_MCP_AI_Tool_Registry::get_instance()
			: null;
	}

	/**
	 * Get recommended system prompt additions for this role
	 *
	 * @return string Additional system prompt text.
	 */
	public function get_system_prompt_additions() {
		return __( 'You are an Executor agent responsible for performing specific tasks using the tools available to you. When assigned a task, focus on executing it efficiently and accurately. Use the appropriate tools for the job and return structured, detailed results. If you encounter errors, handle them gracefully and provide clear error information.', 'nvoos-content-graph-ai-platform' );
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
		if ( null === $this->tool_registry ) {
			return new \WP_Error(
				'wp_mcp_ai_no_tool_registry',
				__( 'Tool registry is unavailable in standalone mode.', 'nvoos-content-graph-ai-platform' )
			);
		}

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
					'message'     => __( 'Task received and acknowledged', 'nvoos-content-graph-ai-platform' ),
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
				__( 'Research on "%1$s" yielded %2$d sources.', 'nvoos-content-graph-ai-platform' ),
				$query,
				count( $sources )
			),
		);

		// Save results if requested.
		if ( ! empty( $parameters['save_results'] ) && count( $sources ) > 0 ) {
			/* translators: %s: Research query */
			$post_title   = isset( $parameters['title'] ) ? $parameters['title'] : sprintf( __( 'Research: %s', 'nvoos-content-graph-ai-platform' ), $query );
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
		/* translators: %s: Research query */
		$content = '<h2>' . esc_html( sprintf( __( 'Research Results: %s', 'nvoos-content-graph-ai-platform' ), $query ) ) . '</h2>';
		/* translators: %d: Number of sources found */
		$content .= '<p>' . esc_html( sprintf( __( 'Found %d relevant sources:', 'nvoos-content-graph-ai-platform' ), count( $sources ) ) ) . '</p>';
		$content .= '<ol>';

		foreach ( $sources as $source ) {
			$title   = isset( $source['title'] ) ? $source['title'] : __( 'Untitled', 'nvoos-content-graph-ai-platform' );
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
		$posts    = isset( $data_result['posts'] ) ? $data_result['posts'] : array();
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
					'type'    => isset( $parameters['chart_type'] ) ? $parameters['chart_type'] : 'bar',
					'data'    => $analysis['chart_data'],
					'options' => array(
						'title' => isset( $parameters['chart_title'] ) ? $parameters['chart_title'] : __( 'Analysis Results', 'nvoos-content-graph-ai-platform' ),
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
				'reason' => __( 'Chart tool not available or no chart data', 'nvoos-content-graph-ai-platform' ),
			);
		}

		$results['analysis'] = $analysis;
		$results['status']   = 'completed';
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
			$analysis['summary'] = __( 'No posts found for analysis.', 'nvoos-content-graph-ai-platform' );
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
					'label' => __( 'Posts by Type', 'nvoos-content-graph-ai-platform' ),
					'data'  => array_values( $analysis['post_types'] ),
				),
			),
		);

		// Generate summary.
		$analysis['summary'] = sprintf(
			/* translators: %d: number of posts */
			__( 'Analyzed %1$d posts across %2$d post types.', 'nvoos-content-graph-ai-platform' ),
			$analysis['total_posts'],
			count( $analysis['post_types'] )
		);

		return $analysis;
	}

	/**
	 * Execute a creation task
	 *
	 * Executes content creation using save_post/create_post, optionally with research.
	 *
	 * @param array $task Task data.
	 * @param array $context Execution context.
	 * @return array Creation results with created content IDs.
	 */
	protected function execute_creation_task( $task, $context ) {
		$description = isset( $task['description'] ) ? $task['description'] : '';
		$parameters  = isset( $task['parameters'] ) ? $task['parameters'] : array();

		$results = array(
			'type'        => 'creation',
			'description' => $description,
			'steps'       => array(),
		);

		// Step 1: Research content (optional, if research enabled).
		if ( ! empty( $parameters['research'] ) && $this->tool_registry->is_tool_registered( 'web_search' ) ) {
			$research_result = $this->execute_tool_with_context(
				'web_search',
				array(
					'query' => isset( $parameters['research_query'] ) ? $parameters['research_query'] : $description,
					'limit' => 5,
				),
				$context
			);

			$results['steps'][] = array(
				'step'   => 1,
				'action' => 'research_content',
				'tool'   => 'web_search',
				'status' => is_wp_error( $research_result ) ? 'failed' : 'completed',
				'result' => $research_result,
			);

			// Extract research insights for content.
			if ( ! is_wp_error( $research_result ) && isset( $research_result['results'] ) ) {
				$parameters['research_insights'] = $research_result['results'];
			}
		} else {
			$results['steps'][] = array(
				'step'   => 1,
				'action' => 'research_content',
				'status' => 'skipped',
				'reason' => __( 'Research not requested or web_search tool unavailable', 'nvoos-content-graph-ai-platform' ),
			);
		}

		// Step 2: Create content draft.
		$post_title   = isset( $parameters['title'] ) ? $parameters['title'] : $description;
		$post_content = isset( $parameters['content'] ) ? $parameters['content'] : $this->generate_content_from_task( $task, $parameters );
		$post_type    = isset( $parameters['post_type'] ) ? $parameters['post_type'] : 'post';
		$post_status  = isset( $parameters['status'] ) ? $parameters['status'] : 'draft';

		$create_result = $this->execute_tool_with_context(
			'save_post',
			array(
				'title'   => $post_title,
				'content' => $post_content,
				'status'  => $post_status,
				'type'    => $post_type,
			),
			$context
		);

		$results['steps'][] = array(
			'step'   => 2,
			'action' => 'create_draft',
			'tool'   => 'save_post',
			'status' => is_wp_error( $create_result ) ? 'failed' : 'completed',
			'result' => $create_result,
		);

		if ( is_wp_error( $create_result ) ) {
			$results['status'] = 'failed';
			$results['error']  = $create_result->get_error_message();
			return $results;
		}

		// Extract post ID from result.
		$post_id = null;
		if ( isset( $create_result['post_id'] ) ) {
			$post_id = $create_result['post_id'];
		} elseif ( isset( $create_result['id'] ) ) {
			$post_id = $create_result['id'];
		}

		// Step 3: Refine and publish (if requested).
		if ( ! empty( $parameters['publish'] ) && $post_id && 'draft' === $post_status ) {
			$publish_result = $this->execute_tool_with_context(
				'save_post',
				array(
					'id'     => $post_id,
					'status' => 'publish',
				),
				$context
			);

			$results['steps'][] = array(
				'step'   => 3,
				'action' => 'refine_and_publish',
				'tool'   => 'save_post',
				'status' => is_wp_error( $publish_result ) ? 'failed' : 'completed',
				'result' => $publish_result,
			);
		} else {
			$results['steps'][] = array(
				'step'   => 3,
				'action' => 'refine_and_publish',
				'status' => 'skipped',
				'reason' => __( 'Publish not requested or post creation failed', 'nvoos-content-graph-ai-platform' ),
			);
		}

		$results['created_content'] = array(
			'post_id'    => $post_id,
			'post_title' => $post_title,
			'post_type'  => $post_type,
			'status'     => ! empty( $parameters['publish'] ) && $post_id ? 'publish' : $post_status,
		);

		$results['status'] = 'completed';
		return $results;
	}

	/**
	 * Generate content from task parameters
	 *
	 * @param array $task Task data.
	 * @param array $parameters Task parameters.
	 * @return string Generated content.
	 */
	protected function generate_content_from_task( $task, $parameters ) {
		$content = '';

		// Add task description as intro.
		if ( ! empty( $task['description'] ) ) {
			$content .= '<p>' . esc_html( $task['description'] ) . '</p>';
		}

		// Add research insights if available.
		if ( ! empty( $parameters['research_insights'] ) ) {
			$content .= '<h2>' . esc_html__( 'Research Insights', 'nvoos-content-graph-ai-platform' ) . '</h2>';
			$content .= '<ul>';
			foreach ( $parameters['research_insights'] as $insight ) {
				if ( isset( $insight['title'] ) ) {
					$content .= '<li>';
					if ( isset( $insight['url'] ) ) {
						$content .= '<a href="' . esc_url( $insight['url'] ) . '">' . esc_html( $insight['title'] ) . '</a>';
					} else {
						$content .= esc_html( $insight['title'] );
					}
					if ( isset( $insight['snippet'] ) ) {
						$content .= '<p>' . esc_html( $insight['snippet'] ) . '</p>';
					}
					$content .= '</li>';
				}
			}
			$content .= '</ul>';
		}

		// Add custom sections if provided.
		if ( ! empty( $parameters['sections'] ) && is_array( $parameters['sections'] ) ) {
			foreach ( $parameters['sections'] as $section ) {
				if ( isset( $section['heading'] ) ) {
					$content .= '<h2>' . esc_html( $section['heading'] ) . '</h2>';
				}
				if ( isset( $section['content'] ) ) {
					$content .= wp_kses_post( $section['content'] );
				}
			}
		}

		return $content;
	}

	/**
	 * Execute a tool with proper context and error handling
	 *
	 * Industry best practices implementation (2026):
	 * - Circuit breaker pattern for failure prevention
	 * - Exponential backoff retry logic
	 * - Tool result caching
	 * - Structured logging with trace IDs
	 *
	 * @param string $tool_slug Tool identifier.
	 * @param array  $arguments Tool arguments.
	 * @param array  $context Execution context.
	 * @return array|WP_Error Tool execution result or error.
	 */
	protected function execute_tool_with_context( $tool_slug, $arguments, $context ) {
		// Ensure tool registry is available.
		if ( ! $this->tool_registry ) {
			return new \WP_Error(
				'wp_mcp_ai_no_tool_registry',
				__( 'Tool registry not available for executor agent.', 'nvoos-content-graph-ai-platform' )
			);
		}

		// Check if tool exists.
		if ( ! $this->tool_registry->is_tool_registered( $tool_slug ) ) {
			return new \WP_Error(
				'wp_mcp_ai_tool_not_found',
				sprintf(
					/* translators: %s: tool slug */
					__( 'Tool "%s" not found in registry.', 'nvoos-content-graph-ai-platform' ),
					$tool_slug
				)
			);
		}

		// Check circuit breaker before executing.
		if ( ! $this->should_execute_tool( $tool_slug ) ) {
			return new \WP_Error(
				'wp_mcp_ai_circuit_breaker_open',
				sprintf(
					/* translators: %s: tool slug */
					__( 'Circuit breaker is open for tool "%s" due to repeated failures. Temporarily blocking execution.', 'nvoos-content-graph-ai-platform' ),
					$tool_slug
				)
			);
		}

		// Try to get cached result first (for cacheable tools).
		$cached_result = $this->get_cached_tool_result( $tool_slug, $arguments );
		if ( false !== $cached_result ) {
			$this->log(
				sprintf( 'Tool result served from cache: %s', $tool_slug ),
				'debug',
				array(
					'tool'     => $tool_slug,
					'trace_id' => $this->get_trace_id( $context ),
				)
			);
			return $cached_result;
		}

		$this->log(
			sprintf( 'Executing tool: %s', $tool_slug ),
			'debug',
			array(
				'tool'      => $tool_slug,
				'arguments' => $arguments,
				'trace_id'  => $this->get_trace_id( $context ),
			)
		);

		// Execute with retry logic.
		$result = $this->execute_with_retry( $tool_slug, $arguments, $context );

		// Handle result.
		if ( is_wp_error( $result ) ) {
			$this->increment_tool_failure_count( $tool_slug );
			$this->log(
				sprintf( 'Tool execution failed: %s', $tool_slug ),
				'error',
				array(
					'tool'          => $tool_slug,
					'error'         => $result->get_error_message(),
					'failure_count' => $this->get_tool_failure_count( $tool_slug ),
					'trace_id'      => $this->get_trace_id( $context ),
				)
			);
		} else {
			$this->reset_tool_failure_count( $tool_slug );
			$this->cache_tool_result( $tool_slug, $arguments, $result );
			$this->log(
				sprintf( 'Tool execution succeeded: %s', $tool_slug ),
				'debug',
				array(
					'tool'     => $tool_slug,
					'result'   => is_array( $result ) && isset( $result['message'] ) ? $result['message'] : 'Success',
					'trace_id' => $this->get_trace_id( $context ),
				)
			);
		}

		return $result;
	}

	/**
	 * Check if tool should execute (circuit breaker pattern)
	 *
	 * @param string $tool_slug Tool identifier.
	 * @return bool True if tool should execute, false if circuit breaker is open.
	 */
	protected function should_execute_tool( $tool_slug ) {
		$failure_count = $this->get_tool_failure_count( $tool_slug );

		if ( $failure_count >= $this->circuit_breaker_threshold ) {
			$this->log(
				'Circuit breaker open for tool',
				'warning',
				array(
					'tool'     => $tool_slug,
					'failures' => $failure_count,
				)
			);
			return false;
		}

		return true;
	}

	/**
	 * Execute tool with exponential backoff retry
	 *
	 * @param string $tool_slug Tool identifier.
	 * @param array  $arguments Tool arguments.
	 * @param array  $context Execution context.
	 * @param int    $max_retries Maximum retry attempts.
	 * @return array|WP_Error Tool execution result or error.
	 */
	protected function execute_with_retry( $tool_slug, $arguments, $context, $max_retries = 3 ) {
		$attempt = 0;
		$delay   = 1; // seconds.

		while ( $attempt < $max_retries ) {
			$result = $this->tool_registry->execute_tool( $tool_slug, $arguments, $context );

			if ( ! is_wp_error( $result ) ) {
				if ( $attempt > 0 ) {
					$this->log(
						sprintf( 'Tool succeeded after %d retries', $attempt ),
						'info',
						array(
							'tool'     => $tool_slug,
							'attempts' => $attempt + 1,
							'trace_id' => $this->get_trace_id( $context ),
						)
					);
				}
				return $result;
			}

			++$attempt;
			if ( $attempt < $max_retries ) {
				$this->log(
					sprintf( 'Tool execution failed, retrying in %d seconds (attempt %d/%d)', $delay, $attempt + 1, $max_retries ),
					'warning',
					array(
						'tool'     => $tool_slug,
						'error'    => $result->get_error_message(),
						'attempt'  => $attempt,
						'trace_id' => $this->get_trace_id( $context ),
					)
				);
				sleep( $delay );
				$delay *= 2; // Exponential backoff.
			}
		}

		return new \WP_Error(
			'wp_mcp_ai_tool_execution_failed_after_retries',
			sprintf(
				/* translators: 1: tool slug, 2: number of retries */
				__( 'Tool "%1$s" execution failed after %2$d retry attempts.', 'nvoos-content-graph-ai-platform' ),
				$tool_slug,
				$max_retries
			)
		);
	}

	/**
	 * Get tool failure count
	 *
	 * @param string $tool_slug Tool identifier.
	 * @return int Failure count.
	 */
	protected function get_tool_failure_count( $tool_slug ) {
		return isset( $this->tool_failure_counts[ $tool_slug ] ) ? $this->tool_failure_counts[ $tool_slug ] : 0;
	}

	/**
	 * Increment tool failure count
	 *
	 * @param string $tool_slug Tool identifier.
	 */
	protected function increment_tool_failure_count( $tool_slug ) {
		if ( ! isset( $this->tool_failure_counts[ $tool_slug ] ) ) {
			$this->tool_failure_counts[ $tool_slug ] = 0;
		}
		++$this->tool_failure_counts[ $tool_slug ];
	}

	/**
	 * Reset tool failure count
	 *
	 * @param string $tool_slug Tool identifier.
	 */
	protected function reset_tool_failure_count( $tool_slug ) {
		$this->tool_failure_counts[ $tool_slug ] = 0;
	}

	/**
	 * Get cached tool result
	 *
	 * @param string $tool_slug Tool identifier.
	 * @param array  $arguments Tool arguments.
	 * @return mixed|false Cached result or false if not found.
	 */
	protected function get_cached_tool_result( $tool_slug, $arguments ) {
		$cache_key = $this->get_tool_cache_key( $tool_slug, $arguments );
		return isset( $this->tool_cache[ $cache_key ] ) ? $this->tool_cache[ $cache_key ] : false;
	}

	/**
	 * Cache tool result
	 *
	 * @param string $tool_slug Tool identifier.
	 * @param array  $arguments Tool arguments.
	 * @param mixed  $result Tool result.
	 */
	protected function cache_tool_result( $tool_slug, $arguments, $result ) {
		// Only cache successful, non-error results.
		if ( is_wp_error( $result ) ) {
			return;
		}

		// Check if tool is cacheable.
		if ( ! $this->is_tool_cacheable( $tool_slug ) ) {
			return;
		}

		$cache_key                      = $this->get_tool_cache_key( $tool_slug, $arguments );
		$this->tool_cache[ $cache_key ] = $result;
	}

	/**
	 * Check if tool results are cacheable
	 *
	 * @param string $tool_slug Tool identifier.
	 * @return bool True if cacheable, false otherwise.
	 */
	protected function is_tool_cacheable( $tool_slug ) {
		// Tools that are safe to cache (read-only, deterministic).
		$cacheable_tools = array(
			'get_recent_posts',
			'search_content',
			'get_post',
			'list_categories',
			'get_user_info',
		);

		return in_array( $tool_slug, $cacheable_tools, true );
	}

	/**
	 * Generate cache key for tool execution
	 *
	 * @param string $tool_slug Tool identifier.
	 * @param array  $arguments Tool arguments.
	 * @return string Cache key.
	 */
	protected function get_tool_cache_key( $tool_slug, $arguments ) {
		return md5( $tool_slug . wp_json_encode( $arguments ) );
	}

	/**
	 * Get trace ID from context
	 *
	 * @param array $context Execution context.
	 * @return string Trace ID.
	 */
	protected function get_trace_id( $context ) {
		return isset( $context['trace_id'] ) ? $context['trace_id'] : uniqid( 'trace_', true );
	}
}
