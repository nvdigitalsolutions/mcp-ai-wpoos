<?php
/**
 * NV oOS Docs Hub — Core Plugin Class
 *
 * Handles hook registration, shortcode registration, block registration,
 * cron scheduling, and the primary plugin lifecycle.
 *
 * @package NV_oOS_Docs_Hub
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Core singleton for the NV oOS Docs Hub addon.
 *
 * @since 1.0.0
 */
class NV_oOS_Docs_Hub_Plugin {

	/**
	 * WordPress option key for addon settings.
	 *
	 * @var string
	 */
	const OPTION_KEY = 'nvoos_docs_hub_settings';

	/**
	 * Register all WordPress hooks.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_notices', array( __CLASS__, 'admin_notices' ) );
		add_action( 'plugins_loaded', array( __CLASS__, 'on_plugins_loaded' ) );
		add_action( 'init', array( __CLASS__, 'register_shortcodes' ), 12 );
		add_action( 'init', array( __CLASS__, 'register_block' ), 12 );
		add_action( 'rest_api_init', array( __CLASS__, 'init_rest' ) );
		add_action( 'nvoos_docs_hub_rebuild_cron', array( __CLASS__, 'run_scheduled_rebuild' ) );
		add_action( 'activated_plugin', array( __CLASS__, 'clear_cache_on_change' ) );
		add_action( 'deactivated_plugin', array( __CLASS__, 'clear_cache_on_change' ) );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'on_upgrader_complete' ), 10, 2 );

		// Auto-trigger a rebuild when settings that affect the index are changed
		// (sources, remote_repos, context_enabled, include_addon_readmes).
		add_action( 'update_option_' . self::OPTION_KEY, array( __CLASS__, 'on_settings_changed' ), 10, 2 );

		// Register the chunked-rebuild tick handler. Settings page
		// registers itself when its file is loaded (admin context only).
		NV_oOS_Docs_Hub_Rebuild_Pipeline::register();
	}

	/**
	 * Fired on plugins_loaded.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function on_plugins_loaded() {
		load_plugin_textdomain(
			'nvoos-docs-hub',
			false,
			dirname( plugin_basename( NVOOS_DOCS_HUB_FILE ) ) . '/languages'
		);

		NV_oOS_Docs_Hub_Rebuild_Job::schedule();
	}

	/**
	 * Check whether the addon is enabled in settings.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		$settings = get_option( self::OPTION_KEY, array() );
		return ! isset( $settings['enabled'] ) || ! empty( $settings['enabled'] );
	}

	/**
	 * Get addon settings with defaults.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public static function get_settings() {
		$option = get_option( self::OPTION_KEY, null );

		// Fresh install (option does not yet exist) → remote-first defaults.
		// Existing installs keep their saved sources unchanged.
		$default_sources = ( null === $option )
			? array( 'remote' )
			: array( 'base', 'addons', 'root' );

		$parsed = wp_parse_args(
			is_array( $option ) ? $option : array(),
			array(
				'enabled'               => true,
				'public_access'         => true,
				'sources'               => $default_sources,
				'context_enabled'       => false,
				'default_theme'         => 'auto',
				'search_enabled'        => true,
				'sidebar_enabled'       => true,
				'include_addon_readmes' => true,
				'default_home'          => 'readme',
				'github_repo_url'       => '',
				'remote_repos'          => array(),
			)
		);

		// Defensive: coerce remote_repos into a list of array rows. Anything that
		// isn't an array (string / null / scalar from a partial migration) is dropped
		// here so downstream renderers and the indexer never see a malformed row.
		$raw_repos              = isset( $parsed['remote_repos'] ) && is_array( $parsed['remote_repos'] ) ? $parsed['remote_repos'] : array();
		$parsed['remote_repos'] = array_values(
			array_filter(
				$raw_repos,
				static function ( $row ) {
					return is_array( $row );
				}
			)
		);

		return $parsed;
	}

	/**
	 * Register shortcodes.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function register_shortcodes() {
		NV_oOS_Docs_Hub_Shortcode::register();
	}

	/**
	 * Register the Gutenberg block.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function register_block() {
		NV_oOS_Docs_Hub_Block::register();
	}

	/**
	 * Initialize REST API routes.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function init_rest() {
		NV_oOS_Docs_Hub_REST::register_routes();
	}

	/**
	 * Run the scheduled rebuild via cron.
	 *
	 * Daily cron now enqueues the async chunked pipeline instead of
	 * running the entire rebuild inline (which historically OOM'd on
	 * large repos).
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function run_scheduled_rebuild() {
		NV_oOS_Docs_Hub_Rebuild_Job::enqueue_async();
	}

	/**
	 * Clear doc cache when a plugin is activated or deactivated.
	 *
	 * @since 1.0.0
	 *
	 * @param string $plugin Plugin file path relative to plugins directory.
	 * @return void
	 */
	public static function clear_cache_on_change( $plugin ) {
		NV_oOS_Docs_Hub_Rebuild_Job::handle_plugin_change( $plugin );
	}

	/**
	 * Clear doc cache when WordPress upgrades plugins.
	 *
	 * @since 1.0.0
	 *
	 * @param object $upgrader_object WP_Upgrader instance.
	 * @param array  $options         Upgrade options array.
	 * @return void
	 */
	public static function on_upgrader_complete( $upgrader_object, $options ) {
		NV_oOS_Docs_Hub_Rebuild_Job::handle_upgrade( $upgrader_object, $options );
	}

	/**
	 * Auto-rebuild after settings affecting the documentation index are saved.
	 *
	 * Fires on `update_option_nvoos_docs_hub_settings`. Only enqueues a
	 * rebuild when the saved old/new values for sources, remote_repos,
	 * context_enabled, or include_addon_readmes actually differ — a no-op
	 * re-save of the same values does not trigger work.
	 *
	 * @since 1.3.0
	 *
	 * @param array $old_value Previous settings.
	 * @param array $value     New settings.
	 * @return void
	 */
	public static function on_settings_changed( $old_value, $value ) {
		// Compare the fields that affect the index.
		$index_keys = array( 'sources', 'remote_repos', 'context_enabled', 'include_addon_readmes' );
		$changed    = false;
		foreach ( $index_keys as $key ) {
			$old = isset( $old_value[ $key ] ) ? $old_value[ $key ] : null;
			$new = isset( $value[ $key ] ) ? $value[ $key ] : null;
			// phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison -- arrays may be re-ordered; loose comparison is sufficient.
			if ( $old != $new ) {
				$changed = true;
				break;
			}
		}

		if ( ! $changed ) {
			return;
		}

		// Clear the live cache so the next manifest request triggers a rebuild
		// (the GET /manifest endpoint already auto-enqueues when the cache is
		// empty and an admin is logged in).
		$cache = new NV_oOS_Docs_Hub_Cache();
		$cache->clear();

		// Also enqueue the async rebuild immediately so it starts building
		// without waiting for the next visitor request.
		NV_oOS_Docs_Hub_Rebuild_Job::enqueue_async();
	}

	/**
	 * Display admin notices about addon status.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function admin_notices() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! nvoos_docs_hub_is_base_active() ) {
			echo '<div class="notice notice-warning is-dismissible"><p>';
			esc_html_e( 'NV oOS Docs Hub: the NV oOS base plugin is not active. Documentation discovery from the base plugin will be skipped.', 'nvoos-docs-hub' );
			echo '</p></div>';
		}
	}
}
