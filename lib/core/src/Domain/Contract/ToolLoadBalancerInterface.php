<?php
/**
 * Tool Load Balancer Interface — the contract for intelligent tool routing.
 *
 * Abstracts load-based execution routing, result caching, performance
 * metrics tracking, and tool recommendation logic.
 *
 * @package Nvoos\Core
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Contract;

interface ToolLoadBalancerInterface {

	/**
	 * Route tool execution based on load and caching strategy.
	 *
	 * Determines optimal execution path:
	 * - Returns cached result if available
	 * - Executes synchronously if system is under low load
	 * - Queues asynchronously if system is under high load
	 *
	 * @param string $tool_slug Tool identifier.
	 * @param array  $arguments Tool arguments.
	 * @param array  $context   Execution context.
	 *
	 * @return array|mixed  Execution result or error.
	 */
	public function routeToolExecution(
		string $tool_slug,
		array $arguments,
		array $context
	): mixed;

	/**
	 * Track tool performance metrics after execution.
	 *
	 * @param string $tool_slug      Tool identifier.
	 * @param array  $execution_data Execution data (duration, success, context).
	 *
	 * @return void
	 */
	public function trackToolMetrics(
		string $tool_slug,
		array $execution_data
	): void;

	/**
	 * Get ranked tool recommendations for a task description.
	 *
	 * Analyses the task and returns tools ranked by relevance,
	 * considering historical performance data.
	 *
	 * @param string $task_description Natural-language task description.
	 * @param array  $context          Optional execution context.
	 *
	 * @return array  Ranked tool slugs with confidence scores.
	 */
	public function getToolRecommendations(
		string $task_description,
		array $context = array()
	): array;

	/**
	 * Clear cached results for a specific tool or all tools.
	 *
	 * @param string|null $tool_slug Tool slug to clear, or null for all.
	 *
	 * @return void
	 */
	public function clearCache(
		?string $tool_slug = null
	): void;
}
