<?php
/**
 * NV oOS Graphify — Admin Settings Page
 *
 * Registers the "Knowledge Graph" settings page under the NV oOS admin menu.
 * Provides three setting sections (General, Build, Display) plus a graph
 * overview stats card with a rebuild button and the Cytoscape.js graph explorer.
 *
 * @package NV_oOS_Graphify
 * @since   0.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin settings for the Graphify addon.
 *
 * @since 0.5.0
 */
class NV_oOS_Graphify_Settings {

	/**
	 * Settings page slug.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'nvoos-graphify';

	/**
	 * Register admin hooks.
	 *
	 * @since 0.5.0
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_ajax_nvoos_graphify_build', array( __CLASS__, 'handle_ajax_build' ) );
	}

	/**
	 * Add the "Knowledge Graph" submenu under the NV oOS parent menu.
	 *
	 * Falls back to a top-level menu if the parent isn't registered.
	 *
	 * @since 0.5.0
	 *
	 * @return void
	 */
	public static function add_menu_page() {
		$parent = 'mcp-ai-wpoos'; // NV oOS parent slug.

		if ( ! menu_page_url( $parent, false ) ) {
			// Fallback to standalone page.
			add_menu_page(
				__( 'Knowledge Graph', 'nvoos-graphify' ),
				__( 'Knowledge Graph', 'nvoos-graphify' ),
				'manage_options',
				self::PAGE_SLUG,
				array( __CLASS__, 'render_page' ),
				'dashicons-networking',
				85
			);
			return;
		}

		add_submenu_page(
			$parent,
			__( 'Knowledge Graph', 'nvoos-graphify' ),
			__( 'Knowledge Graph', 'nvoos-graphify' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Register settings, sections, and fields.
	 *
	 * @since 0.5.0
	 *
	 * @return void
	 */
	public static function register_settings() {
		register_setting(
			'nvoos_graphify_settings_group',
			NV_oOS_Graphify::OPTION_KEY,
			array( 'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ) )
		);

		// --- General section ---
		add_settings_section( 'nvoos_graphify_general', __( 'General', 'nvoos-graphify' ), '__return_false', self::PAGE_SLUG );
		add_settings_field( 'enabled', __( 'Enable Graphify', 'nvoos-graphify' ), array( __CLASS__, 'field_enabled' ), self::PAGE_SLUG, 'nvoos_graphify_general' );

		// --- Build section ---
		add_settings_section( 'nvoos_graphify_build', __( 'Build Settings', 'nvoos-graphify' ), '__return_false', self::PAGE_SLUG );
		add_settings_field( 'semantic_extraction', __( 'Semantic Extraction', 'nvoos-graphify' ), array( __CLASS__, 'field_semantic' ), self::PAGE_SLUG, 'nvoos_graphify_build' );
		add_settings_field( 'incremental_builds', __( 'Incremental Builds', 'nvoos-graphify' ), array( __CLASS__, 'field_incremental' ), self::PAGE_SLUG, 'nvoos_graphify_build' );
		add_settings_field( 'auto_rebuild', __( 'Auto-Rebuild on Save', 'nvoos-graphify' ), array( __CLASS__, 'field_auto_rebuild' ), self::PAGE_SLUG, 'nvoos_graphify_build' );
		add_settings_field( 'rebuild_schedule', __( 'Scheduled Rebuild', 'nvoos-graphify' ), array( __CLASS__, 'field_rebuild_schedule' ), self::PAGE_SLUG, 'nvoos_graphify_build' );
		add_settings_field( 'openai_api_key', __( 'OpenAI API Key (optional)', 'nvoos-graphify' ), array( __CLASS__, 'field_openai_key' ), self::PAGE_SLUG, 'nvoos_graphify_build' );

		// --- Display section ---
		add_settings_section( 'nvoos_graphify_display', __( 'Display', 'nvoos-graphify' ), '__return_false', self::PAGE_SLUG );
		add_settings_field( 'schema_injection', __( 'Schema.org Injection', 'nvoos-graphify' ), array( __CLASS__, 'field_schema' ), self::PAGE_SLUG, 'nvoos_graphify_display' );
		add_settings_field( 'related_content', __( 'Related Content Widget', 'nvoos-graphify' ), array( __CLASS__, 'field_related' ), self::PAGE_SLUG, 'nvoos_graphify_display' );
		add_settings_field( 'cytoscape_height', __( 'Graph Explorer Height', 'nvoos-graphify' ), array( __CLASS__, 'field_height' ), self::PAGE_SLUG, 'nvoos_graphify_display' );
		add_settings_field( 'max_display_nodes', __( 'Max Explorer Nodes', 'nvoos-graphify' ), array( __CLASS__, 'field_max_nodes' ), self::PAGE_SLUG, 'nvoos_graphify_display' );
	}

	/**
	 * Sanitize incoming settings array.
	 *
	 * @since 0.5.0
	 *
	 * @param array $raw Submitted form data.
	 * @return array Sanitized settings.
	 */
	public static function sanitize_settings( $raw ) {
		$sanitized = array();
		$sanitized['enabled']             = ! empty( $raw['enabled'] ) ? 1 : 0;
		$sanitized['semantic_extraction'] = ! empty( $raw['semantic_extraction'] ) ? 1 : 0;
		$sanitized['incremental_builds']  = ! empty( $raw['incremental_builds'] ) ? 1 : 0;
		$sanitized['auto_rebuild']        = ! empty( $raw['auto_rebuild'] ) ? 1 : 0;
		$sanitized['schema_injection']    = ! empty( $raw['schema_injection'] ) ? 1 : 0;
		$sanitized['related_content']     = ! empty( $raw['related_content'] ) ? 1 : 0;

		$allowed_schedules = array( 'hourly', 'twicedaily', 'daily', 'weekly' );
		$sanitized['rebuild_schedule'] = in_array( $raw['rebuild_schedule'] ?? 'daily', $allowed_schedules, true )
			? $raw['rebuild_schedule'] : 'daily';

		$sanitized['openai_api_key']    = sanitize_text_field( $raw['openai_api_key'] ?? '' );
		$sanitized['cytoscape_height']  = sanitize_text_field( $raw['cytoscape_height'] ?? '600px' );
		$sanitized['max_display_nodes'] = max( 50, min( 2000, absint( $raw['max_display_nodes'] ?? 300 ) ) );
		$sanitized['max_related']       = max( 1, min( 10, absint( $raw['max_related'] ?? 5 ) ) );

		return $sanitized;
	}

	// -------------------------------------------------------------------------
	// Field renderers
	// -------------------------------------------------------------------------

	/** Render the enabled checkbox. */
	public static function field_enabled() {
		$s = NV_oOS_Graphify::get_settings();
		echo '<input type="checkbox" name="' . esc_attr( NV_oOS_Graphify::OPTION_KEY ) . '[enabled]" value="1" ' . checked( 1, $s['enabled'], false ) . '>';
		echo '<p class="description">' . esc_html__( 'Enable the Knowledge Graph addon.', 'nvoos-graphify' ) . '</p>';
	}

	/** Render semantic extraction field. */
	public static function field_semantic() {
		$s = NV_oOS_Graphify::get_settings();
		echo '<input type="checkbox" name="' . esc_attr( NV_oOS_Graphify::OPTION_KEY ) . '[semantic_extraction]" value="1" ' . checked( 1, $s['semantic_extraction'], false ) . '>';
		echo '<p class="description">' . esc_html__( 'Use AI to extract named entities and topics from content.', 'nvoos-graphify' ) . '</p>';
	}

	/** Render incremental builds field. */
	public static function field_incremental() {
		$s = NV_oOS_Graphify::get_settings();
		echo '<input type="checkbox" name="' . esc_attr( NV_oOS_Graphify::OPTION_KEY ) . '[incremental_builds]" value="1" ' . checked( 1, $s['incremental_builds'], false ) . '>';
		echo '<p class="description">' . esc_html__( 'Only process content modified since last build.', 'nvoos-graphify' ) . '</p>';
	}

	/** Render auto-rebuild field. */
	public static function field_auto_rebuild() {
		$s = NV_oOS_Graphify::get_settings();
		echo '<input type="checkbox" name="' . esc_attr( NV_oOS_Graphify::OPTION_KEY ) . '[auto_rebuild]" value="1" ' . checked( 1, $s['auto_rebuild'], false ) . '>';
		echo '<p class="description">' . esc_html__( 'Trigger an incremental rebuild whenever a post is published or updated.', 'nvoos-graphify' ) . '</p>';
	}

	/** Render rebuild schedule field. */
	public static function field_rebuild_schedule() {
		$s = NV_oOS_Graphify::get_settings();
		$options = array(
			'hourly'     => __( 'Hourly', 'nvoos-graphify' ),
			'twicedaily' => __( 'Twice Daily', 'nvoos-graphify' ),
			'daily'      => __( 'Daily', 'nvoos-graphify' ),
			'weekly'     => __( 'Weekly', 'nvoos-graphify' ),
		);
		echo '<select name="' . esc_attr( NV_oOS_Graphify::OPTION_KEY ) . '[rebuild_schedule]">';
		foreach ( $options as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '" ' . selected( $s['rebuild_schedule'], $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
	}

	/** Render OpenAI key field. */
	public static function field_openai_key() {
		$s = NV_oOS_Graphify::get_settings();
		echo '<input type="password" name="' . esc_attr( NV_oOS_Graphify::OPTION_KEY ) . '[openai_api_key]" value="' . esc_attr( $s['openai_api_key'] ) . '" class="regular-text" autocomplete="new-password">';
		echo '<p class="description">' . esc_html__( 'Used as fallback when the oOS AI provider is not available. Leave blank to use the global oOS key.', 'nvoos-graphify' ) . '</p>';
	}

	/** Render schema injection field. */
	public static function field_schema() {
		$s = NV_oOS_Graphify::get_settings();
		echo '<input type="checkbox" name="' . esc_attr( NV_oOS_Graphify::OPTION_KEY ) . '[schema_injection]" value="1" ' . checked( 1, $s['schema_injection'], false ) . '>';
		echo '<p class="description">' . esc_html__( 'Inject Schema.org JSON-LD (about, relatedLink) on singular views.', 'nvoos-graphify' ) . '</p>';
	}

	/** Render related content field. */
	public static function field_related() {
		$s = NV_oOS_Graphify::get_settings();
		echo '<input type="checkbox" name="' . esc_attr( NV_oOS_Graphify::OPTION_KEY ) . '[related_content]" value="1" ' . checked( 1, $s['related_content'], false ) . '>';
		echo '<p class="description">' . esc_html__( 'Append a "Related Content" list from graph neighbors below singular post content.', 'nvoos-graphify' ) . '</p>';
	}

	/** Render explorer height field. */
	public static function field_height() {
		$s = NV_oOS_Graphify::get_settings();
		echo '<input type="text" name="' . esc_attr( NV_oOS_Graphify::OPTION_KEY ) . '[cytoscape_height]" value="' . esc_attr( $s['cytoscape_height'] ) . '" class="small-text">';
		echo '<p class="description">' . esc_html__( 'CSS height for the graph explorer (e.g. 600px, 80vh).', 'nvoos-graphify' ) . '</p>';
	}

	/** Render max display nodes field. */
	public static function field_max_nodes() {
		$s = NV_oOS_Graphify::get_settings();
		echo '<input type="number" name="' . esc_attr( NV_oOS_Graphify::OPTION_KEY ) . '[max_display_nodes]" value="' . absint( $s['max_display_nodes'] ) . '" min="50" max="2000" class="small-text">';
		echo '<p class="description">' . esc_html__( 'Maximum nodes to render in the graph explorer. Lower values improve browser performance.', 'nvoos-graphify' ) . '</p>';
	}

	// -------------------------------------------------------------------------
	// Asset enqueuing
	// -------------------------------------------------------------------------

	/**
	 * Enqueue admin assets on the Graphify settings page.
	 *
	 * @since 0.5.0
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public static function enqueue_assets( $hook ) {
		if ( false === strpos( $hook, self::PAGE_SLUG ) ) {
			return;
		}

		// Cytoscape.js from CDN.
		wp_enqueue_script(
			'cytoscape',
			'https://cdnjs.cloudflare.com/ajax/libs/cytoscape/3.28.1/cytoscape.min.js',
			array(),
			'3.28.1',
			true
		);
		wp_enqueue_script(
			'cytoscape-fcose',
			'https://cdnjs.cloudflare.com/ajax/libs/cytoscape-fcose/2.2.0/cytoscape-fcose.min.js',
			array( 'cytoscape' ),
			'2.2.0',
			true
		);

		wp_enqueue_script(
			'nvoos-graphify-admin',
			NVOOS_GRAPHIFY_URL . 'assets/js/graphify-admin.js',
			array( 'jquery', 'cytoscape', 'cytoscape-fcose' ),
			NVOOS_GRAPHIFY_VERSION,
			true
		);

		wp_enqueue_style(
			'nvoos-graphify-admin',
			NVOOS_GRAPHIFY_URL . 'assets/css/graphify-admin.css',
			array(),
			NVOOS_GRAPHIFY_VERSION
		);

		$settings = NV_oOS_Graphify::get_settings();

		wp_localize_script(
			'nvoos-graphify-admin',
			'nvoosGraphifyAdmin',
			array(
				'rest_url'   => esc_url_raw( rest_url( 'nvoos-graphify/v1' ) ),
				'nonce'      => wp_create_nonce( 'wp_rest' ),
				'ajax_url'   => admin_url( 'admin-ajax.php' ),
				'ajax_nonce' => wp_create_nonce( 'nvoos_graphify_admin' ),
				'height'     => esc_js( $settings['cytoscape_height'] ),
				'max_nodes'  => absint( $settings['max_display_nodes'] ),
			)
		);
	}

	// -------------------------------------------------------------------------
	// Page render
	// -------------------------------------------------------------------------

	/**
	 * Render the settings page.
	 *
	 * @since 0.5.0
	 *
	 * @return void
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'nvoos-graphify' ) );
		}

		$stats      = NV_oOS_Graphify_DB::get_stats();
		$last_build = NV_oOS_Graphify_DB::get_meta( 'last_build_completed', __( 'Never', 'nvoos-graphify' ) );
		$status     = NV_oOS_Graphify_DB::get_meta( 'build_status', 'idle' );
		$settings   = NV_oOS_Graphify::get_settings();
		?>
		<div class="wrap nvoos-graphify-admin">
			<h1><?php esc_html_e( 'Knowledge Graph', 'nvoos-graphify' ); ?></h1>

			<?php settings_errors(); ?>

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
						<option value="post"><?php esc_html_e( 'Posts', 'nvoos-graphify' ); ?></option>
						<option value="page"><?php esc_html_e( 'Pages', 'nvoos-graphify' ); ?></option>
						<option value="term"><?php esc_html_e( 'Terms', 'nvoos-graphify' ); ?></option>
						<option value="topic"><?php esc_html_e( 'Topics', 'nvoos-graphify' ); ?></option>
						<option value="entity"><?php esc_html_e( 'Entities', 'nvoos-graphify' ); ?></option>
					</select>
					<button id="nvoos-graphify-fit-btn" class="button"><?php esc_html_e( 'Fit', 'nvoos-graphify' ); ?></button>
					<button id="nvoos-graphify-relayout-btn" class="button"><?php esc_html_e( 'Relayout', 'nvoos-graphify' ); ?></button>
					<button id="nvoos-graphify-export-png-btn" class="button"><?php esc_html_e( 'Export PNG', 'nvoos-graphify' ); ?></button>
				</div>
				<div id="nvoos-graphify-explorer" style="height:<?php echo esc_attr( $settings['cytoscape_height'] ); ?>;"></div>
				<div id="nvoos-graphify-sidebar" class="nvoos-graphify-sidebar" style="display:none;"></div>
			</div>
			<?php endif; ?>

			<?php /* Settings form */ ?>
			<h2><?php esc_html_e( 'Settings', 'nvoos-graphify' ); ?></h2>
			<form method="post" action="options.php">
				<?php
				settings_fields( 'nvoos_graphify_settings_group' );
				do_settings_sections( self::PAGE_SLUG );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// AJAX: trigger build from settings page
	// -------------------------------------------------------------------------

	/**
	 * Handle AJAX request to trigger a graph build.
	 *
	 * @since 0.5.0
	 *
	 * @return void
	 */
	public static function handle_ajax_build() {
		check_ajax_referer( 'nvoos_graphify_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'nvoos-graphify' ) ), 403 );
		}

		$incremental = ! empty( $_POST['incremental'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already checked above.

		$result = NV_oOS_Graphify_Builder::build(
			array(
				'incremental'    => $incremental,
				'semantic'       => true,
				'async_semantic' => true,
			)
		);

		wp_send_json_success( $result );
	}
}
