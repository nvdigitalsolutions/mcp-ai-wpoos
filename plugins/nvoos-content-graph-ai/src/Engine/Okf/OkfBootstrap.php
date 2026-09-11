<?php
/**
 * OKF subsystem bootstrap (Wave E6, sub-cluster 4).
 *
 * Aligned port of the base plugin's `includes/okf/okf-init.php`: the
 * byte-identical hook surface — the `wp_mcp_ai_bootstrapped`
 * priority-32 tool-registration listener (after Paper Store at
 * priority 30) and the `OkfSkillKnowledgeGenerator::init()`
 * priority-32 `maybe_generate` hook with the `has_action` guard. The
 * engine classes themselves are lazy utilities with no hooks of their
 * own.
 *
 * Documented deviations:
 *  - Class name/namespace — the AI addon's PSR-4 tree (decision D4).
 *  - The base's inline `require_once` block disappears (PSR-4
 *    autoloading) and the base's file-level hook wiring becomes a
 *    static `register()` — wired standalone-only via
 *    `Plugin::registerEngine()`; the base init file owns the same
 *    hooks monolith (double registration would double-register the
 *    ten `okf_*` tools and double-hook the generator).
 *  - `register_tools()` resolves per install mode and is a documented
 *    no-op in both modes today: monolith the base loader owns the
 *    same ten registrations; standalone the ten base tool classes
 *    (`WP_MCP_AI_Tool_OKF_*`) remain base-owned until the OKF tool
 *    wave ports them. The listener itself stays hooked at priority 32
 *    so the surface is byte-identical when the tool wave lands.
 *  - The `wp_mcp_ai_bootstrapped` action is dormant standalone — no
 *    standalone surface fires it yet (byte-identical dormancy; the
 *    generator's `maybe_generate` degrades to the byte-identical
 *    'No bundled skills found' error without persisting the
 *    fingerprint option when it does fire).
 *
 * @since 1.1.0
 * @package NvoosContentGraphAi\Engine\Okf
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Engine\Okf;

/**
 * Wires the OKF subsystem hooks (standalone-only).
 *
 * @since 1.1.0
 */
final class OkfBootstrap {

	/**
	 * Wiring state.
	 *
	 * @var bool
	 */
	private static $registered = false;

	/**
	 * Register the OKF subsystem hooks.
	 *
	 * @return void
	 */
	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		// Auto-generate the skill-knowledge bundle from bundled skills so the
		// OKF tools work out of the box. Hooks the same action at priority 32
		// (guarded — the base's init file owns this hook monolith).
		OkfSkillKnowledgeGenerator::init();

		// Tool registration — priority 32, after Paper Store at priority 30.
		\add_action(
			'wp_mcp_ai_bootstrapped',
			static function (): void {
				self::register_tools();
			},
			32
		);
	}

	/**
	 * Register the OKF tools with the tool registry.
	 *
	 * Resolves per install mode (see the class docblock): the ten base
	 * `okf_*` tools are registered by the base loader monolith and
	 * remain deferred standalone until the OKF tool wave ports them.
	 *
	 * @return void
	 */
	public static function register_tools(): void {
		// Monolith: the base loader owns the same ten registrations
		// (okf-init.php, hooked at the same priority).
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			return;
		}

		// Standalone: deferred — the ten base tool classes
		// (WP_MCP_AI_Tool_OKF_Read_Concept/Browse/Traverse/Search/
		// List_Bundles/Write_Concept/Delete_Concept/Validate_Attestation/
		// Validate_Bundle/Import_Bundle) land with the OKF tool wave.
		// Until then the engine is consumed directly via OkfReader /
		// OkfWriter / OkfBundleManager.
	}
}
