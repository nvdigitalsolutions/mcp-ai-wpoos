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

		// Edit screen meta boxes.
		add_action( 'add_meta_boxes', array( __CLASS__, 'register_meta_boxes' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'save_lead_meta' ), 10, 2 );

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

		// Register lead CPT for AI Assistant metabox integration.
		add_filter( 'wp_mcp_ai_cpt_supported_post_types', array( __CLASS__, 'add_to_ai_cpt_support' ) );
	}

	/**
	 * Register lead CPT for AI Assistant metabox integration.
	 *
	 * @since 2.5.0
	 * @param array $post_types Supported post types.
	 * @return array
	 */
	public static function add_to_ai_cpt_support( $post_types ) {
		if ( ! in_array( self::POST_TYPE, $post_types, true ) ) {
			$post_types[] = self::POST_TYPE;
		}
		return $post_types;
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
				echo esc_html( $phone ? $phone : '—' );
				break;
			case 'lead_company':
				$company = get_post_meta( $post_id, 'company', true );
				if ( ! $company ) {
					$company = get_post_meta( $post_id, 'company_name', true );
				}
				echo esc_html( $company ? $company : '—' );
				break;
			case 'lead_status':
				$status = get_post_meta( $post_id, 'lead_status', true );
				echo esc_html( $status ? $status : 'new' );
				break;
			case 'lifecycle':
				$stage = get_post_meta( $post_id, 'lifecycle_stage', true );
				echo esc_html( $stage ? $stage : 'lead' );
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
				$source = get_post_meta( $post_id, 'source', true );
				echo esc_html( $source ? $source : '—' );
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
			'new'          => __( 'New', 'mcp-ai-wpoos-pro' ),
			'contacted'    => __( 'Contacted', 'mcp-ai-wpoos-pro' ),
			'engaged'      => __( 'Engaged', 'mcp-ai-wpoos-pro' ),
			'qualified'    => __( 'Qualified', 'mcp-ai-wpoos-pro' ),
			'unqualified'  => __( 'Unqualified', 'mcp-ai-wpoos-pro' ),
			'disqualified' => __( 'Disqualified', 'mcp-ai-wpoos-pro' ),
			'converted'    => __( 'Converted', 'mcp-ai-wpoos-pro' ),
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
				echo '<span class="channel-badge">' . wp_kses_post( self::get_channel_icon( $source ) ) . ' ' . esc_html( ucfirst( $source ) ) . '</span>';
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
		$label = $connection_name ? $connection_name : ( $connection_type ? $connection_type : $connection_id );

		if ( $connection_link ) {
			/* translators: %s: connection name or label */
			echo '<a href="' . esc_url( $connection_link ) . '" class="channel-badge channel-badge--linked" title="' . esc_attr( sprintf( __( 'View connection: %s', 'mcp-ai-wpoos-pro' ), $label ) ) . '">';
			echo wp_kses_post( $icon ) . ' ' . esc_html( $label );
			echo '</a>';
		} else {
			echo '<span class="channel-badge">' . wp_kses_post( $icon ) . ' ' . esc_html( $label ) . '</span>';
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
			'whatsapp'        => '<span class="dashicons dashicons-whatsapp" style="color:#25D366;"></span>',
			'whatsapp_cloud'  => '<span class="dashicons dashicons-whatsapp" style="color:#25D366;"></span>',
			'telegram'        => '<span class="dashicons dashicons-email-alt" style="color:#0088cc;"></span>',
			'slack'           => '<span class="dashicons dashicons-groups" style="color:#4A154B;"></span>',
			'discord'         => '<span class="dashicons dashicons-microphone" style="color:#5865F2;"></span>',
			'microsoft_teams' => '<span class="dashicons dashicons-video-alt3" style="color:#6264A7;"></span>',
			'google_chat'     => '<span class="dashicons dashicons-google" style="color:#4285F4;"></span>',
			'messenger'       => '<span class="dashicons dashicons-format-chat" style="color:#00B2FF;"></span>',
			'email'           => '<span class="dashicons dashicons-email"></span>',
			'web_form'        => '<span class="dashicons dashicons-admin-site"></span>',
			'gmail'           => '<span class="dashicons dashicons-email" style="color:#EA4335;"></span>',
			'wordpress'       => '<span class="dashicons dashicons-wordpress"></span>',
			'sms'             => '<span class="dashicons dashicons-smartphone"></span>',
			'chat_channel'    => '<span class="dashicons dashicons-format-chat"></span>',
		);

		if ( isset( $icons[ $type ] ) ) {
			return $icons[ $type ];
		}

		// Generic fallback icon.
		return '<span class="dashicons dashicons-networking"></span>';
	}

	/**
	 * Render a "View in Gmail" link when the lead was imported from Gmail.
	 *
	 * Uses the stored Gmail message ID and connection ID to build a direct
	 * link to the original email in Gmail.
	 *
	 * @since 2.8.0
	 * @param int   $post_id Lead post ID.
	 * @param array $meta    Lead meta array (from get_lead_meta).
	 */
	private static function render_source_message_link( $post_id, $meta ) {
		$message_id    = isset( $meta['_source_message_id'] ) ? $meta['_source_message_id'] : '';
		$connection_id = isset( $meta['_source_connection_id'] ) ? $meta['_source_connection_id'] : '';

		if ( empty( $message_id ) ) {
			return;
		}

		// Determine if this is a Gmail-sourced lead.
		$is_gmail = false;
		$gmail_url = '';

		if ( $connection_id && class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );
			if ( $connection && isset( $connection['connection_type'] ) && 'gmail' === $connection['connection_type'] ) {
				$is_gmail = true;
				// Gmail message URL: https://mail.google.com/mail/u/0/#inbox/<message_id>
				$gmail_url = 'https://mail.google.com/mail/u/0/#inbox/' . rawurlencode( $message_id );
			}
		}

		// Fallback: check if message ID looks like a Gmail ID (hex string).
		if ( ! $is_gmail && preg_match( '/^[a-f0-9]{12,}$/i', $message_id ) ) {
			$is_gmail  = true;
			$gmail_url = 'https://mail.google.com/mail/u/0/#inbox/' . rawurlencode( $message_id );
		}

		if ( ! $is_gmail ) {
			return;
		}

		echo '<br><a href="' . esc_url( $gmail_url ) . '" target="_blank" rel="noopener noreferrer" class="button button-small" style="margin-top: 6px;">';
		echo '<span class="dashicons dashicons-email" style="color:#EA4335; vertical-align: middle;"></span> ';
		esc_html_e( 'View in Gmail', 'mcp-ai-wpoos-pro' );
		echo ' <span class="dashicons dashicons-external" style="font-size: 14px; vertical-align: text-top;"></span>';
		echo '</a>';
	}

	/**
	 * Register meta boxes for the lead edit screen.
	 *
	 * @since 2.5.0
	 */
	public static function register_meta_boxes() {
		add_meta_box(
			'mcp_ai_lead_details',
			__( 'Lead Details', 'mcp-ai-wpoos-pro' ),
			array( __CLASS__, 'render_details_meta_box' ),
			self::POST_TYPE,
			'normal',
			'high'
		);

		add_meta_box(
			'mcp_ai_lead_qualification',
			__( 'Qualification (BANT)', 'mcp-ai-wpoos-pro' ),
			array( __CLASS__, 'render_qualification_meta_box' ),
			self::POST_TYPE,
			'normal',
			'high'
		);

		add_meta_box(
			'mcp_ai_lead_activities',
			__( 'Recent Activities', 'mcp-ai-wpoos-pro' ),
			array( __CLASS__, 'render_activities_meta_box' ),
			self::POST_TYPE,
			'normal',
			'default'
		);

		add_meta_box(
			'mcp_ai_lead_deals',
			__( 'Associated Deals', 'mcp-ai-wpoos-pro' ),
			array( __CLASS__, 'render_deals_meta_box' ),
			self::POST_TYPE,
			'normal',
			'default'
		);

		// Remove default author meta box since we'd replace with contact_owner.
		remove_meta_box( 'authordiv', self::POST_TYPE, 'normal' );
	}

	/**
	 * Render the Lead Details meta box.
	 *
	 * @since 2.5.0
	 * @param WP_Post $post The lead post.
	 */
	public static function render_details_meta_box( $post ) {
		wp_nonce_field( 'mcp_ai_lead_save', 'mcp_ai_lead_nonce' );

		$meta = self::get_lead_meta( $post->ID );

		$lifecycle_stages = array(
			'lead'        => __( 'Lead', 'mcp-ai-wpoos-pro' ),
			'mql'         => __( 'MQL', 'mcp-ai-wpoos-pro' ),
			'sal'         => __( 'SAL', 'mcp-ai-wpoos-pro' ),
			'sql'         => __( 'SQL', 'mcp-ai-wpoos-pro' ),
			'opportunity' => __( 'Opportunity', 'mcp-ai-wpoos-pro' ),
			'customer'    => __( 'Customer', 'mcp-ai-wpoos-pro' ),
		);

		$statuses = array(
			'new'          => __( 'New', 'mcp-ai-wpoos-pro' ),
			'contacted'    => __( 'Contacted', 'mcp-ai-wpoos-pro' ),
			'engaged'      => __( 'Engaged', 'mcp-ai-wpoos-pro' ),
			'qualified'    => __( 'Qualified', 'mcp-ai-wpoos-pro' ),
			'unqualified'  => __( 'Unqualified', 'mcp-ai-wpoos-pro' ),
			'disqualified' => __( 'Disqualified', 'mcp-ai-wpoos-pro' ),
			'converted'    => __( 'Converted', 'mcp-ai-wpoos-pro' ),
		);

		$score       = isset( $meta['lead_score'] ) ? (int) $meta['lead_score'] : 0;
		$score_label = self::get_score_label( $score );
		$score_class = self::get_score_class( $score );
		?>
		<style>
			.mcp-lead-meta-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px 24px; margin-top: 8px; }
			.mcp-lead-meta-field { display: flex; flex-direction: column; }
			.mcp-lead-meta-field label { font-weight: 600; margin-bottom: 4px; color: #1d2327; }
			.mcp-lead-meta-field input[type="text"],
			.mcp-lead-meta-field input[type="email"],
			.mcp-lead-meta-field input[type="tel"],
			.mcp-lead-meta-field select { width: 100%; }
			.mcp-lead-score-display { display: flex; align-items: center; gap: 10px; }
			.mcp-lead-score-value { font-size: 24px; font-weight: 700; }
			.mcp-lead-score-value.hot { color: #d63638; }
			.mcp-lead-score-value.warm { color: #dba617; }
			.mcp-lead-score-value.cold { color: #50575e; }
			.mcp-lead-score-badge { display: inline-block; padding: 2px 10px; border-radius: 12px; font-size: 12px; font-weight: 600; }
			.mcp-lead-score-badge.hot { background: #fcf0f1; color: #d63638; }
			.mcp-lead-score-badge.warm { background: #fcf9e8; color: #dba617; }
			.mcp-lead-score-badge.cold { background: #f0f0f1; color: #50575e; }
			.mcp-lead-meta-full { grid-column: 1 / -1; }
			.mcp-lead-contact-links { margin-top: 4px; display: flex; gap: 12px; }
			.mcp-lead-contact-links a { text-decoration: none; }
			.mcp-lead-contact-links .dashicons { vertical-align: middle; }
		</style>

		<div class="mcp-lead-meta-grid">
			<!-- Contact Info -->
			<div class="mcp-lead-meta-field">
				<label for="mcp_lead_email"><?php esc_html_e( 'Email', 'mcp-ai-wpoos-pro' ); ?></label>
				<input type="email" id="mcp_lead_email" name="mcp_lead[email]" value="<?php echo esc_attr( $meta['email'] ?? '' ); ?>" />
			</div>
			<div class="mcp-lead-meta-field">
				<label for="mcp_lead_phone"><?php esc_html_e( 'Phone', 'mcp-ai-wpoos-pro' ); ?></label>
				<input type="tel" id="mcp_lead_phone" name="mcp_lead[phone]" value="<?php echo esc_attr( $meta['phone'] ?? '' ); ?>" />
			</div>
			<div class="mcp-lead-meta-field">
				<label for="mcp_lead_first_name"><?php esc_html_e( 'First Name', 'mcp-ai-wpoos-pro' ); ?></label>
				<input type="text" id="mcp_lead_first_name" name="mcp_lead[first_name]" value="<?php echo esc_attr( $meta['first_name'] ?? '' ); ?>" />
			</div>
			<div class="mcp-lead-meta-field">
				<label for="mcp_lead_last_name"><?php esc_html_e( 'Last Name', 'mcp-ai-wpoos-pro' ); ?></label>
				<input type="text" id="mcp_lead_last_name" name="mcp_lead[last_name]" value="<?php echo esc_attr( $meta['last_name'] ?? '' ); ?>" />
			</div>
			<div class="mcp-lead-meta-field">
				<label for="mcp_lead_company"><?php esc_html_e( 'Company', 'mcp-ai-wpoos-pro' ); ?></label>
				<input type="text" id="mcp_lead_company" name="mcp_lead[company_name]" value="<?php echo esc_attr( $meta['company_name'] ?? $meta['company'] ?? '' ); ?>" />
			</div>
			<div class="mcp-lead-meta-field">
				<label for="mcp_lead_job_title"><?php esc_html_e( 'Job Title', 'mcp-ai-wpoos-pro' ); ?></label>
				<input type="text" id="mcp_lead_job_title" name="mcp_lead[job_title]" value="<?php echo esc_attr( $meta['job_title'] ?? '' ); ?>" />
			</div>

			<!-- Quick Actions -->
			<div class="mcp-lead-meta-full">
				<?php if ( ! empty( $meta['email'] ) ) : ?>
				<div class="mcp-lead-contact-links">
					<a href="<?php echo esc_url( 'mailto:' . $meta['email'] ); ?>" class="button button-small">
						<span class="dashicons dashicons-email"></span> <?php esc_html_e( 'Send Email', 'mcp-ai-wpoos-pro' ); ?>
					</a>
					<?php if ( ! empty( $meta['phone'] ) ) : ?>
					<a href="<?php echo esc_url( 'tel:' . $meta['phone'] ); ?>" class="button button-small">
						<span class="dashicons dashicons-phone"></span> <?php esc_html_e( 'Call', 'mcp-ai-wpoos-pro' ); ?>
					</a>
					<?php endif; ?>
					<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=mcp_ai_crm_activity&related_type=lead&related_id=' . $post->ID ) ); ?>" class="button button-small">
						<span class="dashicons dashicons-plus"></span> <?php esc_html_e( 'Log Activity', 'mcp-ai-wpoos-pro' ); ?>
					</a>
				</div>
				<?php endif; ?>
			</div>

			<!-- Status & Lifecycle -->
			<div class="mcp-lead-meta-field">
				<label for="mcp_lead_status"><?php esc_html_e( 'Status', 'mcp-ai-wpoos-pro' ); ?></label>
				<select id="mcp_lead_status" name="mcp_lead[lead_status]">
					<?php foreach ( $statuses as $value => $label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $meta['lead_status'] ?? 'new', $value ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<div class="mcp-lead-meta-field">
				<label for="mcp_lead_lifecycle"><?php esc_html_e( 'Lifecycle Stage', 'mcp-ai-wpoos-pro' ); ?></label>
				<select id="mcp_lead_lifecycle" name="mcp_lead[lifecycle_stage]">
					<?php foreach ( $lifecycle_stages as $value => $label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $meta['lifecycle_stage'] ?? 'lead', $value ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>

			<!-- Lead Score -->
			<div class="mcp-lead-meta-field">
				<label for="mcp_lead_score"><?php esc_html_e( 'Lead Score', 'mcp-ai-wpoos-pro' ); ?></label>
				<div class="mcp-lead-score-display">
					<span class="mcp-lead-score-value <?php echo esc_attr( $score_class ); ?>"><?php echo esc_html( $score ); ?></span>
					<span class="mcp-lead-score-badge <?php echo esc_attr( $score_class ); ?>"><?php echo esc_html( $score_label ); ?></span>
				</div>
				<input type="range" id="mcp_lead_score" name="mcp_lead[lead_score]" min="0" max="100" value="<?php echo esc_attr( $score ); ?>" style="width: 100%; margin-top: 6px;" />
			</div>

			<!-- Owner -->
			<div class="mcp-lead-meta-field">
				<label for="mcp_lead_owner"><?php esc_html_e( 'Contact Owner', 'mcp-ai-wpoos-pro' ); ?></label>
				<?php
				$owner_id = isset( $meta['contact_owner'] ) ? (int) $meta['contact_owner'] : 0;
				wp_dropdown_users(
					array(
						'name'             => 'mcp_lead[contact_owner]',
						'id'               => 'mcp_lead_owner',
						'selected'         => $owner_id,
						'show_option_none' => __( '— Unassigned —', 'mcp-ai-wpoos-pro' ),
						'role__in'         => array( 'administrator', 'editor', 'author', 'contributor' ),
					)
				);
				?>
			</div>

			<!-- Source -->
			<div class="mcp-lead-meta-field">
				<label for="mcp_lead_source"><?php esc_html_e( 'Source', 'mcp-ai-wpoos-pro' ); ?></label>
				<input type="text" id="mcp_lead_source" name="mcp_lead[source]" value="<?php echo esc_attr( $meta['source'] ?? '' ); ?>" />
			</div>

			<!-- Channel Link -->
			<div class="mcp-lead-meta-field">
				<label><?php esc_html_e( 'Channel', 'mcp-ai-wpoos-pro' ); ?></label>
				<div style="padding-top: 8px;">
					<?php self::render_channel_column( $post->ID ); ?>
					<?php self::render_source_message_link( $post->ID, $meta ); ?>
				</div>
			</div>

			<!-- Notes -->
			<div class="mcp-lead-meta-full">
				<label for="mcp_lead_notes"><?php esc_html_e( 'Notes', 'mcp-ai-wpoos-pro' ); ?></label>
				<textarea id="mcp_lead_notes" name="mcp_lead[notes]" rows="4" style="width: 100%; margin-top: 4px;"><?php echo esc_textarea( $meta['notes'] ?? '' ); ?></textarea>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the Qualification (BANT) meta box.
	 *
	 * @since 2.5.0
	 * @param WP_Post $post The lead post.
	 */
	public static function render_qualification_meta_box( $post ) {
		$meta = self::get_lead_meta( $post->ID );
		?>
		<style>
			.mcp-qual-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px 24px; margin-top: 8px; }
			.mcp-qual-field { display: flex; flex-direction: column; }
			.mcp-qual-field label { font-weight: 600; margin-bottom: 4px; }
			.mcp-qual-field input[type="text"],
			.mcp-qual-field input[type="number"],
			.mcp-qual-field textarea { width: 100%; }
			.mcp-qual-full { grid-column: 1 / -1; }
			.mcp-qual-meta-row { display: flex; gap: 16px; align-items: center; margin-bottom: 8px; }
			.mcp-qual-meta-label { font-weight: 600; min-width: 80px; }
		</style>
		<p class="description"><?php esc_html_e( 'BANT qualification framework: Budget, Authority, Need, Timeline. Used by AI scoring and lead qualification tools.', 'mcp-ai-wpoos-pro' ); ?></p>

		<div class="mcp-qual-grid">
			<div class="mcp-qual-field">
				<label for="mcp_lead_budget"><?php esc_html_e( 'Budget', 'mcp-ai-wpoos-pro' ); ?></label>
				<input type="number" id="mcp_lead_budget" name="mcp_lead[budget]" value="<?php echo esc_attr( $meta['budget'] ?? '' ); ?>" step="0.01" min="0" placeholder="0.00" />
				<p class="description"><?php esc_html_e( 'Estimated budget amount.', 'mcp-ai-wpoos-pro' ); ?></p>
			</div>
			<div class="mcp-qual-field">
				<label for="mcp_lead_timeline"><?php esc_html_e( 'Timeline', 'mcp-ai-wpoos-pro' ); ?></label>
				<input type="text" id="mcp_lead_timeline" name="mcp_lead[timeline]" value="<?php echo esc_attr( $meta['timeline'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'e.g. Q3 2026, 30 days', 'mcp-ai-wpoos-pro' ); ?>" />
			</div>
			<div class="mcp-qual-full">
				<label for="mcp_lead_authority"><?php esc_html_e( 'Authority', 'mcp-ai-wpoos-pro' ); ?></label>
				<input type="text" id="mcp_lead_authority" name="mcp_lead[authority]" value="<?php echo esc_attr( $meta['authority'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'e.g. Decision maker, Influencer, Champion', 'mcp-ai-wpoos-pro' ); ?>" style="width: 100%;" />
			</div>
			<div class="mcp-qual-full">
				<label for="mcp_lead_need"><?php esc_html_e( 'Need / Pain Point', 'mcp-ai-wpoos-pro' ); ?></label>
				<textarea id="mcp_lead_need" name="mcp_lead[need]" rows="3" style="width: 100%;" placeholder="<?php esc_attr_e( 'Describe the identified need or pain point...', 'mcp-ai-wpoos-pro' ); ?>"><?php echo esc_textarea( $meta['need'] ?? '' ); ?></textarea>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the Recent Activities meta box.
	 *
	 * @since 2.5.0
	 * @param WP_Post $post The lead post.
	 */
	public static function render_activities_meta_box( $post ) {
		// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Targeted meta lookups on our own CPT.
		$activities = get_posts(
			array(
				'post_type'      => 'mcp_ai_crm_activity',
				'post_status'    => 'publish',
				'posts_per_page' => 10,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'meta_query'     => array(
					array(
						'key'   => 'related_id',
						'value' => $post->ID,
						'type'  => 'NUMERIC',
					),
					array(
						'key'   => 'related_type',
						'value' => 'lead',
					),
				),
			)
		);

		if ( empty( $activities ) ) :
			?>
			<p><?php esc_html_e( 'No activities recorded for this lead yet.', 'mcp-ai-wpoos-pro' ); ?></p>
			<p>
				<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=mcp_ai_crm_activity' ) ); ?>" class="button button-small">
					<?php esc_html_e( 'Log Activity', 'mcp-ai-wpoos-pro' ); ?>
				</a>
			</p>
			<?php
		else :
			$type_labels = array(
				'call'    => __( 'Call', 'mcp-ai-wpoos-pro' ),
				'email'   => __( 'Email', 'mcp-ai-wpoos-pro' ),
				'meeting' => __( 'Meeting', 'mcp-ai-wpoos-pro' ),
				'task'    => __( 'Task', 'mcp-ai-wpoos-pro' ),
				'note'    => __( 'Note', 'mcp-ai-wpoos-pro' ),
			);
			$type_icons  = array(
				'call'    => 'dashicons-phone',
				'email'   => 'dashicons-email',
				'meeting' => 'dashicons-calendar',
				'task'    => 'dashicons-yes',
				'note'    => 'dashicons-edit',
			);
			?>
			<table class="widefat striped" style="border: none; margin-top: 8px;">
				<thead>
					<tr>
						<th style="width: 120px;"><?php esc_html_e( 'Date', 'mcp-ai-wpoos-pro' ); ?></th>
						<th><?php esc_html_e( 'Type', 'mcp-ai-wpoos-pro' ); ?></th>
						<th><?php esc_html_e( 'Subject', 'mcp-ai-wpoos-pro' ); ?></th>
						<th><?php esc_html_e( 'Due', 'mcp-ai-wpoos-pro' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php
					foreach ( $activities as $activity ) :
						$type     = get_post_meta( $activity->ID, 'activity_type', true );
						$type     = $type ? $type : 'note';
						$icon     = isset( $type_icons[ $type ] ) ? $type_icons[ $type ] : 'dashicons-yes';
						$label    = isset( $type_labels[ $type ] ) ? $type_labels[ $type ] : ucfirst( $type );
						$due      = get_post_meta( $activity->ID, 'due_date', true );
						$disp     = get_post_meta( $activity->ID, 'disposition', true );
						$edit_url = get_edit_post_link( $activity->ID );
						?>
						<tr>
							<td><?php echo esc_html( get_the_date( 'Y-m-d H:i', $activity ) ); ?></td>
							<td>
								<span class="dashicons <?php echo esc_attr( $icon ); ?>" style="vertical-align: middle;"></span>
								<?php echo esc_html( $label ); ?>
								<?php if ( $disp ) : ?>
									<span style="color: #50575e;">— <?php echo esc_html( $disp ); ?></span>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( $edit_url ) : ?>
									<a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $activity->post_title ); ?></a>
								<?php else : ?>
									<?php echo esc_html( $activity->post_title ); ?>
								<?php endif; ?>
							</td>
							<td>
								<?php
								if ( $due ) {
									$due_ts     = strtotime( $due );
									$is_overdue = $due_ts && $due_ts < time();
									echo '<span style="' . ( $is_overdue ? 'color: #d63638; font-weight: 600;' : '' ) . '">';
									echo esc_html( $due );
									if ( $is_overdue ) {
										echo ' ' . esc_html__( '(Overdue)', 'mcp-ai-wpoos-pro' );
									}
									echo '</span>';
								} else {
									echo '—';
								}
								?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php
		endif;
		// phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_query
	}

	/**
	 * Render the Associated Deals meta box.
	 *
	 * @since 2.5.0
	 * @param WP_Post $post The lead post.
	 */
	public static function render_deals_meta_box( $post ) {
		// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Targeted meta lookups on our own CPT.
		$deals = get_posts(
			array(
				'post_type'      => 'mcp_ai_deal',
				'post_status'    => 'publish',
				'posts_per_page' => 10,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'meta_query'     => array(
					array(
						'key'   => '_deal_lead_id',
						'value' => $post->ID,
						'type'  => 'NUMERIC',
					),
				),
			)
		);

		if ( empty( $deals ) ) :
			?>
			<p><?php esc_html_e( 'No deals associated with this lead.', 'mcp-ai-wpoos-pro' ); ?></p>
			<p>
				<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=mcp_ai_deal' ) ); ?>" class="button button-small">
					<?php esc_html_e( 'Create Deal', 'mcp-ai-wpoos-pro' ); ?>
				</a>
			</p>
			<?php
		else :
			$deal_stages  = array(
				'discovery'     => __( 'Discovery', 'mcp-ai-wpoos-pro' ),
				'qualification' => __( 'Qualification', 'mcp-ai-wpoos-pro' ),
				'proposal'      => __( 'Proposal', 'mcp-ai-wpoos-pro' ),
				'negotiation'   => __( 'Negotiation', 'mcp-ai-wpoos-pro' ),
				'closed_won'    => __( 'Closed Won', 'mcp-ai-wpoos-pro' ),
				'closed_lost'   => __( 'Closed Lost', 'mcp-ai-wpoos-pro' ),
			);
			$stage_colors = array(
				'discovery'     => '#50575e',
				'qualification' => '#2271b1',
				'proposal'      => '#dba617',
				'negotiation'   => '#d63638',
				'closed_won'    => '#00a32a',
				'closed_lost'   => '#50575e',
			);
			?>
			<table class="widefat striped" style="border: none; margin-top: 8px;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Deal', 'mcp-ai-wpoos-pro' ); ?></th>
						<th><?php esc_html_e( 'Stage', 'mcp-ai-wpoos-pro' ); ?></th>
						<th><?php esc_html_e( 'Value', 'mcp-ai-wpoos-pro' ); ?></th>
						<th><?php esc_html_e( 'Close Date', 'mcp-ai-wpoos-pro' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php
					foreach ( $deals as $deal ) :
						$deal_stage  = get_post_meta( $deal->ID, '_deal_stage', true );
						$deal_stage  = $deal_stage ? $deal_stage : 'discovery';
						$deal_value  = get_post_meta( $deal->ID, '_deal_value', true );
						$deal_value  = $deal_value ? $deal_value : 0;
						$close_date  = get_post_meta( $deal->ID, '_deal_close_date', true );
						$stage_label = isset( $deal_stages[ $deal_stage ] ) ? $deal_stages[ $deal_stage ] : ucfirst( $deal_stage );
						$stage_color = isset( $stage_colors[ $deal_stage ] ) ? $stage_colors[ $deal_stage ] : '#50575e';
						$edit_url    = get_edit_post_link( $deal->ID );
						?>
						<tr>
							<td>
								<?php if ( $edit_url ) : ?>
									<a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $deal->post_title ); ?></a>
								<?php else : ?>
									<?php echo esc_html( $deal->post_title ); ?>
								<?php endif; ?>
							</td>
							<td>
								<span style="display: inline-block; padding: 2px 10px; border-radius: 12px; font-size: 12px; font-weight: 600; background: <?php echo esc_attr( $stage_color ); ?>15; color: <?php echo esc_attr( $stage_color ); ?>;">
									<?php echo esc_html( $stage_label ); ?>
								</span>
							</td>
							<td><?php echo esc_html( is_numeric( $deal_value ) && $deal_value > 0 ? '$' . number_format_i18n( (float) $deal_value, 2 ) : '—' ); ?></td>
							<td><?php echo esc_html( $close_date ? $close_date : '—' ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php
		endif;
		// phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_query
	}

	/**
	 * Save lead meta fields.
	 *
	 * @since 2.5.0
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public static function save_lead_meta( $post_id, $post ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Required by WordPress hook signature.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! isset( $_POST['mcp_ai_lead_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['mcp_ai_lead_nonce'] ), 'mcp_ai_lead_save' ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( ! isset( $_POST['mcp_lead'] ) || ! is_array( $_POST['mcp_lead'] ) ) {
			return;
		}

		$data = wp_unslash( $_POST['mcp_lead'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized per-field below.

		$string_fields = array( 'email', 'phone', 'first_name', 'last_name', 'source', 'job_title' );
		foreach ( $string_fields as $field ) {
			if ( isset( $data[ $field ] ) ) {
				$value = 'email' === $field ? sanitize_email( $data[ $field ] ) : sanitize_text_field( $data[ $field ] );
				update_post_meta( $post_id, $field, $value );
			}
		}

		if ( isset( $data['company_name'] ) ) {
			$company = sanitize_text_field( $data['company_name'] );
			update_post_meta( $post_id, 'company_name', $company );
			update_post_meta( $post_id, 'company', $company );
		}

		if ( isset( $data['lead_status'] ) ) {
			update_post_meta( $post_id, 'lead_status', sanitize_key( $data['lead_status'] ) );
		}

		if ( isset( $data['lifecycle_stage'] ) ) {
			update_post_meta( $post_id, 'lifecycle_stage', sanitize_key( $data['lifecycle_stage'] ) );
		}

		if ( isset( $data['lead_score'] ) ) {
			update_post_meta( $post_id, 'lead_score', absint( $data['lead_score'] ) );
		}

		if ( isset( $data['contact_owner'] ) ) {
			update_post_meta( $post_id, 'contact_owner', absint( $data['contact_owner'] ) );
		}

		if ( isset( $data['notes'] ) ) {
			update_post_meta( $post_id, 'notes', sanitize_textarea_field( $data['notes'] ) );
		}

		// BANT qualification fields.
		$bant_fields = array( 'budget', 'authority', 'need', 'timeline' );
		foreach ( $bant_fields as $field ) {
			if ( isset( $data[ $field ] ) ) {
				$value = 'budget' === $field ? (float) $data[ $field ] : sanitize_text_field( $data[ $field ] );
				update_post_meta( $post_id, $field, $value );
			}
		}
	}

	/**
	 * Get all lead meta in a single call.
	 *
	 * @since 2.5.0
	 * @param int $post_id Lead post ID.
	 * @return array Lead meta key-value pairs.
	 */
	private static function get_lead_meta( $post_id ) {
		$meta_keys = array(
			'email',
			'phone',
			'first_name',
			'last_name',
			'company_name',
			'company',
			'job_title',
			'lead_status',
			'lifecycle_stage',
			'lead_score',
			'contact_owner',
			'source',
			'notes',
			'budget',
			'authority',
			'need',
			'timeline',
			'score_factors',
			'_source_message_id',
			'_source_connection_id',
		);

		$meta = array();
		foreach ( $meta_keys as $key ) {
			$value = get_post_meta( $post_id, $key, true );
			if ( '' !== $value && false !== $value ) {
				$meta[ $key ] = $value;
			}
		}

		return $meta;
	}

	/**
	 * Get human-readable score label.
	 *
	 * @since 2.5.0
	 * @param int $score Lead score 0-100.
	 * @return string Score label.
	 */
	private static function get_score_label( $score ) {
		if ( $score >= 80 ) {
			return __( 'Hot', 'mcp-ai-wpoos-pro' );
		} elseif ( $score >= 50 ) {
			return __( 'Warm', 'mcp-ai-wpoos-pro' );
		}
		return __( 'Cold', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get CSS class for score display.
	 *
	 * @since 2.5.0
	 * @param int $score Lead score 0-100.
	 * @return string CSS class.
	 */
	private static function get_score_class( $score ) {
		if ( $score >= 80 ) {
			return 'hot';
		} elseif ( $score >= 50 ) {
			return 'warm';
		}
		return 'cold';
	}
}
