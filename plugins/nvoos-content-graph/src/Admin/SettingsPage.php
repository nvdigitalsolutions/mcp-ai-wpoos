<?php
declare(strict_types=1);

namespace NvoosContentGraph\Admin;

use NvoosContentGraph\Schema;
use NvoosContentGraph\Settings;
use NvoosContentGraph\Graph\Db;
use NvoosContentGraph\Graph\Builder;

use function __;
use function absint;
use function add_action;
use function add_menu_page;
use function add_query_arg;
use function admin_url;
use function class_exists;
use function current_user_can;
use function delete_transient;
use function do_action;
use function esc_attr;
use function esc_attr__;
use function esc_html;
use function esc_html__;
use function esc_js;
use function esc_url;
use function esc_url_raw;
use function get_option;
use function get_transient;
use function number_format_i18n;
use function register_setting;
use function rest_url;
use function sanitize_key;
use function set_transient;
use function settings_errors;
use function strpos;
use function submit_button;
use function wp_create_nonce;
use function wp_date;
use function wp_die;
use function wp_enqueue_script;
use function wp_enqueue_style;
use function wp_localize_script;
use function wp_parse_str;
use function wp_parse_url;
use function wp_send_json_error;
use function wp_send_json_success;
use function wp_unslash;

/**
 * Admin settings page for the NV oOS Content Graph plugin.
 *
 * Registers a standalone top-level "Knowledge Graph" menu page with
 * tabbed settings, a graph overview stats card with rebuild button,
 * and the Cytoscape.js graph explorer.
 *
 * Uses the Section/Registry pattern — each settings section is a
 * concrete subclass of {@see Section} registered into
 * {@see SettingsRegistry}. Addons hook `nvoos_content_graph/admin/register_sections`
 * to inject their own tabs and sections.
 *
 * Pattern mirrored from the NV oOS base plugin's Settings_Dashboard.
 *
 * @since 1.0.0
 */
class SettingsPage {

