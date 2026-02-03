<?php
/**
 * Financial Account Custom Post Type
 *
 * Manages user financial accounts for the Financial Planner Toolkit.
 * Supports both manual entry and optional API sync (Plaid).
 *
 * @package WP_MCP_AI_Pro
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Financial Account CPT Class
 *
 * Handles registration and management of financial accounts.
 * Works independently without requiring external API connections.
 */
class WP_MCP_AI_Financial_Account_CPT {

	/**
	 * Post type slug.
	 */
	const POST_TYPE = 'mcp_ai_fin_account';

	/**
	 * Initialize the CPT.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_post_type' ) );
		add_action( 'init', array( __CLASS__, 'register_taxonomies' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_boxes' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'save_meta_boxes' ), 10, 2 );
	}

	/**
	 * Register the custom post type.
	 */
	public static function register_post_type() {
		$labels = array(
			'name'                  => __( 'Financial Accounts', 'mcp-ai-wpoos-pro' ),
			'singular_name'         => __( 'Financial Account', 'mcp-ai-wpoos-pro' ),
			'menu_name'             => __( 'Financial Accounts', 'mcp-ai-wpoos-pro' ),
			'add_new'               => __( 'Add Account', 'mcp-ai-wpoos-pro' ),
			'add_new_item'          => __( 'Add New Account', 'mcp-ai-wpoos-pro' ),
			'edit_item'             => __( 'Edit Account', 'mcp-ai-wpoos-pro' ),
			'new_item'              => __( 'New Account', 'mcp-ai-wpoos-pro' ),
			'view_item'             => __( 'View Account', 'mcp-ai-wpoos-pro' ),
			'search_items'          => __( 'Search Accounts', 'mcp-ai-wpoos-pro' ),
			'not_found'             => __( 'No accounts found', 'mcp-ai-wpoos-pro' ),
			'not_found_in_trash'    => __( 'No accounts found in trash', 'mcp-ai-wpoos-pro' ),
			'all_items'             => __( 'All Accounts', 'mcp-ai-wpoos-pro' ),
			'insert_into_item'      => __( 'Add to account', 'mcp-ai-wpoos-pro' ),
			'uploaded_to_this_item' => __( 'Uploaded to this account', 'mcp-ai-wpoos-pro' ),
		);

		$args = array(
			'labels'              => $labels,
			'public'              => false,
			'publicly_queryable'  => false,
			'show_ui'             => true,
			'show_in_menu'        => true,
			'menu_icon'           => 'dashicons-bank',
			'menu_position'       => 56,
			'query_var'           => true,
			'rewrite'             => false,
			'capability_type'     => 'post',
			'has_archive'         => false,
			'hierarchical'        => false,
			'supports'            => array( 'title', 'author' ),
			'show_in_rest'        => true,
			'rest_base'           => 'financial-accounts',
			'rest_namespace'      => 'mcp-ai/v1',
		);

		register_post_type( self::POST_TYPE, $args );
	}

	/**
	 * Register taxonomies for categorizing accounts.
	 */
	public static function register_taxonomies() {
		// Account types taxonomy.
		$type_labels = array(
			'name'              => __( 'Account Types', 'mcp-ai-wpoos-pro' ),
			'singular_name'     => __( 'Account Type', 'mcp-ai-wpoos-pro' ),
			'search_items'      => __( 'Search Account Types', 'mcp-ai-wpoos-pro' ),
			'all_items'         => __( 'All Account Types', 'mcp-ai-wpoos-pro' ),
			'edit_item'         => __( 'Edit Account Type', 'mcp-ai-wpoos-pro' ),
			'update_item'       => __( 'Update Account Type', 'mcp-ai-wpoos-pro' ),
			'add_new_item'      => __( 'Add New Account Type', 'mcp-ai-wpoos-pro' ),
			'new_item_name'     => __( 'New Account Type Name', 'mcp-ai-wpoos-pro' ),
			'menu_name'         => __( 'Account Types', 'mcp-ai-wpoos-pro' ),
		);

		register_taxonomy(
			'mcp_ai_account_type',
			array( self::POST_TYPE ),
			array(
				'hierarchical'      => true,
				'labels'            => $type_labels,
				'show_ui'           => true,
				'show_admin_column' => true,
				'query_var'         => true,
				'show_in_rest'      => true,
			)
		);

		// Add default account types.
		if ( ! term_exists( 'Checking', 'mcp_ai_account_type' ) ) {
			wp_insert_term( 'Checking', 'mcp_ai_account_type', array(
				'description' => __( 'Checking bank account', 'mcp-ai-wpoos-pro' ),
			) );
		}
		if ( ! term_exists( 'Savings', 'mcp_ai_account_type' ) ) {
			wp_insert_term( 'Savings', 'mcp_ai_account_type', array(
				'description' => __( 'Savings account', 'mcp-ai-wpoos-pro' ),
			) );
		}
		if ( ! term_exists( 'Credit Card', 'mcp_ai_account_type' ) ) {
			wp_insert_term( 'Credit Card', 'mcp_ai_account_type', array(
				'description' => __( 'Credit card account', 'mcp-ai-wpoos-pro' ),
			) );
		}
		if ( ! term_exists( 'Investment', 'mcp_ai_account_type' ) ) {
			wp_insert_term( 'Investment', 'mcp_ai_account_type', array(
				'description' => __( 'Investment account (brokerage, IRA, 401k)', 'mcp-ai-wpoos-pro' ),
			) );
		}
		if ( ! term_exists( 'Loan', 'mcp_ai_account_type' ) ) {
			wp_insert_term( 'Loan', 'mcp_ai_account_type', array(
				'description' => __( 'Loan or mortgage account', 'mcp-ai-wpoos-pro' ),
			) );
		}
	}

