<?php
/**
 * WordPress adapter: ToolLoadBalancerInterface implementation.
 *
 * Wraps the legacy WP_MCP_AI_Tool_Load_Balancer behind the
 * framework-agnostic ToolLoadBalancerInterface.
 *
 * @package Nvoos\WordPress
 * @since   1.0.0
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Nvoos\WordPress\Adapter;

use Nvoos\Core\Domain\Contract\ToolLoadBalancerInterface;

class ToolLoadBalancer implements ToolLoadBalancerInterface {

	private ?\WP_MCP_AI_Tool_Load_Balancer $balancer = null;

	/**
	 * Lazy-load the legacy load balancer.
	 */
	private function balancer(): \WP_MCP_AI_Tool_Load_Balancer {
		if ( null === $this->balancer ) {
			$registry      = \WP_MCP_AI_Tool_Registry::get_instance();
			$registry->init();
			$loadMonitor   = new \WP_MCP_AI_Tool_Load_Monitor();
			$orchestrator  = new \WP_MCP_AI_Tool_Execution_Orchestrator( $registry, null );

			$this->balancer = new \WP_MCP_AI_Tool_Load_Balancer(
				$registry,
				$loadMonitor,
				$orchestrator
			);
		}

		return $this->balancer;
	}

	public function routeToolExecution(
		string $tool_slug,
		array  $arguments,
		array  $context
	): mixed {
		return $this->balancer()->route_tool_execution(
			\sanitize_key( $tool_slug ),
			$arguments,
			$context
		);
	}

	public function trackToolMetrics(
		string $tool_slug,
		array  $execution_data
	): void {
		$this->balancer()->track_tool_metrics( $tool_slug, $execution_data );
	}

	public function getToolRecommendations(
		string $task_description,
		array  $context = array()
	): array {
		return $this->balancer()->get_tool_recommendations(
			$task_description,
			$context
		);
	}

	public function clearCache(
		?string $tool_slug = null
	): void {
		if ( null === $tool_slug ) {
			\wp_cache_flush();
			return;
		}

		$this->balancer()->clear_cache( $tool_slug );
	}
}
