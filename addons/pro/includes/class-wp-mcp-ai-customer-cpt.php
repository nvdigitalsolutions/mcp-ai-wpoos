<?php
/**
 * Customer Custom Post Type for CRM customer management.
 *
 * Registers `mcp_ai_customer` — a dedicated post-conversion entity for
 * customers who have passed through the lead lifecycle.  Linked back to
 * the originating lead via `source_lead_id` meta.  Coexists alongside
 * `mcp_ai_lead` (pre-conversion) and `mcp_ai_company` (accounts).
 *
 * @package   WP_MCP_AI_Pro
 * @subpackage CRM_Toolkit
 * @since     2.6.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and manages the Customer custom post type.
 *
 * @since 2.6.0
 */
class WP_MCP_AI_Customer_CPT {

	/**
	 * Post type slug.
	 *
	 * @var string
	 */
	const POST_TYPE = 'mcp_ai_customer';

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
		add_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'save_customer_meta' ), 10, 2 );

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

		// Register customer CPT for AI Assistant metabox integration.
		add_filter( 'wp_mcp_ai_cpt_supported_post_types', array( __CLASS__, 'add_to_ai_cpt_support' ) );
	}

	/**
	 * Register customer CPT for AI Assistant metabox integration.
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
	 * Register the customer post type.
	 *
	 * @since 2.6.0
	 */
	public static function register_post_type() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'          => array(
					'name'               => _x( 'Customers', 'post type general name', 'mcp-ai-wpoos-pro' ),
					'singular_name'      => _x( 'Customer', 'post type singular name', 'mcp-ai-wpoos-pro' ),
					'menu_name'          => _x( 'Customers', 'admin menu', 'mcp-ai-wpoos-pro' ),
					'add_new'            => _x( 'Add New', 'customer', 'mcp-ai-wpoos-pro' ),
					'add_new_item'       => __( 'Add New Customer', 'mcp-ai-wpoos-pro' ),
					'edit_item'          => __( 'Edit Customer', 'mcp-ai-wpoos-pro' ),
					'all_items'          => __( 'All Customers', 'mcp-ai-wpoos-pro' ),
					'search_items'       => __( 'Search Customers', 'mcp-ai-wpoos-pro' ),
					'not_found'          => __( 'No customers found.', 'mcp-ai-wpoos-pro' ),
					'not_found_in_trash' => __( 'No customers found in Trash.', 'mcp-ai-wpoos-pro' ),
				),
				'description'     => __( 'CRM post-conversion customer records.', 'mcp-ai-wpoos-pro' ),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => true,
				'menu_icon'       => 'dashicons-smiley',
				'menu_position'   => 57,
				'capability_type' => 'post',
				'has_archive'     => false,
				'hierarchical'    => false,
				'supports'        => array( 'title', 'author' ),
				'show_in_rest'    => true,
			)
		);
	}

	/**
	 * Add custom admin columns.
	 *
	 * @since 2.6.0
	 *
	 * @param array $columns Existing columns.
	 * @return array Modified columns.
	 */
	public static function add_admin_columns( $columns ) {
		$date_column = isset( $columns['date'] ) ? $columns['date'] : null;
		unset( $columns['date'] );

		$new_columns = array(
			'email'           => __( 'Email', 'mcp-ai-wpoos-pro' ),
			'company_name'    => __( 'Company', 'mcp-ai-wpoos-pro' ),
			'lifecycle_stage' => __( 'Stage', 'mcp-ai-wpoos-pro' ),
			'contact_owner'   => __( 'Owner', 'mcp-ai-wpoos-pro' ),
			'customer_since'  => __( 'Customer Since', 'mcp-ai-wpoos-pro' ),
			'source_lead'     => __( 'Source Lead', 'mcp-ai-wpoos-pro' ),
		);

		// Insert after title.
		$position = array_search( 'title', array_keys( $columns ), true ) + 1;
		$columns  = array_slice( $columns, 0, $position, true )
			+ $new_columns
			+ array_slice( $columns, $position, null, true );

		if ( $date_column ) {
			$columns['date'] = $date_column;
		}

		return $columns;
	}

	/**
	 * Render custom admin columns.
	 *
	 * @since 2.6.0
	 *
	 * @param string $column  Column name.
	 * @param int    $post_id Post ID.
	 */
	public static function render_admin_columns( $column, $post_id ) {
		switch ( $column ) {
			case 'email':
				$email = get_post_meta( $post_id, 'email', true );
				if ( $email ) {
					echo '<a href="' . esc_url( 'mailto:' . $email ) . '">' . esc_html( $email ) . '</a>';
				} else {
					echo '—';
				}
				break;

			case 'company_name':
				$company = get_post_meta( $post_id, 'company_name', true );
				echo esc_html( ! empty( $company ) ? $company : '—' );
				break;

			case 'lifecycle_stage':
				$stage = get_post_meta( $post_id, 'lifecycle_stage', true );
				$label = $stage ? ucfirst( $stage ) : __( 'Customer', 'mcp-ai-wpoos-pro' );
				echo esc_html( $label );
				break;

			case 'contact_owner':
				$owner_id = get_post_meta( $post_id, 'contact_owner', true );
				if ( $owner_id ) {
					$user = get_userdata( (int) $owner_id );
					echo esc_html( $user ? $user->display_name : __( 'User #', 'mcp-ai-wpoos-pro' ) . $owner_id );
				} else {
					echo '—';
				}
				break;

			case 'customer_since':
				$since = get_post_meta( $post_id, 'customer_since', true );
				echo esc_html( ! empty( $since ) ? $since : '—' );
				break;

			case 'source_lead':
				$lead_id = get_post_meta( $post_id, 'source_lead_id', true );
				if ( $lead_id ) {
					$lead = get_post( (int) $lead_id );
					if ( $lead ) {
						$edit_url = get_edit_post_link( $lead_id, 'raw' );
						echo '<a href="' . esc_url( $edit_url ) . '">' . esc_html( $lead->post_title ) . '</a>';
					} else {
						echo esc_html( '#' . $lead_id );
					}
				} else {
					echo '—';
				}
				break;
		}
	}

	/**
	 * Add sortable columns.
	 *
	 * @since 2.6.0
	 *
	 * @param array $columns Sortable columns.
	 * @return array
	 */
	public static function sortable_columns( $columns ) {
		$columns['email']           = 'email';
		$columns['company_name']    = 'company_name';
		$columns['lifecycle_stage'] = 'lifecycle_stage';
		$columns['customer_since']  = 'customer_since';
		return $columns;
	}

	/**
	 * Handle sortable column queries.
	 *
	 * @since 2.6.0
	 *
	 * @param WP_Query $query Current query.
	 */
	public static function handle_sortable_query( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		$orderby = $query->get( 'orderby' );

		$sortable_map = array(
			'email'           => 'email',
			'company_name'    => 'company_name',
			'lifecycle_stage' => 'lifecycle_stage',
			'customer_since'  => 'customer_since',
		);

		if ( isset( $sortable_map[ $orderby ] ) ) {
			$query->set( 'meta_key', $sortable_map[ $orderby ] );
			$query->set( 'orderby', 'meta_value' );
		}
	}

	/**
	 * Add row actions.
	 *
	 * @since 2.6.0
	 *
	 * @param array   $actions Existing actions.
	 * @param WP_Post $post    Current post.
	 * @return array
	 */
	public static function add_row_actions( $actions, $post ) {
		if ( self::POST_TYPE !== $post->post_type ) {
			return $actions;
		}

		// Add a View link since the post type is not public.
		$view_url = get_permalink( $post->ID );
		if ( $view_url && ! str_contains( $view_url, '?p=' ) ) {
			$actions['view'] = sprintf(
				'<a href="%s" target="_blank" rel="noopener">%s</a>',
				esc_url( $view_url ),
				esc_html__( 'View', 'mcp-ai-wpoos-pro' )
			);
		}

		return $actions;
	}

	/**
	 * Add quick-filter dropdowns.
	 *
	 * @since 2.6.0
	 *
	 * @param string $post_type Current post type.
	 */
	public static function add_quick_filters( $post_type ) {
		if ( self::POST_TYPE !== $post_type ) {
			return;
		}

		// Lifecycle stage filter.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter dropdown, no state change.
		$current_stage = isset( $_GET['customer_lifecycle_stage'] ) ? sanitize_key( wp_unslash( $_GET['customer_lifecycle_stage'] ) ) : '';
		?>
		<select name="customer_lifecycle_stage">
			<option value=""><?php esc_html_e( 'All Stages', 'mcp-ai-wpoos-pro' ); ?></option>
			<option value="customer" <?php selected( $current_stage, 'customer' ); ?>><?php esc_html_e( 'Customer', 'mcp-ai-wpoos-pro' ); ?></option>
			<option value="evangelist" <?php selected( $current_stage, 'evangelist' ); ?>><?php esc_html_e( 'Evangelist', 'mcp-ai-wpoos-pro' ); ?></option>
			<option value="other" <?php selected( $current_stage, 'other' ); ?>><?php esc_html_e( 'Other', 'mcp-ai-wpoos-pro' ); ?></option>
		</select>
		<?php

		// Owner filter.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter dropdown, no state change.
		$current_owner = isset( $_GET['customer_owner'] ) ? absint( wp_unslash( $_GET['customer_owner'] ) ) : 0;
		$users         = get_users( array( 'fields' => array( 'ID', 'display_name' ) ) );
		?>
		<select name="customer_owner">
			<option value=""><?php esc_html_e( 'All Owners', 'mcp-ai-wpoos-pro' ); ?></option>
			<?php foreach ( $users as $user ) : ?>
				<option value="<?php echo esc_attr( $user->ID ); ?>" <?php selected( $current_owner, $user->ID ); ?>>
					<?php echo esc_html( $user->display_name ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	/**
	 * Handle quick-filter queries.
	 *
	 * @since 2.6.0
	 *
	 * @param WP_Query $query Current query.
	 */
	public static function handle_quick_filter_query( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$stage = isset( $_GET['customer_lifecycle_stage'] ) ? sanitize_key( wp_unslash( $_GET['customer_lifecycle_stage'] ) ) : '';
		$owner = isset( $_GET['customer_owner'] ) ? absint( wp_unslash( $_GET['customer_owner'] ) ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$meta_query = $query->get( 'meta_query', array() );
		if ( ! is_array( $meta_query ) ) {
			$meta_query = array();
		}

		if ( ! empty( $stage ) ) {
			$meta_query[] = array(
				'key'   => 'lifecycle_stage',
				'value' => $stage,
			);
		}

		if ( ! empty( $owner ) ) {
			$meta_query[] = array(
				'key'   => 'contact_owner',
				'value' => (string) $owner,
			);
		}

		if ( ! empty( $meta_query ) ) {
			$query->set( 'meta_query', $meta_query );
		}
	}

	/**
	 * Register meta boxes.
	 *
	 * @since 2.6.0
	 */
	public static function register_meta_boxes() {
		add_meta_box(
			'mcp_customer_details',
			__( 'Customer Details', 'mcp-ai-wpoos-pro' ),
			array( __CLASS__, 'render_details_meta_box' ),
			self::POST_TYPE,
			'normal',
			'high'
		);

		add_meta_box(
			'mcp_customer_billing',
			__( 'Billing & Revenue', 'mcp-ai-wpoos-pro' ),
			array( __CLASS__, 'render_billing_meta_box' ),
			self::POST_TYPE,
			'normal',
			'default'
		);

		add_meta_box(
			'mcp_customer_source',
			__( 'Source & Attribution', 'mcp-ai-wpoos-pro' ),
			array( __CLASS__, 'render_source_meta_box' ),
			self::POST_TYPE,
			'side',
			'default'
		);
	}

	/**
	 * Render the customer details meta box.
	 *
	 * @since 2.6.0
	 *
	 * @param WP_Post $post Current post.
	 */
	public static function render_details_meta_box( $post ) {
		wp_nonce_field( 'mcp_customer_meta', 'mcp_customer_meta_nonce' );

		$email      = get_post_meta( $post->ID, 'email', true );
		$first_name = get_post_meta( $post->ID, 'first_name', true );
		$last_name  = get_post_meta( $post->ID, 'last_name', true );
		$phone      = get_post_meta( $post->ID, 'phone', true );
		$company    = get_post_meta( $post->ID, 'company_name', true );
		$job_title  = get_post_meta( $post->ID, 'job_title', true );
		$stage      = get_post_meta( $post->ID, 'lifecycle_stage', true );
		$owner      = get_post_meta( $post->ID, 'contact_owner', true );
		$tags       = get_post_meta( $post->ID, 'tags', true );
		$notes      = get_post_meta( $post->ID, 'notes', true );

		if ( is_array( $tags ) ) {
			$tags = implode( ', ', $tags );
		}
		?>
		<style>
		.mcp-customer-meta-grid {
			display: grid;
			grid-template-columns: 1fr 1fr;
			gap: 12px 20px;
		}
		.mcp-customer-meta-field label {
			display: block;
			font-weight: 600;
			margin-bottom: 4px;
		}
		.mcp-customer-meta-field input[type="text"],
		.mcp-customer-meta-field input[type="email"],
		.mcp-customer-meta-field input[type="tel"],
		.mcp-customer-meta-field select {
			width: 100%;
		}
		.mcp-customer-meta-full {
			grid-column: 1 / -1;
		}
		</style>

		<div class="mcp-customer-meta-grid">
			<div class="mcp-customer-meta-field">
				<label for="mcp_customer_email"><?php esc_html_e( 'Email', 'mcp-ai-wpoos-pro' ); ?></label>
				<input type="email" id="mcp_customer_email" name="mcp_customer_email"
					value="<?php echo esc_attr( $email ); ?>" />
			</div>

			<div class="mcp-customer-meta-field">
				<label for="mcp_customer_phone"><?php esc_html_e( 'Phone', 'mcp-ai-wpoos-pro' ); ?></label>
				<input type="tel" id="mcp_customer_phone" name="mcp_customer_phone"
					value="<?php echo esc_attr( $phone ); ?>"
					placeholder="+1234567890" />
			</div>

			<div class="mcp-customer-meta-field">
				<label for="mcp_customer_first_name"><?php esc_html_e( 'First Name', 'mcp-ai-wpoos-pro' ); ?></label>
				<input type="text" id="mcp_customer_first_name" name="mcp_customer_first_name"
					value="<?php echo esc_attr( $first_name ); ?>" />
			</div>

			<div class="mcp-customer-meta-field">
				<label for="mcp_customer_last_name"><?php esc_html_e( 'Last Name', 'mcp-ai-wpoos-pro' ); ?></label>
				<input type="text" id="mcp_customer_last_name" name="mcp_customer_last_name"
					value="<?php echo esc_attr( $last_name ); ?>" />
			</div>

			<div class="mcp-customer-meta-field">
				<label for="mcp_customer_company"><?php esc_html_e( 'Company', 'mcp-ai-wpoos-pro' ); ?></label>
				<input type="text" id="mcp_customer_company" name="mcp_customer_company"
					value="<?php echo esc_attr( $company ); ?>" />
			</div>

			<div class="mcp-customer-meta-field">
				<label for="mcp_customer_job_title"><?php esc_html_e( 'Job Title', 'mcp-ai-wpoos-pro' ); ?></label>
				<input type="text" id="mcp_customer_job_title" name="mcp_customer_job_title"
					value="<?php echo esc_attr( $job_title ); ?>" />
			</div>

			<div class="mcp-customer-meta-field">
				<label for="mcp_customer_stage"><?php esc_html_e( 'Lifecycle Stage', 'mcp-ai-wpoos-pro' ); ?></label>
				<select id="mcp_customer_stage" name="mcp_customer_stage">
					<option value="customer" <?php selected( $stage, 'customer' ); ?>><?php esc_html_e( 'Customer', 'mcp-ai-wpoos-pro' ); ?></option>
					<option value="evangelist" <?php selected( $stage, 'evangelist' ); ?>><?php esc_html_e( 'Evangelist', 'mcp-ai-wpoos-pro' ); ?></option>
					<option value="other" <?php selected( $stage, 'other' ); ?>><?php esc_html_e( 'Other', 'mcp-ai-wpoos-pro' ); ?></option>
				</select>
			</div>

			<div class="mcp-customer-meta-field">
				<label for="mcp_customer_owner"><?php esc_html_e( 'Contact Owner', 'mcp-ai-wpoos-pro' ); ?></label>
				<?php
				wp_dropdown_users(
					array(
						'name'             => 'mcp_customer_owner',
						'selected'         => (int) $owner,
						'show_option_none' => __( '— Unassigned —', 'mcp-ai-wpoos-pro' ),
					)
				);
				?>
			</div>

			<div class="mcp-customer-meta-field">
				<label for="mcp_customer_tags"><?php esc_html_e( 'Tags', 'mcp-ai-wpoos-pro' ); ?></label>
				<input type="text" id="mcp_customer_tags" name="mcp_customer_tags"
					value="<?php echo esc_attr( $tags ); ?>"
					placeholder="<?php esc_attr_e( 'Comma-separated', 'mcp-ai-wpoos-pro' ); ?>" />
			</div>

			<div class="mcp-customer-meta-full mcp-customer-meta-field">
				<label for="mcp_customer_notes"><?php esc_html_e( 'Notes', 'mcp-ai-wpoos-pro' ); ?></label>
				<textarea id="mcp_customer_notes" name="mcp_customer_notes" rows="4" style="width:100%;"><?php echo esc_textarea( $notes ); ?></textarea>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the billing & revenue meta box.
	 *
	 * @since 2.6.0
	 *
	 * @param WP_Post $post Current post.
	 */
	public static function render_billing_meta_box( $post ) {
		$total_revenue  = get_post_meta( $post->ID, 'total_revenue', true );
		$lifetime_value = get_post_meta( $post->ID, 'lifetime_value', true );
		$customer_since = get_post_meta( $post->ID, 'customer_since', true );
		$currency       = get_post_meta( $post->ID, 'currency', true );

		if ( ! $currency && class_exists( 'WP_MCP_AI_CRM_Engine' ) ) {
			$settings = WP_MCP_AI_CRM_Engine::get_toolkit_settings();
			$currency = $settings['default_currency'];
		}
		?>
		<style>
		.mcp-customer-billing-grid {
			display: grid;
			grid-template-columns: 1fr 1fr;
			gap: 12px 20px;
		}
		.mcp-customer-billing-field label {
			display: block;
			font-weight: 600;
			margin-bottom: 4px;
		}
		.mcp-customer-billing-field input {
			width: 100%;
		}
		</style>

		<div class="mcp-customer-billing-grid">
			<div class="mcp-customer-billing-field">
				<label for="mcp_customer_total_revenue"><?php esc_html_e( 'Total Revenue', 'mcp-ai-wpoos-pro' ); ?></label>
				<input type="number" id="mcp_customer_total_revenue" name="mcp_customer_total_revenue"
					value="<?php echo esc_attr( $total_revenue ); ?>" step="0.01" min="0" />
			</div>

			<div class="mcp-customer-billing-field">
				<label for="mcp_customer_lifetime_value"><?php esc_html_e( 'Lifetime Value (LTV)', 'mcp-ai-wpoos-pro' ); ?></label>
				<input type="number" id="mcp_customer_lifetime_value" name="mcp_customer_lifetime_value"
					value="<?php echo esc_attr( $lifetime_value ); ?>" step="0.01" min="0" />
			</div>

			<div class="mcp-customer-billing-field">
				<label for="mcp_customer_since"><?php esc_html_e( 'Customer Since', 'mcp-ai-wpoos-pro' ); ?></label>
				<input type="date" id="mcp_customer_since" name="mcp_customer_since"
					value="<?php echo esc_attr( $customer_since ); ?>" />
			</div>

			<div class="mcp-customer-billing-field">
				<label for="mcp_customer_currency"><?php esc_html_e( 'Currency', 'mcp-ai-wpoos-pro' ); ?></label>
				<input type="text" id="mcp_customer_currency" name="mcp_customer_currency"
					value="<?php echo esc_attr( $currency ); ?>" maxlength="3"
					placeholder="USD" />
			</div>
		</div>
		<?php
	}

	/**
	 * Render the source & attribution meta box.
	 *
	 * @since 2.6.0
	 *
	 * @param WP_Post $post Current post.
	 */
	public static function render_source_meta_box( $post ) {
		$source_lead_id = get_post_meta( $post->ID, 'source_lead_id', true );
		$source         = get_post_meta( $post->ID, 'source', true );
		?>
		<p>
			<label for="mcp_customer_source_lead_id"><strong><?php esc_html_e( 'Source Lead ID', 'mcp-ai-wpoos-pro' ); ?></strong></label><br />
			<input type="number" id="mcp_customer_source_lead_id" name="mcp_customer_source_lead_id"
				value="<?php echo esc_attr( $source_lead_id ); ?>" style="width:100%;" />
			<?php if ( $source_lead_id ) : ?>
				<?php $lead = get_post( (int) $source_lead_id ); ?>
				<?php if ( $lead ) : ?>
					<br /><small>
						<a href="<?php echo esc_url( get_edit_post_link( $lead->ID, 'raw' ) ); ?>" target="_blank">
							<?php echo esc_html( $lead->post_title ); ?>
						</a>
					</small>
				<?php endif; ?>
			<?php endif; ?>
		</p>
		<p>
			<label for="mcp_customer_source"><strong><?php esc_html_e( 'Source', 'mcp-ai-wpoos-pro' ); ?></strong></label><br />
			<input type="text" id="mcp_customer_source" name="mcp_customer_source"
				value="<?php echo esc_attr( $source ); ?>" style="width:100%;"
				placeholder="<?php esc_attr_e( 'e.g. website, referral, event', 'mcp-ai-wpoos-pro' ); ?>" />
		</p>
		<?php
	}

	/**
	 * Save customer meta.
	 *
	 * @since 2.6.0
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public static function save_customer_meta( $post_id, $post ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Required by WordPress hook signature.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! isset( $_POST['mcp_customer_meta_nonce'] )
			|| ! wp_verify_nonce( sanitize_key( $_POST['mcp_customer_meta_nonce'] ), 'mcp_customer_meta' )
		) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Text fields to save.
		$fields = array(
			'email'           => 'sanitize_email',
			'first_name'      => 'sanitize_text_field',
			'last_name'       => 'sanitize_text_field',
			'phone'           => 'sanitize_text_field',
			'company_name'    => 'sanitize_text_field',
			'job_title'       => 'sanitize_text_field',
			'lifecycle_stage' => 'sanitize_key',
			'customer_since'  => 'sanitize_text_field',
			'currency'        => 'sanitize_text_field',
			'source'          => 'sanitize_text_field',
		);

		foreach ( $fields as $meta_key => $sanitizer ) {
			$post_key = 'mcp_customer_' . $meta_key;
			if ( isset( $_POST[ $post_key ] ) ) {
				$value = call_user_func( $sanitizer, wp_unslash( $_POST[ $post_key ] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized via call_user_func with the correct sanitizer per field.
				update_post_meta( $post_id, $meta_key, $value );
			}
		}

		// Numeric fields.
		$numeric_fields = array(
			'total_revenue',
			'lifetime_value',
			'contact_owner',
			'source_lead_id',
		);

		foreach ( $numeric_fields as $meta_key ) {
			$post_key = 'mcp_customer_' . $meta_key;
			if ( isset( $_POST[ $post_key ] ) ) {
				$value = '' !== $_POST[ $post_key ] ? floatval( wp_unslash( $_POST[ $post_key ] ) ) : '';
				update_post_meta( $post_id, $meta_key, $value );
			}
		}

		// Notes (allow rich text).
		if ( isset( $_POST['mcp_customer_notes'] ) ) {
			update_post_meta( $post_id, 'notes', wp_kses_post( wp_unslash( $_POST['mcp_customer_notes'] ) ) );
		}

		// Tags: store as serialized array.
		if ( isset( $_POST['mcp_customer_tags'] ) ) {
			$tags_string = sanitize_text_field( wp_unslash( $_POST['mcp_customer_tags'] ) );
			$tags        = array_filter( array_map( 'trim', explode( ',', $tags_string ) ) );
			update_post_meta( $post_id, 'tags', $tags );
		}
	}

	/**
	 * Get customer meta as a structured array.
	 *
	 * @since 2.6.0
	 *
	 * @param int $post_id Post ID.
	 * @return array Customer meta data.
	 */
	public static function get_customer_meta( $post_id ) {
		$meta_keys = array(
			'email',
			'first_name',
			'last_name',
			'phone',
			'company_name',
			'job_title',
			'lifecycle_stage',
			'contact_owner',
			'source_lead_id',
			'source',
			'total_revenue',
			'lifetime_value',
			'customer_since',
			'currency',
			'tags',
			'notes',
		);

		$data = array();
		foreach ( $meta_keys as $key ) {
			$value = get_post_meta( $post_id, $key, true );
			if ( '' !== $value ) {
				$data[ $key ] = $value;
			}
		}

		return $data;
	}
}
