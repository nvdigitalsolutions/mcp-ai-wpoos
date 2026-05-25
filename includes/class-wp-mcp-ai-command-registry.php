<?php
/**
 * Command Registry — Universal action palette for the command launcher.
 *
 * Registers all discoverable actions (threads, tools, navigation, settings)
 * for the Command Palette UI. Commands are exposed via REST API and consumed
 * by the Pro React SPA or third-party clients. Extensible via filter.
 *
 * @package NV_oOS
 * @since   1.7.0
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class WP_MCP_AI_Command_Registry
 *
 * @since 1.7.0
 */
class WP_MCP_AI_Command_Registry {

	/**
	 * Get all registered commands for the current user.
	 *
	 * Each command has:
	 * - id: unique slug (e.g., 'thread.new', 'tool.web_search', 'nav.settings')
	 * - label: human-readable name
	 * - category: grouping (Threads, Tools, Navigation, Settings, Profiles, Actions)
	 * - keywords: search aliases
	 * - capability: required WordPress capability
	 * - handler: action identifier (e.g., 'nvoos:thread.new', 'nvoos:nav.go')
	 * - meta: optional handler-specific data (e.g., { route: '/settings' }, { tool_slug: 'web_search' })
	 *
	 * @since 1.7.0
	 *
	 * @return array Array of command objects.
	 */
	public function get_commands() {
		$user_id  = get_current_user_id();
		$commands = array();

		// ── Thread Commands ──
		$commands[] = array(
			'id'         => 'thread.new',
			'label'      => __( 'New Thread', 'mcp-ai-wpoos' ),
			'category'   => 'Threads',
			'keywords'   => array( 'create', 'start', 'conversation', 'new' ),
			'capability' => 'read',
			'handler'    => 'nvoos:thread.new',
		);

		$commands[] = array(
			'id'         => 'thread.archive',
			'label'      => __( 'Archive Thread', 'mcp-ai-wpoos' ),
			'category'   => 'Threads',
			'keywords'   => array( 'delete', 'close', 'remove', 'hide' ),
			'capability' => 'edit_posts',
			'handler'    => 'nvoos:thread.archive',
		);

		$commands[] = array(
			'id'         => 'thread.restore',
			'label'      => __( 'Restore Thread', 'mcp-ai-wpoos' ),
			'category'   => 'Threads',
			'keywords'   => array( 'unarchive', 'recover', 'undo' ),
			'capability' => 'edit_posts',
			'handler'    => 'nvoos:thread.restore',
		);

		$commands[] = array(
			'id'         => 'thread.compact',
			'label'      => __( 'Compact Thread (New from Summary)', 'mcp-ai-wpoos' ),
			'category'   => 'Threads',
			'keywords'   => array( 'summarize', 'compact', 'compress', 'continue' ),
			'capability' => 'edit_posts',
			'handler'    => 'nvoos:thread.summarize',
		);

		$commands[] = array(
			'id'         => 'thread.history',
			'label'      => __( 'Thread History', 'mcp-ai-wpoos' ),
			'category'   => 'Threads',
			'keywords'   => array( 'archive', 'past', 'old', 'history' ),
			'capability' => 'read',
			'handler'    => 'nvoos:thread.history',
		);

		// ── Profile Commands ──
		$commands[] = array(
			'id'         => 'profile.switch',
			'label'      => __( 'Switch Profile', 'mcp-ai-wpoos' ),
			'category'   => 'Profiles',
			'keywords'   => array( 'write', 'ask', 'minimal', 'tools', 'permissions' ),
			'capability' => 'read',
			'handler'    => 'nvoos:profile.switch',
		);

		$commands[] = array(
			'id'         => 'profile.manage',
			'label'      => __( 'Manage Profiles', 'mcp-ai-wpoos' ),
			'category'   => 'Profiles',
			'keywords'   => array( 'create', 'edit', 'delete', 'custom', 'configure' ),
			'capability' => 'manage_options',
			'handler'    => 'nvoos:profile.manage',
		);

		$commands[] = array(
			'id'         => 'profile.switch_model',
			'label'      => __( 'Switch Model', 'mcp-ai-wpoos' ),
			'category'   => 'Profiles',
			'keywords'   => array( 'model', 'provider', 'gpt', 'claude', 'gemini' ),
			'capability' => 'read',
			'handler'    => 'nvoos:model.switch',
		);

		// ── Tool Commands (auto-registered from tool registry) ──
		if ( class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
			$registry  = WP_MCP_AI_Tool_Registry::get_instance();
			$all_tools = $registry->get_tools();

			foreach ( $all_tools as $slug => $tool ) {
				$commands[] = array(
					'id'         => 'tool.' . $slug,
					'label'      => isset( $tool['name'] ) ? $tool['name'] : $slug,
					'category'   => 'Tools',
					'keywords'   => array_merge(
						array( $slug ),
						isset( $tool['description'] ) ? explode( ' ', wp_trim_words( $tool['description'], 5, '' ) ) : array()
					),
					'capability' => isset( $tool['required_capability'] ) ? $tool['required_capability'] : 'read',
					'handler'    => 'nvoos:tool.execute',
					'meta'       => array( 'tool_slug' => $slug ),
				);
			}
		}

		// ── Navigation Commands ──
		$commands[] = array(
			'id'         => 'nav.chat',
			'label'      => __( 'Go to Chat', 'mcp-ai-wpoos' ),
			'category'   => 'Navigation',
			'keywords'   => array( 'chat', 'conversation', 'assistant', 'home' ),
			'capability' => 'read',
			'handler'    => 'nvoos:nav.go',
			'meta'       => array( 'route' => '/chat' ),
		);

		$commands[] = array(
			'id'         => 'nav.settings',
			'label'      => __( 'Settings', 'mcp-ai-wpoos' ),
			'category'   => 'Navigation',
			'keywords'   => array( 'config', 'preferences', 'options', 'configure' ),
			'capability' => 'manage_options',
			'handler'    => 'nvoos:nav.go',
			'meta'       => array( 'route' => '/settings' ),
		);

		$commands[] = array(
			'id'         => 'nav.tools',
			'label'      => __( 'Tools', 'mcp-ai-wpoos' ),
			'category'   => 'Navigation',
			'keywords'   => array( 'browse', 'catalogue', 'list', 'registry' ),
			'capability' => 'read',
			'handler'    => 'nvoos:nav.go',
			'meta'       => array( 'route' => '/tools' ),
		);

		$commands[] = array(
			'id'         => 'nav.assistants',
			'label'      => __( 'Assistants', 'mcp-ai-wpoos' ),
			'category'   => 'Navigation',
			'keywords'   => array( 'manage', 'create', 'edit', 'configure' ),
			'capability' => 'edit_posts',
			'handler'    => 'nvoos:nav.go',
			'meta'       => array( 'route' => '/assistants' ),
		);

		$commands[] = array(
			'id'         => 'nav.workflows',
			'label'      => __( 'Workflows', 'mcp-ai-wpoos' ),
			'category'   => 'Navigation',
			'keywords'   => array( 'automation', 'trigger', 'pipeline' ),
			'capability' => 'edit_posts',
			'handler'    => 'nvoos:nav.go',
			'meta'       => array( 'route' => '/workflows' ),
		);

		$commands[] = array(
			'id'         => 'nav.analytics',
			'label'      => __( 'Analytics', 'mcp-ai-wpoos' ),
			'category'   => 'Navigation',
			'keywords'   => array( 'stats', 'usage', 'tokens', 'cost', 'dashboard' ),
			'capability' => 'manage_options',
			'handler'    => 'nvoos:nav.go',
			'meta'       => array( 'route' => '/analytics' ),
		);

		// ── Action Commands ──
		$commands[] = array(
			'id'         => 'action.review_changes',
			'label'      => __( 'Review Changes', 'mcp-ai-wpoos' ),
			'category'   => 'Actions',
			'keywords'   => array( 'diff', 'checkpoint', 'changes', 'review' ),
			'capability' => 'read',
			'handler'    => 'nvoos:checkpoint.review',
		);

		$commands[] = array(
			'id'         => 'action.restore_checkpoint',
			'label'      => __( 'Restore Checkpoint', 'mcp-ai-wpoos' ),
			'category'   => 'Actions',
			'keywords'   => array( 'undo', 'revert', 'rollback', 'restore' ),
			'capability' => 'edit_posts',
			'handler'    => 'nvoos:checkpoint.restore',
		);

		$commands[] = array(
			'id'         => 'action.toggle_follow',
			'label'      => __( 'Toggle Follow Agent', 'mcp-ai-wpoos' ),
			'category'   => 'Actions',
			'keywords'   => array( 'follow', 'track', 'scroll', 'auto' ),
			'capability' => 'read',
			'handler'    => 'nvoos:agent.follow',
		);

		/**
		 * Filter the full command registry.
		 *
		 * Use this to register custom commands from addons or themes.
		 *
		 * @since 1.7.0
		 *
		 * @param array $commands Array of command objects.
		 */
		return apply_filters( 'wp_mcp_ai_commands', $commands );
	}

	/**
	 * Get commands filtered by capability for the current user.
	 *
	 * @since 1.7.0
	 *
	 * @return array Commands the current user can execute.
	 */
	public function get_commands_for_current_user() {
		$commands = $this->get_commands();

		return array_filter(
			$commands,
			function ( $command ) {
				return current_user_can( $command['capability'] );
			}
		);
	}
}
