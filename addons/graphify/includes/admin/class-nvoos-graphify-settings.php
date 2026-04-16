<?php
/**
 * NV oOS Graphify — Admin Settings & Dashboard
 *
 * Provides the top-level admin menu with Dashboard, Explorer,
 * and Settings pages for the Graphify addon.
 *
 * @package NV_oOS_Graphify
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin settings and dashboard handler.
 *
 * @since 0.1.0
 */
class NV_oOS_Graphify_Settings {

	/**
	 * Register hooks.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
	}

	/**
	 * Add top-level menu and submenus.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function add_menu() {
		add_menu_page(
			__( 'Graphify', 'nvoos-graphify' ),
			__( 'Graphify', 'nvoos-graphify' ),
			'manage_options',
			'nvoos-graphify',
			array( __CLASS__, 'render_main_page' ),
			'dashicons-networking',
			30
		);

		add_submenu_page(
			'nvoos-graphify',
			__( 'Dashboard', 'nvoos-graphify' ),
			__( 'Dashboard', 'nvoos-graphify' ),
			'manage_options',
			'nvoos-graphify',
			array( __CLASS__, 'render_main_page' )
		);

		add_submenu_page(
			'nvoos-graphify',
			__( 'Graph Explorer', 'nvoos-graphify' ),
			__( 'Explorer', 'nvoos-graphify' ),
			'manage_options',
			'nvoos-graphify-explorer',
			array( __CLASS__, 'render_explorer_page' )
		);

		add_submenu_page(
			'nvoos-graphify',
			__( 'Settings', 'nvoos-graphify' ),
			__( 'Settings', 'nvoos-graphify' ),
			'manage_options',
			'nvoos-graphify-settings',
			array( __CLASS__, 'render_settings_page' )
		);
	}

	/**
	 * Register settings fields with the Settings API.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function register_settings() {
		register_setting(
			'nvoos_graphify_settings',
			NV_oOS_Graphify::OPTION_KEY,
			array(
				'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ),
				'default'           => array(),
			)
		);

		// ── General section ──────────────────────────────────────────────
		add_settings_section(
			'nvoos_graphify_general',
			__( 'General Settings', 'nvoos-graphify' ),
			'__return_false',
			'nvoos-graphify-settings'
		);

		add_settings_field(
			'enabled',
			__( 'Enable Addon', 'nvoos-graphify' ),
			array( __CLASS__, 'render_checkbox' ),
			'nvoos-graphify-settings',
			'nvoos_graphify_general',
			array(
				'id'          => 'enabled',
				'description' => __( 'Enable the Graphify knowledge graph addon.', 'nvoos-graphify' ),
			)
		);

		add_settings_field(
			'content_types',
			__( 'Content Types', 'nvoos-graphify' ),
			array( __CLASS__, 'render_content_types' ),
			'nvoos-graphify-settings',
			'nvoos_graphify_general',
			array(
				'id'          => 'content_types',
				'description' => __( 'Select which post types to include in the knowledge graph.', 'nvoos-graphify' ),
			)
		);

		add_settings_field(
			'include_taxonomies',
			__( 'Include Taxonomies', 'nvoos-graphify' ),
			array( __CLASS__, 'render_checkbox' ),
			'nvoos-graphify-settings',
			'nvoos_graphify_general',
			array(
				'id'          => 'include_taxonomies',
				'description' => __( 'Include taxonomy terms as nodes in the graph.', 'nvoos-graphify' ),
			)
		);

		add_settings_field(
			'include_users',
			__( 'Include Users', 'nvoos-graphify' ),
			array( __CLASS__, 'render_checkbox' ),
			'nvoos-graphify-settings',
			'nvoos_graphify_general',
			array(
				'id'          => 'include_users',
				'description' => __( 'Include author nodes in the graph.', 'nvoos-graphify' ),
			)
		);

		add_settings_field(
			'include_media',
			__( 'Include Media', 'nvoos-graphify' ),
			array( __CLASS__, 'render_checkbox' ),
			'nvoos-graphify-settings',
			'nvoos_graphify_general',
			array(
				'id'          => 'include_media',
				'description' => __( 'Include media attachment nodes in the graph.', 'nvoos-graphify' ),
			)
		);

		// ── Build Settings section ───────────────────────────────────────
		add_settings_section(
			'nvoos_graphify_build',
			__( 'Build Settings', 'nvoos-graphify' ),
			'__return_false',
			'nvoos-graphify-settings'
		);

		add_settings_field(
			'auto_rebuild',
			__( 'Auto Rebuild', 'nvoos-graphify' ),
			array( __CLASS__, 'render_select' ),
			'nvoos-graphify-settings',
			'nvoos_graphify_build',
			array(
				'id'      => 'auto_rebuild',
				'options' => array(
					'manual'    => __( 'Manual', 'nvoos-graphify' ),
					'on_save'   => __( 'On Post Save', 'nvoos-graphify' ),
					'scheduled' => __( 'Scheduled', 'nvoos-graphify' ),
				),
			)
		);

		add_settings_field(
			'scheduled_frequency',
			__( 'Scheduled Frequency', 'nvoos-graphify' ),
			array( __CLASS__, 'render_select' ),
			'nvoos-graphify-settings',
			'nvoos_graphify_build',
			array(
				'id'          => 'scheduled_frequency',
				'options'     => array(
					'daily'  => __( 'Daily', 'nvoos-graphify' ),
					'weekly' => __( 'Weekly', 'nvoos-graphify' ),
				),
				'description' => __( 'Only applies when Auto Rebuild is set to Scheduled.', 'nvoos-graphify' ),
			)
		);

		add_settings_field(
			'include_semantic',
			__( 'Semantic Extraction', 'nvoos-graphify' ),
			array( __CLASS__, 'render_checkbox' ),
			'nvoos-graphify-settings',
			'nvoos_graphify_build',
			array(
				'id'          => 'include_semantic',
				'description' => __( 'Enable keyword-based semantic relationship extraction.', 'nvoos-graphify' ),
			)
		);

		// ── Visualization section ────────────────────────────────────────
		add_settings_section(
			'nvoos_graphify_visualization',
			__( 'Visualization', 'nvoos-graphify' ),
			'__return_false',
			'nvoos-graphify-settings'
		);

		add_settings_field(
			'visualization_lib',
			__( 'Visualization Library', 'nvoos-graphify' ),
			array( __CLASS__, 'render_select' ),
			'nvoos-graphify-settings',
			'nvoos_graphify_visualization',
			array(
				'id'      => 'visualization_lib',
				'options' => array(
					'cytoscape' => __( 'Cytoscape.js', 'nvoos-graphify' ),
					'visjs'     => __( 'vis.js', 'nvoos-graphify' ),
				),
			)
		);

		add_settings_field(
			'max_vis_nodes',
			__( 'Max Visualization Nodes', 'nvoos-graphify' ),
			array( __CLASS__, 'render_number' ),
			'nvoos-graphify-settings',
			'nvoos_graphify_visualization',
			array(
				'id'          => 'max_vis_nodes',
				'min'         => 100,
				'max'         => 10000,
				'description' => __( 'Maximum number of nodes rendered in the visualization (default: 2000).', 'nvoos-graphify' ),
			)
		);

		// ── Analysis section ─────────────────────────────────────────────
		add_settings_section(
			'nvoos_graphify_analysis',
			__( 'Analysis', 'nvoos-graphify' ),
			'__return_false',
			'nvoos-graphify-settings'
		);

		add_settings_field(
			'community_algorithm',
			__( 'Community Algorithm', 'nvoos-graphify' ),
			array( __CLASS__, 'render_select' ),
			'nvoos-graphify-settings',
			'nvoos_graphify_analysis',
			array(
				'id'      => 'community_algorithm',
				'options' => array(
					'louvain'              => __( 'Louvain', 'nvoos-graphify' ),
					'connected_components' => __( 'Connected Components', 'nvoos-graphify' ),
				),
			)
		);
	}

	/**
	 * Sanitize settings on save.
	 *
	 * @since 0.1.0
	 *
	 * @param array $input Raw input values.
	 * @return array Sanitized values.
	 */
	public static function sanitize_settings( $input ) {
		$sanitized = array();

		$sanitized['enabled']            = ! empty( $input['enabled'] );
		$sanitized['include_taxonomies'] = ! empty( $input['include_taxonomies'] );
		$sanitized['include_users']      = ! empty( $input['include_users'] );
		$sanitized['include_media']      = ! empty( $input['include_media'] );
		$sanitized['include_semantic']   = ! empty( $input['include_semantic'] );

		// Content types — array of sanitized strings.
		$sanitized['content_types'] = array();
		if ( ! empty( $input['content_types'] ) && is_array( $input['content_types'] ) ) {
			$sanitized['content_types'] = array_map( 'sanitize_text_field', $input['content_types'] );
		}

		// Select fields.
		$allowed_rebuild           = array( 'manual', 'on_save', 'scheduled' );
		$sanitized['auto_rebuild'] = isset( $input['auto_rebuild'] ) && in_array( $input['auto_rebuild'], $allowed_rebuild, true )
			? $input['auto_rebuild']
			: 'manual';

		$allowed_frequency                = array( 'daily', 'weekly' );
		$sanitized['scheduled_frequency'] = isset( $input['scheduled_frequency'] ) && in_array( $input['scheduled_frequency'], $allowed_frequency, true )
			? $input['scheduled_frequency']
			: 'weekly';

		$allowed_viz                    = array( 'cytoscape', 'visjs' );
		$sanitized['visualization_lib'] = isset( $input['visualization_lib'] ) && in_array( $input['visualization_lib'], $allowed_viz, true )
			? $input['visualization_lib']
			: 'cytoscape';

		$sanitized['max_vis_nodes'] = isset( $input['max_vis_nodes'] )
			? max( 100, min( 10000, absint( $input['max_vis_nodes'] ) ) )
			: 2000;

		$allowed_algo                     = array( 'louvain', 'connected_components' );
		$sanitized['community_algorithm'] = isset( $input['community_algorithm'] ) && in_array( $input['community_algorithm'], $allowed_algo, true )
			? $input['community_algorithm']
			: 'louvain';

		return $sanitized;
	}

