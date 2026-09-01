<?php
/**
 * Destructive Operations Gate for the Content Graph AI addon.
 *
 * Ported 1:1 from the base plugin's
 * `includes/security/class-wp-mcp-ai-destructive-ops-gate.php`
 * (behaviour-preserving; base copy retained permanently — ecosystem port
 * plan D-NOBASE). Hook priority (0), destructive flag vocabulary,
 * confirmation value handling, preview payload shape, the
 * `wp_mcp_ai_destructive_confirmation_flags` /
 * `wp_mcp_ai_destructive_gate_rejected` hooks, and the rejection
 * exception flow keep their base names and semantics.
 *
 * Decoupling (documented, additive):
 * - Settings reads go through `is_enabled()` — the base settings
 *   repository in monolith installs, the content-graph settings store
 *   standalone (fail-safe default: enabled).
 * - `get_tool_flags()` is a protected seam: monolith installs read flags
 *   via the base capability-flags interface; standalone installs
 *   duck-type `get_capability_flags()`.
 * - Rejections audit through the ported `SecurityAuditLogger`.
 * - `register()` is registered standalone-only by `Plugin.php` — the
 *   base plugin owns the same hook in monolith installs.
 *
 * @package NvoosContentGraphAi\Security
 * @since   1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary (commercial license required)
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Security;

use NvoosContentGraphAi\CoreBridge;
use NvoosContentGraphAi\Security\Exceptions\DestructiveConfirmationRequired;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enforces destructive operation confirmation.
 *
 * Hooks into `wp_mcp_ai_before_tool_execution` at priority 0 (before
 * the capability boundary) so it runs for every tool execution regardless
 * of whether a capability boundary is active.
 *
 * @since 1.1.0
 */
class DestructiveOpsGate {

	/**
	 * Register the gate hook.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'wp_mcp_ai_before_tool_execution', array( __CLASS__, 'on_before_tool_execution' ), 0, 4 );
	}

	/**
	 * Check whether a destructive tool requires confirmation.
	 *
	 * @param string      $tool_slug Tool identifier.
	 * @param array       $arguments Sanitised tool arguments.
	 * @param array       $context   Execution context.
	 * @param object|null $tool      Tool instance.
	 * @return void
	 *
	 * @throws DestructiveConfirmationRequired When a destructive tool is
	 *         invoked without confirmation. Callers (tool executors) must
	 *         catch this and convert it via to_wp_error() so the rejection
	 *         flows through the normal REST error pipeline.
	 */
	public static function on_before_tool_execution( $tool_slug, $arguments, $context, $tool = null ) {
		// Check if the admin setting is enabled.
		if ( ! static::is_enabled() ) {
			return;
		}

		// Resolve the tool instance if not provided.
		if ( null === $tool ) {
			$tool = self::get_tool_instance( $tool_slug );
		}

		if ( null === $tool ) {
			return;
		}

		// Determine if the tool is destructive.
		if ( ! self::is_tool_destructive( $tool ) ) {
			return;
		}

		// Check if confirmation was explicitly provided.
		if ( self::is_confirmed( $arguments ) ) {
			return;
		}

		// Short-circuit: reject with a preview instead of executing.
		self::reject_unconfirmed( $tool_slug, $arguments, $tool );
	}

	/**
	 * Check if the destructive ops confirmation setting is enabled
	 * (per-install-mode seam). Defaults to enabled (fail-safe).
	 *
	 * @return bool
	 */
	protected static function is_enabled() {
		if ( defined( 'WP_MCP_AI_PATH' ) && function_exists( 'wp_mcp_ai_get_settings_repository' ) ) {
			return (bool) wp_mcp_ai_get_settings_repository()->get( 'require_confirm_destructive_ops', true );
		}

		return (bool) CoreBridge::instance()->settings->get( 'require_confirm_destructive_ops', true );
	}

	/**
	 * Read a tool's capability flags (per-install-mode seam).
	 *
	 * @param object $tool Tool instance.
	 * @return array
	 */
	protected static function get_tool_flags( $tool ) {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			if ( $tool instanceof \WP_MCP_AI_Tool_Capability_Flags_Interface ) {
				return (array) $tool->get_capability_flags();
			}
			return array();
		}

