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
		add_action( 'init', array( __CLASS__, 'schedule_rebuild_cron' ) );
		add_action( 'init', array( __CLASS__, 'register_shortcodes' ), 12 );
		add_action( 'init', array( __CLASS__, 'register_block' ), 12 );
		add_action( 'rest_api_init', array( __CLASS__, 'init_rest' ) );
		add_action( 'nvoos_docs_hub_rebuild_cron', array( __CLASS__, 'run_scheduled_rebuild' ) );
		add_action( 'activated_plugin', array( __CLASS__, 'clear_cache_on_change' ) );
		add_action( 'deactivated_plugin', array( __CLASS__, 'on_plugin_deactivated' ) );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'on_upgrader_complete' ), 10, 2 );

		// Activation: create the slug-named uploads content folder for the
		// default local source (with a blank index.html to prevent directory
		// listing) and migrate any pre-0.5.1 uploads/docs content into it.
		register_activation_hook( NVOOS_DOCS_HUB_FILE, array( __CLASS__, 'on_activate' ) );

		// Deactivation cleanup: drop the daily rebuild cron event and any
		// pending chunked-rebuild ticks so no ghost events linger while the
		// plugin is inactive.
		register_deactivation_hook( NVOOS_DOCS_HUB_FILE, array( 'NV_oOS_Docs_Hub_Rebuild_Job', 'unschedule' ) );

		// The base plugin's built-in updater replaces files in place and never
		// fires upgrader_process_complete — it emits this action instead.
		add_action( 'wp_mcp_ai_plugin_updated', array( __CLASS__, 'on_base_plugin_updated' ) );

		// Deterministic safety net: when an admin loads any admin page, a
		// version mismatch between the cached manifest and the installed
		// plugins triggers a rebuild. Covers update paths that never fired
		// any hook (e.g. manual file replacement).
		add_action( 'admin_init', array( __CLASS__, 'maybe_rebuild_after_version_change' ) );

		// One-time migration of any pre-0.5.1 uploads/docs content into the
		// slug-named content folder (upgrades skip the activation hook).
		add_action( 'admin_init', array( __CLASS__, 'maybe_migrate_legacy_content_dir' ) );

		// Auto-trigger a rebuild when settings that affect the index are changed
		// (sources, remote_repos, context_enabled, include_addon_readmes).
		add_action( 'update_option_' . self::OPTION_KEY, array( __CLASS__, 'on_settings_changed' ), 10, 2 );

		// Register the chunked-rebuild tick handler. Settings page
		// registers itself when its file is loaded (admin context only).
		NV_oOS_Docs_Hub_Rebuild_Pipeline::register();
	}

	/**
	 * Ensure the daily rebuild cron event is scheduled.
	 *
	 * Runs on init — never on plugins_loaded. wp_schedule_event() consults
	 * wp_get_schedules(), which applies the cron_schedules filter. Several
	 * popular plugins register translated schedule names in that filter
	 * (e.g. WooCommerce's monthly interval), so calling wp_schedule_event()
	 * before init triggers WordPress 6.7+'s "translation loading triggered
	 * too early" notice on sites with those plugins active. Translations
	 * load on init, so scheduling there is safe.
	 *
	 * Translations are loaded automatically by WordPress core (4.6+) from
	 * the `languages/` directory declared in the plugin header — no
	 * load_plugin_textdomain() call is needed for wp.org-hosted plugins.
	 *
	 * @since 0.4.7
	 *
	 * @return void
	 */
	public static function schedule_rebuild_cron() {
		NV_oOS_Docs_Hub_Rebuild_Job::schedule();
	}

	/**
	 * Activation: migrate legacy content and ensure the content folder exists.
	 *
	 * Moves any pre-0.5.1 uploads/docs content into the plugin's slug-named
	 * folder (wp-content/uploads/nvoos-docs-hub/content/) and writes a blank
	 * index.html so the folder cannot be directory-listed on hosts that
	 * expose uploads without an index file.
	 *
	 * @since 0.5.0
	 * @since 0.5.1 Migrates the legacy uploads/docs folder.
	 *
	 * @return void
	 */
	public static function on_activate() {
		// One-time migration: move any pre-0.5.1 uploads/docs content into
		// the slug-named content folder before it is (re)created below.
		self::migrate_legacy_uploads_dir();

		$dir = self::uploads_docs_dir();
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return;
		}
		$guard = $dir . DIRECTORY_SEPARATOR . 'index.html';
		if ( ! file_exists( $guard ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- tiny empty guard file, best-effort.
			@file_put_contents( $guard, '' );
		}
	}

	/**
	 * Absolute path to the uploads content directory.
	 *
	 * This is the plugin's default local documentation source: site owners
	 * drop Markdown / text files here and they are published by the
	 * documentation browser after a rebuild. The folder lives inside the
	 * plugin's slug-named uploads directory
	 * (wp-content/uploads/nvoos-docs-hub/content/), resolved at runtime via
	 * wp_upload_dir() — never a hard-coded path. Filterable so sites that
	 * keep docs elsewhere (or multi-site setups with custom upload dirs)
	 * can point the source at a different location.
	 *
	 * @since 0.5.0
	 * @since 0.5.1 Moved from wp-content/uploads/docs/ into the slug-named folder.
	 *
	 * @return string Absolute directory path.
	 */
	public static function uploads_docs_dir() {
		$info = wp_upload_dir();
		$dir  = ( isset( $info['basedir'] ) ? (string) $info['basedir'] : '' ) . '/nvoos-docs-hub/content';

		/**
		 * Filter the uploads content directory scanned by the default local source.
		 *
		 * @since 0.5.0
		 *
		 * @param string $dir Absolute directory path.
		 */
		return apply_filters( 'nvoos_docs_hub_uploads_docs_dir', $dir );
	}

	/**
	 * One-time migration of the legacy uploads/docs content folder.
	 *
	 * Installs created with 0.5.0 dropped Markdown into
	 * wp-content/uploads/docs/. 0.5.1 moved the folder inside the plugin's
	 * slug-named uploads directory; this migrates any existing content so
	 * nothing is lost. Runs at most once per site — upgrades skip the
	 * activation hook, so it is also hooked to admin_init.
	 *
	 * @since 0.5.1
	 *
	 * @return void
	 */
	public static function maybe_migrate_legacy_content_dir() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( '1' === get_option( 'nvoos_docs_hub_content_migrated', '' ) ) {
			return;
		}
		self::migrate_legacy_uploads_dir();
		update_option( 'nvoos_docs_hub_content_migrated', '1' );
	}

	/**
	 * Move the legacy uploads/docs folder into the slug-named content folder.
	 *
	 * Best-effort and non-destructive: when the target already exists only
	 * missing entries are moved, conflicting files are left in place, and
	 * the legacy folder is removed only when it ends up empty. Symlinks are
	 * never followed.
	 *
	 * @since 0.5.1
	 *
	 * @return void
	 */
	public static function migrate_legacy_uploads_dir() {
		$info   = wp_upload_dir();
		$legacy = ( isset( $info['basedir'] ) ? (string) $info['basedir'] : '' ) . '/docs';

		if ( ! is_dir( $legacy ) || is_link( $legacy ) ) {
			return;
		}

		$target = self::uploads_docs_dir();

		if ( is_dir( $target ) ) {
			self::move_dir_contents( $legacy, $target );
		} elseif ( @rename( $legacy, $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.rename_rename -- best-effort same-filesystem fast path.
			return;
		} else {
			// Cross-device or restricted filesystem: create the target and
			// move entries one by one.
			wp_mkdir_p( $target );
			self::move_dir_contents( $legacy, $target );
		}

		// Remove the legacy folder only when nothing was left behind.
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- best-effort; non-empty dirs stay.
		@rmdir( $legacy );
	}

	/**
	 * Move the contents of one directory into another, entry by entry.
	 *
	 * @since 0.5.1
	 *
	 * @param string $from Source directory.
	 * @param string $to   Destination directory.
	 * @return void
	 */
	private static function move_dir_contents( $from, $to ) {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- best-effort.
		$entries = @scandir( $from );
		if ( ! is_array( $entries ) ) {
			return;
		}
		foreach ( $entries as $name ) {
			if ( '.' === $name || '..' === $name ) {
				continue;
			}
			$src = $from . DIRECTORY_SEPARATOR . $name;
			$dst = $to . DIRECTORY_SEPARATOR . $name;

			if ( is_link( $src ) ) {
				continue; // Never follow (or move) symlinks.
			}
			if ( is_dir( $src ) ) {
				if ( ! is_dir( $dst ) ) {
					wp_mkdir_p( $dst );
				}
				self::move_dir_contents( $src, $dst );
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- best-effort; non-empty dirs stay.
				@rmdir( $src );
			} elseif ( ! file_exists( $dst ) ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.rename_rename -- best-effort.
				if ( ! @rename( $src, $dst ) ) {
					// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- cross-device fallback; source removed only after a successful copy.
					if ( @copy( $src, $dst ) ) {
						wp_delete_file( $src );
					}
				}
			} elseif ( 'index.html' === $name ) {
				// The 0.5.0 activation guard file: the target already has its
				// own guard, so drop the legacy copy — this lets the legacy
				// folder be removed once it is otherwise empty.
				wp_delete_file( $src );
			}
		}
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

		$has_saved_option = is_array( $option );

		// Fresh install (option does not yet exist) → local-first defaults:
		// index the uploads content folder; remote GitHub import is opt-in
		// (off). Existing installs keep their saved sources unchanged.
		$default_sources = $has_saved_option
			? array( 'base', 'addons', 'root' )
			: array( 'uploads' );

		$parsed = wp_parse_args(
			$has_saved_option ? $option : array(),
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
				'enable_remote_repos'   => false,
			)
		);

		// Migration for installs created before 0.5.0: the opt-in remote
		// toggle did not exist. Installs already using remote repositories
		// must keep them enabled — default the toggle to ON when saved
		// settings contain configured remote repos or list 'remote' as a
		// source. Fresh installs and purely-local installs stay OFF.
		if ( $has_saved_option && ! isset( $option['enable_remote_repos'] ) ) {
			$has_remote_config             = ( isset( $parsed['remote_repos'] ) && is_array( $parsed['remote_repos'] ) && ! empty( $parsed['remote_repos'] ) )
				|| ( isset( $parsed['sources'] ) && is_array( $parsed['sources'] ) && in_array( 'remote', $parsed['sources'], true ) );
			$parsed['enable_remote_repos'] = $has_remote_config;
		}

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
	 * Clear doc cache when a plugin is activated.
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
	 * Clear doc cache when a plugin is deactivated.
	 *
	 * When Docs Hub itself is deactivated, the deactivation hook has already
	 * unscheduled the rebuild cron events, so a rebuild must not be
	 * re-enqueued — its tick callbacks no longer exist once the plugin is
	 * inactive. The cache is still cleared so a later re-activation starts
	 * from a fresh index.
	 *
	 * @since 0.4.7
	 *
	 * @param string $plugin Plugin file path relative to plugins directory.
	 * @return void
	 */
	public static function on_plugin_deactivated( $plugin ) {
		if ( plugin_basename( NVOOS_DOCS_HUB_FILE ) === (string) $plugin ) {
			$cache = new NV_oOS_Docs_Hub_Cache();
			$cache->clear( true );
			return;
		}

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
	 * Handle the base plugin updater's post-update notification.
	 *
	 * @since 0.4.1
	 *
	 * @param string $basename Updated plugin's file path relative to the plugins directory.
	 * @return void
	 */
	public static function on_base_plugin_updated( $basename ) {
		NV_oOS_Docs_Hub_Rebuild_Job::handle_plugin_update_notice( $basename );
	}

	/**
	 * Rebuild the index when the base plugin or this addon changed version.
	 *
	 * The manifest records the plugin versions it was built against. When
	 * either differs from the installed version, the cache is cleared and
	 * an async rebuild is enqueued. This guarantees the index refreshes
	 * after an update even when no hook fired (in-place updater, manual
	 * file replacement, plugin restore from backup).
	 *
	 * @since 0.4.1
	 *
	 * @return void
	 */
	public static function maybe_rebuild_after_version_change() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$cache    = new NV_oOS_Docs_Hub_Cache();
		$manifest = $cache->get_manifest();
		if ( ! is_array( $manifest ) ) {
			// Nothing cached yet — the first manifest request auto-enqueues
			// a rebuild, so there is nothing to reconcile here.
			return;
		}

		$built_addon  = isset( $manifest['version'] ) ? (string) $manifest['version'] : '';
		$built_base   = isset( $manifest['base_version'] ) ? (string) $manifest['base_version'] : '';
		$current_base = defined( 'WP_MCP_AI_VERSION' ) ? WP_MCP_AI_VERSION : '';

		if ( NVOOS_DOCS_HUB_VERSION === $built_addon && $current_base === $built_base ) {
			return;
		}

		$cache->clear( true );
		NV_oOS_Docs_Hub_Rebuild_Job::enqueue_async();
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
		$index_keys = array( 'sources', 'remote_repos', 'context_enabled', 'include_addon_readmes', 'enable_remote_repos' );
		$changed    = false;
		foreach ( $index_keys as $key ) {
			$old = isset( $old_value[ $key ] ) ? $old_value[ $key ] : null;
			$new = isset( $value[ $key ] ) ? $value[ $key ] : null;
			// phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual, WordPress.PHP.StrictComparisons.LooseComparison -- arrays may be re-ordered; loose comparison is sufficient.
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
	 * Notices are scoped to the plugin's own settings screen (Guideline 11 —
	 * notices must be contextual and must not pollute every admin page).
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function admin_notices() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! self::is_settings_screen() ) {
			return;
		}

		if ( ! nvoos_docs_hub_is_base_active() ) {
			echo '<div class="notice notice-warning is-dismissible"><p>';
			esc_html_e( 'NV oOS Docs Hub: the NV oOS base plugin is not active. Documentation discovery from the base plugin will be skipped.', 'nvoos-docs-hub' );
			echo '</p></div>';
		}
	}

	/**
	 * Whether the current admin screen is the plugin's settings page.
	 *
	 * @since 0.4.4
	 *
	 * @return bool
	 */
	private static function is_settings_screen() {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return false;
		}
		$screen = get_current_screen();
		if ( ! $screen ) {
			return false;
		}
		return 'settings_page_nvoos-docs-hub' === $screen->id;
	}
}