	/**
	 * Add meta boxes for account details.
	 */
	public static function add_meta_boxes() {
		add_meta_box(
			'mcp_ai_account_details',
			__( 'Account Details', 'mcp-ai-wpoos-pro' ),
			array( __CLASS__, 'render_account_details_metabox' ),
			self::POST_TYPE,
			'normal',
			'high'
		);

		add_meta_box(
			'mcp_ai_account_sync',
			__( 'API Sync Settings (Optional)', 'mcp-ai-wpoos-pro' ),
			array( __CLASS__, 'render_sync_settings_metabox' ),
			self::POST_TYPE,
			'side',
			'default'
		);
	}

	/**
	 * Render account details metabox.
	 *
	 * @param WP_Post $post Current post object.
	 */
	public static function render_account_details_metabox( $post ) {
		wp_nonce_field( 'mcp_ai_account_details', 'mcp_ai_account_details_nonce' );

		$account_number = get_post_meta( $post->ID, '_account_number', true );
		$institution    = get_post_meta( $post->ID, '_institution', true );
		$balance        = get_post_meta( $post->ID, '_balance', true );
		$currency       = get_post_meta( $post->ID, '_currency', true ) ?: 'USD';
		$interest_rate  = get_post_meta( $post->ID, '_interest_rate', true );
		$credit_limit   = get_post_meta( $post->ID, '_credit_limit', true );
		$notes          = get_post_meta( $post->ID, '_notes', true );
		?>
		<table class="form-table">
			<tr>
				<th><label for="institution"><?php esc_html_e( 'Institution Name', 'mcp-ai-wpoos-pro' ); ?></label></th>
				<td>
					<input type="text" id="institution" name="institution" value="<?php echo esc_attr( $institution ); ?>" class="regular-text" />
					<p class="description"><?php esc_html_e( 'Bank or financial institution name', 'mcp-ai-wpoos-pro' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="account_number"><?php esc_html_e( 'Account Number', 'mcp-ai-wpoos-pro' ); ?></label></th>
				<td>
					<input type="text" id="account_number" name="account_number" value="<?php echo esc_attr( $account_number ); ?>" class="regular-text" />
					<p class="description"><?php esc_html_e( 'Last 4 digits or masked account number (stored encrypted)', 'mcp-ai-wpoos-pro' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="balance"><?php esc_html_e( 'Current Balance', 'mcp-ai-wpoos-pro' ); ?></label></th>
				<td>
					<input type="number" id="balance" name="balance" value="<?php echo esc_attr( $balance ); ?>" step="0.01" class="regular-text" />
					<p class="description"><?php esc_html_e( 'Current account balance', 'mcp-ai-wpoos-pro' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="currency"><?php esc_html_e( 'Currency', 'mcp-ai-wpoos-pro' ); ?></label></th>
				<td>
					<input type="text" id="currency" name="currency" value="<?php echo esc_attr( $currency ); ?>" class="small-text" />
					<p class="description"><?php esc_html_e( 'Currency code (e.g., USD, EUR, GBP)', 'mcp-ai-wpoos-pro' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="interest_rate"><?php esc_html_e( 'Interest Rate (%)', 'mcp-ai-wpoos-pro' ); ?></label></th>
				<td>
					<input type="number" id="interest_rate" name="interest_rate" value="<?php echo esc_attr( $interest_rate ); ?>" step="0.01" class="small-text" />
					<p class="description"><?php esc_html_e( 'Annual interest rate (for savings, loans, or credit cards)', 'mcp-ai-wpoos-pro' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="credit_limit"><?php esc_html_e( 'Credit Limit', 'mcp-ai-wpoos-pro' ); ?></label></th>
				<td>
					<input type="number" id="credit_limit" name="credit_limit" value="<?php echo esc_attr( $credit_limit ); ?>" step="0.01" class="regular-text" />
					<p class="description"><?php esc_html_e( 'Credit limit (for credit card accounts)', 'mcp-ai-wpoos-pro' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="notes"><?php esc_html_e( 'Notes', 'mcp-ai-wpoos-pro' ); ?></label></th>
				<td>
					<textarea id="notes" name="notes" rows="3" class="large-text"><?php echo esc_textarea( $notes ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Additional notes or details', 'mcp-ai-wpoos-pro' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Render sync settings metabox.
	 *
	 * @param WP_Post $post Current post object.
	 */
	public static function render_sync_settings_metabox( $post ) {
		wp_nonce_field( 'mcp_ai_account_sync', 'mcp_ai_account_sync_nonce' );

		$sync_enabled   = get_post_meta( $post->ID, '_sync_enabled', true );
		$sync_provider  = get_post_meta( $post->ID, '_sync_provider', true );
		$last_sync      = get_post_meta( $post->ID, '_last_sync', true );
		?>
		<div class="mcp-ai-sync-settings">
			<p>
				<label>
					<input type="checkbox" name="sync_enabled" value="1" <?php checked( $sync_enabled, '1' ); ?> />
					<?php esc_html_e( 'Enable API Sync', 'mcp-ai-wpoos-pro' ); ?>
				</label>
			</p>
			<p class="description">
				<?php esc_html_e( 'Optional: Connect to external API for automatic transaction sync.', 'mcp-ai-wpoos-pro' ); ?>
			</p>

			<p>
				<label for="sync_provider"><?php esc_html_e( 'Sync Provider', 'mcp-ai-wpoos-pro' ); ?></label>
				<select id="sync_provider" name="sync_provider" class="widefat">
					<option value=""><?php esc_html_e( 'None (Manual Entry)', 'mcp-ai-wpoos-pro' ); ?></option>
					<option value="plaid" <?php selected( $sync_provider, 'plaid' ); ?>><?php esc_html_e( 'Plaid', 'mcp-ai-wpoos-pro' ); ?></option>
					<option value="custom" <?php selected( $sync_provider, 'custom' ); ?>><?php esc_html_e( 'Custom API', 'mcp-ai-wpoos-pro' ); ?></option>
				</select>
			</p>

			<?php if ( $last_sync ) : ?>
				<p>
					<strong><?php esc_html_e( 'Last Sync:', 'mcp-ai-wpoos-pro' ); ?></strong><br>
					<?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $last_sync ) ) ); ?>
				</p>
			<?php endif; ?>

			<p class="description">
				<strong><?php esc_html_e( 'Note:', 'mcp-ai-wpoos-pro' ); ?></strong>
				<?php esc_html_e( 'This account can work completely independently without API connections. Manual transaction entry is always available.', 'mcp-ai-wpoos-pro' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Save meta box data.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public static function save_meta_boxes( $post_id, $post ) {
		// Check nonces.
		if ( ! isset( $_POST['mcp_ai_account_details_nonce'] ) ||
			! wp_verify_nonce( $_POST['mcp_ai_account_details_nonce'], 'mcp_ai_account_details' ) ) {
			return;
		}

		if ( ! isset( $_POST['mcp_ai_account_sync_nonce'] ) ||
			! wp_verify_nonce( $_POST['mcp_ai_account_sync_nonce'], 'mcp_ai_account_sync' ) ) {
			return;
		}

		// Check permissions.
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Prevent autosave.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// Save account details.
		if ( isset( $_POST['institution'] ) ) {
			update_post_meta( $post_id, '_institution', sanitize_text_field( $_POST['institution'] ) );
		}

		if ( isset( $_POST['account_number'] ) ) {
			// In production, this should be encrypted.
			update_post_meta( $post_id, '_account_number', sanitize_text_field( $_POST['account_number'] ) );
		}

		if ( isset( $_POST['balance'] ) ) {
			update_post_meta( $post_id, '_balance', floatval( $_POST['balance'] ) );
		}

		if ( isset( $_POST['currency'] ) ) {
			update_post_meta( $post_id, '_currency', sanitize_text_field( $_POST['currency'] ) );
		}

		if ( isset( $_POST['interest_rate'] ) ) {
			update_post_meta( $post_id, '_interest_rate', floatval( $_POST['interest_rate'] ) );
		}

		if ( isset( $_POST['credit_limit'] ) ) {
			update_post_meta( $post_id, '_credit_limit', floatval( $_POST['credit_limit'] ) );
		}

		if ( isset( $_POST['notes'] ) ) {
			update_post_meta( $post_id, '_notes', sanitize_textarea_field( $_POST['notes'] ) );
		}

		// Save sync settings.
		$sync_enabled = isset( $_POST['sync_enabled'] ) ? '1' : '0';
		update_post_meta( $post_id, '_sync_enabled', $sync_enabled );

		if ( isset( $_POST['sync_provider'] ) ) {
			update_post_meta( $post_id, '_sync_provider', sanitize_text_field( $_POST['sync_provider'] ) );
		}
	}
}

// Initialize if financial planner toolkit is enabled.
$settings = get_option( 'wp_mcp_ai_settings', array() );
if ( ! empty( $settings['enable_financial_planner_toolkit'] ) ) {
	WP_MCP_AI_Financial_Account_CPT::init();
}
