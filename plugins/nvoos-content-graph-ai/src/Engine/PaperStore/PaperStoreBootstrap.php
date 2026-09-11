<?php
/**
 * Paper Store subsystem bootstrap (Wave E6, sub-cluster 3).
 *
 * Aligned port of the base plugin's `includes/paper-store/paper-store-init.php`:
 * the byte-identical hook surface — the `wp_mcp_ai_bootstrapped`
 * priority-30 tool-registration listener (after the default tool init
 * at priority 20 and the `wp_mcp_ai_register_tools` action). The
 * engine classes themselves are lazy singletons with no hooks of their
 * own.
 *
 * Documented deviations:
 *  - Class name/namespace — the AI addon's PSR-4 tree (decision D4).
 *  - The base's inline `require_once` block disappears (PSR-4
 *    autoloading) and the base's file-level hook wiring becomes a
 *    static `register()` — wired standalone-only via
 *    `Plugin::registerEngine()`.
 *  - `register_tools()` resolves per install mode and is a documented
 *    no-op in both modes today: monolith the base loader owns the same
 *    six registrations (double registration would double-register the
 *    `paper_store_*` tools); standalone the six base tool classes and
 *    the `paper-store` REST controller remain base-owned until the
 *    Paper Store tool wave ports them (the trait
 *    `PaperStoreRemoteTrait` already carries the remote-proxy contract
 *    those tools will consume). The listener itself stays hooked at
 *    priority 30 so the surface is byte-identical when the tool wave
 *    lands.
 *  - The `wp_mcp_ai_bootstrapped` action is dormant standalone — no
 *    standalone surface fires it yet (byte-identical dormancy).
 *
 * @since 1.1.0
 * @package NvoosContentGraphAi\Engine\PaperStore
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Engine\PaperStore;

/**
 * Wires the Paper Store subsystem hooks (standalone-only).
 *
 * @since 1.1.0
 */
final class PaperStoreBootstrap {

	/**
	 * Wiring state.
	 *
	 * @var bool
	 */
	private static $registered = false;

	/**
	 * Register the Paper Store subsystem hooks.
	 *
	 * @return void
	 */
	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		// Tool registration — priority 30, after the default tool init at
		// priority 20 and the wp_mcp_ai_register_tools action.
		\add_action(
			'wp_mcp_ai_bootstrapped',
			static function (): void {
				self::register_tools();
			},
			30
		);
	}

	/**
	 * Register the Paper Store tools with the tool registry.
	 *
	 * Resolves per install mode (see the class docblock): the six base
	 * `paper_store_*` tools are registered by the base loader monolith
	 * and remain deferred standalone until the Paper Store tool wave
	 * ports them.
	 *
	 * @return void
	 */
	public static function register_tools(): void {
		// Monolith: the base loader owns the same six registrations
		// (paper-store-init.php, hooked at the same priority).
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			return;
		}

		// Standalone: deferred — the six base tool classes
		// (WP_MCP_AI_Tool_Paper_Store_List/Read/Search/Write/Update/Delete)
		// and the paper-store REST controller land with the Paper Store
		// tool wave. Until then the engine is consumed directly via
		// PaperStoreManager::get_instance()->get_repository().
	}
}