	// ------------------------------------------------------------------
	// Page renders.
	// ------------------------------------------------------------------

	/**
	 * Render the dashboard main page.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function render_main_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$report = new NV_oOS_Graphify_Report();
		$stats  = $report->get_summary_stats();
		$cached = $report->get_cached_report();

		wp_enqueue_style(
			'nvoos-graphify-admin',
			NVOOS_GRAPHIFY_URL . 'assets/css/graphify-admin.css',
			array(),
			NVOOS_GRAPHIFY_VERSION
		);

		wp_enqueue_script(
			'nvoos-graphify-admin',
			NVOOS_GRAPHIFY_URL . 'assets/js/graphify-admin.js',
			array(),
			NVOOS_GRAPHIFY_VERSION,
			true
		);

		wp_localize_script(
			'nvoos-graphify-admin',
			'nvoosGraphifyConfig',
			array(
				'restUrl' => esc_url_raw( rest_url( 'nvoos-graphify/v1' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
			)
		);

		?>
		<div class="nvoos-graphify-wrap">
			<h1><?php esc_html_e( 'Graphify Dashboard', 'nvoos-graphify' ); ?></h1>

			<div class="nvoos-graphify-dashboard">
				<div class="nvoos-graphify-card">
					<h3><?php esc_html_e( 'Nodes', 'nvoos-graphify' ); ?></h3>
					<div class="nvoos-graphify-stat-value" id="nvoos-graphify-stat-nodes">
						<?php echo esc_html( number_format_i18n( $stats['node_count'] ) ); ?>
					</div>
				</div>

				<div class="nvoos-graphify-card">
					<h3><?php esc_html_e( 'Edges', 'nvoos-graphify' ); ?></h3>
					<div class="nvoos-graphify-stat-value" id="nvoos-graphify-stat-edges">
						<?php echo esc_html( number_format_i18n( $stats['edge_count'] ) ); ?>
					</div>
				</div>

				<div class="nvoos-graphify-card">
					<h3><?php esc_html_e( 'Communities', 'nvoos-graphify' ); ?></h3>
					<div class="nvoos-graphify-stat-value" id="nvoos-graphify-stat-communities">
						<?php echo esc_html( number_format_i18n( $stats['community_count'] ) ); ?>
					</div>
				</div>

				<div class="nvoos-graphify-card">
					<h3><?php esc_html_e( 'Status', 'nvoos-graphify' ); ?></h3>
					<div class="nvoos-graphify-stat-value">
						<span class="nvoos-graphify-status-badge nvoos-graphify-status-<?php echo esc_attr( $stats['build_status'] ); ?>" id="nvoos-graphify-stat-status">
							<?php echo esc_html( ucfirst( $stats['build_status'] ) ); ?>
						</span>
					</div>
					<?php if ( $stats['last_built'] ) : ?>
						<p class="nvoos-graphify-last-built">
							<?php
							printf(
								/* translators: %s: date/time string */
								esc_html__( 'Last built: %s', 'nvoos-graphify' ),
								esc_html( $stats['last_built'] )
							);
							?>
						</p>
					<?php endif; ?>
				</div>
			</div>

			<div class="nvoos-graphify-actions">
				<button type="button" class="nvoos-graphify-btn" id="nvoos-graphify-build-btn">
					<?php esc_html_e( 'Build Graph', 'nvoos-graphify' ); ?>
				</button>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=nvoos-graphify-explorer' ) ); ?>" class="nvoos-graphify-btn-secondary">
					<?php esc_html_e( 'Open Explorer', 'nvoos-graphify' ); ?>
				</a>
				<button type="button" class="nvoos-graphify-btn-secondary" id="nvoos-graphify-report-btn">
					<?php esc_html_e( 'View Report', 'nvoos-graphify' ); ?>
				</button>
			</div>

			<?php if ( $cached ) : ?>
				<div class="nvoos-graphify-report" id="nvoos-graphify-report-section">
					<h2><?php esc_html_e( 'Report Summary', 'nvoos-graphify' ); ?></h2>

					<?php if ( ! empty( $cached['god_nodes'] ) ) : ?>
						<h3><?php esc_html_e( 'Top Hub Nodes', 'nvoos-graphify' ); ?></h3>
						<ul class="nvoos-graphify-god-nodes-list">
							<?php foreach ( array_slice( $cached['god_nodes'], 0, 10 ) as $gn ) : ?>
								<li>
									<strong><?php echo esc_html( $gn['label'] ); ?></strong>
									<span>(<?php echo esc_html( $gn['node_type'] ); ?>, <?php echo esc_html__( 'degree', 'nvoos-graphify' ) . ': ' . intval( $gn['degree'] ); ?>)</span>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>

					<?php if ( ! empty( $cached['communities'] ) ) : ?>
						<h3><?php esc_html_e( 'Communities', 'nvoos-graphify' ); ?></h3>
						<ul>
							<?php foreach ( array_slice( $cached['communities'], 0, 10 ) as $comm ) : ?>
								<li>
									<?php
									printf(
										/* translators: 1: community label, 2: member count */
										esc_html__( '%1$s (%2$d members)', 'nvoos-graphify' ),
										esc_html( $comm['label'] ),
										intval( $comm['size'] )
									);
									?>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render the graph explorer page.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function render_explorer_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = NV_oOS_Graphify::get_settings();

		wp_enqueue_style(
			'nvoos-graphify-admin',
			NVOOS_GRAPHIFY_URL . 'assets/css/graphify-admin.css',
			array(),
			NVOOS_GRAPHIFY_VERSION
		);

		// Cytoscape.js from CDN.
		wp_enqueue_script(
			'cytoscape',
			'https://unpkg.com/cytoscape@3.30.4/dist/cytoscape.min.js',
			array(),
			'3.30.4',
			true
		);

		wp_enqueue_script(
			'nvoos-graphify-explorer',
			NVOOS_GRAPHIFY_URL . 'assets/js/graphify-explorer.js',
			array( 'cytoscape' ),
			NVOOS_GRAPHIFY_VERSION,
			true
		);

		wp_localize_script(
			'nvoos-graphify-explorer',
			'nvoosGraphifyConfig',
			array(
				'restUrl'  => esc_url_raw( rest_url( 'nvoos-graphify/v1' ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'maxNodes' => intval( $settings['max_vis_nodes'] ),
				'vizLib'   => sanitize_text_field( $settings['visualization_lib'] ),
			)
		);

		?>
		<div class="nvoos-graphify-wrap nvoos-graphify-explorer-wrap">
			<div class="nvoos-graphify-sidebar" id="nvoos-graphify-sidebar">
				<h2><?php esc_html_e( 'Graph Explorer', 'nvoos-graphify' ); ?></h2>

				<div class="nvoos-graphify-search">
					<input type="text" id="nvoos-graphify-search-input" placeholder="<?php esc_attr_e( 'Search nodes...', 'nvoos-graphify' ); ?>" />
				</div>

				<div class="nvoos-graphify-toolbar">
					<button type="button" class="nvoos-graphify-btn" id="nvoos-graphify-fit-btn" title="<?php esc_attr_e( 'Fit All', 'nvoos-graphify' ); ?>">
						<?php esc_html_e( 'Fit', 'nvoos-graphify' ); ?>
					</button>
					<button type="button" class="nvoos-graphify-btn-secondary" id="nvoos-graphify-labels-btn" title="<?php esc_attr_e( 'Toggle Labels', 'nvoos-graphify' ); ?>">
						<?php esc_html_e( 'Labels', 'nvoos-graphify' ); ?>
					</button>
					<button type="button" class="nvoos-graphify-btn-secondary" id="nvoos-graphify-relayout-btn" title="<?php esc_attr_e( 'Reset Layout', 'nvoos-graphify' ); ?>">
						<?php esc_html_e( 'Relayout', 'nvoos-graphify' ); ?>
					</button>
				</div>

				<div class="nvoos-graphify-node-info" id="nvoos-graphify-node-info">
					<p class="nvoos-graphify-placeholder"><?php esc_html_e( 'Click a node to see details.', 'nvoos-graphify' ); ?></p>
				</div>

				<div class="nvoos-graphify-community-legend" id="nvoos-graphify-community-legend">
					<h3><?php esc_html_e( 'Communities', 'nvoos-graphify' ); ?></h3>
				</div>
			</div>

			<div class="nvoos-graphify-canvas" id="nvoos-graphify-explorer">
			</div>
		</div>
		<?php
	}

	/**
	 * Render the settings page.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_enqueue_style(
			'nvoos-graphify-admin',
			NVOOS_GRAPHIFY_URL . 'assets/css/graphify-admin.css',
			array(),
			NVOOS_GRAPHIFY_VERSION
		);

		?>
		<div class="wrap nvoos-graphify-wrap">
			<h1><?php esc_html_e( 'Graphify Settings', 'nvoos-graphify' ); ?></h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( 'nvoos_graphify_settings' );
				do_settings_sections( 'nvoos-graphify-settings' );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	// ------------------------------------------------------------------
	// Field renderers.
	// ------------------------------------------------------------------

	/**
	 * Render a checkbox field.
	 *
	 * @since 0.1.0
	 *
	 * @param array $args Field arguments.
	 * @return void
	 */
	public static function render_checkbox( $args ) {
		$settings = NV_oOS_Graphify::get_settings();
		$value    = ! empty( $settings[ $args['id'] ] );
		?>
		<label>
			<input type="checkbox"
				name="<?php echo esc_attr( NV_oOS_Graphify::OPTION_KEY . '[' . $args['id'] . ']' ); ?>"
				value="1"
				<?php checked( $value ); ?> />
			<?php
			if ( ! empty( $args['description'] ) ) {
				echo esc_html( $args['description'] );
			}
			?>
		</label>
		<?php
	}

	/**
	 * Render content types as multiple checkboxes.
	 *
	 * @since 0.1.0
	 *
	 * @param array $args Field arguments.
	 * @return void
	 */
	public static function render_content_types( $args ) {
		$settings   = NV_oOS_Graphify::get_settings();
		$selected   = isset( $settings['content_types'] ) && is_array( $settings['content_types'] ) ? $settings['content_types'] : array( 'post', 'page' );
		$post_types = get_post_types( array( 'public' => true ), 'objects' );
		$field_name = NV_oOS_Graphify::OPTION_KEY . '[content_types][]';

		foreach ( $post_types as $pt ) {
			$checked = in_array( $pt->name, $selected, true );
			?>
			<label style="display: block; margin-bottom: 4px;">
				<input type="checkbox"
					name="<?php echo esc_attr( $field_name ); ?>"
					value="<?php echo esc_attr( $pt->name ); ?>"
					<?php checked( $checked ); ?> />
				<?php echo esc_html( $pt->labels->singular_name ); ?>
			</label>
			<?php
		}

		if ( ! empty( $args['description'] ) ) {
			echo '<p class="description">' . esc_html( $args['description'] ) . '</p>';
		}
	}

	/**
	 * Render a number field.
	 *
	 * @since 0.1.0
	 *
	 * @param array $args Field arguments.
	 * @return void
	 */
	public static function render_number( $args ) {
		$settings = NV_oOS_Graphify::get_settings();
		$value    = isset( $settings[ $args['id'] ] ) ? $settings[ $args['id'] ] : '';
		?>
		<input type="number"
			name="<?php echo esc_attr( NV_oOS_Graphify::OPTION_KEY . '[' . $args['id'] . ']' ); ?>"
			value="<?php echo esc_attr( $value ); ?>"
			min="<?php echo esc_attr( isset( $args['min'] ) ? $args['min'] : '' ); ?>"
			max="<?php echo esc_attr( isset( $args['max'] ) ? $args['max'] : '' ); ?>"
			class="small-text" />
		<?php if ( ! empty( $args['description'] ) ) : ?>
			<p class="description"><?php echo esc_html( $args['description'] ); ?></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render a select field.
	 *
	 * @since 0.1.0
	 *
	 * @param array $args Field arguments.
	 * @return void
	 */
	public static function render_select( $args ) {
		$settings = NV_oOS_Graphify::get_settings();
		$value    = isset( $settings[ $args['id'] ] ) ? $settings[ $args['id'] ] : '';
		?>
		<select name="<?php echo esc_attr( NV_oOS_Graphify::OPTION_KEY . '[' . $args['id'] . ']' ); ?>">
			<?php foreach ( $args['options'] as $key => $label ) : ?>
				<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $value, $key ); ?>>
					<?php echo esc_html( $label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<?php if ( ! empty( $args['description'] ) ) : ?>
			<p class="description"><?php echo esc_html( $args['description'] ); ?></p>
		<?php endif; ?>
		<?php
	}
}

// Initialize.
NV_oOS_Graphify_Settings::init();
