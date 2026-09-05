<?php
/**
 * Global Action Scheduler stub for the scheduler-bridge port tests.
 *
 * The production `SchedulerBridge` probes `function_exists(
 * 'as_enqueue_async_action' )` from its own namespace, and unqualified
 * function lookups only fall back to the GLOBAL namespace — so the stub
 * cannot live inside the namespaced test files. It is required by
 * `test-scheduler-bridge.php` after the WordPress test bootstrap has
 * loaded any optional plugins, and the `function_exists()` guard means a
 * real Action Scheduler (e.g. the copy bundled by WooCommerce) wins when
 * it is present.
 *
 * @package NvoosContentGraphAiPlatform\Tests
 */

declare(strict_types=1);

if ( ! function_exists( 'as_enqueue_async_action' ) ) {
	/**
	 * Test stub for Action Scheduler's enqueue function.
	 *
	 * Records dispatches into
	 * `\NvoosContentGraphAiPlatform\Tests\AsStub`.
	 *
	 * @param string $hook  Action Scheduler hook.
	 * @param array  $args  Hook arguments.
	 * @param string $group Action Scheduler group.
	 * @return int Fake action ID.
	 */
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Byte-identical Action Scheduler function name; the production bridge probes this exact global.
	function as_enqueue_async_action( $hook, $args = array(), $group = '' ) {
		\NvoosContentGraphAiPlatform\Tests\AsStub::$actions[] = array(
			'hook'  => $hook,
			'args'  => $args,
			'group' => $group,
		);
		++\NvoosContentGraphAiPlatform\Tests\AsStub::$next_id;

		return \NvoosContentGraphAiPlatform\Tests\AsStub::$next_id;
	}
}
