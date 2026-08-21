<?php
/**
 * Agent role system — registers and manages AI agents.
 *
 * Extracted from the base plugin's `includes/assistants/` and
 * `includes/admin/class-wp-mcp-ai-assistant-*.php`. The agent
 * system bridges the Platform addon with the existing assistant
 * CPT (`mcp_ai_assistant`) registered by the core plugin.
 *
 * @since 1.0.0
 * @package NvoosContentGraphAiPlatform\Agents
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Agents;

/**
 * Agent service — wires the agent subsystem into WordPress.
 *
 * Each method corresponds to a responsibility extracted from
 * the base plugin:
 *
 * - Admin pages (Add, Build, Test) → `registerAdmin()`
 * - Agent creation from templates → `createFromTemplate()`
 * - Agent configuration management → future
 * - Agent-to-Agent communication → future (A2A subsystem)
 *
 * @since 1.0.0
 */
final class Agents {

	/** @var self|null Singleton instance. */
	private static ?self $instance = null;

	/**
	 * The assistant post type slug.
	 *
	 * @var string
	 */
	public const POST_TYPE = 'mcp_ai_assistant';

	/** Private constructor — use {@see instance()}. */
	private function __construct() {}

	/**
	 * Retrieve the singleton instance.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register all agent-related WordPress hooks.
	 *
	 * Called from {@see \NvoosContentGraphAiPlatform\Plugin::register()}.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( is_admin() ) {
			$this->registerAdmin();
		}

		// Future hooks to wire as extraction progresses:
		// - Agent creation/update/deletion lifecycle
		// - Agent capability registration
		// - Agent REST API endpoints
		// - Agent default seeding
	}

	/**
	 * Register admin pages for agent management.
	 *
	 * @return void
	 */
	private function registerAdmin(): void {
		if ( class_exists( 'NvoosContentGraphAiPlatform\Agents\Admin\AgentsAdmin' ) ) {
			( new \NvoosContentGraphAiPlatform\Agents\Admin\AgentsAdmin() )->register();
		}
	}

	/** Prevent cloning. */
	private function __clone() {}
}
