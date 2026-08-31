<?php
declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\SlashCommands;

final class SlashCommandService {

	private static ?self $instance = null;

	private function __construct() {}

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register(): void {
		if ( is_admin() ) {
			$this->registerAdmin();
		}

		// Standalone mode only: the base plugin owns the slash-command wiring
		// and global function surface in monolith mode — never wire twice.
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			return;
		}

		// Global function surface shims (wp_mcp_ai_init_slash_commands,
		// wp_mcp_ai_execute_slash_command, wp_mcp_ai_register_slash_command,
		// wp_mcp_ai_get_slash_commands, …) — the full port of the base
		// slash-commands-init.php, including its init wiring.
		require_once __DIR__ . '/shim-functions.php';
	}

	private function registerAdmin(): void {
		if ( class_exists( 'NvoosContentGraphAiPlatform\SlashCommands\Admin\SlashCommandsAdmin' ) ) {
			( new \NvoosContentGraphAiPlatform\SlashCommands\Admin\SlashCommandsAdmin() )->register();
		}
	}

	private function __clone() {}
}
