<?php
/**
 * NV oOS Fantasy Football Addon — Core Class
 *
 * Handles activation checks, admin notices, tool registration,
 * CPT initialization, and settings for the Fantasy Football addon.
 *
 * @package NV_oOS_Fantasy_Football
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Core singleton for the NV oOS Fantasy Football Addon.
 *
 * @since 0.1.0
 */
class NV_oOS_Fantasy_Football {

	/**
	 * WordPress option key for addon settings.
	 *
	 * @var string
	 */
	const OPTION_KEY = 'nvoos_fantasy_football_settings';

	/**
	 * Register all WordPress hooks.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_notices', array( __CLASS__, 'admin_notices' ) );
		add_action( 'plugins_loaded', array( __CLASS__, 'on_plugins_loaded' ) );
	}

	/**
	 * Fired on plugins_loaded — verify base plugin, register tools, and init CPTs.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function on_plugins_loaded() {
		if ( ! nvoos_fantasy_football_is_base_active() ) {
			return;
		}

		if ( ! self::is_enabled() ) {
			return;
		}

		// Initialize CPTs.
		WP_MCP_AI_Fantasy_Team_CPT::init();
		WP_MCP_AI_Fantasy_Player_CPT::init();

		// Initialize admin pages.
		if ( is_admin() ) {
			NV_oOS_Fantasy_Football_Settings::init();
			WP_MCP_AI_Fantasy_Football_Research_Page::init();
		}

		// Register fantasy football tools with the oOS tool registry.
		add_action( 'wp_mcp_ai_register_tools', array( __CLASS__, 'register_tools' ) );

		// Also support the Pro-style lazy loading hook.
		add_action( 'wp_mcp_ai_load_pro_tools', array( __CLASS__, 'load_tools' ) );
	}

	/**
	 * Check whether the fantasy football addon is enabled in settings.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		$settings = get_option( self::OPTION_KEY, array() );
		return ! isset( $settings['enabled'] ) || ! empty( $settings['enabled'] );
	}

	/**
	 * Get addon settings.
	 *
	 * @since 0.1.0
	 *
	 * @return array
	 */
	public static function get_settings() {
		return wp_parse_args(
			get_option( self::OPTION_KEY, array() ),
			array(
				'enabled' => true,
			)
		);
	}

	/**
	 * List of all tool class names provided by this addon.
	 *
	 * @since 0.1.0
	 *
	 * @return array
	 */
	private static function get_tool_classes() {
		return array(
			// Yahoo Fantasy Sports API tools.
			'WP_MCP_AI_Tool_Yahoo_FF_Auth',
			'WP_MCP_AI_Tool_Yahoo_FF_Get_Leagues',
			'WP_MCP_AI_Tool_Yahoo_FF_Get_Roster',
			'WP_MCP_AI_Tool_Yahoo_FF_Get_Player_Stats',
			'WP_MCP_AI_Tool_Yahoo_FF_Trade_Analyzer',
			'WP_MCP_AI_Tool_Yahoo_FF_League_Standings',
			// ESPN Fantasy Football API tools.
			'WP_MCP_AI_Tool_ESPN_Fantasy_Get_League',
			'WP_MCP_AI_Tool_ESPN_Fantasy_Get_Teams',
			'WP_MCP_AI_Tool_ESPN_Fantasy_Get_Roster',
			'WP_MCP_AI_Tool_ESPN_Fantasy_Get_Standings',
			'WP_MCP_AI_Tool_ESPN_Fantasy_Analyze_Lineup',
			'WP_MCP_AI_Tool_ESPN_Fantasy_Sync_League',
			// AI-powered FF tools.
			'WP_MCP_AI_Tool_FF_Generate_Team_Logo',
			'WP_MCP_AI_Tool_FF_Create_League_Report',
			'WP_MCP_AI_Tool_FF_Player_Research',
		);
	}

