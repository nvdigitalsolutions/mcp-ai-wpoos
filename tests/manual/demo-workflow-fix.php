<?php
/**
 * Demonstration of the 403 Error Fix
 *
 * This script demonstrates how the fix prevents 403 errors by validating
 * user capabilities before executing workflows.
 *
 * Usage: Run this from WP-CLI or include in a test environment.
 */

// Simulate the workflow class capability validation.
class Workflow_Demo {

	/**
	 * Get required capability for a task
	 *
	 * @param string $task Task name.
	 * @return string|null Required capability or null if none required.
	 */
	private function get_task_required_capability( $task ) {
		$task_capabilities = array(
			'next-task'         => 'edit_posts',
			'check_drafts'      => 'edit_posts',
			'audit_drafts'      => 'edit_posts',
			'ship'              => 'publish_posts',
			'publish_post'      => 'publish_posts',
			'clean-content'     => 'edit_posts',
			'check_content'     => 'edit_posts',
			'optimize-perf'     => 'manage_options',
			'check_performance' => 'manage_options',
			'sync-docs'         => 'edit_posts',
			'check_docs'        => 'edit_posts',
			'notify_admin'      => 'edit_posts',
			'send_email'        => 'edit_posts',
			'wait'              => null,
			'sleep'             => null,
		);

		return isset( $task_capabilities[ $task ] ) ? $task_capabilities[ $task ] : null;
	}

	/**
	 * Validate user has required capabilities for all workflow steps
	 *
	 * @param array $workflow Workflow definition.
	 * @param array $user_capabilities User capabilities.
	 * @return array Result with success status and message.
	 */
	public function validate_workflow_capabilities( $workflow, $user_capabilities ) {
		if ( empty( $workflow['steps'] ) || ! is_array( $workflow['steps'] ) ) {
			return array( 'success' => true );
		}

		$missing_capabilities = array();

		foreach ( $workflow['steps'] as $step ) {
			if ( empty( $step['task'] ) ) {
				continue;
			}

			$required_capability = $this->get_task_required_capability( $step['task'] );

			if ( $required_capability && ! in_array( $required_capability, $user_capabilities, true ) ) {
				$missing_capabilities[ $step['task'] ] = $required_capability;
			}
		}

		if ( ! empty( $missing_capabilities ) ) {
			$task_list = array();
			foreach ( $missing_capabilities as $task => $capability ) {
				$task_list[] = sprintf( '%s (requires %s)', $task, $capability );
			}

			return array(
				'success' => false,
				'error'   => 'You do not have sufficient permissions to execute this workflow. The following tasks require higher privileges: ' . implode( ', ', $task_list ),
			);
		}

		return array( 'success' => true );
	}
}

// Define test workflows.
$workflows = array(
	'site-health'  => array(
		'name'  => 'Site Health Check',
		'steps' => array(
			array( 'task' => 'optimize-perf' ),
			array( 'task' => 'clean-content' ),
			array( 'task' => 'sync-docs' ),
		),
	),
	'daily-review' => array(
		'name'  => 'Daily Content Review',
		'steps' => array(
			array( 'task' => 'next-task' ),
			array( 'task' => 'clean-content' ),
		),
	),
);

// Define user capabilities.
$user_roles = array(
	'administrator' => array( 'read', 'edit_posts', 'publish_posts', 'manage_options' ),
	'editor'        => array( 'read', 'edit_posts', 'publish_posts' ),
	'contributor'   => array( 'read', 'edit_posts' ),
);

// Test the validation.
$demo = new Workflow_Demo();

echo "=== Workflow Capability Validation Demo ===\n\n";

foreach ( $workflows as $workflow_slug => $workflow ) {
	echo "Workflow: {$workflow['name']} ({$workflow_slug})\n";
	echo str_repeat( '-', 50 ) . "\n";

	foreach ( $user_roles as $role => $capabilities ) {
		$result = $demo->validate_workflow_capabilities( $workflow, $capabilities );

		if ( $result['success'] ) {
			echo "✓ {$role}: ALLOWED\n";
		} else {
			echo "✗ {$role}: BLOCKED\n";
			echo "  Reason: {$result['error']}\n";
		}
	}

	echo "\n";
}

echo "\n=== Summary ===\n";
echo "Before the fix:\n";
echo "  - Editor executes 'site-health' workflow\n";
echo "  - Workflow starts executing\n";
echo "  - First step 'optimize-perf' checks capability\n";
echo "  - ERROR 403: User lacks 'manage_options'\n";
echo "  - Workflow fails mid-execution\n\n";

echo "After the fix:\n";
echo "  - Editor executes 'site-health' workflow\n";
echo "  - Workflow validates ALL required capabilities FIRST\n";
echo "  - ERROR: Missing 'manage_options' (detected before execution)\n";
echo "  - No steps executed, clear error message returned\n";
echo "  - ✓ Prevents partial execution and confusing errors\n";
