<?php
/**
 * Runtime mode detection and degradation notices.
 *
 * During the extraction transition the Platform addon's business logic is
 * split between this addon (ported subsystems) and the base plugin
 * (bridged subsystems). This class detects which runtime mode we are in
 * and produces a loud admin notice when a subsystem has no implementation
 * available — replacing the old silent class_exists()-skip failure mode.
 *
 * @package NvoosContentGraphAiPlatform
 * @since 2.0.0
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform;

final class RuntimeMode {

	/**
	 * Subsystems whose business logic is not yet ported into this addon.
	 *
	 * Map of subsystem label => base-plugin probe. The probe is a class name
	 * or function name that exists when the base plugin provides the logic.
	 *
	 * @var array<string,string>
	 */
	private const BRIDGED_SUBSYSTEMS = array(
		'Agents'         => 'WP_MCP_AI_Assistant_CPT',
		'Skills'         => 'WP_MCP_AI_Skill_Registry',
		'Slash Commands' => 'wp_mcp_ai_execute_slash_command',
		'Harness'        => 'WP_MCP_AI_Guardrails',
		'Measurement'    => 'WP_MCP_AI_Measurement_Registry',
		'Professions'    => 'WP_MCP_AI_Profession_CPT',
	);

	/**
	 * Register the admin notice hook.
	 */
	public static function register(): void {
		add_action( 'admin_notices', array( __CLASS__, 'render_unavailable_notice' ) );
	}

	/**
	 * List subsystems that have no implementation available in this runtime.
	 *
	 * A bridged subsystem is unavailable when the base plugin is absent.
	 * The Blueprints subsystem has no implementation anywhere yet, so it is
	 * always reported until its greenfield build lands (extraction Phase 4).
	 *
	 * @return string[] Subsystem labels without an implementation.
	 */
	public static function unavailable_subsystems(): array {
		$missing = array();

		// The base plugin defines WP_MCP_AI_PATH only when it actually boots.
		// Class/function probes alone are unreliable: the monorepo root
		// autoloader can map base-plugin classes to disk without the plugin
		// ever being active.
		$base_present = defined( 'WP_MCP_AI_PATH' );

		foreach ( self::BRIDGED_SUBSYSTEMS as $label => $probe ) {
			if ( $base_present && ( class_exists( $probe ) || function_exists( $probe ) ) ) {
				continue;
			}
			$missing[] = $label;
		}

		// A2A was ported in extraction Wave A (src/A2A/) — always available.
		// Blueprints is greenfield and not yet built anywhere.
		if ( ! self::blueprints_implemented() ) {
			$missing[] = 'Blueprints';
		}

		return $missing;
	}

	/**
	 * Whether the Blueprints subsystem has a real implementation.
	 *
	 * @since 2.0.0
	 *
	 * @return bool True when the greenfield registry class exists.
	 */
	private static function blueprints_implemented(): bool {
		return class_exists( '\NvoosContentGraphAiPlatform\Blueprints\BlueprintRegistry' );
	}

	/**
	 * Render the unavailable-subsystems admin notice.
	 *
	 * Dismissible, capability-gated to users who can act on it.
	 */
	public static function render_unavailable_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$missing = self::unavailable_subsystems();
		if ( empty( $missing ) ) {
			return;
		}

		echo '<div class="notice notice-warning is-dismissible"><p>';
		echo wp_kses_post(
			sprintf(
				/* translators: %s: comma-separated subsystem list */
				__( '<strong>NV oOS Content Graph Platform:</strong> the following subsystems have no implementation available in this environment: %s. Install and activate the NV oOS (mcp-ai-wpoos) base plugin, or upgrade to a Platform version where these subsystems are extracted.', 'nvoos-content-graph-ai-platform' ),
				esc_html( implode( ', ', $missing ) )
			)
		);
		echo '</p></div>';
	}
}