	/**
	 * Register fantasy football tools with the oOS tool registry.
	 *
	 * @since 0.1.0
	 *
	 * @param object $registry WP_MCP_AI_Tool_Registry instance.
	 * @return void
	 */
	public static function register_tools( $registry ) {
		if ( ! self::is_enabled() ) {
			return;
		}

		self::load_tool_files();

		foreach ( self::get_tool_classes() as $class ) {
			if ( class_exists( $class ) ) {
				$registry->register_tool( new $class() );
			}
		}
	}

	/**
	 * Load tools via the Pro-style lazy loading hook.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function load_tools() {
		if ( ! self::is_enabled() ) {
			return;
		}

		self::load_tool_files();

		$registry = function_exists( 'wp_mcp_ai_get_tool_registry' )
			? wp_mcp_ai_get_tool_registry()
			: null;

		if ( ! $registry ) {
			return;
		}

		foreach ( self::get_tool_classes() as $class ) {
			if ( class_exists( $class ) ) {
				$registry->register_tool( new $class() );
			}
		}
	}

	/**
	 * Require all tool class files.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	private static function load_tool_files() {
		static $loaded = false;
		if ( $loaded ) {
			return;
		}
		$loaded = true;

		$dir = NVOOS_FANTASY_FOOTBALL_PATH . 'includes/tools/';

		// Yahoo FF tools.
		require_once $dir . 'class-wp-mcp-ai-tool-yahoo-ff-auth.php';
		require_once $dir . 'class-wp-mcp-ai-tool-yahoo-ff-get-leagues.php';
		require_once $dir . 'class-wp-mcp-ai-tool-yahoo-ff-get-roster.php';
		require_once $dir . 'class-wp-mcp-ai-tool-yahoo-ff-get-player-stats.php';
		require_once $dir . 'class-wp-mcp-ai-tool-yahoo-ff-trade-analyzer.php';
		require_once $dir . 'class-wp-mcp-ai-tool-yahoo-ff-league-standings.php';

		// ESPN Fantasy tools.
		require_once $dir . 'class-wp-mcp-ai-tool-espn-fantasy-get-league.php';
		require_once $dir . 'class-wp-mcp-ai-tool-espn-fantasy-get-teams.php';
		require_once $dir . 'class-wp-mcp-ai-tool-espn-fantasy-get-roster.php';
		require_once $dir . 'class-wp-mcp-ai-tool-espn-fantasy-get-standings.php';
		require_once $dir . 'class-wp-mcp-ai-tool-espn-fantasy-analyze-lineup.php';
		require_once $dir . 'class-wp-mcp-ai-tool-espn-fantasy-sync-league.php';

		// AI-powered FF tools.
		require_once $dir . 'class-wp-mcp-ai-tool-ff-generate-team-logo.php';
		require_once $dir . 'class-wp-mcp-ai-tool-ff-create-league-report.php';
		require_once $dir . 'class-wp-mcp-ai-tool-ff-player-research.php';
	}

	/**
	 * Display admin notices about addon status.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function admin_notices() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Show activation notice.
		if ( get_transient( 'nvoos_fantasy_football_activated' ) ) {
			delete_transient( 'nvoos_fantasy_football_activated' );
			echo '<div class="notice notice-success is-dismissible"><p>';
			esc_html_e( 'NV oOS Fantasy Football Addon activated — ESPN and Yahoo Fantasy Sports tools are now available in the oOS chat interface.', 'nvoos-fantasy-football' );
			echo '</p></div>';
		}

		// Warn if base plugin is missing.
		if ( ! nvoos_fantasy_football_is_base_active() ) {
			echo '<div class="notice notice-warning is-dismissible"><p>';
			esc_html_e( 'NV oOS Fantasy Football Addon requires the NV oOS base plugin to be installed and active.', 'nvoos-fantasy-football' );
			echo '</p></div>';
		}
	}
}

/**
 * Set the "just activated" transient on plugin activation.
 */
register_activation_hook(
	NVOOS_FANTASY_FOOTBALL_FILE,
	function () {
		set_transient( 'nvoos_fantasy_football_activated', true, 30 );
	}
);
