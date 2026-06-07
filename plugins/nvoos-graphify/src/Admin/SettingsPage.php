<?php
declare(strict_types=1);

namespace NvoosGraphify\Admin;

use NvoosGraphify\Schema;
use NvoosGraphify\Settings;
use NvoosGraphify\Graph\Db;
use NvoosGraphify\Graph\Builder;

use function __;
use function absint;
use function add_action;
use function add_menu_page;
use function add_query_arg;
use function admin_url;
use function class_exists;
use function current_user_can;
use function delete_transient;
use function did_action;
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
 * Admin settings page for the NV oOS Graphify plugin.
 *
 * Registers a standalone top-level "Knowledge Graph" menu page with
 * tabbed settings, a graph overview stats card with rebuild button,
 * and the Cytoscape.js graph explorer.
 *
 * Uses the Section/Registry pattern — each settings section is a
 * concrete subclass of {@see Section} registered into
 * {@see SettingsRegistry}. Addons hook `nvoos_graphify/admin/register_sections`
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
	public const PAGE_SLUG = 'nvoos-graphify';

	/**
	 * Register admin hooks.
	 *
	 * Called by {@see \NvoosGraphify\Plugin::registerAdmin()}.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'addMenuPage' ) );
		add_action( 'admin_init', array( $this, 'registerSettings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueueAssets' ) );
		add_action( 'wp_ajax_nvoos_graphify_build', array( $this, 'handleAjaxBuild' ) );
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
			__( 'Knowledge Graph', 'nvoos-graphify' ),
			__( 'Knowledge Graph', 'nvoos-graphify' ),
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
			'nvoos_graphify_settings_group',
			Schema::OPTION_SETTINGS,
			array( 'sanitize_callback' => array( $this, 'sanitizeSettings' ) )
		);

		// ─── Register core tabs ─────────────────────────────────
		SettingsRegistry::register_tab( 'general', __( 'General', 'nvoos-graphify' ) );
		SettingsRegistry::register_tab( 'sources', __( 'Sources (CPT / CCT)', 'nvoos-graphify' ) );
		SettingsRegistry::register_tab( 'remote', __( 'Remote Sources', 'nvoos-graphify' ) );
		SettingsRegistry::register_tab( 'embeddings', __( 'Embeddings', 'nvoos-graphify' ) );

		// ─── Register core sections ─────────────────────────────-
		if ( class_exists( 'NvoosGraphify\Admin\Sections\GeneralSection' ) ) {
			SettingsRegistry::register_section( new \NvoosGraphify\Admin\Sections\GeneralSection() );
		}
		if ( class_exists( 'NvoosGraphify\Admin\Sections\BuildSection' ) ) {
			SettingsRegistry::register_section( new \NvoosGraphify\Admin\Sections\BuildSection() );
		}
		if ( class_exists( 'NvoosGraphify\Admin\Sections\DisplaySection' ) ) {
			SettingsRegistry::register_section( new \NvoosGraphify\Admin\Sections\DisplaySection() );
		}
		if ( class_exists( 'NvoosGraphify\Admin\Sections\RemoteSection' ) ) {
			SettingsRegistry::register_section( new \NvoosGraphify\Admin\Sections\RemoteSection() );
		}
		if ( class_exists( 'NvoosGraphify\Admin\Sections\EmbeddingsSection' ) ) {
			SettingsRegistry::register_section( new \NvoosGraphify\Admin\Sections\EmbeddingsSection() );
		}
		if ( class_exists( 'NvoosGraphify\Admin\Sections\SourcesCptsSection' ) ) {
			SettingsRegistry::register_section( new \NvoosGraphify\Admin\Sections\SourcesCptsSection() );
		}
		if ( class_exists( 'NvoosGraphify\Admin\Sections\SourcesExtSection' ) ) {
			SettingsRegistry::register_section( new \NvoosGraphify\Admin\Sections\SourcesExtSection() );
		}

		/**
		 * Fires after core sections are registered so addons
		 * can register their own tabs and sections.
		 *
		 * @since 1.0.0
		 */
		do_action( 'nvoos_graphify/admin/register_sections' );
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
		$referer = isset( $_REQUEST['_wp_http_referer'] )
			? esc_url_raw( wp_unslash( $_REQUEST['_wp_http_referer'] ) )
			: '';

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
			wp_die( esc_html__( 'You do not have permission to access this page.', 'nvoos-graphify' ) );
		}

		// Ensure sections are registered before rendering (in case
		// admin_menu fires before admin_init in some edge cases).
		if ( ! did_action( 'nvoos_graphify/admin/register_sections' ) ) {
			do_action( 'nvoos_graphify/admin/register_sections' );
		}

		$stats      = Db::getStats();
		$last_build = Db::getMeta( 'last_build_completed', __( 'Never', 'nvoos-graphify' ) );
		$status     = Db::getMeta( 'build_status', 'idle' );
		$settings   = Settings::all();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'general';

		// Core tabs only — AI tabs belong on the separate NV oOS AI page.
		$tabs = array_filter(
			SettingsRegistry::get_tabs(),
			function ( $tab ) {
				return strpos( $tab['id'], 'ai_' ) !== 0;
			}
		);
		?>
		<div class="wrap nvoos-graphify-admin">
			<h1><?php esc_html_e( 'Knowledge Graph', 'nvoos-graphify' ); ?></h1>

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
					: __( 'Unknown', 'nvoos-graphify' );
				$error_file = isset( $last_error['file'] ) ? $last_error['file'] : '';
				$error_line = isset( $last_error['line'] ) ? $last_error['line'] : '';
				?>
				<div class="notice notice-error is-dismissible">
					<p>
						<strong><?php esc_html_e( 'Build Error', 'nvoos-graphify' ); ?></strong>
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
									__( 'File: %1$s, line %2$d', 'nvoos-graphify' ),
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
			<div class="nvoos-graphify-stats-card">
				<h2><?php esc_html_e( 'Graph Overview', 'nvoos-graphify' ); ?></h2>
				<div class="nvoos-graphify-stats-grid">
					<div class="nvoos-graphify-stat">
						<span class="nvoos-graphify-stat-value"><?php echo esc_html( number_format_i18n( $stats['node_count'] ) ); ?></span>
						<span class="nvoos-graphify-stat-label"><?php esc_html_e( 'Nodes', 'nvoos-graphify' ); ?></span>
					</div>
					<div class="nvoos-graphify-stat">
						<span class="nvoos-graphify-stat-value"><?php echo esc_html( number_format_i18n( $stats['edge_count'] ) ); ?></span>
						<span class="nvoos-graphify-stat-label"><?php esc_html_e( 'Edges', 'nvoos-graphify' ); ?></span>
					</div>
					<div class="nvoos-graphify-stat">
						<span class="nvoos-graphify-stat-value"><?php echo esc_html( number_format_i18n( $stats['community_count'] ) ); ?></span>
						<span class="nvoos-graphify-stat-label"><?php esc_html_e( 'Communities', 'nvoos-graphify' ); ?></span>
					</div>
				</div>
				<p class="nvoos-graphify-last-build">
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: build status, 2: last build time */
							__( 'Status: %1$s — Last build: %2$s', 'nvoos-graphify' ),
							$status,
							$last_build
						)
					);
					?>
				</p>
				<button id="nvoos-graphify-build-btn" class="button button-primary">
					<?php esc_html_e( 'Rebuild Graph', 'nvoos-graphify' ); ?>
				</button>
				<span id="nvoos-graphify-build-status" style="margin-left:12px; display:none;"></span>
			</div>

			<?php /* Graph explorer */ ?>
			<?php if ( $stats['node_count'] > 0 ) : ?>
			<div class="nvoos-graphify-explorer-wrap">
				<h2><?php esc_html_e( 'Graph Explorer', 'nvoos-graphify' ); ?></h2>
				<div class="nvoos-graphify-explorer-toolbar">
					<input type="text" id="nvoos-graphify-search" placeholder="<?php esc_attr_e( 'Search nodes…', 'nvoos-graphify' ); ?>">
					<select id="nvoos-graphify-type-filter">
						<option value=""><?php esc_html_e( 'All types', 'nvoos-graphify' ); ?></option>
					</select>
					<input type="text" id="nvoos-graphify-agent-filter" placeholder="<?php esc_attr_e( 'Agent ID…', 'nvoos-graphify' ); ?>" style="width:140px;">
					<input type="text" id="nvoos-graphify-wing-filter" placeholder="<?php esc_attr_e( 'Wing…', 'nvoos-graphify' ); ?>" style="width:120px;">
					<button id="nvoos-graphify-memory-preset-btn" class="button" title="<?php esc_attr_e( 'Show only the agent / wing combination above', 'nvoos-graphify' ); ?>">
						<?php esc_html_e( 'Apply', 'nvoos-graphify' ); ?>
					</button>
					<button id="nvoos-graphify-memory-clear-btn" class="button">
						<?php esc_html_e( 'Clear', 'nvoos-graphify' ); ?>
					</button>
					<button id="nvoos-graphify-fit-btn" class="button"><?php esc_html_e( 'Fit', 'nvoos-graphify' ); ?></button>
					<button id="nvoos-graphify-relayout-btn" class="button"><?php esc_html_e( 'Relayout', 'nvoos-graphify' ); ?></button>
					<button id="nvoos-graphify-export-png-btn" class="button"><?php esc_html_e( 'Export PNG', 'nvoos-graphify' ); ?></button>
				</div>
				<div id="nvoos-graphify-explorer" style="height:<?php echo esc_attr( $settings['cytoscape_height'] ); ?>;"></div>
				<div id="nvoos-graphify-sidebar" class="nvoos-graphify-sidebar" style="display:none;"></div>
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
				settings_fields( 'nvoos_graphify_settings_group' );
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
	 * Enqueue admin assets on the Graphify settings page.
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
		\wp_enqueue_script(
			'layout-base',
			NVOOS_GRAPHIFY_URL . 'assets/vendor/layout-base/layout-base.js',
			array(),
			'2.0.1',
			true
		);
		\wp_enqueue_script(
			'cose-base',
			NVOOS_GRAPHIFY_URL . 'assets/vendor/cose-base/cose-base.js',
			array( 'layout-base' ),
			'2.2.0',
			true
		);
		\wp_enqueue_script(
			'cytoscape',
			NVOOS_GRAPHIFY_URL . 'assets/vendor/cytoscape/cytoscape.min.js',
			array(),
			'3.28.1',
			true
		);
		\wp_enqueue_script(
			'cytoscape-fcose',
			NVOOS_GRAPHIFY_URL . 'assets/vendor/cytoscape-fcose/cytoscape-fcose.js',
			array( 'cytoscape', 'cose-base' ),
			'2.2.0',
			true
		);

		\wp_enqueue_script(
			'nvoos-graphify-admin',
			NVOOS_GRAPHIFY_URL . 'assets/js/graphify-admin.js',
			array( 'jquery', 'cytoscape', 'cytoscape-fcose' ),
			NVOOS_GRAPHIFY_VERSION,
			true
		);

		\wp_enqueue_style(
			'nvoos-graphify-admin',
			NVOOS_GRAPHIFY_URL . 'assets/css/graphify-admin.css',
			array(),
			NVOOS_GRAPHIFY_VERSION
		);

		$settings = Settings::all();

		\wp_localize_script(
			'nvoos-graphify-admin',
			'nvoosGraphifyAdmin',
			array(
				'rest_url'   => esc_url_raw( rest_url( Schema::REST_NAMESPACE ) ),
				'nonce'      => wp_create_nonce( 'wp_rest' ),
				'ajax_url'   => admin_url( 'admin-ajax.php' ),
				'ajax_nonce' => wp_create_nonce( 'nvoos_graphify_admin' ),
				'height'     => esc_js( $settings['cytoscape_height'] ),
				'max_nodes'  => absint( $settings['max_display_nodes'] ),
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
		check_ajax_referer( 'nvoos_graphify_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'nvoos-graphify' ) ), 403 );
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
					'message' => __( 'Build failed due to an unexpected error. See the error notice on the settings page for details.', 'nvoos-graphify' ),
				)
			);
		}
	}
}
