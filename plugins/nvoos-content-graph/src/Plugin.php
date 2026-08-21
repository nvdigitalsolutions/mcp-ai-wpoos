<?php
declare(strict_types=1);

namespace NvoosContentGraph;

use NvoosContentGraph\Memory\Bridge;
use NvoosContentGraph\Memory\EmbeddingsOnIngest;
use NvoosContentGraph\Remote\Enricher;
use NvoosContentGraph\Remote\Registry as RemoteRegistry;

use function get_current_screen;
use function in_array;
use function wp_clear_scheduled_hook;
use function wp_next_scheduled;
use function wp_schedule_event;

/**
 * Composition root for the NV oOS Content Graph plugin.
 *
 * Wires all services, registers WordPress hooks, and exposes
 * singletons for consumer addons via public API functions.
 *
 * This class is intentionally kept lean at Phase 1 — each
 * `register*` method is fleshed out as its corresponding
 * subsystem is built in later phases.
 *
 * @since 1.0.0
 */
final class Plugin {

	/** @var self|null Singleton instance. */
	private static ?self $instance = null;

	/** @var ToolRegistry The tool registry instance. */
	private ToolRegistry $toolRegistry;

	/** @var RemoteRegistry The remote source driver registry. */
	private RemoteRegistry $remoteRegistry;

	/** Private constructor — use {@see instance()}. */
	private function __construct() {
		$this->toolRegistry   = new ToolRegistry();
		$this->remoteRegistry = new RemoteRegistry();
	}

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
	 * Register all WordPress hooks and wire services.
	 *
	 * Called once on plugins_loaded priority 10.
	 *
	 * @return void
	 */
	public function register(): void {
		// ─── DB schema upgrade check ───────────────────────────
		$this->upgradeDb();

		// ─── Admin UI ──────────────────────────────────────────
		if ( is_admin() ) {
			$this->registerAdmin();
		}

		// ─── REST API ──────────────────────────────────────────
		add_action(
			'rest_api_init',
			static function (): void {
				if ( class_exists( 'NvoosContentGraph\Rest\Controller' ) ) {
					( new \NvoosContentGraph\Rest\Controller() )->registerRoutes();
				}
			}
		);

		// ─── Frontend ──────────────────────────────────────────
		$this->registerFrontend();

		// ─── Cron handlers ─────────────────────────────────────
		add_action( Schema::CRON_BUILD, array( $this, 'runScheduledBuild' ) );
		add_action( Schema::CRON_ENRICH, array( $this, 'runScheduledEnrich' ) );
		add_action( 'nvoos_content_graph/initial_build', array( $this, 'runInitialBuild' ) );

		// Keep the recurring rebuild event in sync with the saved schedule.
		$this->syncRebuildSchedule();
		add_action(
			'update_option_' . Schema::OPTION_SETTINGS,
			static function (): void {
				Plugin::instance()->syncRebuildSchedule( true );
			}
		);

		// Embeddings reindex batches (registered here, not in the
		// admin-only RemoteAdmin::register(), so WP-Cron requests can
		// continue batching without admin context).
		if ( class_exists( 'NvoosContentGraph\Admin\RemoteAdmin' ) ) {
			add_action( 'nvoos_content_graph_cron_reindex_embeddings', array( 'NvoosContentGraph\Admin\RemoteAdmin', 'reindexAllEmbeddings' ) );
		}

		// ─── Auto-rebuild on post save ─────────────────────────
		add_action( 'save_post', array( $this, 'onSavePost' ), 20, 3 );

		// ─── Built-in tools ────────────────────────────────────
		$this->registerBuiltinTools();

		/**
		 * Fires when NV oOS Content Graph is ready for tool registration.
		 *
		 * Consumer addons hook into this to register their tools.
		 *
		 * @since 1.0.0
		 * @param ToolRegistry $registry The tool registry instance.
		 */
		do_action( Schema::ACTION_REGISTER_TOOLS, $this->toolRegistry );

		// ─── Built-in remote source drivers ────────────────────
		$this->registerBuiltinDrivers();

		/**
		 * Fires when Content Graph is ready for remote source driver
		 * registration.
		 *
		 * Consumer addons hook into this to register their drivers.
		 *
		 * @since 1.0.0
		 * @param \NvoosContentGraph\Remote\Registry $registry The remote source registry.
		 */
		do_action( Schema::ACTION_REGISTER_REMOTE_SOURCES, $this->remoteRegistry );

		// ─── Memory bridge ─────────────────────────────────────
		if ( class_exists( 'NvoosContentGraph\Memory\Bridge' ) ) {
			Bridge::register();
		}
		if ( class_exists( 'NvoosContentGraph\Memory\EmbeddingsOnIngest' ) ) {
			EmbeddingsOnIngest::register();
		}
	}

