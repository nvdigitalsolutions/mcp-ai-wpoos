<?php
/**
 * Architectural Design Project Custom Post Type
 *
 * Manages architectural design projects with industry-standard classifications.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Architectural_Design_Toolkit
 * @since 2.10.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and manages the Architectural Design Project custom post type.
 *
 * @since 2.10.0
 */
class WP_MCP_AI_Architectural_Project_CPT {
	/**
	 * Post type slug.
	 *
	 * @var string
	 */
	const POST_TYPE = 'mcp_ai_arch_proj';

	/**
	 * Initialize the class.
	 *
	 * @since 2.10.0
	 */
	public static function init() {
		// Only available in Full Version (not Base Version), unless Pro addon is active.
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() && ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'show_disabled_notice' ) );
			return;
		}

		// Only initialize if architectural design toolkit is enabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_architectural_design_toolkit'] ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'show_disabled_notice' ) );
			return;
		}

		add_action( 'init', array( __CLASS__, 'register_post_type' ) );
		add_action( 'init', array( __CLASS__, 'register_taxonomies' ) );
		add_action( 'init', array( __CLASS__, 'register_meta' ) );
		add_action( 'admin_notices', array( __CLASS__, 'show_info_notice' ) );

		// Admin columns.
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( __CLASS__, 'add_admin_columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( __CLASS__, 'render_admin_columns' ), 10, 2 );
		add_filter( 'manage_edit-' . self::POST_TYPE . '_sortable_columns', array( __CLASS__, 'sortable_columns' ) );
	}

	/**
	 * Show admin notice when architectural design toolkit is disabled.
	 *
	 * @since 2.10.0
	 */
	public static function show_disabled_notice() {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Just checking URL parameter for display logic.
		$post_type             = isset( $_GET['post_type'] ) ? sanitize_key( $_GET['post_type'] ) : '';
		$is_arch_project_page = ( self::POST_TYPE === $post_type );
		if ( ! $is_arch_project_page ) {
			return;
		}

		// Check if in Base Version without Pro addon.
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() && ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
			?>
			<div class="notice notice-warning">
				<p>
					<strong><?php esc_html_e( 'Architectural Design Toolkit Not Available', 'mcp-ai-wpoos-pro' ); ?></strong>
				</p>
				<p>
					<?php
					echo wp_kses_post(
						__( 'The Architectural Design Toolkit is a <strong>Full Version</strong> feature and is not available in Base Version mode.', 'mcp-ai-wpoos-pro' )
					);
					?>
				</p>
				<p>
					<?php
					echo wp_kses_post(
						sprintf(
							/* translators: %s: Code snippet */
							__( 'To use the Architectural Design Toolkit, remove or set to <code>false</code> the following constant in your <code>wp-config.php</code>: %s', 'mcp-ai-wpoos-pro' ),
							'<code>define( \'WP_MCP_AI_BASE_VERSION\', true );</code>'
						)
					);
					?>
				</p>
			</div>
			<?php
			return;
		}

		// Check if feature is disabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_architectural_design_toolkit'] ) ) {
			$settings_url = admin_url( 'admin.php?page=wp_mcp_ai_settings&tab=tools' );
			?>
			<div class="notice notice-warning">
				<p>
					<strong><?php esc_html_e( 'Architectural Design Toolkit Disabled', 'mcp-ai-wpoos-pro' ); ?></strong>
				</p>
				<p>
					<?php esc_html_e( 'The Architectural Design Toolkit is currently disabled. Enable it to create and manage design projects, drawings, and specifications.', 'mcp-ai-wpoos-pro' ); ?>
				</p>
				<p>
					<?php
					echo wp_kses_post(
						sprintf(
							/* translators: %s: Link to settings page */
							__( 'To enable the Architectural Design Toolkit, go to <a href="%s">Settings &rarr; NV oOS &rarr; Tools &amp; Features</a>, click the <strong>Features</strong> tab, check <strong>"Enable Architectural Design Toolkit"</strong>, and save your changes.', 'mcp-ai-wpoos-pro' ),
							esc_url( $settings_url )
						)
					);
					?>
				</p>
			</div>
			<?php
		}
	}

	/**
	 * Show informational notice on architectural project edit screen.
	 *
	 * @since 2.10.0
	 */
	public static function show_info_notice() {
		$screen = get_current_screen();

		// Only show on architectural project edit screens.
		if ( ! $screen ) {
			return;
		}

		$arch_project_screens = array(
			self::POST_TYPE,
			'edit-' . self::POST_TYPE,
		);

		if ( ! in_array( $screen->id, $arch_project_screens, true ) ) {
			return;
		}

		// Don't show if feature is disabled (other notice will show).
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_architectural_design_toolkit'] ) ) {
			return;
		}
		?>
		<div class="notice notice-info architectural-design-info-notice">
			<p>
				<strong><?php esc_html_e( 'Architectural Design Toolkit', 'mcp-ai-wpoos-pro' ); ?></strong>
			</p>
			<p>
				<?php esc_html_e( 'Design projects can be created and managed both manually here in the WordPress admin and via AI assistant tools.', 'mcp-ai-wpoos-pro' ); ?>
			</p>
			<p>
				<?php
				echo wp_kses_post(
					__( '<strong>Industry Standards:</strong> This toolkit follows AIA, CSI MasterFormat, and international building code standards for professional architectural documentation.', 'mcp-ai-wpoos-pro' )
				);
				?>
			</p>
			<p>
				<?php
				echo wp_kses_post(
					__( '<strong>AI Tools:</strong> AI assistants can generate floor plans, 3D models, construction documents, and perform code compliance checks using dedicated architectural design tools.', 'mcp-ai-wpoos-pro' )
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Register architectural design project custom post type.
	 *
	 * @since 2.10.0
	 */
	public static function register_post_type() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'             => array(
					'name'               => _x( 'Design Projects', 'post type general name', 'mcp-ai-wpoos-pro' ),
					'singular_name'      => _x( 'Design Project', 'post type singular name', 'mcp-ai-wpoos-pro' ),
					'menu_name'          => _x( 'Architectural Design', 'admin menu', 'mcp-ai-wpoos-pro' ),
					'name_admin_bar'     => _x( 'Design Project', 'add new on admin bar', 'mcp-ai-wpoos-pro' ),
					'add_new'            => _x( 'Add New', 'design project', 'mcp-ai-wpoos-pro' ),
					'add_new_item'       => __( 'Add New Design Project', 'mcp-ai-wpoos-pro' ),
					'new_item'           => __( 'New Design Project', 'mcp-ai-wpoos-pro' ),
					'edit_item'          => __( 'Edit Design Project', 'mcp-ai-wpoos-pro' ),
					'view_item'          => __( 'View Design Project', 'mcp-ai-wpoos-pro' ),
					'all_items'          => __( 'Design Projects', 'mcp-ai-wpoos-pro' ),
					'search_items'       => __( 'Search Design Projects', 'mcp-ai-wpoos-pro' ),
					'not_found'          => __( 'No design projects found', 'mcp-ai-wpoos-pro' ),
					'not_found_in_trash' => __( 'No design projects found in trash', 'mcp-ai-wpoos-pro' ),
				),
				'public'             => false,
				'publicly_queryable' => false,
				'show_ui'            => true,
				'show_in_menu'       => true,
				'show_in_rest'       => true,
				'has_archive'        => false,
				'rewrite'            => false,
				'capability_type'    => 'post',
				'supports'           => array( 'title', 'editor', 'thumbnail', 'author', 'excerpt' ),
				'menu_icon'          => 'dashicons-building',
				'menu_position'      => 31,
			)
		);
	}

	/**
	 * Register post meta fields.
	 *
	 * @since 2.10.0
	 */
	public static function register_meta() {
		// Text fields with descriptions.
		$text_fields = array(
			'_arch_client_name'     => 'Client or owner name',
			'_arch_location'        => 'Project location (city, state/province, country)',
			'_arch_start_date'      => 'Project start date',
			'_arch_completion_date' => 'Target completion date',
			'_arch_unit_system'     => 'Measurement system (imperial or metric)',
			'_arch_building_code'   => 'Building code standard (ibc, irc, or local)',
		);

		foreach ( $text_fields as $meta_key => $description ) {
			register_post_meta(
				self::POST_TYPE,
				$meta_key,
				array(
					'type'              => 'string',
					'description'       => $description,
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => 'sanitize_text_field',
				)
			);
		}

		// Numeric fields with descriptions.
		$numeric_fields = array(
			'_arch_budget'         => 'Project budget in USD',
			'_arch_square_footage' => 'Total square footage or area',
		);

		foreach ( $numeric_fields as $meta_key => $description ) {
			register_post_meta(
				self::POST_TYPE,
				$meta_key,
				array(
					'type'              => 'number',
					'description'       => $description,
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => 'floatval',
				)
			);
		}
	}

	/**
	 * Register taxonomies for architectural design projects.
	 *
	 * @since 2.10.0
	 */
	public static function register_taxonomies() {
		// Register Project Type taxonomy (Residential, Commercial, Industrial, Institutional, Mixed-Use).
		register_taxonomy(
			'mcp_ai_arch_proj_type',
			self::POST_TYPE,
			array(
				'labels'            => array(
					'name'          => __( 'Project Types', 'mcp-ai-wpoos-pro' ),
					'singular_name' => __( 'Project Type', 'mcp-ai-wpoos-pro' ),
					'search_items'  => __( 'Search Project Types', 'mcp-ai-wpoos-pro' ),
					'all_items'     => __( 'All Project Types', 'mcp-ai-wpoos-pro' ),
					'edit_item'     => __( 'Edit Project Type', 'mcp-ai-wpoos-pro' ),
					'update_item'   => __( 'Update Project Type', 'mcp-ai-wpoos-pro' ),
					'add_new_item'  => __( 'Add New Project Type', 'mcp-ai-wpoos-pro' ),
					'new_item_name' => __( 'New Project Type Name', 'mcp-ai-wpoos-pro' ),
					'menu_name'     => __( 'Project Types', 'mcp-ai-wpoos-pro' ),
				),
				'hierarchical'      => true,
				'show_ui'           => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'query_var'         => true,
				'rewrite'           => false,
			)
		);

		// Register default project types based on industry standards.
		$default_project_types = array(
			'residential'   => __( 'Residential', 'mcp-ai-wpoos-pro' ),
			'commercial'    => __( 'Commercial', 'mcp-ai-wpoos-pro' ),
			'industrial'    => __( 'Industrial', 'mcp-ai-wpoos-pro' ),
			'institutional' => __( 'Institutional', 'mcp-ai-wpoos-pro' ),
			'mixed-use'     => __( 'Mixed-Use', 'mcp-ai-wpoos-pro' ),
			'infrastructure' => __( 'Infrastructure', 'mcp-ai-wpoos-pro' ),
			'recreational'  => __( 'Recreational', 'mcp-ai-wpoos-pro' ),
		);

		foreach ( $default_project_types as $slug => $name ) {
			if ( ! term_exists( $slug, 'mcp_ai_arch_proj_type' ) ) {
				wp_insert_term( $name, 'mcp_ai_arch_proj_type', array( 'slug' => $slug ) );
			}
		}

		// Register Project Status taxonomy.
		register_taxonomy(
			'mcp_ai_arch_proj_status',
			self::POST_TYPE,
			array(
				'labels'            => array(
					'name'          => __( 'Project Status', 'mcp-ai-wpoos-pro' ),
					'singular_name' => __( 'Status', 'mcp-ai-wpoos-pro' ),
					'search_items'  => __( 'Search Statuses', 'mcp-ai-wpoos-pro' ),
					'all_items'     => __( 'All Statuses', 'mcp-ai-wpoos-pro' ),
					'edit_item'     => __( 'Edit Status', 'mcp-ai-wpoos-pro' ),
					'update_item'   => __( 'Update Status', 'mcp-ai-wpoos-pro' ),
					'add_new_item'  => __( 'Add New Status', 'mcp-ai-wpoos-pro' ),
					'new_item_name' => __( 'New Status Name', 'mcp-ai-wpoos-pro' ),
					'menu_name'     => __( 'Status', 'mcp-ai-wpoos-pro' ),
				),
				'hierarchical'      => false,
				'show_ui'           => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'query_var'         => true,
				'rewrite'           => false,
			)
		);

		// Register default project statuses.
		$default_statuses = array(
			'concept'      => __( 'Concept Design', 'mcp-ai-wpoos-pro' ),
			'schematic'    => __( 'Schematic Design', 'mcp-ai-wpoos-pro' ),
			'design-dev'   => __( 'Design Development', 'mcp-ai-wpoos-pro' ),
			'construction' => __( 'Construction Documents', 'mcp-ai-wpoos-pro' ),
			'bidding'      => __( 'Bidding', 'mcp-ai-wpoos-pro' ),
			'execution'    => __( 'Construction Execution', 'mcp-ai-wpoos-pro' ),
			'completed'    => __( 'Completed', 'mcp-ai-wpoos-pro' ),
			'on-hold'      => __( 'On Hold', 'mcp-ai-wpoos-pro' ),
		);

		foreach ( $default_statuses as $slug => $name ) {
			if ( ! term_exists( $slug, 'mcp_ai_arch_proj_status' ) ) {
				wp_insert_term( $name, 'mcp_ai_arch_proj_status', array( 'slug' => $slug ) );
			}
		}
	}

	/**
	 * Add custom admin columns.
	 *
	 * @since 2.10.0
	 * @param array $columns Existing columns.
	 * @return array Modified columns.
	 */
	public static function add_admin_columns( $columns ) {
		// Insert after title column.
		$new_columns = array();
		foreach ( $columns as $key => $value ) {
			$new_columns[ $key ] = $value;
			if ( 'title' === $key ) {
				$new_columns['project_type'] = __( 'Type', 'mcp-ai-wpoos-pro' );
				$new_columns['project_status'] = __( 'Status', 'mcp-ai-wpoos-pro' );
				$new_columns['client'] = __( 'Client', 'mcp-ai-wpoos-pro' );
			}
		}
		return $new_columns;
	}

	/**
	 * Render custom admin columns.
	 *
	 * @since 2.10.0
	 * @param string $column  Column name.
	 * @param int    $post_id Post ID.
	 */
	public static function render_admin_columns( $column, $post_id ) {
		switch ( $column ) {
			case 'project_type':
				$types = get_the_terms( $post_id, 'mcp_ai_arch_proj_type' );
				if ( $types && ! is_wp_error( $types ) ) {
					$type_names = array_map(
						function( $term ) {
							return $term->name;
						},
						$types
					);
					echo esc_html( implode( ', ', $type_names ) );
				} else {
					echo '—';
				}
				break;

			case 'project_status':
				$statuses = get_the_terms( $post_id, 'mcp_ai_arch_proj_status' );
				if ( $statuses && ! is_wp_error( $statuses ) ) {
					$status_names = array_map(
						function( $term ) {
							return $term->name;
						},
						$statuses
					);
					echo esc_html( implode( ', ', $status_names ) );
				} else {
					echo '—';
				}
				break;

			case 'client':
				$client = get_post_meta( $post_id, '_arch_client_name', true );
				echo esc_html( $client ? $client : '—' );
				break;
		}
	}

	/**
	 * Make columns sortable.
	 *
	 * @since 2.10.0
	 * @param array $columns Existing sortable columns.
	 * @return array Modified sortable columns.
	 */
	public static function sortable_columns( $columns ) {
		$columns['project_type'] = 'project_type';
		$columns['project_status'] = 'project_status';
		return $columns;
	}
}

// Initialize the Architectural Project CPT.
WP_MCP_AI_Architectural_Project_CPT::init();
