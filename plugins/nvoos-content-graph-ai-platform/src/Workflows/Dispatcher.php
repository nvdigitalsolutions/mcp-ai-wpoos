<?php
/**
 * Workflow Dispatcher — pluggable executor entry point (Wave E1).
 *
 * Aligned port of the base plugin's `WP_MCP_AI_Workflow_Dispatcher`:
 * byte-identical `wp_mcp_ai_workflow_executor` filter contract (null =
 * defer, array|WP_Error = ownership), the default-executor fallback
 * chain (Engine V2 when available and enabled), and the
 * `no_workflow_executor` error envelope.
 *
 * Documented deviations:
 *  - Text domain `nvoos-content-graph-ai-platform`.
 *  - The default executor resolves per install mode through the
 *    `engine_class()` seam: base `WP_MCP_AI_Workflow_Engine_V2` monolith
 *    (boot-gated probe); standalone resolves the platform
 *    `Workflows\WorkflowEngine` once the engine sub-cluster lands —
 *    until then dispatch without a registered executor returns
 *    `no_workflow_executor` (documented degradation).
 *  - The `is_enabled()` gate is method_exists-guarded (documented
 *    hardening — the base assumes the class always exposes it).
 *
 * @since 2.1.0
 * @package NvoosContentGraphAiPlatform\Workflows
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Workflows;

/**
 * Pluggable workflow executor entry point.
 *
 * @since 2.1.0
 */
class Dispatcher {

	/**
	 * Dispatch a workflow execution to the first registered executor.
	 *
	 * @since 2.3.0
	 *
	 * @param int|string $workflow_id Workflow identifier (int post ID for base, string slug for Pro).
	 * @param array      $input       Runtime input.
	 * @param array      $context     Execution context.
	 * @return array|\WP_Error {
	 *   @type bool       $success
	 *   @type string|int $run_id
	 *   @type string     $message
	 * }
	 */
	public static function dispatch( $workflow_id, $input = array(), $context = array() ) {
		/**
		 * Filter to plug in a custom workflow executor.
		 *
		 * Return null to defer to the default executor (Engine V2).
		 * Return an array or WP_Error to take ownership of this dispatch.
		 *
		 * @since 2.3.0
		 *
		 * @param array|\WP_Error|null $result      Executor result, or null to defer.
		 * @param int|string           $workflow_id Workflow identifier.
		 * @param array                $input       Runtime input.
		 * @param array                $context     Execution context.
		 */
		$result = apply_filters( 'wp_mcp_ai_workflow_executor', null, $workflow_id, $input, $context );

		if ( null !== $result ) {
			return $result;
		}

		// Default executor — Engine V2 if available and enabled.
		$engine_class = static::engine_class();
		if ( null !== $engine_class
			&& ( ! method_exists( $engine_class, 'is_enabled' ) || $engine_class::is_enabled() )
		) {
			return $engine_class::execute(
				absint( $workflow_id ),
				is_array( $input ) ? $input : array(),
				is_array( $context ) ? $context : array()
			);
		}

		return new \WP_Error(
			'no_workflow_executor',
			__( 'No workflow executor is registered for this workflow.', 'nvoos-content-graph-ai-platform' )
		);
	}

	/**
	 * Resolve the default executor (engine) class per install mode.
	 *
	 * The base engine owns default execution in monolith installs;
	 * standalone resolves the platform `Workflows\WorkflowEngine` once
	 * the engine sub-cluster ports (null → `no_workflow_executor`
	 * degradation until then).
	 *
	 * @return string|null Fully-qualified class name, or null when unavailable.
	 */
	protected static function engine_class(): ?string {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Workflow_Engine_V2' ) ) {
			return 'WP_MCP_AI_Workflow_Engine_V2';
		}

		if ( class_exists( __NAMESPACE__ . '\WorkflowEngine' ) ) {
			return __NAMESPACE__ . '\WorkflowEngine';
		}

		return null;
	}
}