	// ───────────────────────────────────────────────────────────────
	// Subsystem registration (progressively filled in per phase)
	// ───────────────────────────────────────────────────────────────

	/**
	 * Register admin components.
	 *
	 * @return void
	 */
	private function registerAdmin(): void {
		if ( class_exists( 'NvoosContentGraph\Admin\SettingsPage' ) ) {
			$settings = new \NvoosContentGraph\Admin\SettingsPage();
			$settings->register();
		}

		if ( class_exists( 'NvoosContentGraph\Admin\RemoteAdmin' ) ) {
			$remoteAdmin = new \NvoosContentGraph\Admin\RemoteAdmin();
			$remoteAdmin->register();
		}

		add_action( 'admin_notices', array( $this, 'renderAdminNotices' ) );
	}

	/**
	 * Register frontend components.
	 *
	 * @return void
	 */
	private function registerFrontend(): void {
		if ( class_exists( 'NvoosContentGraph\Frontend\Shortcode' ) ) {
			( new \NvoosContentGraph\Frontend\Shortcode() )->register();
		}
		if ( class_exists( 'NvoosContentGraph\Frontend\Block' ) ) {
			( new \NvoosContentGraph\Frontend\Block() )->register();
		}
		if ( class_exists( 'NvoosContentGraph\Frontend\SchemaOrg' ) ) {
			( new \NvoosContentGraph\Frontend\SchemaOrg() )->register();
		}
		if ( class_exists( 'NvoosContentGraph\Frontend\RelatedContent' ) ) {
			( new \NvoosContentGraph\Frontend\RelatedContent() )->register();
		}
	}

	// ───────────────────────────────────────────────────────────────
	// DB / Settings
	// ───────────────────────────────────────────────────────────────

	/**
	 * Check DB schema version and upgrade if needed.
	 *
	 * @return void
	 */
	public function upgradeDb(): void {
		$installedVer = get_option( Schema::OPTION_DB_VERSION, '0' );
		if ( NVOOS_CONTENT_GRAPH_DB_VERSION !== $installedVer ) {
			if ( class_exists( 'NvoosContentGraph\Graph\Db' ) ) {
				\NvoosContentGraph\Graph\Db::install();
			}
		}
	}

	// ───────────────────────────────────────────────────────────────
	// Tool registration
	// ───────────────────────────────────────────────────────────────

	/**
	 * Register all 14 built-in tools with the tool registry.
	 *
	 * Each class is registered only when its file exists
	 * (tools are built out in Phase 4).
	 *
	 * @return void
	 */
	private function registerBuiltinTools(): void {
		$toolClasses = array(
			'NvoosContentGraph\Tools\GetNode',
			'NvoosContentGraph\Tools\QueryGraph',
			'NvoosContentGraph\Tools\GetNeighbors',
			'NvoosContentGraph\Tools\BuildGraph',
			'NvoosContentGraph\Tools\GraphStats',
			'NvoosContentGraph\Tools\ShortestPath',
			'NvoosContentGraph\Tools\ContentGaps',
			'NvoosContentGraph\Tools\GodNodes',
			'NvoosContentGraph\Tools\SuggestLinks',
			'NvoosContentGraph\Tools\RetrieveContext',
			'NvoosContentGraph\Tools\ResolveExternal',
			'NvoosContentGraph\Tools\ListRemoteSources',
			'NvoosContentGraph\Tools\SyncRemoteSource',
			'NvoosContentGraph\Tools\GetCommunity',
		);

		foreach ( $toolClasses as $className ) {
			if ( class_exists( $className ) ) {
				$this->toolRegistry->register( new $className() );
			}
		}
	}

	/**
	 * Register built-in remote source drivers.
	 *
	 * @return void
	 */
	private function registerBuiltinDrivers(): void {
		$driverClasses = array(
			'NvoosContentGraph\Remote\Drivers\Wikidata',
			'NvoosContentGraph\Remote\Drivers\GenericRest',
			'NvoosContentGraph\Remote\Drivers\RssSitemap',
			'NvoosContentGraph\Remote\Drivers\Sparql',
			'NvoosContentGraph\Remote\Drivers\WooCommerce',
			'NvoosContentGraph\Remote\Drivers\Csv',
			'NvoosContentGraph\Remote\Drivers\Webhook',
		);

		foreach ( $driverClasses as $className ) {
			if ( class_exists( $className ) ) {
				$this->remoteRegistry->registerDriver( new $className() );
			}
		}
	}

	// ───────────────────────────────────────────────────────────────
	// Cron / Build handlers
	// ───────────────────────────────────────────────────────────────

	/** @return void */
	public function runScheduledBuild(): void {
		if ( class_exists( 'NvoosContentGraph\Graph\Builder' ) ) {
			$builder = new \NvoosContentGraph\Graph\Builder();
			$builder->build();
		}
	}