	/**
	 * Settings page slug.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const PAGE_SLUG = 'nvoos-content-graph';

	/**
	 * Register admin hooks.
	 *
	 * Called by {@see \NvoosContentGraph\Plugin::registerAdmin()}.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'addMenuPage' ) );
		add_action( 'admin_init', array( $this, 'registerSettings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueueAssets' ) );
		add_action( 'wp_ajax_nvoos_content_graph_build', array( $this, 'handleAjaxBuild' ) );
	}

	/**
	 * Add the standalone "Knowledge Graph" top-level menu page.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function addMenuPage(): void {
		add_menu_page(
			__( 'NV Content Graph', 'nvoos-content-graph' ),
			__( 'NV Content Graph', 'nvoos-content-graph' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'renderPage' ),
			'dashicons-networking',
			85
		);
	}

	/**
	 * Register settings, tabs, and sections.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function registerSettings(): void {
		register_setting(
			'nvoos_content_graph_settings_group',
			Schema::OPTION_SETTINGS,
			array( 'sanitize_callback' => array( $this, 'sanitizeSettings' ) )
		);

		// ─── Register core tabs ─────────────────────────────────
		SettingsRegistry::register_tab( 'general', __( 'General', 'nvoos-content-graph' ) );
		SettingsRegistry::register_tab( 'sources', __( 'Sources (CPT / CCT)', 'nvoos-content-graph' ) );
		SettingsRegistry::register_tab( 'remote', __( 'Remote Sources', 'nvoos-content-graph' ) );
		SettingsRegistry::register_tab( 'embeddings', __( 'Embeddings', 'nvoos-content-graph' ) );
		SettingsRegistry::register_tab( 'appearance', __( 'Appearance', 'nvoos-content-graph' ) );

		// ─── Register core sections ─────────────────────────────-
		if ( class_exists( 'NvoosContentGraph\Admin\Sections\GeneralSection' ) ) {
			SettingsRegistry::register_section( new \NvoosContentGraph\Admin\Sections\GeneralSection() );
		}
		if ( class_exists( 'NvoosContentGraph\Admin\Sections\BuildSection' ) ) {
			SettingsRegistry::register_section( new \NvoosContentGraph\Admin\Sections\BuildSection() );
		}
		if ( class_exists( 'NvoosContentGraph\Admin\Sections\DisplaySection' ) ) {
			SettingsRegistry::register_section( new \NvoosContentGraph\Admin\Sections\DisplaySection() );
		}
		if ( class_exists( 'NvoosContentGraph\Admin\Sections\RemoteSection' ) ) {
			SettingsRegistry::register_section( new \NvoosContentGraph\Admin\Sections\RemoteSection() );
		}
		if ( class_exists( 'NvoosContentGraph\Admin\Sections\EmbeddingsSection' ) ) {
			SettingsRegistry::register_section( new \NvoosContentGraph\Admin\Sections\EmbeddingsSection() );
		}
		if ( class_exists( 'NvoosContentGraph\Admin\Sections\SourcesCptsSection' ) ) {
			SettingsRegistry::register_section( new \NvoosContentGraph\Admin\Sections\SourcesCptsSection() );
		}
		if ( class_exists( 'NvoosContentGraph\Admin\Sections\SourcesExtSection' ) ) {
			SettingsRegistry::register_section( new \NvoosContentGraph\Admin\Sections\SourcesExtSection() );
		}
		if ( class_exists( 'NvoosContentGraph\Admin\Sections\AppearanceSection' ) ) {
			SettingsRegistry::register_section( new \NvoosContentGraph\Admin\Sections\AppearanceSection() );
		}

		/**
		 * Fires after core sections are registered so addons
		 * can register their own tabs and sections.
		 *
		 * @since 1.0.0
		 */
		do_action( 'nvoos_content_graph/admin/register_sections' );
	}

	/**
	 * Sanitize incoming settings merged with existing values.
	 *
	 * Delegates to each section's {@see Section::sanitize()} method
	 * for the submitted tab.
	 *
	 * @param mixed $raw Submitted form data.
	 * @return array<string,mixed>
	 */
	public function sanitizeSettings( $raw ): array {
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}

		$existing = Settings::all();

		// Determine the active tab from the referer.
		// This runs inside a register_setting() sanitization callback;
		// WordPress core handles nonce verification before invoking sanitizers.
		$referer = wp_get_referer();

		$tab = 'general';
		if ( is_string( $referer ) && '' !== $referer ) {
			$query = wp_parse_url( $referer, PHP_URL_QUERY );
			if ( is_string( $query ) && '' !== $query ) {
				$args = array();
				wp_parse_str( $query, $args );
				$tab = isset( $args['tab'] ) ? sanitize_key( $args['tab'] ) : 'general';
			}
		}

		$merged = $existing;

		$sections = SettingsRegistry::get_sections( $tab );
		foreach ( $sections as $section ) {
			$sanitized = $section->sanitize( $raw );
			$merged    = array_merge( $merged, $sanitized );
		}

		return $merged;
	}

	/**
	 * Render the settings page.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function renderPage(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'nvoos-content-graph' ) );
		}

		$stats      = Db::getStats();
		$last_build = Db::getMeta( 'last_build_completed', __( 'Never', 'nvoos-content-graph' ) );
		$status     = Db::getMeta( 'build_status', 'idle' );
		$settings   = Settings::all();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'general';
		$tabs        = SettingsRegistry::get_tabs();
		?>
		<div class="wrap nvoos-content-graph-admin">
			<h1><?php esc_html_e( 'NV Content Graph', 'nvoos-content-graph' ); ?></h1>

			<?php settings_errors(); ?>

			<?php
			// Display last build error if present.
			$last_error = get_transient( Schema::TRANSIENT_PREFIX . 'last_build_error' );
			if ( is_array( $last_error ) && ! empty( $last_error['message'] ) ) :
				$error_time = isset( $last_error['timestamp'] )
					? wp_date(
						get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
						$last_error['timestamp']
					)
					: __( 'Unknown', 'nvoos-content-graph' );
				$error_file = isset( $last_error['file'] ) ? $last_error['file'] : '';
				$error_line = isset( $last_error['line'] ) ? $last_error['line'] : '';
				?>
				<div class="notice notice-error is-dismissible">
					<p>
						<strong><?php esc_html_e( 'Build Error', 'nvoos-content-graph' ); ?></strong>
						(<?php echo esc_html( $error_time ); ?>)
					</p>
					<p>
						<code><?php echo esc_html( $last_error['message'] ); ?></code>
					</p>
					<?php if ( '' !== $error_file ) : ?>
						<p class="description">
							<?php
							echo esc_html(
								sprintf(
									/* translators: 1: file path, 2: line number */
									__( 'File: %1$s, line %2$d', 'nvoos-content-graph' ),
									$error_file,
									$error_line
								)
							);
							?>
						</p>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<?php /* Graph overview card */ ?>
			<div class="nvoos-content-graph-stats-card">
				<h2><?php esc_html_e( 'Graph Overview', 'nvoos-content-graph' ); ?></h2>
				<div class="nvoos-content-graph-stats-grid">
					<div class="nvoos-content-graph-stat">
						<span class="nvoos-content-graph-stat-value"><?php echo esc_html( number_format_i18n( $stats['node_count'] ) ); ?></span>
						<span class="nvoos-content-graph-stat-label"><?php esc_html_e( 'Nodes', 'nvoos-content-graph' ); ?></span>
					</div>
					<div class="nvoos-content-graph-stat">
						<span class="nvoos-content-graph-stat-value"><?php echo esc_html( number_format_i18n( $stats['edge_count'] ) ); ?></span>
						<span class="nvoos-content-graph-stat-label"><?php esc_html_e( 'Edges', 'nvoos-content-graph' ); ?></span>
					</div>
					<div class="nvoos-content-graph-stat">
						<span class="nvoos-content-graph-stat-value"><?php echo esc_html( number_format_i18n( $stats['community_count'] ) ); ?></span>
						<span class="nvoos-content-graph-stat-label"><?php esc_html_e( 'Communities', 'nvoos-content-graph' ); ?></span>
					</div>
				</div>
				<p class="nvoos-content-graph-last-build">
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: build status, 2: last build time */
							__( 'Status: %1$s — Last build: %2$s', 'nvoos-content-graph' ),
							$status,
							$last_build
						)
					);
					?>
				</p>
				<button id="nvoos-content-graph-build-btn" class="button button-primary">
					<?php esc_html_e( 'Rebuild Graph', 'nvoos-content-graph' ); ?>
				</button>
				<span id="nvoos-content-graph-build-status" style="margin-left:12px; display:none;"></span>
			</div>

			<?php /* Graph explorer */ ?>
			<?php if ( $stats['node_count'] > 0 ) : ?>
			<div class="nvoos-content-graph-explorer-wrap">
				<h2><?php esc_html_e( 'Graph Explorer', 'nvoos-content-graph' ); ?></h2>
				<div class="nvoos-content-graph-explorer-toolbar">
					<input type="text" id="nvoos-content-graph-search" placeholder="<?php esc_attr_e( 'Search nodes…', 'nvoos-content-graph' ); ?>">
					<select id="nvoos-content-graph-type-filter">
						<option value=""><?php esc_html_e( 'All types', 'nvoos-content-graph' ); ?></option>
					</select>
					<select id="nvoos-content-graph-color-by" title="<?php esc_attr_e( 'Color nodes by — live preview, saved in Appearance settings', 'nvoos-content-graph' ); ?>">
						<option value="type"><?php esc_html_e( 'Color: Type', 'nvoos-content-graph' ); ?></option>
						<option value="community"><?php esc_html_e( 'Color: Community', 'nvoos-content-graph' ); ?></option>
						<option value="degree"><?php esc_html_e( 'Color: Degree', 'nvoos-content-graph' ); ?></option>
						<option value="monochrome"><?php esc_html_e( 'Color: Monochrome', 'nvoos-content-graph' ); ?></option>
					</select>
					<select id="nvoos-content-graph-layout-select" title="<?php esc_attr_e( 'Layout algorithm', 'nvoos-content-graph' ); ?>"></select>
					<select id="nvoos-content-graph-edge-style" title="<?php esc_attr_e( 'Edge style — live preview, saved in Appearance settings', 'nvoos-content-graph' ); ?>">
						<option value="plain"><?php esc_html_e( 'Edges: Plain', 'nvoos-content-graph' ); ?></option>
						<option value="arrows"><?php esc_html_e( 'Edges: Arrows', 'nvoos-content-graph' ); ?></option>
						<option value="tapered"><?php esc_html_e( 'Edges: Tapered', 'nvoos-content-graph' ); ?></option>
						<option value="density"><?php esc_html_e( 'Edges: Density', 'nvoos-content-graph' ); ?></option>
						<option value="auto"><?php esc_html_e( 'Edges: Auto', 'nvoos-content-graph' ); ?></option>
					</select>
					<input type="text" id="nvoos-content-graph-agent-filter" placeholder="<?php esc_attr_e( 'Agent ID…', 'nvoos-content-graph' ); ?>" style="width:140px;">
					<input type="text" id="nvoos-content-graph-wing-filter" placeholder="<?php esc_attr_e( 'Wing…', 'nvoos-content-graph' ); ?>" style="width:120px;">
					<button id="nvoos-content-graph-memory-preset-btn" class="button" title="<?php esc_attr_e( 'Show only the agent / wing combination above', 'nvoos-content-graph' ); ?>">
						<?php esc_html_e( 'Apply', 'nvoos-content-graph' ); ?>
					</button>
					<button id="nvoos-content-graph-memory-clear-btn" class="button">
						<?php esc_html_e( 'Clear', 'nvoos-content-graph' ); ?>
					</button>
					<span class="nvoos-cg-zoom-cluster">
						<button id="nvoos-content-graph-zoom-out-btn" class="button" title="<?php esc_attr_e( 'Zoom out', 'nvoos-content-graph' ); ?>">−</button>
						<span id="nvoos-content-graph-zoom-badge" class="nvoos-cg-zoom-badge" aria-live="polite">100%</span>
						<button id="nvoos-content-graph-zoom-in-btn" class="button" title="<?php esc_attr_e( 'Zoom in', 'nvoos-content-graph' ); ?>">+</button>
						<button id="nvoos-content-graph-fit-btn" class="button"><?php esc_html_e( 'Fit', 'nvoos-content-graph' ); ?></button>
					</span>
					<select id="nvoos-content-graph-export-bg" title="<?php esc_attr_e( 'Export background', 'nvoos-content-graph' ); ?>">
						<option value="theme"><?php esc_html_e( 'BG: Theme', 'nvoos-content-graph' ); ?></option>
						<option value="transparent"><?php esc_html_e( 'BG: Transparent', 'nvoos-content-graph' ); ?></option>
						<option value="white"><?php esc_html_e( 'BG: White', 'nvoos-content-graph' ); ?></option>
					</select>
					<select id="nvoos-content-graph-export-scale" title="<?php esc_attr_e( 'Export scale', 'nvoos-content-graph' ); ?>">
						<option value="1">1×</option>
						<option value="2" selected>2×</option>
						<option value="3">3×</option>
					</select>
					<button id="nvoos-content-graph-export-png-btn" class="button"><?php esc_html_e( 'Export PNG', 'nvoos-content-graph' ); ?></button>
					<button id="nvoos-content-graph-fullscreen-btn" class="button" title="<?php esc_attr_e( 'Toggle fullscreen', 'nvoos-content-graph' ); ?>">⛶</button>
				</div>
				<div class="nvoos-cg-explorer-body">
					<div id="nvoos-content-graph-explorer"
						style="height:<?php echo esc_attr( $settings['cytoscape_height'] ); ?>;"
						tabindex="0"
						role="application"
						aria-label="<?php esc_attr_e( 'Knowledge graph explorer', 'nvoos-content-graph' ); ?>">
						<div id="nvoos-content-graph-legend" class="nvoos-cg-legend" hidden></div>
						<div id="nvoos-content-graph-minimap" class="nvoos-cg-minimap" hidden><canvas id="nvoos-content-graph-minimap-canvas" width="160" height="100"></canvas></div>
					</div>
					<div id="nvoos-content-graph-sidebar" class="nvoos-content-graph-sidebar" style="display:none;"></div>
				</div>
			</div>
			<?php endif; ?>

			<?php /* Tabbed settings */ ?>
			<h2 class="nav-tab-wrapper">
				<?php foreach ( $tabs as $tab_key => $tab_data ) : ?>
					<a href="<?php echo esc_url( add_query_arg( 'tab', $tab_key ) ); ?>"
						class="nav-tab<?php echo ( $current_tab === $tab_key ) ? ' nav-tab-active' : ''; ?>">
						<?php echo esc_html( $tab_data['label'] ); ?>
					</a>
				<?php endforeach; ?>
			</h2>

			<form method="post" action="options.php">
				<?php
				settings_fields( 'nvoos_content_graph_settings_group' );
				$sections = SettingsRegistry::get_sections( $current_tab );
				foreach ( $sections as $section ) {
					$section->render_wrapper( self::PAGE_SLUG );
				}
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	// ───────────────────────────────────────────────────────────────
	// Asset enqueuing
	// ───────────────────────────────────────────────────────────────

	/**
	 * Enqueue admin assets on the Content Graph settings page.
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueueAssets( $hook ): void {
		if ( false === strpos( $hook, self::PAGE_SLUG ) ) {
			return;
		}

		// Cytoscape.js + fcose layout (bundled locally — see assets/vendor/).
		// Handles are prefixed 'nvoos-content-graph-' to avoid collisions with
		// other plugins that enqueue cytoscape under the bare 'cytoscape' handle.
		\wp_enqueue_script(
			'nvoos-content-graph-layout-base',
			NVOOS_CONTENT_GRAPH_URL . 'assets/vendor/layout-base/layout-base.js',
			array(),
			'2.0.1',
			true
		);
		\wp_enqueue_script(
			'nvoos-content-graph-cose-base',
			NVOOS_CONTENT_GRAPH_URL . 'assets/vendor/cose-base/cose-base.js',
			array( 'nvoos-content-graph-layout-base' ),
			'2.2.0',
			true
		);
		\wp_enqueue_script(
			'nvoos-content-graph-cytoscape',
			NVOOS_CONTENT_GRAPH_URL . 'assets/vendor/cytoscape/cytoscape.min.js',
			array(),
			'3.28.1',
			true
		);
		\wp_enqueue_script(
			'nvoos-content-graph-cytoscape-fcose',
			NVOOS_CONTENT_GRAPH_URL . 'assets/vendor/cytoscape-fcose/cytoscape-fcose.js',
			array( 'nvoos-content-graph-cytoscape', 'nvoos-content-graph-cose-base' ),
			'2.2.0',
			true
		);

		// Visual experience system: icon glyphs, then the theme engine,
		// then the admin explorer (which depends on both).
		\wp_enqueue_script(
			'nvoos-content-graph-icons',
			NVOOS_CONTENT_GRAPH_URL . 'assets/js/content-graph-icons.js',
			array(),
			NVOOS_CONTENT_GRAPH_VERSION,
			true
		);
		\wp_enqueue_script(
			'nvoos-content-graph-theme',
			NVOOS_CONTENT_GRAPH_URL . 'assets/js/content-graph-theme.js',
			array( 'nvoos-content-graph-icons' ),
			NVOOS_CONTENT_GRAPH_VERSION,
			true
		);

		\wp_enqueue_script(
			'nvoos-content-graph-admin',
			NVOOS_CONTENT_GRAPH_URL . 'assets/js/content-graph-admin.js',
			array( 'jquery', 'nvoos-content-graph-cytoscape', 'nvoos-content-graph-cytoscape-fcose', 'nvoos-content-graph-theme' ),
			NVOOS_CONTENT_GRAPH_VERSION,
			true
		);

		// WordPress color picker for the Appearance tab's per-type colors.
		\wp_enqueue_style( 'wp-color-picker' );
		\wp_enqueue_script( 'wp-color-picker' );

		// Stripe.js + the addon purchase modal. Only enqueued on this page;
		// Stripe is contacted exclusively when the user opens the checkout.
		\wp_enqueue_script(
			'stripe-v3',
			'https://js.stripe.com/v3/',
			array(),
			'3',
			true
		);
		\wp_enqueue_script(
			'nvoos-content-graph-commerce',
			NVOOS_CONTENT_GRAPH_URL . 'assets/js/content-graph-commerce.js',
			array( 'stripe-v3' ),
			NVOOS_CONTENT_GRAPH_VERSION,
			true
		);

		\wp_enqueue_style(
			'nvoos-content-graph-admin',
			NVOOS_CONTENT_GRAPH_URL . 'assets/css/content-graph-admin.css',
			array(),
			NVOOS_CONTENT_GRAPH_VERSION
		);

		$settings = Settings::all();

		\wp_localize_script(
			'nvoos-content-graph-admin',
			'nvoosContentGraphAdmin',
			array(
				'rest_url'   => esc_url_raw( rest_url( Schema::REST_NAMESPACE ) ),
				'nonce'      => wp_create_nonce( 'wp_rest' ),
				'ajax_url'   => admin_url( 'admin-ajax.php' ),
				'ajax_nonce' => wp_create_nonce( 'nvoos_content_graph_admin' ),
				'height'     => esc_js( $settings['cytoscape_height'] ),
				'max_nodes'  => absint( $settings['max_display_nodes'] ),
				'visual'     => \NvoosContentGraph\Visual\Tokens::visual_config( $settings ),
				'presets'    => \NvoosContentGraph\Visual\Tokens::presets(),
				'i18n'       => array(
					'all_types'      => __( 'All types', 'nvoos-content-graph' ),
					'loading'        => __( 'Loading graph…', 'nvoos-content-graph' ),
					'load_error'     => __( 'Failed to load graph data. Ensure the graph has been built.', 'nvoos-content-graph' ),
					'cy_missing'     => __( 'Cytoscape.js did not load. Check your network connection.', 'nvoos-content-graph' ),
					'legend'         => __( 'Legend', 'nvoos-content-graph' ),
					'zoom_in'        => __( 'Zoom in', 'nvoos-content-graph' ),
					'zoom_out'       => __( 'Zoom out', 'nvoos-content-graph' ),
					'fit'            => __( 'Fit', 'nvoos-content-graph' ),
					'fullscreen'     => __( 'Fullscreen', 'nvoos-content-graph' ),
					'export_png'     => __( 'Export PNG', 'nvoos-content-graph' ),
					'bg_theme'       => __( 'Background: theme', 'nvoos-content-graph' ),
					'bg_transparent' => __( 'Background: transparent', 'nvoos-content-graph' ),
					'bg_white'       => __( 'Background: white', 'nvoos-content-graph' ),
					'scale'          => __( 'Scale', 'nvoos-content-graph' ),
					'color_by'       => __( 'Color by', 'nvoos-content-graph' ),
					'layout'         => __( 'Layout', 'nvoos-content-graph' ),
					'node'           => __( 'Node', 'nvoos-content-graph' ),
					'connections'    => __( 'connections', 'nvoos-content-graph' ),
					'community'      => __( 'Community', 'nvoos-content-graph' ),
					'view_post'      => __( 'View post ↗', 'nvoos-content-graph' ),
					'neighbors'      => __( 'Neighbors', 'nvoos-content-graph' ),
					'a11y_hint'      => __( 'Graph explorer. Use arrow keys to move between nodes, Enter to open details, Escape to clear, + and - to zoom.', 'nvoos-content-graph' ),
				),
			)
		);

		// Commerce config. No Stripe keys live in this plugin — the publishable
		// key is returned per-session by the vendor checkout API.
		\wp_localize_script(
			'nvoos-content-graph-commerce',
			'nvoosContentGraphCommerce',
			array(
				'rest_url'     => esc_url_raw( rest_url( Schema::REST_NAMESPACE ) ),
				'nonce'        => wp_create_nonce( 'wp_rest' ),
				'price_label'  => \NvoosContentGraph\Commerce\Payments::priceLabel(),
				'fallback_url' => esc_url_raw( \NvoosContentGraph\Commerce\Payments::fallbackProductUrl() ),
				'i18n'         => array(
					'title'         => __( 'Get NV oOS Content Graph — AI', 'nvoos-content-graph' ),
					'pay'           => __( 'Pay', 'nvoos-content-graph' ),
					'cancel'        => __( 'Cancel', 'nvoos-content-graph' ),
					'close'         => __( 'Close', 'nvoos-content-graph' ),
					'secure_note'   => __( 'Payments are processed securely by Stripe. Your card never touches this server.', 'nvoos-content-graph' ),
					'generic_error' => __( 'Something went wrong. Please try again.', 'nvoos-content-graph' ),
					'installing'    => __( 'Recording your license and installing the addon…', 'nvoos-content-graph' ),
					'success_title' => __( 'You’re all set!', 'nvoos-content-graph' ),
					'refresh'       => __( 'Reload page', 'nvoos-content-graph' ),
					'license_label' => __( 'License key', 'nvoos-content-graph' ),
					'test_mode'     => __( 'Test mode — no real payment will be taken.', 'nvoos-content-graph' ),
					'pending_retry' => __( 'Check again', 'nvoos-content-graph' ),
					'pending_new'   => __( 'Start a new purchase', 'nvoos-content-graph' ),
					'fallback_note' => __( 'The checkout service is unavailable right now. Redirecting you to the product page to complete your purchase…', 'nvoos-content-graph' ),
				),
			)
		);
	}

	// ───────────────────────────────────────────────────────────────
	// AJAX build handler
	// ───────────────────────────────────────────────────────────────

	/**
	 * Handle AJAX request to trigger a graph build.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function handleAjaxBuild(): void {
		check_ajax_referer( 'nvoos_content_graph_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'nvoos-content-graph' ) ), 403 );
		}

		// Catch fatal errors (TypeError, etc.) so the AJAX response is never garbage.
		register_shutdown_function(
			function (): void {
				$error = error_get_last();
				if ( null === $error || ! in_array( $error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ), true ) ) {
					return;
				}
				set_transient(
					Schema::TRANSIENT_PREFIX . 'last_build_error',
					array(
						'message'   => $error['message'],
						'file'      => $error['file'],
						'line'      => $error['line'],
						'timestamp' => time(),
					),
					DAY_IN_SECONDS
				);
			}
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already checked above.
		$incremental = ! empty( $_POST['incremental'] );

		try {
			$result = Builder::build(
				array(
					'incremental'    => $incremental,
					'semantic'       => true,
					'async_semantic' => true,
				)
			);

			// Clear any previous error on success.
			delete_transient( Schema::TRANSIENT_PREFIX . 'last_build_error' );

			wp_send_json_success( $result );
		} catch ( \Throwable $e ) {
			set_transient(
				Schema::TRANSIENT_PREFIX . 'last_build_error',
				array(
					'message'   => $e->getMessage(),
					'file'      => $e->getFile(),
					'line'      => $e->getLine(),
					'timestamp' => time(),
				),
				DAY_IN_SECONDS
			);
			wp_send_json_error(
				array(
					'message' => __( 'Build failed due to an unexpected error. See the error notice on the settings page for details.', 'nvoos-content-graph' ),
				)
			);
		}
	}
}
