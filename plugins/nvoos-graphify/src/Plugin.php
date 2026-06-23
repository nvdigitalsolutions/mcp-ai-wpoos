<?php
declare(strict_types=1);

namespace NvoosGraphify;

use NvoosGraphify\Memory\Bridge;
use NvoosGraphify\Memory\EmbeddingsOnIngest;
use NvoosGraphify\Remote\Registry as RemoteRegistry;

/**
 * Composition root for the NV oOS Graphify plugin.
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
				if ( class_exists( 'NvoosGraphify\Rest\Controller' ) ) {
					( new \NvoosGraphify\Rest\Controller() )->registerRoutes();
				}
			}
		);

		// ─── Frontend ──────────────────────────────────────────
		$this->registerFrontend();

		// ─── Cron handlers ─────────────────────────────────────
		add_action( Schema::CRON_BUILD, array( $this, 'runScheduledBuild' ) );
		add_action( Schema::CRON_ENRICH, array( $this, 'runScheduledEnrich' ) );
		add_action( 'nvoos_graphify/initial_build', array( $this, 'runInitialBuild' ) );

		// ─── Auto-rebuild on post save ─────────────────────────
		add_action( 'save_post', array( $this, 'onSavePost' ), 20, 3 );

		// ─── Built-in tools (registered at plugins_loaded:11) ──
		add_action(
			'plugins_loaded',
			function (): void {
				$this->registerBuiltinTools();
			},
			11
		);

		// ─── Addon tool registration hook (plugins_loaded:20) ──
		add_action(
			'plugins_loaded',
			function (): void {
				/**
				 * Fires when NV oOS Graphify is ready for tool registration.
				 *
				 * Consumer addons hook into this to register their tools.
				 *
				 * @since 1.0.0
				 * @param ToolRegistry $registry The tool registry instance.
				 */
				do_action( Schema::ACTION_REGISTER_TOOLS, $this->toolRegistry );
			},
			20
		);

		// ─── Remote source driver registration ─────────────────
		add_action(
			Schema::ACTION_REGISTER_REMOTE_SOURCES,
			function (): void {
				$this->registerBuiltinDrivers();
			}
		);

		// ─── Memory bridge ─────────────────────────────────────
		if ( class_exists( 'NvoosGraphify\Memory\Bridge' ) ) {
			Bridge::register();
		}
		if ( class_exists( 'NvoosGraphify\Memory\EmbeddingsOnIngest' ) ) {
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
		if ( class_exists( 'NvoosGraphify\Admin\SettingsPage' ) ) {
			$settings = new \NvoosGraphify\Admin\SettingsPage();
			$settings->register();
		}

		if ( class_exists( 'NvoosGraphify\Admin\RemoteAdmin' ) ) {
			$remoteAdmin = new \NvoosGraphify\Admin\RemoteAdmin();
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
		if ( class_exists( 'NvoosGraphify\Frontend\Shortcode' ) ) {
			( new \NvoosGraphify\Frontend\Shortcode() )->register();
		}
		if ( class_exists( 'NvoosGraphify\Frontend\Block' ) ) {
			( new \NvoosGraphify\Frontend\Block() )->register();
		}
		if ( class_exists( 'NvoosGraphify\Frontend\SchemaOrg' ) ) {
			( new \NvoosGraphify\Frontend\SchemaOrg() )->register();
		}
		if ( class_exists( 'NvoosGraphify\Frontend\RelatedContent' ) ) {
			( new \NvoosGraphify\Frontend\RelatedContent() )->register();
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
		if ( NVOOS_GRAPHIFY_DB_VERSION !== $installedVer ) {
			if ( class_exists( 'NvoosGraphify\Graph\Db' ) ) {
				\NvoosGraphify\Graph\Db::install();
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
			'NvoosGraphify\Tools\GetNode',
			'NvoosGraphify\Tools\QueryGraph',
			'NvoosGraphify\Tools\GetNeighbors',
			'NvoosGraphify\Tools\BuildGraph',
			'NvoosGraphify\Tools\GraphStats',
			'NvoosGraphify\Tools\ShortestPath',
			'NvoosGraphify\Tools\ContentGaps',
			'NvoosGraphify\Tools\GodNodes',
			'NvoosGraphify\Tools\SuggestLinks',
			'NvoosGraphify\Tools\RetrieveContext',
			'NvoosGraphify\Tools\ResolveExternal',
			'NvoosGraphify\Tools\ListRemoteSources',
			'NvoosGraphify\Tools\SyncRemoteSource',
			'NvoosGraphify\Tools\GetCommunity',
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
			'NvoosGraphify\Remote\Drivers\Wikidata',
			'NvoosGraphify\Remote\Drivers\GenericRest',
			'NvoosGraphify\Remote\Drivers\RssSitemap',
			'NvoosGraphify\Remote\Drivers\Sparql',
			'NvoosGraphify\Remote\Drivers\WooCommerce',
			'NvoosGraphify\Remote\Drivers\Csv',
			'NvoosGraphify\Remote\Drivers\Webhook',
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
		if ( class_exists( 'NvoosGraphify\Graph\Builder' ) ) {
			$builder = new \NvoosGraphify\Graph\Builder();
			$builder->build();
		}
	}

	/** @return void */
	public function runScheduledEnrich(): void {
		// Enrichment from remote sources — wired in Phase 7.
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

		if ( class_exists( 'NvoosGraphify\Graph\Builder' ) ) {
			$builder = new \NvoosGraphify\Graph\Builder();
			$builder->buildPost( $post );
		}
	}

	// ───────────────────────────────────────────────────────────────
	// Admin notices
	// ───────────────────────────────────────────────────────────────

	/** @return void */
	public function renderAdminNotices(): void {
		$transientKey = Schema::TRANSIENT_PREFIX . 'build_complete';
		if ( get_transient( $transientKey ) ) {
			delete_transient( $transientKey );
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html__( 'NV oOS Graphify: Knowledge graph built successfully! View it in the Graph Explorer.', 'nvoos-graphify' )
			);
		}

		$settings = Settings::all();
		if ( empty( $settings['enabled'] ) ) {
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				esc_html__( 'NV oOS Graphify is installed but the graph is not enabled. Go to Settings → NV oOS Graphify to enable it.', 'nvoos-graphify' )
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
