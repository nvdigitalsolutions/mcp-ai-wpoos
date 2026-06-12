<?php
/**
 * Support Ticket Custom Post Type for CRM post-sale support correspondence.
 *
 * Registers `mcp_ai_ticket` — an ITIL-aligned support ticket entity
 * with a 7-stage pipeline (New → Triaged → In Progress → Waiting on Customer →
 * Waiting on 3rd Party → Resolved → Closed), P1–P4 SLA enforcement, contact
 * association, and activity timeline.
 *
 * Mirrors WP_MCP_AI_Lead_CPT and WP_MCP_AI_Deal_CPT patterns.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage CRM_Toolkit
 * @since 2.6.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Support Ticket CPT.
 *
 * @since 2.6.0
 */
class WP_MCP_AI_Support_Ticket_CPT {

	/**
	 * Post type slug.
	 *
	 * @var string
	 */
	const POST_TYPE = 'mcp_ai_ticket';

	/**
	 * Canonical ticket pipeline stages.
	 *
	 * @var array<string, array>
	 * @since 2.6.0
	 */
	const PIPELINE_STAGES = array(
		'new'                    => array(
			'label'       => 'New',
			'sla_paused'  => false,
			'is_closed'   => false,
			'is_resolved' => false,
		),
		'triaged'                => array(
			'label'       => 'Triaged',
			'sla_paused'  => false,
			'is_closed'   => false,
			'is_resolved' => false,
		),
		'in_progress'            => array(
			'label'       => 'In Progress',
			'sla_paused'  => false,
			'is_closed'   => false,
			'is_resolved' => false,
		),
		'waiting_on_customer'    => array(
			'label'       => 'Waiting on Customer',
			'sla_paused'  => true,
			'is_closed'   => false,
			'is_resolved' => false,
		),
		'waiting_on_third_party' => array(
			'label'       => 'Waiting on 3rd Party',
			'sla_paused'  => true,
			'is_closed'   => false,
			'is_resolved' => false,
		),
		'resolved'               => array(
			'label'       => 'Resolved',
			'sla_paused'  => false,
			'is_closed'   => false,
			'is_resolved' => true,
		),
		'closed'                 => array(
			'label'       => 'Closed',
			'sla_paused'  => false,
			'is_closed'   => true,
			'is_resolved' => true,
		),
	);

	/**
	 * Priority tiers.
	 *
	 * @var array<string, string>
	 * @since 2.6.0
	 */
	const PRIORITIES = array(
		'p1_critical' => 'P1 — Critical',
		'p2_high'     => 'P2 — High',
		'p3_medium'   => 'P3 — Medium',
		'p4_low'      => 'P4 — Low',
	);

	/**
	 * Ticket source channels.
	 *
	 * @var array<string, string>
	 * @since 2.6.0
	 */
	const SOURCES = array(
		'email'    => 'Email',
		'phone'    => 'Phone',
		'chat'     => 'Chat',
		'web_form' => 'Web Form',
		'api'      => 'API',
		'other'    => 'Other',
	);

	/**
	 * Resolution types.
	 *
	 * @var array<string, string>
	 * @since 2.6.0
	 */
	const RESOLUTION_TYPES = array(
		'solved'           => 'Solved',
		'not_reproducible' => 'Not Reproducible',
		'wont_fix'         => 'Won\'t Fix',
		'duplicate'        => 'Duplicate',
		'third_party'      => 'Third Party',
	);

	/**
	 * SLA status labels with colors.
	 *
	 * @var array<string, array>
	 * @since 2.6.0
	 */
	const SLA_STATUSES = array(
		'on_track' => array(
			'label' => 'On Track',
			'color' => '#00a32a',
		),
		'at_risk'  => array(
			'label' => 'At Risk',
			'color' => '#dba617',
		),
		'breached' => array(
			'label' => 'Breached',
			'color' => '#d63638',
		),
	);

	/**
	 * Ticket category options.
	 *
	 * @var array<string, string>
	 * @since 2.6.0
	 */
	const CATEGORIES = array(
		'bug'             => 'Bug',
		'question'        => 'Question',
		'feature_request' => 'Feature Request',
		'account'         => 'Account',
		'billing'         => 'Billing',
		'other'           => 'Other',
	);

