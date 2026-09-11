<?php
/**
 * Memory-mining integration for imported conversations (Wave E4,
 * sub-cluster 6).
 *
 * Aligned port of the base plugin's
 * `WP_MCP_AI_Conversation_Import_Memory_Miner`: byte-identical setting
 * key (`conversation_import_mine_memory`, default off), the
 * `wp_mcp_ai_default_settings` default registration, the
 * `wp_mcp_ai_conversation_import_completed` listener, the dry-run
 * report skip, the 50-session cap, the `import-miner` virtual agent
 * scoping, the `only_unextracted` transcript query, the
 * `wp_mcp_ai_conversation_import_mined` action, and the
 * `wp_mcp_ai_import_mine_no_keys` /
 * `wp_mcp_ai_import_mine_unavailable` envelopes.
 *
 * Documented deviations:
 *  - Text domain `nvoos-content-graph-ai-platform`.
 *  - Class name/namespace — the platform's PSR-4 tree.
 *  - Base-only collaborators degrade per install mode
 *    (`defined( 'WP_MCP_AI_PATH' )` discriminator): the settings
 *    registry (`WP_MCP_AI_Settings_Registry`) and the mining tool
 *    (`WP_MCP_AI_Tool_Mine_Agent_Memory`) only exist monolith, so
 *    standalone installs report mining disabled / unavailable instead
 *    of touching classmapped base classes whose runtime would fatal.
 *  - The base's file-level self-bootstrap becomes an explicit
 *    `bootstrap()` call, wired standalone-only via
 *    `Plugin::registerConversationImport()` — the base loader owns the
 *    same hooks monolith.
 *  - `WP_Error` is fully qualified (`\WP_Error`).
 *
 * @since 2.1.0
 * @package NvoosContentGraphAiPlatform\ConversationImport
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\ConversationImport;

/**
 * Bridges completed imports into the agent memory mining flow.
 *
 * @since 2.1.0
 */
class MemoryMiner {

	const SETTING_KEY       = 'conversation_import_mine_memory';
	const MAX_MINE_SESSIONS = 50;

	/**
	 * Register settings and the import-completion hook.
	 *
	 * @return void
	 */
	public static function bootstrap() {
		add_filter( 'wp_mcp_ai_default_settings', array( __CLASS__, 'add_default_setting' ) );
		add_action( 'wp_mcp_ai_conversation_import_completed', array( __CLASS__, 'on_import_completed' ), 10, 2 );
	}

	/**
	 * Register the mining toggle in the plugin default settings.
	 *
	 * @param array $defaults Existing default settings.
	 * @return array
	 */
	public static function add_default_setting( $defaults ) {
		$defaults[ self::SETTING_KEY ] = false;

		return $defaults;
	}

	/**
	 * Whether automatic mining after import is enabled.
	 *
	 * Monolith-only: the base settings registry does not exist standalone,
	 * and the opt-in toggle therefore reads disabled there (documented
	 * degradation — imported content is personal data).
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		if ( ! defined( 'WP_MCP_AI_PATH' ) || ! class_exists( 'WP_MCP_AI_Settings_Registry' ) ) {
			return false;
		}

		return (bool) \WP_MCP_AI_Settings_Registry::get_setting( self::SETTING_KEY, false );
	}

	/**
	 * React to a completed import run.
	 *
	 * @param array $report  Final import report.
	 * @param int   $user_id Importing WordPress user ID.
	 * @return void
	 */
	public static function on_import_completed( $report, $user_id ) {
		if ( ! self::is_enabled() ) {
			return;
		}

		if ( ! is_array( $report ) || ! empty( $report['dry_run'] ) ) {
			return;
		}

		$session_keys = isset( $report['imported_session_keys'] ) && is_array( $report['imported_session_keys'] )
			? array_values( array_filter( array_map( 'sanitize_text_field', $report['imported_session_keys'] ) ) )
			: array();

		if ( empty( $session_keys ) ) {
			return;
		}

		self::mine( $session_keys, $user_id, $report );
	}

	/**
	 * Run the existing mining flow against a set of transcript session keys.
	 *
	 * Public so admin tooling can re-mine a specific import retroactively.
	 *
	 * @param string[] $session_keys Import session keys (e.g. "import-chatgpt-abc").
	 * @param int      $user_id      Importing user ID (informational).
	 * @param array    $report       Optional source report (informational).
	 * @return array|\WP_Error Mining result, or a WP_Error.
	 */
	public static function mine( array $session_keys, $user_id = 0, array $report = array() ) {
		if ( ! defined( 'WP_MCP_AI_PATH' ) || ! class_exists( 'WP_MCP_AI_Tool_Mine_Agent_Memory' ) ) {
			return new \WP_Error(
				'wp_mcp_ai_import_mine_unavailable',
				__( 'The agent memory mining tool is unavailable.', 'nvoos-content-graph-ai-platform' )
			);
		}

		$session_keys = array_values( array_filter( array_map( 'sanitize_text_field', $session_keys ) ) );
		if ( empty( $session_keys ) ) {
			return new \WP_Error(
				'wp_mcp_ai_import_mine_no_keys',
				__( 'No transcript session keys were provided for mining.', 'nvoos-content-graph-ai-platform' )
			);
		}

		$session_keys = array_slice( $session_keys, 0, self::MAX_MINE_SESSIONS );

		// Imported conversations have no native assistant post, so memory
		// records are scoped to a stable virtual agent identifier. The mining
		// flow accepts virtual IDs (same convention as virtual team agents).
		$agent_id = 'import-miner';

		$tool   = new \WP_MCP_AI_Tool_Mine_Agent_Memory();
		$result = $tool->execute(
			array(
				'source'           => 'transcripts',
				'agent_id'         => $agent_id,
				'transcript_query' => array(
					'session_keys'     => $session_keys,
					'only_unextracted' => true,
					'posts_per_page'   => count( $session_keys ),
				),
			),
			array()
		);

		/**
		 * Fires after imported conversations were fed through the mining flow.
		 *
		 * @since 2.1.0
		 *
		 * @param array|\WP_Error $result       Mining result.
		 * @param string[]        $session_keys Session keys that were mined.
		 * @param array           $report       Source import report (may be empty).
		 */
		do_action( 'wp_mcp_ai_conversation_import_mined', $result, $session_keys, $report );

		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Logger' ) ) {
			\WP_MCP_AI_Logger::log_event(
				'info',
				'Imported conversations mined into agent memory',
				array(
					'session_count' => count( $session_keys ),
					'is_error'      => is_wp_error( $result ),
				)
			);
		}

		return $result;
	}
}
