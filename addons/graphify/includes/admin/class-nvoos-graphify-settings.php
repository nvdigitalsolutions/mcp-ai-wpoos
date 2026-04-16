<?php
/**
 * NV oOS Graphify Admin Settings Page.
 *
 * Registers the Graphify submenu page under NV oOS, renders settings
 * using the WordPress Settings API, and displays graph overview stats.
 *
 * @package NV_oOS_Graphify
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class NV_oOS_Graphify_Settings
 *
 * Admin settings page for the Graphify Knowledge Graph addon.
 *
 * @since 1.0.0
 */
class NV_oOS_Graphify_Settings {

	/**
	 * Admin page hook suffix.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private static $hook_suffix = '';

	/**
	 * Initialize hooks.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/**
	 * Add the Graphify submenu page under the main NV oOS menu.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function add_menu_page() {
		self::$hook_suffix = add_submenu_page(
			'wp-mcp-ai',
			__( 'Graphify Knowledge Graph', 'nvoos-graphify' ),
			__( 'Graphify', 'nvoos-graphify' ),
			'manage_options',
			'nvoos-graphify',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Register settings, sections, and fields.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function register_settings() {
		register_setting(
			'nvoos_graphify_settings_group',
			NV_oOS_Graphify::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ),
				'default'           => NV_oOS_Graphify::get_default_settings(),
			)
		);

		// General section.
		add_settings_section(
			'nvoos_graphify_general',
			__( 'General', 'nvoos-graphify' ),
			array( __CLASS__, 'render_section_general' ),
			'nvoos-graphify'
		);

		add_settings_field(
			'enabled',
			__( 'Enable Graphify', 'nvoos-graphify' ),
			array( __CLASS__, 'render_field_enabled' ),
			'nvoos-graphify',
			'nvoos_graphify_general'
		);

		add_settings_field(
			'post_types',
			__( 'Content Types', 'nvoos-graphify' ),
			array( __CLASS__, 'render_field_post_types' ),
			'nvoos-graphify',
			'nvoos_graphify_general'
		);

		add_settings_field(
			'include_taxonomies',
			__( 'Include Taxonomies', 'nvoos-graphify' ),
			array( __CLASS__, 'render_field_include_taxonomies' ),
			'nvoos-graphify',
			'nvoos_graphify_general'
		);

		add_settings_field(
			'include_users',
			__( 'Include Authors', 'nvoos-graphify' ),
			array( __CLASS__, 'render_field_include_users' ),
			'nvoos-graphify',
			'nvoos_graphify_general'
		);

		add_settings_field(
			'include_media',
			__( 'Include Media', 'nvoos-graphify' ),
			array( __CLASS__, 'render_field_include_media' ),
			'nvoos-graphify',
			'nvoos_graphify_general'
		);

		// Build Settings section.
		add_settings_section(
			'nvoos_graphify_build',
			__( 'Build Settings', 'nvoos-graphify' ),
			array( __CLASS__, 'render_section_build' ),
			'nvoos-graphify'
		);

		add_settings_field(
			'auto_rebuild',
			__( 'Auto-Rebuild Trigger', 'nvoos-graphify' ),
			array( __CLASS__, 'render_field_auto_rebuild' ),
			'nvoos-graphify',
			'nvoos_graphify_build'
		);

		add_settings_field(
			'rebuild_schedule',
			__( 'Rebuild Schedule', 'nvoos-graphify' ),
			array( __CLASS__, 'render_field_rebuild_schedule' ),
			'nvoos-graphify',
			'nvoos_graphify_build'
		);

		// Display section.
		add_settings_section(
			'nvoos_graphify_display',
			__( 'Display', 'nvoos-graphify' ),
			array( __CLASS__, 'render_section_display' ),
			'nvoos-graphify'
		);

		add_settings_field(
			'max_nodes_display',
			__( 'Max Nodes Display', 'nvoos-graphify' ),
			array( __CLASS__, 'render_field_max_nodes_display' ),
			'nvoos-graphify',
			'nvoos_graphify_display'
		);
	}

	/**
	 * Sanitize and validate settings input.
	 *
	 * @since 1.0.0
	 *
	 * @param array $input Raw input from the settings form.
	 * @return array Sanitized settings.
	 */
	public static function sanitize_settings( $input ) {
		$defaults  = NV_oOS_Graphify::get_default_settings();
		$sanitized = array();

		// Boolean fields.
		$sanitized['enabled']             = ! empty( $input['enabled'] );
		$sanitized['include_taxonomies']  = ! empty( $input['include_taxonomies'] );
		$sanitized['include_users']       = ! empty( $input['include_users'] );
		$sanitized['include_media']       = ! empty( $input['include_media'] );

		// Post types — validate against actual public post types.
		$valid_post_types          = array_keys( get_post_types( array( 'public' => true ), 'names' ) );
		$sanitized['post_types']   = array();
		if ( ! empty( $input['post_types'] ) && is_array( $input['post_types'] ) ) {
			foreach ( $input['post_types'] as $pt ) {
				$pt = sanitize_text_field( $pt );
				if ( in_array( $pt, $valid_post_types, true ) ) {
					$sanitized['post_types'][] = $pt;
				}
			}
		}

		// Auto-rebuild.
		$valid_rebuild_options         = array( 'manual', 'save_post', 'scheduled' );
		$sanitized['auto_rebuild']     = isset( $input['auto_rebuild'] ) && in_array( $input['auto_rebuild'], $valid_rebuild_options, true )
			? sanitize_text_field( $input['auto_rebuild'] )
			: $defaults['auto_rebuild'];

		// Rebuild schedule.
		$valid_schedules                = array( 'daily', 'weekly' );
		$sanitized['rebuild_schedule']  = isset( $input['rebuild_schedule'] ) && in_array( $input['rebuild_schedule'], $valid_schedules, true )
			? sanitize_text_field( $input['rebuild_schedule'] )
			: $defaults['rebuild_schedule'];

		// Max nodes display.
		$max_nodes = isset( $input['max_nodes_display'] ) ? absint( $input['max_nodes_display'] ) : $defaults['max_nodes_display'];
		$sanitized['max_nodes_display'] = max( 100, min( 10000, $max_nodes ) );

		return $sanitized;
	}