	/**
	 * Initialize.
	 *
	 * @since 2.6.0
	 */
	public static function init() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_crm_toolkit'] ) ) {
			return;
		}

		add_action( 'init', array( __CLASS__, 'register_post_type' ) );

		// Edit screen meta boxes.
		add_action( 'add_meta_boxes', array( __CLASS__, 'register_meta_boxes' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'save_ticket_meta' ), 10, 2 );

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

		// Register for AI Assistant metabox integration.
		add_filter( 'wp_mcp_ai_cpt_supported_post_types', array( __CLASS__, 'add_to_ai_cpt_support' ) );

		// Stage transition hook.
		add_action( 'post_updated', array( __CLASS__, 'handle_stage_transition' ), 10, 3 );
	}

	/**
	 * Register support ticket CPT for AI Assistant metabox integration.
	 *
	 * @since 2.6.0
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
	 * Register the support ticket post type.
	 *
	 * @since 2.6.0
	 */
	public static function register_post_type() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'          => array(
					'name'               => _x( 'Support Tickets', 'post type general name', 'mcp-ai-wpoos-pro' ),
					'singular_name'      => _x( 'Support Ticket', 'post type singular name', 'mcp-ai-wpoos-pro' ),
					'menu_name'          => _x( 'Tickets', 'admin menu', 'mcp-ai-wpoos-pro' ),
					'add_new'            => _x( 'Add New', 'ticket', 'mcp-ai-wpoos-pro' ),
					'add_new_item'       => __( 'Add New Support Ticket', 'mcp-ai-wpoos-pro' ),
					'edit_item'          => __( 'Edit Support Ticket', 'mcp-ai-wpoos-pro' ),
					'all_items'          => __( 'All Support Tickets', 'mcp-ai-wpoos-pro' ),
					'search_items'       => __( 'Search Support Tickets', 'mcp-ai-wpoos-pro' ),
					'not_found'          => __( 'No support tickets found.', 'mcp-ai-wpoos-pro' ),
					'not_found_in_trash' => __( 'No support tickets found in Trash.', 'mcp-ai-wpoos-pro' ),
				),
				'description'     => __( 'ITIL-aligned support ticket records with SLA enforcement.', 'mcp-ai-wpoos-pro' ),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => true,
				'menu_icon'       => 'dashicons-sos',
				'menu_position'   => 58,
				'capability_type' => 'post',
				'has_archive'     => false,
				'hierarchical'    => false,
				'supports'        => array( 'title', 'editor', 'author' ),
				'show_in_rest'    => true,
			)
		);
	}

	/*
	 * ------------------------------------------------------------------
	 * Admin List Columns
	 * ------------------------------------------------------------------
	 */

	/**
	 * Add admin columns.
	 *
	 * @since 2.6.0
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public static function add_admin_columns( $columns ) {
		$date = isset( $columns['date'] ) ? $columns['date'] : null;
		unset( $columns['date'] );

		$columns['ticket_status']   = __( 'Status', 'mcp-ai-wpoos-pro' );
		$columns['ticket_priority'] = __( 'Priority', 'mcp-ai-wpoos-pro' );
		$columns['ticket_contact']  = __( 'Contact', 'mcp-ai-wpoos-pro' );
		$columns['ticket_assignee'] = __( 'Assignee', 'mcp-ai-wpoos-pro' );
		$columns['ticket_source']   = __( 'Source', 'mcp-ai-wpoos-pro' );
		$columns['ticket_category'] = __( 'Category', 'mcp-ai-wpoos-pro' );
		$columns['ticket_sla']      = __( 'SLA', 'mcp-ai-wpoos-pro' );

		if ( $date ) {
			$columns['date'] = $date;
		}
		return $columns;
	}

	/**
	 * Render admin column values.
	 *
	 * @since 2.6.0
	 * @param string $column  Column name.
	 * @param int    $post_id Post ID.
	 */
	public static function render_admin_columns( $column, $post_id ) {
		switch ( $column ) {
			case 'ticket_status':
				$status = get_post_meta( $post_id, '_ticket_status', true );
				$stage  = self::get_stage( $status );
				$color  = self::get_stage_color( $status );
				printf(
					'<span style="display:inline-block;padding:2px 10px;border-radius:12px;font-size:12px;font-weight:600;background:%s15;color:%s;">%s</span>',
					esc_attr( $color ),
					esc_attr( $color ),
					esc_html( $stage['label'] ?? ( $status ? $status : 'new' ) )
				);
				break;

			case 'ticket_priority':
				$priority = get_post_meta( $post_id, '_ticket_priority', true );
				$label    = isset( self::PRIORITIES[ $priority ] ) ? self::PRIORITIES[ $priority ] : ( ! empty( $priority ) ? $priority : '—' );
				$color    = self::get_priority_color( $priority );
				printf(
					'<span style="display:inline-block;padding:2px 10px;border-radius:12px;font-size:12px;font-weight:600;background:%s15;color:%s;">%s</span>',
					esc_attr( $color ),
					esc_attr( $color ),
					esc_html( $label )
				);
				break;

			case 'ticket_contact':
				$contact_id = get_post_meta( $post_id, '_ticket_contact_id', true );
				if ( $contact_id ) {
					$contact = get_post( (int) $contact_id );
					if ( $contact ) {
						$edit_url = get_edit_post_link( $contact->ID );
						if ( $edit_url ) {
							echo '<a href="' . esc_url( $edit_url ) . '">' . esc_html( $contact->post_title ) . '</a>';
						} else {
							echo esc_html( $contact->post_title );
						}
					} else {
						echo esc_html( sprintf( '#%d', $contact_id ) );
					}
				} else {
					echo '—';
				}
				break;

			case 'ticket_assignee':
				$assignee_id = get_post_meta( $post_id, '_ticket_assignee_id', true );
				if ( $assignee_id ) {
					$user = get_userdata( (int) $assignee_id );
					echo esc_html( $user ? $user->display_name : sprintf( 'User #%d', $assignee_id ) );
				} else {
					echo '<em>' . esc_html__( 'Unassigned', 'mcp-ai-wpoos-pro' ) . '</em>';
				}
				break;

			case 'ticket_source':
				$source = get_post_meta( $post_id, '_ticket_source', true );
				echo esc_html( isset( self::SOURCES[ $source ] ) ? self::SOURCES[ $source ] : ( ! empty( $source ) ? $source : '—' ) );
				break;

			case 'ticket_category':
				$category = get_post_meta( $post_id, '_ticket_category', true );
				echo esc_html( isset( self::CATEGORIES[ $category ] ) ? self::CATEGORIES[ $category ] : ( ! empty( $category ) ? $category : '—' ) );
				break;

			case 'ticket_sla':
				$sla_status = get_post_meta( $post_id, '_ticket_sla_status', true );
				$sla_info   = isset( self::SLA_STATUSES[ $sla_status ] ) ? self::SLA_STATUSES[ $sla_status ] : self::SLA_STATUSES['on_track'];
				printf(
					'<span style="display:inline-block;padding:2px 10px;border-radius:12px;font-size:12px;font-weight:600;background:%s15;color:%s;">%s</span>',
					esc_attr( $sla_info['color'] ),
					esc_attr( $sla_info['color'] ),
					esc_html( $sla_info['label'] )
				);
				break;
		}
	}

	/**
	 * Declare sortable columns.
	 *
	 * @since 2.6.0
	 * @param array $columns Sortable columns.
	 * @return array
	 */
	public static function sortable_columns( $columns ) {
		$columns['ticket_priority'] = 'ticket_priority';
		$columns['ticket_sla']      = 'ticket_sla';
		return $columns;
	}

	/**
	 * Handle sortable column query modifications.
	 *
	 * @since 2.6.0
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

		if ( 'ticket_priority' === $orderby ) {
			$query->set( 'meta_key', '_ticket_priority' );
			$query->set( 'orderby', 'meta_value' );
		} elseif ( 'ticket_sla' === $orderby ) {
			$query->set( 'meta_key', '_ticket_sla_status' );
			$query->set( 'orderby', 'meta_value' );
		}
	}

	/**
	 * Add "View Ticket" row action since post type is not public.
	 *
	 * @since 2.6.0
	 * @param array   $actions Existing row actions.
	 * @param WP_Post $post    Current post object.
	 * @return array
	 */
	public static function add_row_actions( $actions, $post ) {
		if ( self::POST_TYPE !== $post->post_type ) {
			return $actions;
		}

		$view_link = get_edit_post_link( $post->ID );
		if ( $view_link ) {
			$actions = array_merge(
				array(
					'view_ticket' => sprintf(
						'<a href="%s" aria-label="%s">%s</a>',
						esc_url( $view_link ),
						/* translators: %s: ticket title */
						esc_attr( sprintf( __( 'View &#8220;%s&#8221;', 'mcp-ai-wpoos-pro' ), $post->post_title ) ),
						__( 'View Ticket', 'mcp-ai-wpoos-pro' )
					),
				),
				$actions
			);
		}

		return $actions;
	}

	/*
	 * ------------------------------------------------------------------
	 * Quick Filters
	 * ------------------------------------------------------------------
	 */

	/**
	 * Add quick-filter dropdowns for Stage and Priority.
	 *
	 * @since 2.6.0
	 * @param string $post_type Current post type.
	 */
	public static function add_quick_filters( $post_type ) {
		if ( self::POST_TYPE !== $post_type ) {
			return;
		}

		// Stage filter.
		$selected_stage = isset( $_GET['ticket_stage'] ) ? sanitize_key( $_GET['ticket_stage'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		echo '<select name="ticket_stage">';
		echo '<option value="">' . esc_html__( 'All Stages', 'mcp-ai-wpoos-pro' ) . '</option>';
		foreach ( self::PIPELINE_STAGES as $value => $def ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $value ),
				selected( $selected_stage, $value, false ),
				esc_html( $def['label'] )
			);
		}
		echo '</select>';

		// Priority filter.
		$selected_priority = isset( $_GET['ticket_priority_filter'] ) ? sanitize_key( $_GET['ticket_priority_filter'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		echo '<select name="ticket_priority_filter">';
		echo '<option value="">' . esc_html__( 'All Priorities', 'mcp-ai-wpoos-pro' ) . '</option>';
		foreach ( self::PRIORITIES as $value => $label ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $value ),
				selected( $selected_priority, $value, false ),
				esc_html( $label )
			);
		}
		echo '</select>';

		// Assignee filter.
		$selected_assignee = isset( $_GET['ticket_assignee_filter'] ) ? absint( $_GET['ticket_assignee_filter'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$users             = self::get_assignable_users();

		echo '<select name="ticket_assignee_filter">';
		echo '<option value="">' . esc_html__( 'All Assignees', 'mcp-ai-wpoos-pro' ) . '</option>';
		foreach ( $users as $user_id => $display_name ) {
			printf(
				'<option value="%d" %s>%s</option>',
				absint( $user_id ),
				selected( $selected_assignee, $user_id, false ),
				esc_html( $display_name )
			);
		}
		echo '</select>';
	}

	/**
	 * Handle quick-filter query modifications.
	 *
	 * @since 2.6.0
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
		if ( ! empty( $_GET['ticket_stage'] ) ) {
			$meta_query[] = array(
				'key'   => '_ticket_status',
				'value' => sanitize_key( $_GET['ticket_stage'] ),
			);
		}

		if ( ! empty( $_GET['ticket_priority_filter'] ) ) {
			$meta_query[] = array(
				'key'   => '_ticket_priority',
				'value' => sanitize_key( $_GET['ticket_priority_filter'] ),
			);
		}

		if ( ! empty( $_GET['ticket_assignee_filter'] ) ) {
			$meta_query[] = array(
				'key'   => '_ticket_assignee_id',
				'value' => (string) absint( $_GET['ticket_assignee_filter'] ),
			);
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! empty( $meta_query ) ) {
			$query->set( 'meta_query', $meta_query );
		}
	}

	/*
	 * ------------------------------------------------------------------
	 * Meta Boxes
	 * ------------------------------------------------------------------
	 */

	/**
	 * Register meta boxes for the ticket edit screen.
	 *
	 * @since 2.6.0
	 */
	public static function register_meta_boxes() {
		add_meta_box(
			'mcp_ai_ticket_details',
			__( 'Ticket Details', 'mcp-ai-wpoos-pro' ),
			array( __CLASS__, 'render_details_meta_box' ),
			self::POST_TYPE,
			'normal',
			'high'
		);

		add_meta_box(
			'mcp_ai_ticket_sla',
			__( 'SLA & Timing', 'mcp-ai-wpoos-pro' ),
			array( __CLASS__, 'render_sla_meta_box' ),
			self::POST_TYPE,
			'side',
			'default'
		);

		add_meta_box(
			'mcp_ai_ticket_related',
			__( 'Related Records', 'mcp-ai-wpoos-pro' ),
			array( __CLASS__, 'render_related_meta_box' ),
			self::POST_TYPE,
			'side',
			'default'
		);

		add_meta_box(
			'mcp_ai_ticket_activities',
			__( 'Activity Timeline', 'mcp-ai-wpoos-pro' ),
			array( __CLASS__, 'render_activities_meta_box' ),
			self::POST_TYPE,
			'normal',
			'default'
		);

		// Remove default author meta box — we have a dedicated assignee field.
		remove_meta_box( 'authordiv', self::POST_TYPE, 'normal' );
	}

	/**
	 * Render the Ticket Details meta box.
	 *
	 * @since 2.6.0
	 * @param WP_Post $post The ticket post.
	 */
	public static function render_details_meta_box( $post ) {
		wp_nonce_field( 'mcp_ai_ticket_save', 'mcp_ai_ticket_nonce' );

		$meta      = self::get_ticket_meta( $post->ID );
		$status    = $meta['_ticket_status'] ?? 'new';
		$priority  = $meta['_ticket_priority'] ?? 'p2_high';
		$source    = $meta['_ticket_source'] ?? 'email';
		$assignee  = $meta['_ticket_assignee_id'] ?? 0;
		$contact   = $meta['_ticket_contact_id'] ?? 0;
		$category  = $meta['_ticket_category'] ?? 'question';
		$tags      = isset( $meta['_ticket_tags'] ) ? ( is_array( $meta['_ticket_tags'] ) ? implode( ', ', $meta['_ticket_tags'] ) : $meta['_ticket_tags'] ) : '';
		$parent_id = $meta['_ticket_parent_id'] ?? 0;
		?>
		<style>
			.mcp-tkt-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px 24px; margin-top: 8px; }
			.mcp-tkt-field { display: flex; flex-direction: column; }
			.mcp-tkt-field label { font-weight: 600; margin-bottom: 4px; color: #1d2327; }
			.mcp-tkt-field select,
			.mcp-tkt-field input[type="text"],
			.mcp-tkt-field input[type="number"] { width: 100%; }
			.mcp-tkt-full { grid-column: 1 / -1; }
		</style>

		<div class="mcp-tkt-grid">
			<!-- Status / Stage -->
			<div class="mcp-tkt-field">
				<label for="mcp_ticket_status"><?php esc_html_e( 'Status / Stage', 'mcp-ai-wpoos-pro' ); ?></label>
				<select id="mcp_ticket_status" name="mcp_ticket[_ticket_status]">
					<?php foreach ( self::PIPELINE_STAGES as $value => $def ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $status, $value ); ?>><?php echo esc_html( $def['label'] ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>

			<!-- Priority -->
			<div class="mcp-tkt-field">
				<label for="mcp_ticket_priority"><?php esc_html_e( 'Priority', 'mcp-ai-wpoos-pro' ); ?></label>
				<select id="mcp_ticket_priority" name="mcp_ticket[_ticket_priority]">
					<?php foreach ( self::PRIORITIES as $value => $label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $priority, $value ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>

			<!-- Source -->
			<div class="mcp-tkt-field">
				<label for="mcp_ticket_source"><?php esc_html_e( 'Source', 'mcp-ai-wpoos-pro' ); ?></label>
				<select id="mcp_ticket_source" name="mcp_ticket[_ticket_source]">
					<?php foreach ( self::SOURCES as $value => $label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $source, $value ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>

			<!-- Category -->
			<div class="mcp-tkt-field">
				<label for="mcp_ticket_category"><?php esc_html_e( 'Category / Type', 'mcp-ai-wpoos-pro' ); ?></label>
				<select id="mcp_ticket_category" name="mcp_ticket[_ticket_category]">
					<?php foreach ( self::CATEGORIES as $value => $label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $category, $value ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>

			<!-- Assignee -->
			<div class="mcp-tkt-field">
				<label for="mcp_ticket_assignee"><?php esc_html_e( 'Assignee', 'mcp-ai-wpoos-pro' ); ?></label>
				<?php
				wp_dropdown_users(
					array(
						'name'             => 'mcp_ticket[_ticket_assignee_id]',
						'id'               => 'mcp_ticket_assignee',
						'selected'         => (int) $assignee,
						'show_option_none' => __( '— Unassigned —', 'mcp-ai-wpoos-pro' ),
						'role__in'         => array( 'administrator', 'editor', 'author', 'contributor' ),
					)
				);
				?>
			</div>

			<!-- Contact -->
			<div class="mcp-tkt-field">
				<label for="mcp_ticket_contact_id"><?php esc_html_e( 'Contact / Requester ID', 'mcp-ai-wpoos-pro' ); ?></label>
				<input type="number" id="mcp_ticket_contact_id" name="mcp_ticket[_ticket_contact_id]" value="<?php echo esc_attr( $contact ? $contact : '' ); ?>" min="0" placeholder="<?php esc_attr_e( 'Lead or Contact ID', 'mcp-ai-wpoos-pro' ); ?>" />
				<?php if ( $contact ) : ?>
					<?php $contact_post = get_post( (int) $contact ); ?>
					<?php $contact_url = $contact_post ? get_edit_post_link( $contact_post->ID ) : ''; ?>
					<?php if ( $contact_post && $contact_url ) : ?>
						<p style="margin:4px 0 0;"><a href="<?php echo esc_url( $contact_url ); ?>"><?php echo esc_html( $contact_post->post_title ); ?> (#<?php echo absint( $contact ); ?>)</a></p>
					<?php else : ?>
						<p style="margin:4px 0 0;color:#646970;"><?php esc_html_e( 'Contact not found.', 'mcp-ai-wpoos-pro' ); ?></p>
					<?php endif; ?>
				<?php endif; ?>
			</div>

			<!-- Tags -->
			<div class="mcp-tkt-full">
				<label for="mcp_ticket_tags"><?php esc_html_e( 'Tags', 'mcp-ai-wpoos-pro' ); ?></label>
				<input type="text" id="mcp_ticket_tags" name="mcp_ticket[_ticket_tags]" value="<?php echo esc_attr( $tags ); ?>" style="width: 100%;" placeholder="<?php esc_attr_e( 'Comma-separated, e.g. billing, urgent, onboarding', 'mcp-ai-wpoos-pro' ); ?>" />
				<p class="description"><?php esc_html_e( 'Comma-separated tags for categorisation and filtering.', 'mcp-ai-wpoos-pro' ); ?></p>
			</div>

			<!-- Parent Ticket -->
			<div class="mcp-tkt-full">
				<label for="mcp_ticket_parent"><?php esc_html_e( 'Parent Ticket ID', 'mcp-ai-wpoos-pro' ); ?></label>
				<input type="number" id="mcp_ticket_parent" name="mcp_ticket[_ticket_parent_id]" value="<?php echo esc_attr( $parent_id ? $parent_id : '' ); ?>" min="0" style="width: 150px;" />
				<p class="description"><?php esc_html_e( 'Set to 0 if this is a top-level ticket.', 'mcp-ai-wpoos-pro' ); ?></p>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the SLA & Timing meta box.
	 *
	 * @since 2.6.0
	 * @param WP_Post $post The ticket post.
	 */
	public static function render_sla_meta_box( $post ) {
		$meta              = self::get_ticket_meta( $post->ID );
		$sla_status        = $meta['_ticket_sla_status'] ?? 'on_track';
		$first_response_by = $meta['_ticket_sla_first_response_by'] ?? '';
		$resolution_by     = $meta['_ticket_sla_resolution_by'] ?? '';
		$first_response_at = $meta['_ticket_sla_first_response_at'] ?? '';
		$resolved_at       = $meta['_ticket_sla_resolved_at'] ?? '';
		$paused_secs       = $meta['_ticket_sla_total_paused_secs'] ?? 0;

		$sla_info = isset( self::SLA_STATUSES[ $sla_status ] ) ? self::SLA_STATUSES[ $sla_status ] : self::SLA_STATUSES['on_track'];
		?>
		<style>
			.mcp-sla-status-badge {
				display: inline-block;
				padding: 4px 14px;
				border-radius: 12px;
				font-size: 13px;
				font-weight: 600;
				margin-bottom: 12px;
			}
			.mcp-sla-row { margin-bottom: 10px; }
			.mcp-sla-row label { font-weight: 600; display: block; margin-bottom: 2px; font-size: 12px; color: #50575e; }
			.mcp-sla-row span { font-size: 13px; }
		</style>

		<div class="mcp-sla-status-badge" style="background:<?php echo esc_attr( $sla_info['color'] ); ?>15; color:<?php echo esc_attr( $sla_info['color'] ); ?>;">
			<?php echo esc_html( $sla_info['label'] ); ?>
		</div>

		<div class="mcp-sla-row">
			<label><?php esc_html_e( 'First Response By', 'mcp-ai-wpoos-pro' ); ?></label>
			<span><?php echo esc_html( $first_response_by ? $first_response_by : '—' ); ?></span>
		</div>

		<div class="mcp-sla-row">
			<label><?php esc_html_e( 'First Response At', 'mcp-ai-wpoos-pro' ); ?></label>
			<span><?php echo esc_html( $first_response_at ? $first_response_at : '—' ); ?></span>
		</div>

		<div class="mcp-sla-row">
			<label><?php esc_html_e( 'Resolution By', 'mcp-ai-wpoos-pro' ); ?></label>
			<span><?php echo esc_html( $resolution_by ? $resolution_by : '—' ); ?></span>
		</div>

		<div class="mcp-sla-row">
			<label><?php esc_html_e( 'Resolved At', 'mcp-ai-wpoos-pro' ); ?></label>
			<span><?php echo esc_html( $resolved_at ? $resolved_at : '—' ); ?></span>
		</div>

		<div class="mcp-sla-row">
			<label><?php esc_html_e( 'Total Paused', 'mcp-ai-wpoos-pro' ); ?></label>
			<span><?php echo esc_html( $paused_secs > 0 ? human_time_diff( 0, $paused_secs ) : '—' ); ?></span>
		</div>
		<?php
	}

	/**
	 * Render the Related Records meta box.
	 *
	 * @since 2.6.0
	 * @param WP_Post $post The ticket post.
	 */
	public static function render_related_meta_box( $post ) {
		$contact_id = get_post_meta( $post->ID, '_ticket_contact_id', true );

		// Find related deals linked to this contact.
		$deal_query = array();
		if ( $contact_id ) {
			$deal_query = get_posts(
				array(
					'post_type'      => 'mcp_ai_deal',
					'post_status'    => 'publish',
					'posts_per_page' => 5,
					'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						array(
							'key'   => '_deal_lead_id',
							'value' => (int) $contact_id,
							'type'  => 'NUMERIC',
						),
					),
				)
			);
		}

		// Find child tickets.
		$children = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 10,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => '_ticket_parent_id',
						'value' => $post->ID,
						'type'  => 'NUMERIC',
					),
				),
			)
		);
		?>
		<?php if ( $contact_id ) : ?>
			<div style="margin-bottom: 12px;">
				<strong><?php esc_html_e( 'Contact:', 'mcp-ai-wpoos-pro' ); ?></strong>
				<?php $contact = get_post( (int) $contact_id ); ?>
				<?php if ( $contact ) : ?>
					<a href="<?php echo esc_url( get_edit_post_link( $contact->ID ) ); ?>"><?php echo esc_html( $contact->post_title ); ?></a>
					(<?php echo esc_html( $contact->post_type ); ?>)
				<?php else : ?>
					<span><?php esc_html_e( 'Not found', 'mcp-ai-wpoos-pro' ); ?></span>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<?php if ( ! empty( $deal_query ) ) : ?>
			<div style="margin-bottom: 12px;">
				<strong><?php esc_html_e( 'Related Deals:', 'mcp-ai-wpoos-pro' ); ?></strong>
				<ul style="margin: 4px 0 0; padding-left: 16px;">
					<?php foreach ( $deal_query as $deal ) : ?>
						<li><a href="<?php echo esc_url( get_edit_post_link( $deal->ID ) ); ?>"><?php echo esc_html( $deal->post_title ); ?></a></li>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php endif; ?>

		<?php if ( ! empty( $children ) ) : ?>
			<div style="margin-bottom: 12px;">
				<strong><?php esc_html_e( 'Child Tickets:', 'mcp-ai-wpoos-pro' ); ?></strong>
				<ul style="margin: 4px 0 0; padding-left: 16px;">
					<?php foreach ( $children as $child ) : ?>
						<li><a href="<?php echo esc_url( get_edit_post_link( $child->ID ) ); ?>"><?php echo esc_html( $child->post_title ); ?></a></li>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php endif; ?>

		<?php
		$parent_id = get_post_meta( $post->ID, '_ticket_parent_id', true );
		if ( $parent_id ) :
			$parent = get_post( (int) $parent_id );
			?>
			<div style="margin-bottom: 12px;">
				<strong><?php esc_html_e( 'Parent Ticket:', 'mcp-ai-wpoos-pro' ); ?></strong>
				<?php if ( $parent ) : ?>
					<a href="<?php echo esc_url( get_edit_post_link( $parent->ID ) ); ?>"><?php echo esc_html( $parent->post_title ); ?></a>
				<?php else : ?>
					<span><?php esc_html_e( 'Not found', 'mcp-ai-wpoos-pro' ); ?></span>
				<?php endif; ?>
			</div>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render the Activity Timeline meta box.
	 *
	 * @since 2.6.0
	 * @param WP_Post $post The ticket post.
	 */
	public static function render_activities_meta_box( $post ) {
		// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Targeted meta lookups on own CPT.
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
						'value' => 'ticket',
					),
				),
			)
		);

		if ( empty( $activities ) ) :
			?>
			<p><?php esc_html_e( 'No activities recorded for this ticket yet.', 'mcp-ai-wpoos-pro' ); ?></p>
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

	/*
	 * ------------------------------------------------------------------
	 * Save Handler
	 * ------------------------------------------------------------------
	 */

	/**
	 * Save ticket meta fields.
	 *
	 * @since 2.6.0
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public static function save_ticket_meta( $post_id, $post ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Required by WordPress hook signature.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! isset( $_POST['mcp_ai_ticket_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['mcp_ai_ticket_nonce'] ), 'mcp_ai_ticket_save' ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( ! isset( $_POST['mcp_ticket'] ) || ! is_array( $_POST['mcp_ticket'] ) ) {
			return;
		}

		$data = wp_unslash( $_POST['mcp_ticket'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized per-field below.

		// Stage / status.
		if ( isset( $data['_ticket_status'] ) ) {
			$new_status = sanitize_key( $data['_ticket_status'] );
			$old_status = get_post_meta( $post_id, '_ticket_status', true );

			update_post_meta( $post_id, '_ticket_status', $new_status );

			// Derive SLA pause state from stage.
			$stage_def = self::get_stage( $new_status );
			if ( $stage_def && ! empty( $stage_def['is_resolved'] ) ) {
				// Mark SLA resolved timestamp.
				$existing_resolved = get_post_meta( $post_id, '_ticket_sla_resolved_at', true );
				if ( ! $existing_resolved ) {
					update_post_meta( $post_id, '_ticket_sla_resolved_at', current_time( 'mysql' ) );
					update_post_meta( $post_id, '_ticket_sla_status', 'on_track' );
				}
			}

			if ( $stage_def && ! empty( $stage_def['is_closed'] ) ) {
				update_post_meta( $post_id, '_ticket_closed_at', current_time( 'mysql' ) );
				update_post_meta( $post_id, '_ticket_closed_by', get_current_user_id() );
			}

			// Fire stage-transition hook.
			if ( $old_status && $old_status !== $new_status ) {
				/**
				 * Fires when a support ticket stage changes.
				 *
				 * @since 2.6.0
				 * @param int    $ticket_id  Ticket post ID.
				 * @param string $old_stage  Previous stage slug.
				 * @param string $new_stage  New stage slug.
				 */
				do_action( 'wp_mcp_ai_crm_ticket_status_changed', $post_id, $old_status, $new_status );
			}
		}

		// Priority.
		if ( isset( $data['_ticket_priority'] ) ) {
			$priority = sanitize_key( $data['_ticket_priority'] );
			if ( array_key_exists( $priority, self::PRIORITIES ) ) {
				update_post_meta( $post_id, '_ticket_priority', $priority );
			}
		}

		// Source.
		if ( isset( $data['_ticket_source'] ) ) {
			update_post_meta( $post_id, '_ticket_source', sanitize_key( $data['_ticket_source'] ) );
		}

		// Category.
		if ( isset( $data['_ticket_category'] ) ) {
			update_post_meta( $post_id, '_ticket_category', sanitize_key( $data['_ticket_category'] ) );
		}

		// Contact ID.
		if ( isset( $data['_ticket_contact_id'] ) ) {
			update_post_meta( $post_id, '_ticket_contact_id', absint( $data['_ticket_contact_id'] ) );
		}

		// Assignee ID.
		if ( isset( $data['_ticket_assignee_id'] ) ) {
			$old_assignee = (int) get_post_meta( $post_id, '_ticket_assignee_id', true );
			$new_assignee = absint( $data['_ticket_assignee_id'] );
			update_post_meta( $post_id, '_ticket_assignee_id', $new_assignee );

			if ( $old_assignee !== $new_assignee && $new_assignee > 0 ) {
				/**
				 * Fires when a support ticket is assigned to a user.
				 *
				 * @since 2.6.0
				 * @param int $ticket_id        Ticket post ID.
				 * @param int $old_assignee_id  Previous assignee user ID.
				 * @param int $new_assignee_id  New assignee user ID.
				 */
				do_action( 'wp_mcp_ai_crm_ticket_assigned', $post_id, $old_assignee, $new_assignee );
			}
		}

		// Tags (comma-separated string → JSON array).
		if ( isset( $data['_ticket_tags'] ) ) {
			$tags_string = sanitize_text_field( $data['_ticket_tags'] );
			$tags_array  = array_filter( array_map( 'trim', explode( ',', $tags_string ) ) );
			update_post_meta( $post_id, '_ticket_tags', $tags_array );
		}

		// Parent ticket ID.
		if ( isset( $data['_ticket_parent_id'] ) ) {
			update_post_meta( $post_id, '_ticket_parent_id', absint( $data['_ticket_parent_id'] ) );
		}
	}

	/*
	 * ------------------------------------------------------------------
	 * Stage Transition Handler
	 * ------------------------------------------------------------------
	 */

	/**
	 * Handle stage transitions when a ticket is updated.
	 *
	 * Detects stage changes and fires SLA-relevant hooks.
	 *
	 * @since 2.6.0
	 * @param int     $post_id     Post ID.
	 * @param WP_Post $post_after  Post object after update.
	 * @param WP_Post $post_before Post object before update.
	 */
	public static function handle_stage_transition( $post_id, $post_after, $post_before ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Required by WordPress hook signature.
		if ( self::POST_TYPE !== $post_after->post_type ) {
			return;
		}

		$old_status = get_post_meta( $post_id, '_ticket_status', true );
		$new_status = get_post_meta( $post_id, '_ticket_status', true );

		// Detect reopen from resolved/closed and trigger hook.
		$old_stage = self::get_stage( $old_status );
		if ( $old_stage && ! empty( $old_stage['is_resolved'] ) ) {
			$current_stage = self::get_stage( $new_status );
			if ( $current_stage && empty( $current_stage['is_resolved'] ) && empty( $current_stage['is_closed'] ) ) {
				// Reopened.
				$reopen_count = (int) get_post_meta( $post_id, '_ticket_reopened_count', true );
				update_post_meta( $post_id, '_ticket_reopened_count', $reopen_count + 1 );
				update_post_meta( $post_id, '_ticket_sla_resolved_at', '' );

				/**
				 * Fires when a support ticket is reopened.
				 *
				 * @since 2.6.0
				 * @param int $ticket_id Ticket post ID.
				 */
				do_action( 'wp_mcp_ai_crm_ticket_reopened', $post_id );
			}
		}
	}

	/*
	 * ------------------------------------------------------------------
	 * SLA Calculation Helpers
	 * ------------------------------------------------------------------
	 */

	/**
	 * Calculate SLA targets for a given priority and creation time.
	 *
	 * @since 2.6.0
	 * @param string $priority   Priority slug (p1_critical, etc.).
	 * @param string $created_at MySQL timestamp of ticket creation.
	 * @return array{first_response_by: string, resolution_by: string}
	 */
	public static function calculate_sla_targets( $priority, $created_at = '' ) {
		if ( empty( $created_at ) ) {
			$created_at = current_time( 'mysql' );
		}

		$created_ts = strtotime( $created_at );

		// Default SLA targets (minutes).
		$sla_targets = array(
			'p1_critical' => array(
				'first_response' => 15,
				'resolution'     => 240,
			),
			'p2_high'     => array(
				'first_response' => 60,
				'resolution'     => 480,
			),
			'p3_medium'   => array(
				'first_response' => 240,
				'resolution'     => 1440,
			),
			'p4_low'      => array(
				'first_response' => 480,
				'resolution'     => 4320,
			),
		);

		$targets = isset( $sla_targets[ $priority ] ) ? $sla_targets[ $priority ] : $sla_targets['p2_high'];

		/**
		 * Filter the SLA targets for a given priority.
		 *
		 * @since 2.6.0
		 * @param array  $targets  Map with 'first_response' and 'resolution' in minutes.
		 * @param string $priority Priority slug.
		 */
		$targets = apply_filters( 'wp_mcp_ai_crm_ticket_sla_targets', $targets, $priority );

		$first_response_by = gmdate( 'Y-m-d H:i:s', $created_ts + ( (int) $targets['first_response'] * 60 ) );
		$resolution_by     = gmdate( 'Y-m-d H:i:s', $created_ts + ( (int) $targets['resolution'] * 60 ) );

		return array(
			'first_response_by' => $first_response_by,
			'resolution_by'     => $resolution_by,
		);
	}

	/**
	 * Recalculate SLA status for a ticket.
	 *
	 * @since 2.6.0
	 * @param int $ticket_id Ticket post ID.
	 * @return string SLA status: 'on_track', 'at_risk', or 'breached'.
	 */
	public static function recalc_ticket_sla( $ticket_id ) {
		$ticket = get_post( $ticket_id );
		if ( ! $ticket || self::POST_TYPE !== $ticket->post_type ) {
			return 'on_track';
		}

		$status      = get_post_meta( $ticket_id, '_ticket_status', true );
		$stage       = self::get_stage( $status );
		$first_resp  = get_post_meta( $ticket_id, '_ticket_sla_first_response_at', true );
		$resolved    = get_post_meta( $ticket_id, '_ticket_sla_resolved_at', true );
		$fr_by       = get_post_meta( $ticket_id, '_ticket_sla_first_response_by', true );
		$res_by      = get_post_meta( $ticket_id, '_ticket_sla_resolution_by', true );
		$paused_secs = (int) get_post_meta( $ticket_id, '_ticket_sla_total_paused_secs', true );

		// If resolved, SLA is complete.
		if ( $stage && ( ! empty( $stage['is_resolved'] ) || ! empty( $stage['is_closed'] ) ) ) {
			update_post_meta( $ticket_id, '_ticket_sla_status', 'on_track' );
			return 'on_track';
		}

		$now    = time();
		$result = 'on_track';

		// Check first response timer.
		if ( ! $first_resp && $fr_by ) {
			$fr_deadline = strtotime( $fr_by ) + $paused_secs;
			if ( $now > $fr_deadline ) {
				$result = 'breached';
			} elseif ( $now > ( $fr_deadline - ( ( $fr_deadline - strtotime( $ticket->post_date ) ) * 0.25 ) ) ) {
				// At risk if > 75% of time elapsed.
				if ( 'breached' !== $result ) {
					$result = 'at_risk';
				}
			}
		}

		// Check resolution timer.
		if ( ! $resolved && $res_by ) {
			$res_deadline = strtotime( $res_by ) + $paused_secs;
			if ( $now > $res_deadline ) {
				$result = 'breached';
			} elseif ( $now > ( $res_deadline - ( ( $res_deadline - strtotime( $ticket->post_date ) ) * 0.25 ) ) ) {
				if ( 'breached' !== $result ) {
					$result = 'at_risk';
				}
			}
		}

		update_post_meta( $ticket_id, '_ticket_sla_status', $result );
		return $result;
	}

	/*
	 * ------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------
	 */

	/**
	 * Get stage definition by slug.
	 *
	 * @since 2.6.0
	 * @param string $stage_slug Stage slug.
	 * @return array|null
	 */
	public static function get_stage( $stage_slug ) {
		return isset( self::PIPELINE_STAGES[ $stage_slug ] ) ? self::PIPELINE_STAGES[ $stage_slug ] : null;
	}

	/**
	 * Get color for a stage badge.
	 *
	 * @since 2.6.0
	 * @param string $stage_slug Stage slug.
	 * @return string Hex color.
	 */
	public static function get_stage_color( $stage_slug ) {
		$colors = array(
			'new'                    => '#50575e',
			'triaged'                => '#2271b1',
			'in_progress'            => '#dba617',
			'waiting_on_customer'    => '#9a5c12',
			'waiting_on_third_party' => '#826eb4',
			'resolved'               => '#00a32a',
			'closed'                 => '#50575e',
		);

		return isset( $colors[ $stage_slug ] ) ? $colors[ $stage_slug ] : '#50575e';
	}

	/**
	 * Get color for a priority badge.
	 *
	 * @since 2.6.0
	 * @param string $priority Priority slug.
	 * @return string Hex color.
	 */
	public static function get_priority_color( $priority ) {
		$colors = array(
			'p1_critical' => '#d63638',
			'p2_high'     => '#dba617',
			'p3_medium'   => '#2271b1',
			'p4_low'      => '#50575e',
		);

		return isset( $colors[ $priority ] ) ? $colors[ $priority ] : '#50575e';
	}

	/**
	 * Get all ticket meta in a single call.
	 *
	 * @since 2.6.0
	 * @param int $post_id Ticket post ID.
	 * @return array Ticket meta key-value pairs.
	 */
	private static function get_ticket_meta( $post_id ) {
		$meta_keys = array(
			'_ticket_status',
			'_ticket_priority',
			'_ticket_source',
			'_ticket_category',
			'_ticket_contact_id',
			'_ticket_assignee_id',
			'_ticket_tags',
			'_ticket_parent_id',
			'_ticket_sla_first_response_by',
			'_ticket_sla_resolution_by',
			'_ticket_sla_first_response_at',
			'_ticket_sla_resolved_at',
			'_ticket_sla_status',
			'_ticket_sla_paused_at',
			'_ticket_sla_total_paused_secs',
			'_ticket_resolution_type',
			'_ticket_resolution_note',
			'_ticket_closed_by',
			'_ticket_closed_at',
			'_ticket_reopened_count',
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
	 * Get list of users eligible for ticket assignment.
	 *
	 * @since 2.6.0
	 * @return array Map of user_id => display_name.
	 */
	private static function get_assignable_users() {
		$users    = get_users(
			array(
				'role__in' => array( 'administrator', 'editor', 'author', 'contributor' ),
				'orderby'  => 'display_name',
				'order'    => 'ASC',
				'fields'   => array( 'ID', 'display_name' ),
			)
		);
		$user_map = array();
		foreach ( $users as $user ) {
			$user_map[ $user->ID ] = $user->display_name;
		}
		return $user_map;
	}
}