		if ( method_exists( $tool, 'get_capability_flags' ) ) {
			return (array) $tool->get_capability_flags();
		}

		return array();
	}

	/**
	 * Determine if a tool carries destructive capability flags.
	 *
	 * @param object $tool Tool instance.
	 * @return bool
	 */
	private static function is_tool_destructive( $tool ) {
		$flags = static::get_tool_flags( $tool );

		$destructive_flags = array(
			'destructive',
			'data-destruction',
			'irreversible',
			'state-changing',
			'write',
			'financial-impact',
			'access-control-change',
			'mass-email',
		);

		/**
		 * Filter: customize which capability flags trigger the confirmation gate.
		 *
		 * @param array $destructive_flags Flags that require confirmation.
		 * @param array $flags             Actual flags on the tool.
		 */
		$destructive_flags = apply_filters( 'wp_mcp_ai_destructive_confirmation_flags', $destructive_flags, $flags );

		foreach ( $destructive_flags as $flag ) {
			if ( in_array( $flag, $flags, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if the destructive operation was explicitly confirmed.
	 *
	 * @param array $arguments Tool arguments.
	 * @return bool
	 */
	private static function is_confirmed( $arguments ) {
		return ! empty( $arguments['confirm_destructive'] )
			&& (
				true === $arguments['confirm_destructive']
				|| 'true' === $arguments['confirm_destructive']
				|| 1 === $arguments['confirm_destructive']
				|| '1' === $arguments['confirm_destructive']
				|| 'yes' === strtolower( (string) $arguments['confirm_destructive'] )
			);
	}

	/**
	 * Reject an unconfirmed destructive tool call with a preview.
	 *
	 * @param string $tool_slug Tool identifier.
	 * @param array  $arguments Tool arguments.
	 * @param object $tool      Tool instance.
	 * @return void
	 *
	 * @throws DestructiveConfirmationRequired Always, so the executor can
	 *         convert the rejection into a WP_Error envelope.
	 */
	private static function reject_unconfirmed( $tool_slug, $arguments, $tool ) {
		$flags       = static::get_tool_flags( $tool );
		$tool_name   = method_exists( $tool, 'get_name' ) ? $tool->get_name() : $tool_slug;
		$description = method_exists( $tool, 'get_description' ) ? $tool->get_description() : '';

		$message = sprintf(
			/* translators: %s: tool name */
			__( '"%s" is a destructive operation that requires explicit confirmation.', 'nvoos-content-graph-ai' ),
			$tool_name
		);

		$payload = array(
			'tool_slug' => $tool_slug,
			'tool_name' => $tool_name,
			'flags'     => $flags,
			'preview'   => array(
				'message'      => $description,
				'arguments'    => $arguments,
				'confirmation' => array(
					'required_parameter' => 'confirm_destructive',
					'instructions'       => __( 'To proceed, call this tool again with the parameter "confirm_destructive" set to true.', 'nvoos-content-graph-ai' ),
				),
			),
		);

		// Log the denial before throwing.
		SecurityAuditLogger::log_event(
			SecurityAuditLogger::EVENT_DESTRUCTIVE_OP_DENIED,
			get_current_user_id(),
			array( 'tool_slug' => $tool_slug )
		);

		/**
		 * Filter: observe the gate rejection without catching the exception.
		 *
		 * Primarily intended for tests and integrations that want to assert
		 * the gate fired without intercepting exceptions.
		 *
		 * @param string $tool_slug Rejected tool identifier.
		 * @param array  $payload   Preview/confirmation payload.
		 */
		// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Tool slug, payload, and message are constructor parameters, not direct output.
		do_action( 'wp_mcp_ai_destructive_gate_rejected', $tool_slug, $payload );

		throw new DestructiveConfirmationRequired( $tool_slug, $payload, $message );
		// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	}

	/**
	 * Get a tool instance by slug.
	 *
	 * @param string $tool_slug Tool identifier.
	 * @return object|null
	 */
	private static function get_tool_instance( $tool_slug ) {
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
}
