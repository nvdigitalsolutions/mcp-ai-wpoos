<?php
declare(strict_types=1);

namespace NvoosContentGraph\Memory;

/**
 * Agent memory bridge stub.
 *
 * Connects agent memories to the knowledge graph. Full implementation
 * deferred to the `nvoos-content-graph-ai` addon which provides the
 * `wp_mcp_ai_memory_stored` action.
 *
 * @since 1.0.0
 */
class Bridge {

	/**
	 * Register the memory subscriber hook.
	 *
	 * Idempotent — safe to call multiple times.
	 *
	 * @return void
	 */
	public static function register(): void {
		if ( ! function_exists( 'add_action' ) ) {
			return;
		}
		add_action( 'nvoos_content_graph/memory_stored', array( __CLASS__, 'onMemoryStored' ), 10, 1 );
	}

	/**
	 * Handle a memory-stored event.
	 *
	 * @param array<string,mixed> $payload Event payload.
	 * @return void
	 */
	public static function onMemoryStored( array $payload ): void {
		// Stub — full implementation in nvoos-content-graph-ai addon.
	}
}