	/**
	 * Enqueue admin CSS and inline JS on the Graphify settings page.
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 * @return void
	 */
	public static function enqueue_assets( $hook_suffix ) {
		if ( self::$hook_suffix !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'graphify-admin',
			plugin_dir_url( dirname( dirname( __FILE__ ) ) ) . 'assets/css/graphify-admin.css',
			array(),
			'1.0.0'
		);

		$inline_js = self::get_rebuild_inline_js();
		wp_add_inline_script( 'jquery-core', $inline_js );
	}

	/**
	 * Get inline JavaScript for the rebuild button.
	 *
	 * @since 1.0.0
	 *
	 * @return string JavaScript code.
	 */
	private static function get_rebuild_inline_js() {
		$nonce = wp_create_nonce( 'wp_rest' );
		$url   = esc_url_raw( rest_url( 'nvoos-graphify/v1/build' ) );

		return "
document.addEventListener('DOMContentLoaded', function() {
	var btn = document.getElementById('nvoos-graphify-rebuild-btn');
	if ( ! btn ) return;

	btn.addEventListener('click', function(e) {
		e.preventDefault();
		var mode = document.getElementById('nvoos-graphify-build-mode');
		var buildMode = mode ? mode.value : 'full';
		var statusEl = document.getElementById('nvoos-graphify-build-status');

		btn.disabled = true;
		btn.textContent = 'Building…';
		if ( statusEl ) {
			statusEl.className = 'nvoos-graphify-status nvoos-graphify-status--building';
			statusEl.textContent = 'Building';
		}

		fetch('" . $url . "', {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': '" . $nonce . "'
			},
			body: JSON.stringify({ mode: buildMode })
		})
		.then(function(response) { return response.json(); })
		.then(function(data) {
			btn.disabled = false;
			btn.textContent = 'Rebuild Now';
			if ( data.success ) {
				if ( statusEl ) {
					statusEl.className = 'nvoos-graphify-status nvoos-graphify-status--complete';
					statusEl.textContent = 'Complete';
				}
				if ( data.stats ) {
					var nodeEl = document.getElementById('nvoos-graphify-node-count');
					var edgeEl = document.getElementById('nvoos-graphify-edge-count');
					if ( nodeEl && data.stats.node_count !== undefined ) {
						nodeEl.textContent = data.stats.node_count;
					}
					if ( edgeEl && data.stats.edge_count !== undefined ) {
						edgeEl.textContent = data.stats.edge_count;
					}
				}
			} else {
				if ( statusEl ) {
					statusEl.className = 'nvoos-graphify-status nvoos-graphify-status--error';
					statusEl.textContent = 'Error';
				}
			}
		})
		.catch(function() {
			btn.disabled = false;
			btn.textContent = 'Rebuild Now';
			if ( statusEl ) {
				statusEl.className = 'nvoos-graphify-status nvoos-graphify-status--error';
				statusEl.textContent = 'Error';
			}
		});
	});
});";
	}

	// -------------------------------------------------------------------------
	// Section renderers.
	// -------------------------------------------------------------------------

	/**
	 * Render the General section description.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function render_section_general() {
		echo '<p>' . esc_html__( 'Configure the knowledge graph data sources and feature toggle.', 'nvoos-graphify' ) . '</p>';
	}

	/**
	 * Render the Build Settings section description.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function render_section_build() {
		echo '<p>' . esc_html__( 'Control when and how the knowledge graph is rebuilt.', 'nvoos-graphify' ) . '</p>';
	}

	/**
	 * Render the Display section description.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function render_section_display() {
		echo '<p>' . esc_html__( 'Adjust visualization display settings.', 'nvoos-graphify' ) . '</p>';
	}

	// -------------------------------------------------------------------------
	// Field renderers.
	// -------------------------------------------------------------------------

	/**
	 * Render the "Enable Graphify" checkbox field.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function render_field_enabled() {
		$settings = NV_oOS_Graphify::get_settings();
		?>
		<label>
			<input type="checkbox"
				name="<?php echo esc_attr( NV_oOS_Graphify::OPTION_KEY ); ?>[enabled]"
				value="1"
				<?php checked( ! empty( $settings['enabled'] ) ); ?>
			/>
			<?php esc_html_e( 'Enable Graphify Knowledge Graph', 'nvoos-graphify' ); ?>
		</label>
		<?php
	}

	/**
	 * Render the "Content Types" checkbox group.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function render_field_post_types() {
		$settings       = NV_oOS_Graphify::get_settings();
		$selected       = isset( $settings['post_types'] ) ? (array) $settings['post_types'] : array();
		$public_types   = get_post_types( array( 'public' => true ), 'objects' );

		foreach ( $public_types as $post_type ) {
			$checked = in_array( $post_type->name, $selected, true );
			?>
			<label style="display: block; margin-bottom: 4px;">
				<input type="checkbox"
					name="<?php echo esc_attr( NV_oOS_Graphify::OPTION_KEY ); ?>[post_types][]"
					value="<?php echo esc_attr( $post_type->name ); ?>"
					<?php checked( $checked ); ?>
				/>
				<?php echo esc_html( $post_type->labels->name ); ?>
			</label>
			<?php
		}
		echo '<p class="description">' . esc_html__( 'Select which content types to include in the knowledge graph.', 'nvoos-graphify' ) . '</p>';
	}

	/**
	 * Render the "Include Taxonomies" checkbox field.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function render_field_include_taxonomies() {
		$settings = NV_oOS_Graphify::get_settings();
		?>
		<label>
			<input type="checkbox"
				name="<?php echo esc_attr( NV_oOS_Graphify::OPTION_KEY ); ?>[include_taxonomies]"
				value="1"
				<?php checked( ! empty( $settings['include_taxonomies'] ) ); ?>
			/>
			<?php esc_html_e( 'Include taxonomy terms as nodes', 'nvoos-graphify' ); ?>
		</label>
		<?php
	}

	/**
	 * Render the "Include Authors" checkbox field.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function render_field_include_users() {
		$settings = NV_oOS_Graphify::get_settings();
		?>
		<label>
			<input type="checkbox"
				name="<?php echo esc_attr( NV_oOS_Graphify::OPTION_KEY ); ?>[include_users]"
				value="1"
				<?php checked( ! empty( $settings['include_users'] ) ); ?>
			/>
			<?php esc_html_e( 'Include authors as nodes', 'nvoos-graphify' ); ?>
		</label>
		<?php
	}

	/**
	 * Render the "Include Media" checkbox field.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function render_field_include_media() {
		$settings = NV_oOS_Graphify::get_settings();
		?>
		<label>
			<input type="checkbox"
				name="<?php echo esc_attr( NV_oOS_Graphify::OPTION_KEY ); ?>[include_media]"
				value="1"
				<?php checked( ! empty( $settings['include_media'] ) ); ?>
			/>
			<?php esc_html_e( 'Include media attachments as nodes', 'nvoos-graphify' ); ?>
		</label>
		<?php
	}

	/**
	 * Render the "Auto-Rebuild Trigger" radio buttons.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function render_field_auto_rebuild() {
		$settings     = NV_oOS_Graphify::get_settings();
		$auto_rebuild = isset( $settings['auto_rebuild'] ) ? $settings['auto_rebuild'] : 'manual';
		$options      = array(
			'manual'    => __( 'Manual only', 'nvoos-graphify' ),
			'save_post' => __( 'On post save', 'nvoos-graphify' ),
			'scheduled' => __( 'On a schedule', 'nvoos-graphify' ),
		);

		foreach ( $options as $value => $label ) {
			?>
			<label style="display: block; margin-bottom: 4px;">
				<input type="radio"
					name="<?php echo esc_attr( NV_oOS_Graphify::OPTION_KEY ); ?>[auto_rebuild]"
					value="<?php echo esc_attr( $value ); ?>"
					<?php checked( $auto_rebuild, $value ); ?>
				/>
				<?php echo esc_html( $label ); ?>
			</label>
			<?php
		}
	}

	/**
	 * Render the "Rebuild Schedule" select field.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function render_field_rebuild_schedule() {
		$settings = NV_oOS_Graphify::get_settings();
		$schedule = isset( $settings['rebuild_schedule'] ) ? $settings['rebuild_schedule'] : 'daily';
		?>
		<select name="<?php echo esc_attr( NV_oOS_Graphify::OPTION_KEY ); ?>[rebuild_schedule]">
			<option value="daily" <?php selected( $schedule, 'daily' ); ?>>
				<?php esc_html_e( 'Daily', 'nvoos-graphify' ); ?>
			</option>
			<option value="weekly" <?php selected( $schedule, 'weekly' ); ?>>
				<?php esc_html_e( 'Weekly', 'nvoos-graphify' ); ?>
			</option>
		</select>
		<p class="description"><?php esc_html_e( 'Only applies when auto-rebuild is set to "On a schedule".', 'nvoos-graphify' ); ?></p>
		<?php
	}

	/**
	 * Render the "Max Nodes Display" number field.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function render_field_max_nodes_display() {
		$settings  = NV_oOS_Graphify::get_settings();
		$max_nodes = isset( $settings['max_nodes_display'] ) ? absint( $settings['max_nodes_display'] ) : 2000;
		?>
		<input type="number"
			name="<?php echo esc_attr( NV_oOS_Graphify::OPTION_KEY ); ?>[max_nodes_display]"
			value="<?php echo esc_attr( $max_nodes ); ?>"
			min="100"
			max="10000"
			step="100"
			class="small-text"
		/>
		<p class="description"><?php esc_html_e( 'Maximum number of nodes to render in the visualization (100–10,000).', 'nvoos-graphify' ); ?></p>
		<?php
	}

	// -------------------------------------------------------------------------
	// Page renderer.
	// -------------------------------------------------------------------------

	/**
	 * Render the full Graphify settings page.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$graph_id = NV_oOS_Graphify::get_graph_id();
		$stats    = self::get_graph_overview_stats( $graph_id );
		?>
		<div class="wrap nvoos-graphify-wrap">
			<h1><?php esc_html_e( 'Graphify Knowledge Graph', 'nvoos-graphify' ); ?></h1>

			<?php settings_errors(); ?>

			<!-- Graph Overview -->
			<div class="nvoos-graphify-overview">
				<h2><?php esc_html_e( 'Graph Overview', 'nvoos-graphify' ); ?></h2>

				<div class="nvoos-graphify-stats">
					<div class="nvoos-graphify-stat-card">
						<span class="nvoos-graphify-stat-value" id="nvoos-graphify-node-count">
							<?php echo esc_html( number_format_i18n( $stats['node_count'] ) ); ?>
						</span>
						<span class="nvoos-graphify-stat-label"><?php esc_html_e( 'Nodes', 'nvoos-graphify' ); ?></span>
					</div>
					<div class="nvoos-graphify-stat-card">
						<span class="nvoos-graphify-stat-value" id="nvoos-graphify-edge-count">
							<?php echo esc_html( number_format_i18n( $stats['edge_count'] ) ); ?>
						</span>
						<span class="nvoos-graphify-stat-label"><?php esc_html_e( 'Edges', 'nvoos-graphify' ); ?></span>
					</div>
					<div class="nvoos-graphify-stat-card">
						<span class="nvoos-graphify-stat-value">
							<?php echo esc_html( number_format_i18n( $stats['community_count'] ) ); ?>
						</span>
						<span class="nvoos-graphify-stat-label"><?php esc_html_e( 'Communities', 'nvoos-graphify' ); ?></span>
					</div>
					<div class="nvoos-graphify-stat-card">
						<span class="nvoos-graphify-stat-value" style="font-size: 16px;">
							<?php echo $stats['last_built'] ? esc_html( $stats['last_built'] ) : esc_html__( 'Never', 'nvoos-graphify' ); ?>
						</span>
						<span class="nvoos-graphify-stat-label"><?php esc_html_e( 'Last Built', 'nvoos-graphify' ); ?></span>
					</div>
				</div>

				<div style="display: flex; align-items: center; gap: 12px; margin-top: 10px;">
					<span id="nvoos-graphify-build-status" class="nvoos-graphify-status nvoos-graphify-status--<?php echo esc_attr( $stats['build_status'] ); ?>">
						<?php echo esc_html( ucfirst( $stats['build_status'] ) ); ?>
					</span>

					<select id="nvoos-graphify-build-mode" style="margin: 0;">
						<option value="full"><?php esc_html_e( 'Full Rebuild', 'nvoos-graphify' ); ?></option>
						<option value="incremental"><?php esc_html_e( 'Incremental', 'nvoos-graphify' ); ?></option>
					</select>

					<button type="button" id="nvoos-graphify-rebuild-btn" class="nvoos-graphify-build-btn">
						<?php esc_html_e( 'Rebuild Now', 'nvoos-graphify' ); ?>
					</button>
				</div>
			</div>

			<!-- Settings Form -->
			<form method="post" action="options.php">
				<?php
				settings_fields( 'nvoos_graphify_settings_group' );
				do_settings_sections( 'nvoos-graphify' );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Get graph overview stats from the database.
	 *
	 * @since 1.0.0
	 *
	 * @param int $graph_id The graph ID.
	 * @return array Stats with keys: node_count, edge_count, community_count, last_built, build_status.
	 */
	private static function get_graph_overview_stats( $graph_id ) {
		global $wpdb;

		$nodes_table = NV_oOS_Graphify_Database::get_nodes_table();
		$edges_table = NV_oOS_Graphify_Database::get_edges_table();
		$meta_table  = NV_oOS_Graphify_Database::get_meta_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$node_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$nodes_table} WHERE graph_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$graph_id
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$edge_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$edges_table} WHERE graph_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$graph_id
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$community_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT community_id) FROM {$nodes_table} WHERE graph_id = %d AND community_id > 0", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$graph_id
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$meta = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT last_built, build_status FROM {$meta_table} WHERE graph_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$graph_id
			)
		);

		return array(
			'node_count'      => $node_count,
			'edge_count'      => $edge_count,
			'community_count' => $community_count,
			'last_built'      => $meta ? sanitize_text_field( $meta->last_built ) : null,
			'build_status'    => $meta ? sanitize_text_field( $meta->build_status ) : 'idle',
		);
	}
}

NV_oOS_Graphify_Settings::init();
