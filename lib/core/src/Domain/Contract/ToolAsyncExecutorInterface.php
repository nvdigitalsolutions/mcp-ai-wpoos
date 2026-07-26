<?php
/**
 * Tool Async Executor Interface — the contract for background tool execution.
 *
 * Abstracts queuing tools for async execution, polling for results,
 * cancelling/retrying jobs, and lifecycle management of async results.
 *
 * @package Nvoos\Core
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Contract;

interface ToolAsyncExecutorInterface {

	/**
	 * Queue a tool for asynchronous execution.
	 *
	 * Returns immediately with a job ID. The tool runs in a background
	 * worker (cron, queue worker, sidecar, etc.) and stores its result
	 * for later retrieval via {@see getResult()}.
	 *
	 * @param string $tool_slug Tool identifier.
	 * @param array  $arguments Tool arguments.
	 * @param array  $context   Execution context (user_id, assistant_id, etc.).
	 *
	 * @return string|mixed  Job ID on success, or error.
	 */
	public function queueTool(
		string $tool_slug,
		array $arguments = array(),
		array $context = array()
	): mixed;

	/**
	 * Execute a previously queued async tool job.
	 *
	 * Called by the background worker. Reads job metadata, executes
	 * the tool, and stores the result.
	 *
	 * @param string $job_id Job identifier returned by {@see queueTool()}.
	 *
	 * @return void
	 */
	public function executeAsyncTool( string $job_id ): void;

	/**
	 * Cancel a pending or running async job.
	 *
	 * @param string $job_id  Job identifier.
	 * @param int    $user_id User ID for ownership verification.
	 *
	 * @return bool  True if cancelled, false otherwise.
	 */
	public function cancelJob( string $job_id, int $user_id = 0 ): bool;

	/**
	 * Retry a failed async job.
	 *
	 * @param string $job_id  Job identifier.
	 * @param int    $user_id User ID for ownership verification.
	 *
	 * @return string|mixed  New job ID on success, or error.
	 */
	public function retryJob( string $job_id, int $user_id = 0 ): mixed;

	/**
	 * Check whether a job is owned by a specific user.
	 *
	 * @param string $job_id  Job identifier.
	 * @param int    $user_id User ID to check.
	 *
	 * @return bool  True if owned by the user.
	 */
	public function isOwnedBy( string $job_id, int $user_id ): bool;

	/**
	 * Retrieve the result of an async job.
	 *
	 * Returns the full job metadata including status, result, error,
	 * and timing information.
	 *
	 * @param string $job_id Job identifier.
	 *
	 * @return array|mixed  Job result data, or error if not found.
	 */
	public function getResult( string $job_id ): mixed;

	/**
	 * Clean up expired async job results.
	 *
	 * Removes job metadata and transient data for jobs that have
	 * exceeded their retention period.
	 *
	 * @return void
	 */
	public function cleanupExpiredResults(): void;
}
