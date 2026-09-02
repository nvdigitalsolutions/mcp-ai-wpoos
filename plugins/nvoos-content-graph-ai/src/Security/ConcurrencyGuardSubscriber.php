<?php
/**
 * Concurrency Guard Hook Subscriber for the Content Graph AI addon.
 *
 * Ported 1:1 from the base plugin's
 * `includes/security/class-wp-mcp-ai-concurrency-guard-subscriber.php`
 * (behaviour-preserving; base copy retained permanently — ecosystem port
 * plan D-NOBASE). Flag→operation map, hook priorities (3), and the
 * acquire/release lifecycle keep their base semantics.
 *
 * Decoupling (documented, additive):
 * - `register()` is registered standalone-only by `Plugin.php` — in
 *   monolith installs the base plugin owns the same tool-execution hooks.
 * - `is_capability_flag_tool()` and `resolve_tool()` are protected seam
 *   methods: monolith installs check the base capability-flags interface
 *   and resolve tools via the base container; standalone installs
 *   duck-type `get_capability_flags()` and have no container yet (tool
 *   resolution lands with the tools wave).
 *
 * Priority 3: after DestructiveOpsGate (0) and CoSAI boundary (1),
 * but before token limiter and observers (5).
 *
 * @package NvoosContentGraphAi\Security
 * @since   1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary (commercial license required)
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Security;

use NvoosContentGraphAi\Security\Exceptions\ConcurrencyLimitReached;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Subscriber that enforces concurrency limits during tool execution.
 *
 * @since 1.1.0
 */
class ConcurrencyGuardSubscriber {

	/**
	 * Map of capability flags → concurrency operation type.
	 *
	 * @var array<string, string>
	 */
	const FLAG_TO_OPERATION = array(
		'image-generation'    => 'image_generation',
		'video-generation'    => 'video_generation',
		'music-generation'    => 'music_generation',
		'audio-generation'    => 'music_generation',
		'deep-research'       => 'deep_research',
		'model-download'      => 'model_download',
		'document-ocr'        => 'document_ocr',
		'pdf-generation'      => 'pdf_generation',
		'embeddings-batch'    => 'embeddings_batch',
		'video-frame-extract' => 'video_frame_extract',
	);

	/**
	 * Per-request stack of (tool_slug → operation_type) for release tracking.
	 *
	 * WordPress is single-threaded per request; a static array is safe.
	 *
	 * @var array<string, string>
	 */
	private static $active = array();

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'wp_mcp_ai_before_tool_execution', array( __CLASS__, 'on_before' ), 3, 3 );
		add_action( 'wp_mcp_ai_after_tool_execution', array( __CLASS__, 'on_after' ), 3, 4 );
	}

	/**
	 * Acquire a concurrency slot before tool execution.
	 *
	 * If at capacity, throws ConcurrencyLimitReached so the REST handler's
	 * try/catch converts it to a proper 429 response.
	 *
	 * @param string $tool_slug Tool identifier.
	 * @param array  $arguments Tool arguments.
	 * @param array  $context   Execution context.
	 * @return void
	 *
	 * @throws ConcurrencyLimitReached When the operation type is at capacity.
	 */
	public static function on_before( $tool_slug, $arguments, $context ) {
		// $arguments and $context are passed by the hook but used only for
		// context propagation; the guard only needs the tool slug.
		unset( $arguments, $context );

		$tool = static::resolve_tool( $tool_slug );
		if ( ! static::is_capability_flag_tool( $tool ) ) {
			return;
		}

		$operation = self::map_to_operation( $tool );
		if ( null === $operation ) {
			return; // Not a concurrency-relevant tool.
		}

		$result = ConcurrencyGuard::acquire( $operation );
		if ( is_wp_error( $result ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception constructor parameters, not direct output.
			throw new ConcurrencyLimitReached( $operation, $result->get_error_message() );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		self::push_active( $tool_slug, $operation );
	}

	/**
	 * Release the concurrency slot after tool execution.
	 *
	 * Always releases the slot, even when the tool result is a WP_Error,
	 * to prevent leaked slots from deadlocking the operation type.
	 *
	 * @param string $tool_slug  Tool identifier.
	 * @param array  $arguments  Tool arguments.
	 * @param array  $context    Execution context.
	 * @param mixed  $result     Tool result (may be array or WP_Error).
	 * @return void
	 */
	public static function on_after( $tool_slug, $arguments, $context, $result ) {
		// $arguments, $context, and $result are passed by the hook
		// but the guard only needs the tool slug for release tracking.
		unset( $arguments, $context, $result );

		$operation = self::pop_active( $tool_slug );
		if ( null === $operation ) {
			return;
		}
		ConcurrencyGuard::release( $operation );
	}

	/**
	 * Map a tool's capability flags to a concurrency operation type.
	 *
	 * @param object $tool Tool instance.
	 * @return string|null Operation type or null if no relevant flags found.
	 */
	protected static function map_to_operation( $tool ) {
		$flags = (array) $tool->get_capability_flags();
		foreach ( self::FLAG_TO_OPERATION as $flag => $operation ) {
			if ( in_array( $flag, $flags, true ) ) {
				return $operation;
			}
		}
		return null;
	}

	/**
	 * Whether a tool carries capability flags (per-install-mode seam).
	 *
	 * @param object|null $tool Tool instance.
	 * @return bool
	 */
	protected static function is_capability_flag_tool( $tool ) {
		if ( null === $tool || ! is_object( $tool ) ) {
			return false;
		}

		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			return $tool instanceof \WP_MCP_AI_Tool_Capability_Flags_Interface;
		}

		return method_exists( $tool, 'get_capability_flags' );
	}

	/**
	 * Resolve a tool instance by slug from the container.
	 *
	 * @param string $tool_slug Tool identifier.
	 * @return object|null
	 */
	protected static function resolve_tool( $tool_slug ) {
		if ( ! defined( 'WP_MCP_AI_PATH' ) || ! function_exists( 'wp_mcp_ai_container' ) ) {
			return null;
		}

		$container = wp_mcp_ai_container();
		if ( ! $container || ! method_exists( $container, 'get' ) ) {
			return null;
		}

		try {
			$registry = $container->get( 'tool.registry' );
			if ( ! $registry instanceof \WP_MCP_AI_Tool_Registry ) {
				return null;
			}
			return $registry->get_tool( $tool_slug );
		} catch ( \Exception $e ) {
			return null;
		}
	}

	/**
	 * Push an active operation onto the per-request stack.
	 *
	 * @param string $tool_slug Tool identifier.
	 * @param string $operation Operation type.
	 * @return void
	 */
	private static function push_active( $tool_slug, $operation ) {
		self::$active[ $tool_slug ] = $operation;
	}

	/**
	 * Pop an active operation from the per-request stack.
	 *
	 * @param string $tool_slug Tool identifier.
	 * @return string|null Operation type or null if not found.
	 */
	private static function pop_active( $tool_slug ) {
		$op = isset( self::$active[ $tool_slug ] ) ? self::$active[ $tool_slug ] : null;
		unset( self::$active[ $tool_slug ] );
		return $op;
	}
}
