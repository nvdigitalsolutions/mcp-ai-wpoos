<?php
/**
 * WordPress adapter: ToolAsyncExecutorInterface implementation.
 *
 * Wraps the legacy WP_MCP_AI_Tool_Async_Executor behind the
 * framework-agnostic ToolAsyncExecutorInterface.
 *
 * @package Nvoos\WordPress
 * @since   1.0.0
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Nvoos\WordPress\Adapter;

use Nvoos\Core\Domain\Contract\ToolAsyncExecutorInterface;

class ToolAsyncExecutor implements ToolAsyncExecutorInterface {

	private ?\WP_MCP_AI_Tool_Async_Executor $executor = null;

	/**
	 * Lazy-load the legacy async executor.
	 */
	private function executor(): \WP_MCP_AI_Tool_Async_Executor {
		if ( null === $this->executor ) {
			$this->executor = new \WP_MCP_AI_Tool_Async_Executor();
		}

		return $this->executor;
	}

	public function queueTool(
		string $tool_slug,
		array  $arguments = array(),
		array  $context = array()
	): mixed {
		return $this->executor()->queue_tool( $tool_slug, $arguments, $context );
	}

	public function executeAsyncTool( string $job_id ): void {
		$this->executor()->execute_async_tool( $job_id );
	}

	public function cancelJob( string $job_id, int $user_id = 0 ): bool {
		$result = $this->executor()->cancel_job( $job_id, $user_id );

		if ( \is_wp_error( $result ) ) {
			return false;
		}

		return (bool) $result;
	}

	public function retryJob( string $job_id, int $user_id = 0 ): mixed {
		return $this->executor()->retry_job( $job_id, $user_id );
	}

	public function isOwnedBy( string $job_id, int $user_id ): bool {
		return $this->executor()->is_owned_by( $job_id, $user_id );
	}

	public function getResult( string $job_id ): mixed {
		return $this->executor()->get_result( $job_id );
	}

	public function cleanupExpiredResults(): void {
		$this->executor()->cleanup_expired_results();
	}
}