	/** @return void */
	public function runScheduledEnrich(): void {
		$enricher = new Enricher();
		$enricher->enrichAll( false );
	}

	/** @return void */
	public function runInitialBuild(): void {
		$this->runScheduledBuild();
		set_transient(
			Schema::TRANSIENT_PREFIX . 'build_complete',
			true,
			300
		);
	}

	/**
	 * Ensure the recurring rebuild cron event matches the saved schedule.
	 *
	 * Idempotent: when `$force` is false the event is only created when
	 * missing; when true it is cleared and re-created so interval changes
	 * take effect immediately. Called on boot, on activation, and after
	 * every settings save.
	 *
	 * @since 1.0.3
	 *
	 * @param bool $force Clear and re-create the event.
	 * @return void
	 */
	public function syncRebuildSchedule( bool $force = false ): void {
		$schedule = (string) Settings::get( 'rebuild_schedule', 'weekly' );

		if ( ! in_array( $schedule, array( 'hourly', 'twicedaily', 'daily', 'weekly', 'never' ), true ) ) {
			$schedule = 'weekly';
		}

		$next = wp_next_scheduled( Schema::CRON_BUILD );
		if ( $force && $next ) {
			wp_clear_scheduled_hook( Schema::CRON_BUILD );
			$next = false;
		}

		if ( 'never' === $schedule ) {
			if ( ! $force && $next ) {
				wp_clear_scheduled_hook( Schema::CRON_BUILD );
			}
			return;
		}

		if ( ! $next ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, $schedule, Schema::CRON_BUILD );
		}
	}

	// ───────────────────────────────────────────────────────────────
	// Post save handler
	// ───────────────────────────────────────────────────────────────

	/**
	 * Trigger incremental rebuild when a post is published/updated.
	 *
	 * @param int      $postId Post ID.
	 * @param \WP_Post $post  Post object.
	 * @param bool     $update Whether this is an update.
	 * @return void
	 */
	public function onSavePost( int $postId, \WP_Post $post, bool $update ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- WordPress save_post action signature
		$settings = Settings::all();
		if ( empty( $settings['auto_rebuild'] ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( 'publish' !== $post->post_status ) {
			return;
		}

		if ( class_exists( 'NvoosContentGraph\Graph\Builder' ) ) {
			$builder = new \NvoosContentGraph\Graph\Builder();
			$builder->buildPost( $post );
		}
	}

	// ───────────────────────────────────────────────────────────────
	// Admin notices
	// ───────────────────────────────────────────────────────────────

	/** @return void */
	public function renderAdminNotices(): void {
		// Only show plugin notices on the plugin's own admin page.
		$screen       = get_current_screen();
		$isPluginPage = $screen && false !== strpos( $screen->id, Admin\SettingsPage::PAGE_SLUG );

		// Build-complete success notice is transient-driven and dismissible,
		// so it is safe to show site-wide (it self-removes after one view).
		$transientKey = Schema::TRANSIENT_PREFIX . 'build_complete';
		if ( get_transient( $transientKey ) ) {
			delete_transient( $transientKey );
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html__( 'NV oOS Content Graph: Knowledge graph built successfully! View it in the Graph Explorer.', 'nvoos-content-graph' )
			);
		}

		// The remaining notices only make sense on the plugin's own page.
		if ( ! $isPluginPage ) {
			return;
		}

		// Warn when OpenSSL is unavailable (credentials stored with weak fallback).
		if ( class_exists( 'NvoosContentGraph\Remote\Crypto' ) && ! \NvoosContentGraph\Remote\Crypto::isAvailable() ) {
			printf(
				'<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
				esc_html__( 'NV oOS Content Graph: The OpenSSL PHP extension is not available. Remote-source credentials (API keys, tokens) will be stored with weak encryption. Please enable the OpenSSL extension for secure credential storage.', 'nvoos-content-graph' )
			);
		}

		$settings = Settings::all();
		if ( empty( $settings['enabled'] ) ) {
			printf(
				'<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
				esc_html__( 'NV oOS Content Graph is installed but the graph is not enabled. Go to Settings → NV oOS Content Graph to enable it.', 'nvoos-content-graph' )
			);
		}
	}

	// ───────────────────────────────────────────────────────────────
	// Accessors
	// ───────────────────────────────────────────────────────────────

	/**
	 * Get the tool registry instance.
	 *
	 * @return ToolRegistry
	 */
	public function getToolRegistry(): ToolRegistry {
		return $this->toolRegistry;
	}

	/**
	 * Get the remote source registry instance.
	 *
	 * @return RemoteRegistry
	 */
	public function getRemoteRegistry(): RemoteRegistry {
		return $this->remoteRegistry;
	}

	/** Prevent cloning. */
	private function __clone() {}
}
