<?php
/**
 * Company Custom Post Type for managing companies and organizations.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage CRM_Toolkit
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and manages the Company custom post type.
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Company_CPT {
	/**
	 * Post type slug.
	 *
	 * @var string
	 */
	const POST_TYPE = 'mcp_ai_company';

	/**
	 * Initialize the class.
	 *
	 * @since 1.1.0
	 */
	public static function init() {
		// Only available in Full Version (not Base Version), unless Pro addon is active.
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() && ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'show_disabled_notice' ) );
			return;
		}

		// Only initialize if CRM toolkit is enabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_crm_toolkit'] ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'show_disabled_notice' ) );
			return;
		}

		add_action( 'init', array( __CLASS__, 'register_post_type' ) );
		add_action( 'admin_notices', array( __CLASS__, 'show_info_notice' ) );

		// Admin columns.
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( __CLASS__, 'add_admin_columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( __CLASS__, 'render_admin_columns' ), 10, 2 );
		add_filter( 'manage_edit-' . self::POST_TYPE . '_sortable_columns', array( __CLASS__, 'sortable_columns' ) );
	}

	/**
	 * Show admin notice when CRM toolkit is disabled.
	 *
	 * @since 1.1.0
	 */
	public static function show_disabled_notice() {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Just checking URL parameter for display logic.
		$post_type        = isset( $_GET['post_type'] ) ? sanitize_key( $_GET['post_type'] ) : '';
		$is_company_page = ( self::POST_TYPE === $post_type );
		if ( ! $is_company_page ) {
			return;
		}

		// Check if in Base Version without Pro addon.
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() && ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
			?>
			<div class="notice notice-warning">
				<p>
					<strong><?php esc_html_e( 'CRM Toolkit Not Available', 'mcp-ai-wpoos-pro' ); ?></strong>
				</p>
				<p>
					<?php
					echo wp_kses_post(
						__( 'The CRM & Email Marketing Toolkit is a <strong>Full Version</strong> feature and is not available in Base Version mode.', 'mcp-ai-wpoos-pro' )
					);
					?>
				</p>
				<p>
					<?php
					echo wp_kses_post(
						sprintf(
							/* translators: %s: Code snippet */
							__( 'To use the CRM Toolkit, remove or set to <code>false</code> the following constant in your <code>wp-config.php</code>: %s', 'mcp-ai-wpoos-pro' ),
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
		if ( empty( $settings['enable_crm_toolkit'] ) ) {
			$settings_url = admin_url( 'admin.php?page=wp_mcp_ai_settings&tab=tools' );
			?>
			<div class="notice notice-warning">
				<p>
					<strong><?php esc_html_e( 'CRM Toolkit Disabled', 'mcp-ai-wpoos-pro' ); ?></strong>
				</p>
				<p>
					<?php esc_html_e( 'The CRM & Email Marketing Toolkit is currently disabled. Enable it to create and manage companies.', 'mcp-ai-wpoos-pro' ); ?>
				</p>
				<p>
					<?php
					echo wp_kses_post(
						sprintf(
							/* translators: %s: Link to settings page */
							__( 'To enable the CRM Toolkit, go to <a href="%s">Settings &rarr; NV oOS &rarr; Tools &amp; Features</a>, click the <strong>Features</strong> tab, check <strong>"Enable CRM & Email Marketing Toolkit"</strong>, and save your changes.', 'mcp-ai-wpoos-pro' ),
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
	 * Show informational notice on company edit screen.
	 *
	 * @since 1.1.0
	 */
	public static function show_info_notice() {
		$screen = get_current_screen();

		// Only show on company edit screens.
		if ( ! $screen || ! in_array( $screen->id, array( self::POST_TYPE, 'edit-' . self::POST_TYPE ), true ) ) {
			return;
		}

		// Don't show if feature is disabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_crm_toolkit'] ) ) {
			return;
		}
		?>
		<div class="notice notice-info company-info-notice">
			<p>
				<strong><?php esc_html_e( 'Company Management', 'mcp-ai-wpoos-pro' ); ?></strong>
			</p>
			<p>
				<?php esc_html_e( 'Companies can be created and managed both manually here in the WordPress admin and via AI assistant tools.', 'mcp-ai-wpoos-pro' ); ?>
			</p>
			<p>
				<?php
				echo wp_kses_post(
					__( '<strong>Manual Management:</strong> Use the metaboxes below to add company details, industry information, and contact data.', 'mcp-ai-wpoos-pro' )
				);
				?>
			</p>
			<p>
				<?php
				echo wp_kses_post(
					__( '<strong>AI Tools:</strong> AI assistants can research and create companies using the <code>research_company</code> and <code>create_company</code> tools, and you can edit them here afterwards.', 'mcp-ai-wpoos-pro' )
				);
				?>
			</p>
			<p>
				<?php
				echo wp_kses_post(
					sprintf(
						/* translators: %s: Link to Research & Add page */
						__( 'Use the <a href="%s">Research & Add</a> page to leverage AI-powered web search for identifying target companies and industry best practices.', 'mcp-ai-wpoos-pro' ),
						esc_url( admin_url( 'edit.php?post_type=' . self::POST_TYPE . '&page=research-company' ) )
					)
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Register Company custom post type.
	 *
	 * @since 1.1.0
	 */
	public static function register_post_type() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'             => array(
					'name'               => _x( 'Companies', 'post type general name', 'mcp-ai-wpoos-pro' ),
					'singular_name'      => _x( 'Company', 'post type singular name', 'mcp-ai-wpoos-pro' ),
					'menu_name'          => _x( 'Companies', 'admin menu', 'mcp-ai-wpoos-pro' ),
					'name_admin_bar'     => _x( 'Company', 'add new on admin bar', 'mcp-ai-wpoos-pro' ),
					'add_new'            => _x( 'Add New', 'company', 'mcp-ai-wpoos-pro' ),
					'add_new_item'       => __( 'Add New Company', 'mcp-ai-wpoos-pro' ),
					'new_item'           => __( 'New Company', 'mcp-ai-wpoos-pro' ),
					'edit_item'          => __( 'Edit Company', 'mcp-ai-wpoos-pro' ),
					'view_item'          => __( 'View Company', 'mcp-ai-wpoos-pro' ),
					'all_items'          => __( 'All Companies', 'mcp-ai-wpoos-pro' ),
					'search_items'       => __( 'Search Companies', 'mcp-ai-wpoos-pro' ),
					'parent_item_colon'  => __( 'Parent Companies:', 'mcp-ai-wpoos-pro' ),
					'not_found'          => __( 'No companies found.', 'mcp-ai-wpoos-pro' ),
					'not_found_in_trash' => __( 'No companies found in Trash.', 'mcp-ai-wpoos-pro' ),
				),
				'description'        => __( 'Company and organization management for CRM.', 'mcp-ai-wpoos-pro' ),
				'public'             => false,
				'publicly_queryable' => false,
				'show_ui'            => true,
				'show_in_menu'       => true,
				'menu_icon'          => 'dashicons-building',
				'query_var'          => false,
				'rewrite'            => false,
				'capability_type'    => 'post',
				'has_archive'        => false,
				'hierarchical'       => false,
				'menu_position'      => 56,
				'supports'           => array( 'title', 'editor', 'author', 'thumbnail' ),
				'show_in_rest'       => true,
			)
		);
	}

	/**
	 * Add custom admin columns.
	 *
	 * @since 1.1.0
	 *
	 * @param array $columns Existing columns.
	 * @return array Modified columns.
	 */
	public static function add_admin_columns( $columns ) {
		// Remove date column, we'll add it back at the end.
		$date_column = isset( $columns['date'] ) ? $columns['date'] : null;
		unset( $columns['date'] );

		// Add custom columns.
		$new_columns = array(
			'industry'       => __( 'Industry', 'mcp-ai-wpoos-pro' ),
			'company_size'   => __( 'Size', 'mcp-ai-wpoos-pro' ),
			'location'       => __( 'Location', 'mcp-ai-wpoos-pro' ),
			'target_status'  => __( 'Target Status', 'mcp-ai-wpoos-pro' ),
			'contact_count'  => __( 'Contacts', 'mcp-ai-wpoos-pro' ),
		);

		// Insert after title.
		$position = array_search( 'title', array_keys( $columns ), true ) + 1;
		$columns  = array_slice( $columns, 0, $position, true ) + $new_columns + array_slice( $columns, $position, null, true );

		// Add date back at the end.
		if ( $date_column ) {
			$columns['date'] = $date_column;
		}

		return $columns;
	}

	/**
	 * Render custom admin columns.
	 *
	 * @since 1.1.0
	 *
	 * @param string $column  Column name.
	 * @param int    $post_id Post ID.
	 */
	public static function render_admin_columns( $column, $post_id ) {
		switch ( $column ) {
			case 'industry':
				$industry = get_post_meta( $post_id, '_company_industry', true );
				echo $industry ? esc_html( $industry ) : '—';
				break;

			case 'company_size':
				$size = get_post_meta( $post_id, '_company_size', true );
				if ( $size ) {
					$size_labels = array(
						'1-10'      => __( '1-10', 'mcp-ai-wpoos-pro' ),
						'11-50'     => __( '11-50', 'mcp-ai-wpoos-pro' ),
						'51-200'    => __( '51-200', 'mcp-ai-wpoos-pro' ),
						'201-500'   => __( '201-500', 'mcp-ai-wpoos-pro' ),
						'501-1000'  => __( '501-1,000', 'mcp-ai-wpoos-pro' ),
						'1001-5000' => __( '1,001-5,000', 'mcp-ai-wpoos-pro' ),
						'5001+'     => __( '5,001+', 'mcp-ai-wpoos-pro' ),
					);
					echo isset( $size_labels[ $size ] ) ? esc_html( $size_labels[ $size ] ) : esc_html( $size );
				} else {
					echo '—';
				}
				break;

			case 'location':
				$city    = get_post_meta( $post_id, '_company_city', true );
				$state   = get_post_meta( $post_id, '_company_state', true );
				$country = get_post_meta( $post_id, '_company_country', true );

				$location_parts = array_filter( array( $city, $state, $country ) );
				if ( ! empty( $location_parts ) ) {
					echo esc_html( implode( ', ', $location_parts ) );
				} else {
					echo '—';
				}
				break;

			case 'target_status':
				$status = get_post_meta( $post_id, '_company_target_status', true );
				if ( $status ) {
					$status_labels = array(
						'prospect'      => __( 'Prospect', 'mcp-ai-wpoos-pro' ),
						'target'        => __( 'Target', 'mcp-ai-wpoos-pro' ),
						'in_discussion' => __( 'In Discussion', 'mcp-ai-wpoos-pro' ),
						'client'        => __( 'Client', 'mcp-ai-wpoos-pro' ),
						'not_interested' => __( 'Not Interested', 'mcp-ai-wpoos-pro' ),
					);
					$label = isset( $status_labels[ $status ] ) ? $status_labels[ $status ] : $status;

					// Add status indicator styling.
					$status_colors = array(
						'prospect'       => '#999',
						'target'         => '#2271b1',
						'in_discussion'  => '#f0a000',
						'client'         => '#00a32a',
						'not_interested' => '#d63638',
					);
					$color = isset( $status_colors[ $status ] ) ? $status_colors[ $status ] : '#999';

					echo '<span style="display: inline-block; padding: 2px 8px; border-radius: 3px; background-color: ' . esc_attr( $color . '20' ) . '; color: ' . esc_attr( $color ) . '; font-size: 12px; font-weight: 500;">' . esc_html( $label ) . '</span>';
				} else {
					echo '—';
				}
				break;

			case 'contact_count':
				// Count related contacts (stored as post meta or in a relationship).
				$contacts = get_post_meta( $post_id, '_company_contacts', true );
				if ( is_array( $contacts ) && ! empty( $contacts ) ) {
					echo '<strong>' . esc_html( count( $contacts ) ) . '</strong>';
				} else {
					echo '0';
				}
				break;
		}
	}

	/**
	 * Make custom columns sortable.
	 *
	 * @since 1.1.0
	 *
	 * @param array $columns Sortable columns.
	 * @return array Modified sortable columns.
	 */
	public static function sortable_columns( $columns ) {
		$columns['industry']      = 'industry';
		$columns['company_size']  = 'company_size';
		$columns['target_status'] = 'target_status';

		return $columns;
	}
}
