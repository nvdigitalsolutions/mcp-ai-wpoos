<?php
/**
 * Lead Custom Post Type for CRM lead management.
 *
 * Registers `mcp_ai_lead` — a lifecycle-stage entity with BANT/MEDDIC
 * qualification fields, lead score, owner assignment, source tracking,
 * and MQL/SQL stage progression.  Coexists alongside `mcp_crm_contacts`
 * (WP_MCP_AI_CRM_Engine::resolve_lead_id() resolves both).
 *
 * @package WP_MCP_AI_Pro
 * @subpackage CRM_Toolkit
 * @since 2.3.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lead CPT.
 *
 * @since 2.3.0
 */
class WP_MCP_AI_Lead_CPT {

	/**
	 * Post type slug.
	 *
	 * @var string
	 */
	const POST_TYPE = 'mcp_ai_lead';

	/**
	 * Initialize.
	 *
	 * @since 2.3.0
	 */
	public static function init() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_crm_toolkit'] ) ) {
			return;
		}

		add_action( 'init', array( __CLASS__, 'register_post_type' ) );

		// Admin list columns.
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( __CLASS__, 'add_admin_columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( __CLASS__, 'render_admin_columns' ), 10, 2 );

		// Sortable columns.
		add_filter( 'manage_edit-' . self::POST_TYPE . '_sortable_columns', array( __CLASS__, 'sortable_columns' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'handle_sortable_query' ) );

		// Row actions (View link since post type is not public).
		add_filter( 'post_row_actions', array( __CLASS__, 'add_row_actions' ), 10, 2 );

		// Quick-filter dropdowns.
		add_action( 'restrict_manage_posts', array( __CLASS__, 'add_quick_filters' ) );
		add_filter( 'parse_query', array( __CLASS__, 'handle_quick_filter_query' ) );
	}

	/**
	 * Register the lead post type.
	 *
	 * @since 2.3.0
	 */
	public static function register_post_type() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'          => array(
					'name'               => _x( 'Leads', 'post type general name', 'mcp-ai-wpoos-pro' ),
					'singular_name'      => _x( 'Lead', 'post type singular name', 'mcp-ai-wpoos-pro' ),
					'menu_name'          => _x( 'Leads', 'admin menu', 'mcp-ai-wpoos-pro' ),
					'add_new'            => _x( 'Add New', 'lead', 'mcp-ai-wpoos-pro' ),
					'add_new_item'       => __( 'Add New Lead', 'mcp-ai-wpoos-pro' ),
					'edit_item'          => __( 'Edit Lead', 'mcp-ai-wpoos-pro' ),
					'all_items'          => __( 'All Leads', 'mcp-ai-wpoos-pro' ),
					'search_items'       => __( 'Search Leads', 'mcp-ai-wpoos-pro' ),
					'not_found'          => __( 'No leads found.', 'mcp-ai-wpoos-pro' ),
					'not_found_in_trash' => __( 'No leads found in Trash.', 'mcp-ai-wpoos-pro' ),
				),
				'description'     => __( 'CRM lifecycle-stage lead records.', 'mcp-ai-wpoos-pro' ),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => true,
				'menu_icon'       => 'dashicons-groups',
				'menu_position'   => 55,
				'capability_type' => 'post',
				'has_archive'     => false,
				'hierarchical'    => false,
				'supports'        => array( 'title', 'author' ),
				'show_in_rest'    => true,
			)
		);
	}

	/**
	 * Add admin columns.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public static function add_admin_columns( $columns ) {
		$date = isset( $columns['date'] ) ? $columns['date'] : null;
		unset( $columns['date'] );

		$columns['lead_email']    = __( 'Email', 'mcp-ai-wpoos-pro' );
		$columns['lead_phone']    = __( 'Phone', 'mcp-ai-wpoos-pro' );
		$columns['lead_company']  = __( 'Company', 'mcp-ai-wpoos-pro' );
		$columns['lead_status']   = __( 'Status', 'mcp-ai-wpoos-pro' );
		$columns['lifecycle']     = __( 'Lifecycle', 'mcp-ai-wpoos-pro' );
		$columns['lead_score']    = __( 'Score', 'mcp-ai-wpoos-pro' );
		$columns['contact_owner'] = __( 'Owner', 'mcp-ai-wpoos-pro' );
		$columns['source']        = __( 'Source', 'mcp-ai-wpoos-pro' );
		$columns['channel_link']  = __( 'Channel', 'mcp-ai-wpoos-pro' );

		if ( $date ) {
			$columns['date'] = $date;
		}
		return $columns;
	}

	/**
	 * Render admin column values.
	 *
	 * @param string $column  Column name.
	 * @param int    $post_id Post ID.
	 */
	public static function render_admin_columns( $column, $post_id ) {
		switch ( $column ) {
			case 'lead_email':
				$email = get_post_meta( $post_id, 'email', true );
				if ( $email ) {
					echo '<a href="' . esc_url( 'mailto:' . $email ) . '">' . esc_html( $email ) . '</a>';
				} else {
					echo '—';
				}
				break;
			case 'lead_phone':
				$phone = get_post_meta( $post_id, 'phone', true );
				echo esc_html( $phone ?: '—' );
				break;
			case 'lead_company':
				$company = get_post_meta( $post_id, 'company', true );
				if ( ! $company ) {
					$company = get_post_meta( $post_id, 'company_name', true );
				}
				echo esc_html( $company ?: '—' );
				break;
			case 'lead_status':
				echo esc_html( get_post_meta( $post_id, 'lead_status', true ) ?: 'new' );
				break;
			case 'lifecycle':
				echo esc_html( get_post_meta( $post_id, 'lifecycle_stage', true ) ?: 'lead' );
				break;
			case 'lead_score':
				$score = get_post_meta( $post_id, 'lead_score', true );
				echo esc_html( is_numeric( $score ) ? (int) $score : '0' );
				break;
			case 'contact_owner':
				$owner = get_post_meta( $post_id, 'contact_owner', true );
				if ( $owner ) {
					$user = get_userdata( (int) $owner );
					echo esc_html( $user ? $user->display_name : $owner );
				} else {
					echo '—';
				}
				break;
			case 'source':
				echo esc_html( get_post_meta( $post_id, 'source', true ) ?: '—' );
				break;
			case 'channel_link':
				self::render_channel_column( $post_id );
				break;
		}
	}

	/**
	 * Declare sortable columns.
	 *
	 * @since 2.4.0
	 * @param array $columns Sortable columns.
	 * @return array
	 */
	public static function sortable_columns( $columns ) {
		$columns['lead_score'] = 'lead_score';
		$columns['lead_email'] = 'lead_email';
		return $columns;
	}

	/**
	 * Handle sortable column query modifications.
	 *
	 * @since 2.4.0
	 * @param WP_Query $query The current query.
	 */
	public static function handle_sortable_query( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || 'edit-' . self::POST_TYPE !== $screen->id ) {
			return;
		}

		$orderby = $query->get( 'orderby' );

		if ( 'lead_score' === $orderby ) {
			$query->set( 'meta_key', 'lead_score' );
			$query->set( 'orderby', 'meta_value_num' );
		} elseif ( 'lead_email' === $orderby ) {
			$query->set( 'meta_key', 'email' );
			$query->set( 'orderby', 'meta_value' );
		}
	}

	/**
	 * Add "View Lead" row action since post type is not public.
	 *
	 * @since 2.4.0
	 * @param array   $actions Existing row actions.
	 * @param WP_Post $post    Current post object.
	 * @return array
	 */
	public static function add_row_actions( $actions, $post ) {
		if ( self::POST_TYPE !== $post->post_type ) {
			return $actions;
		}

		// Prepend a "View" link that goes to the edit screen.
		$view_link = get_edit_post_link( $post->ID );
		if ( $view_link ) {
			$actions = array_merge(
				array(
					'view_lead' => sprintf(
						'<a href="%s" aria-label="%s">%s</a>',
						esc_url( $view_link ),
						/* translators: %s: lead title */
						esc_attr( sprintf( __( 'View &#8220;%s&#8221;', 'mcp-ai-wpoos-pro' ), $post->post_title ) ),
						__( 'View Lead', 'mcp-ai-wpoos-pro' )
					),
				),
				$actions
			);
		}

		return $actions;
	}

	/**
	 * Add quick-filter dropdowns for Lifecycle Stage and Lead Status.
	 *
	 * @since 2.4.0
	 * @param string $post_type Current post type.
	 */
	public static function add_quick_filters( $post_type ) {
		if ( self::POST_TYPE !== $post_type ) {
			return;
		}

		// Lifecycle stage filter.
		$lifecycle_stages = array(
			'lead'        => __( 'Lead', 'mcp-ai-wpoos-pro' ),
			'mql'         => __( 'MQL', 'mcp-ai-wpoos-pro' ),
			'sal'         => __( 'SAL', 'mcp-ai-wpoos-pro' ),
			'sql'         => __( 'SQL', 'mcp-ai-wpoos-pro' ),
			'opportunity' => __( 'Opportunity', 'mcp-ai-wpoos-pro' ),
			'customer'    => __( 'Customer', 'mcp-ai-wpoos-pro' ),
		);

		$selected_lifecycle = isset( $_GET['lead_lifecycle'] ) ? sanitize_key( $_GET['lead_lifecycle'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		echo '<select name="lead_lifecycle">';
		echo '<option value="">' . esc_html__( 'All Lifecycle Stages', 'mcp-ai-wpoos-pro' ) . '</option>';
		foreach ( $lifecycle_stages as $value => $label ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $value ),
				selected( $selected_lifecycle, $value, false ),
				esc_html( $label )
			);
		}
		echo '</select>';

		// Lead status filter.
		$statuses = array(
			'new'         => __( 'New', 'mcp-ai-wpoos-pro' ),
			'contacted'   => __( 'Contacted', 'mcp-ai-wpoos-pro' ),
			'engaged'     => __( 'Engaged', 'mcp-ai-wpoos-pro' ),
			'qualified'   => __( 'Qualified', 'mcp-ai-wpoos-pro' ),
			'unqualified' => __( 'Unqualified', 'mcp-ai-wpoos-pro' ),
			'disqualified' => __( 'Disqualified', 'mcp-ai-wpoos-pro' ),
			'converted'   => __( 'Converted', 'mcp-ai-wpoos-pro' ),
		);

		$selected_status = isset( $_GET['lead_status_filter'] ) ? sanitize_key( $_GET['lead_status_filter'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		echo '<select name="lead_status_filter">';
		echo '<option value="">' . esc_html__( 'All Statuses', 'mcp-ai-wpoos-pro' ) . '</option>';
		foreach ( $statuses as $value => $label ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $value ),
				selected( $selected_status, $value, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
	}

	/**
	 * Handle quick-filter query modifications.
	 *
	 * @since 2.4.0
	 * @param WP_Query $query The current query.
	 */
	public static function handle_quick_filter_query( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || 'edit-' . self::POST_TYPE !== $screen->id ) {
			return;
		}

		$meta_query = $query->get( 'meta_query', array() );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET['lead_lifecycle'] ) ) {
			$meta_query[] = array(
				'key'   => 'lifecycle_stage',
				'value' => sanitize_key( $_GET['lead_lifecycle'] ),
			);
		}

		if ( ! empty( $_GET['lead_status_filter'] ) ) {
			$meta_query[] = array(
				'key'   => 'lead_status',
				'value' => sanitize_key( $_GET['lead_status_filter'] ),
			);
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! empty( $meta_query ) ) {
			$query->set( 'meta_query', $meta_query );
		}
	}

	/**
	 * Render the Channel column with platform icon and connection link.
	 *
	 * @since 2.4.0
	 * @param int $post_id Lead post ID.
	 */
	private static function render_channel_column( $post_id ) {
		$connection_id = get_post_meta( $post_id, '_source_connection_id', true );

		if ( ! $connection_id ) {
			// Fall back to the 'source' meta for basic channel display.
			$source = get_post_meta( $post_id, 'source', true );
			if ( $source ) {
				echo '<span class="channel-badge">' . self::get_channel_icon( $source ) . ' ' . esc_html( ucfirst( $source ) ) . '</span>';
			} else {
				echo '—';
			}
			return;
		}

		// Try to resolve the connection details from Remote Site Manager.
		$connection_name = '';
		$connection_type = '';
		$connection_link = '';

		if ( class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );
			if ( $connection ) {
				$connection_name = isset( $connection['name'] ) ? $connection['name'] : '';
				$connection_type = isset( $connection['connection_type'] ) ? $connection['connection_type'] : '';
				$connection_link = admin_url( 'admin.php?page=wp-mcp-ai-remote-sites&edit=' . rawurlencode( $connection_id ) );
			}
		}

		// Fall back to source meta for channel type if connection not found.
		if ( ! $connection_type ) {
			$connection_type = get_post_meta( $post_id, 'source', true );
		}

		$icon  = self::get_channel_icon( $connection_type );
		$label = $connection_name ?: $connection_type ?: $connection_id;

		if ( $connection_link ) {
			echo '<a href="' . esc_url( $connection_link ) . '" class="channel-badge channel-badge--linked" title="' . esc_attr( sprintf( __( 'View connection: %s', 'mcp-ai-wpoos-pro' ), $label ) ) . '">';
			echo $icon . ' ' . esc_html( $label );
			echo '</a>';
		} else {
			echo '<span class="channel-badge">' . $icon . ' ' . esc_html( $label ) . '</span>';
		}
	}

	/**
	 * Get a dashicon or emoji for a channel/connection type.
	 *
	 * @since 2.4.0
	 * @param string $type Channel or connection type slug.
	 * @return string HTML for the icon.
	 */
	private static function get_channel_icon( $type ) {
		$type = strtolower( $type );

		$icons = array(
			'whatsapp'         => '<span class="dashicons dashicons-whatsapp" style="color:#25D366;"></span>',
			'whatsapp_cloud'   => '<span class="dashicons dashicons-whatsapp" style="color:#25D366;"></span>',
			'telegram'         => '<span class="dashicons dashicons-email-alt" style="color:#0088cc;"></span>',
			'slack'            => '<span class="dashicons dashicons-groups" style="color:#4A154B;"></span>',
			'discord'          => '<span class="dashicons dashicons-microphone" style="color:#5865F2;"></span>',
			'microsoft_teams'  => '<span class="dashicons dashicons-video-alt3" style="color:#6264A7;"></span>',
			'google_chat'      => '<span class="dashicons dashicons-google" style="color:#4285F4;"></span>',
			'messenger'        => '<span class="dashicons dashicons-format-chat" style="color:#00B2FF;"></span>',
			'email'            => '<span class="dashicons dashicons-email"></span>',
			'web_form'         => '<span class="dashicons dashicons-admin-site"></span>',
			'gmail'            => '<span class="dashicons dashicons-email" style="color:#EA4335;"></span>',
			'wordpress'        => '<span class="dashicons dashicons-wordpress"></span>',
			'sms'              => '<span class="dashicons dashicons-smartphone"></span>',
			'chat_channel'     => '<span class="dashicons dashicons-format-chat"></span>',
		);

		if ( isset( $icons[ $type ] ) ) {
			return $icons[ $type ];
		}

		// Generic fallback icon.
		return '<span class="dashicons dashicons-networking"></span>';
	}
	}
