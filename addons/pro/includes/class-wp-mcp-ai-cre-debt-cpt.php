<?php
/**
 * CRE Debt & Securitization Custom Post Types
 *
 * Manages CRE loan and property entities for the CRE Debt & Securitization Toolkit.
 * Follows CREFC IRP (Investor Reporting Package) data standards for field structure.
 * Supports manual data entry and optional integration with external analytics APIs.
 *
 * Entity Model:
 *   Loan (mcp_ai_cre_loan) → collateralized by → Property (mcp_ai_cre_property)
 *   Linked via _cre_property_id post meta on Loan.
 *
 * @package WP_MCP_AI_Pro
 * @since 2.0.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CRE Debt & Securitization CPT Class
 *
 * Registers and manages CRE Loan and Property CPTs with CREFC-aligned meta fields,
 * taxonomies for loan type and property type, and admin meta boxes.
 */
class WP_MCP_AI_CRE_Debt_CPT {

	/**
	 * Loan post type slug.
	 */
	const LOAN_POST_TYPE = 'mcp_ai_cre_loan';

	/**
	 * Property post type slug.
	 */
	const PROPERTY_POST_TYPE = 'mcp_ai_cre_property';

	/**
	 * Initialize the CPTs.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_post_types' ) );
		add_action( 'init', array( __CLASS__, 'register_taxonomies' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_boxes' ) );
		add_action( 'save_post_' . self::LOAN_POST_TYPE, array( __CLASS__, 'save_loan_meta' ), 10, 2 );
		add_action( 'save_post_' . self::PROPERTY_POST_TYPE, array( __CLASS__, 'save_property_meta' ), 10, 2 );
	}

	/**
	 * Register the CRE Loan and Property post types.
	 */
	public static function register_post_types() {
		// ── CRE Loan ──────────────────────────────────────────────────
		$loan_labels = array(
			'name'                  => __( 'CRE Loans', 'mcp-ai-wpoos-pro' ),
			'singular_name'         => __( 'CRE Loan', 'mcp-ai-wpoos-pro' ),
			'menu_name'             => __( 'CRE Debt', 'mcp-ai-wpoos-pro' ),
			'add_new'               => __( 'Add Loan', 'mcp-ai-wpoos-pro' ),
			'add_new_item'          => __( 'Add New CRE Loan', 'mcp-ai-wpoos-pro' ),
			'edit_item'             => __( 'Edit CRE Loan', 'mcp-ai-wpoos-pro' ),
			'new_item'              => __( 'New CRE Loan', 'mcp-ai-wpoos-pro' ),
			'view_item'             => __( 'View CRE Loan', 'mcp-ai-wpoos-pro' ),
			'search_items'          => __( 'Search CRE Loans', 'mcp-ai-wpoos-pro' ),
			'not_found'             => __( 'No CRE loans found', 'mcp-ai-wpoos-pro' ),
			'not_found_in_trash'    => __( 'No CRE loans found in trash', 'mcp-ai-wpoos-pro' ),
			'all_items'             => __( 'All Loans', 'mcp-ai-wpoos-pro' ),
			'insert_into_item'      => __( 'Add to loan', 'mcp-ai-wpoos-pro' ),
			'uploaded_to_this_item' => __( 'Uploaded to this loan', 'mcp-ai-wpoos-pro' ),
		);

		register_post_type(
			self::LOAN_POST_TYPE,
			array(
				'labels'             => $loan_labels,
				'public'             => false,
				'publicly_queryable' => false,
				'show_ui'            => true,
				'show_in_menu'       => true,
				'menu_icon'          => 'dashicons-building',
				'menu_position'      => 57,
				'query_var'          => true,
				'rewrite'            => false,
				'capability_type'    => 'post',
				'has_archive'        => false,
				'hierarchical'       => false,
				'supports'           => array( 'title', 'author' ),
				'show_in_rest'       => true,
				'rest_base'          => 'cre-loans',
				'rest_namespace'     => 'mcp-ai/v1',
			)
		);

		// ── CRE Property ──────────────────────────────────────────────
		$property_labels = array(
			'name'                  => __( 'CRE Properties', 'mcp-ai-wpoos-pro' ),
			'singular_name'         => __( 'CRE Property', 'mcp-ai-wpoos-pro' ),
			'menu_name'             => __( 'Properties', 'mcp-ai-wpoos-pro' ),
			'add_new'               => __( 'Add Property', 'mcp-ai-wpoos-pro' ),
			'add_new_item'          => __( 'Add New CRE Property', 'mcp-ai-wpoos-pro' ),
			'edit_item'             => __( 'Edit CRE Property', 'mcp-ai-wpoos-pro' ),
			'new_item'              => __( 'New CRE Property', 'mcp-ai-wpoos-pro' ),
			'view_item'             => __( 'View CRE Property', 'mcp-ai-wpoos-pro' ),
			'search_items'          => __( 'Search Properties', 'mcp-ai-wpoos-pro' ),
			'not_found'             => __( 'No properties found', 'mcp-ai-wpoos-pro' ),
			'not_found_in_trash'    => __( 'No properties found in trash', 'mcp-ai-wpoos-pro' ),
			'all_items'             => __( 'All Properties', 'mcp-ai-wpoos-pro' ),
			'insert_into_item'      => __( 'Add to property', 'mcp-ai-wpoos-pro' ),
			'uploaded_to_this_item' => __( 'Uploaded to this property', 'mcp-ai-wpoos-pro' ),
		);

		register_post_type(
			self::PROPERTY_POST_TYPE,
			array(
				'labels'             => $property_labels,
				'public'             => false,
				'publicly_queryable' => false,
				'show_ui'            => true,
				'show_in_menu'       => 'edit.php?post_type=' . self::LOAN_POST_TYPE,
				'query_var'          => true,
				'rewrite'            => false,
				'capability_type'    => 'post',
				'has_archive'        => false,
				'hierarchical'       => false,
				'supports'           => array( 'title', 'author' ),
				'show_in_rest'       => true,
				'rest_base'          => 'cre-properties',
				'rest_namespace'     => 'mcp-ai/v1',
			)
		);
	}

	/**
	 * Register taxonomies for CRE Loans and Properties.
	 */
	public static function register_taxonomies() {
		// ── Loan Type taxonomy ────────────────────────────────────────
		$loan_type_labels = array(
			'name'          => __( 'Loan Types', 'mcp-ai-wpoos-pro' ),
			'singular_name' => __( 'Loan Type', 'mcp-ai-wpoos-pro' ),
			'search_items'  => __( 'Search Loan Types', 'mcp-ai-wpoos-pro' ),
			'all_items'     => __( 'All Loan Types', 'mcp-ai-wpoos-pro' ),
			'edit_item'     => __( 'Edit Loan Type', 'mcp-ai-wpoos-pro' ),
			'update_item'   => __( 'Update Loan Type', 'mcp-ai-wpoos-pro' ),
			'add_new_item'  => __( 'Add New Loan Type', 'mcp-ai-wpoos-pro' ),
			'new_item_name' => __( 'New Loan Type Name', 'mcp-ai-wpoos-pro' ),
			'menu_name'     => __( 'Loan Types', 'mcp-ai-wpoos-pro' ),
		);

		register_taxonomy(
			'mcp_ai_cre_loan_type',
			array( self::LOAN_POST_TYPE ),
			array(
				'hierarchical'      => true,
				'labels'            => $loan_type_labels,
				'show_ui'           => true,
				'show_admin_column' => true,
				'query_var'         => true,
				'show_in_rest'      => true,
			)
		);

		// Seed default loan types (CREFC / industry standard).
		$default_loan_types = array(
			'Permanent'        => __( 'Fixed-rate permanent / stabilized CRE loan', 'mcp-ai-wpoos-pro' ),
			'Bridge'           => __( 'Short-term bridge or transitional loan', 'mcp-ai-wpoos-pro' ),
			'Construction'     => __( 'Construction or development loan', 'mcp-ai-wpoos-pro' ),
			'Mezzanine'        => __( 'Mezzanine debt (subordinate to senior)', 'mcp-ai-wpoos-pro' ),
			'CMBS'             => __( 'CMBS conduit securitized loan', 'mcp-ai-wpoos-pro' ),
			'Agency'           => __( 'Fannie Mae / Freddie Mac agency loan', 'mcp-ai-wpoos-pro' ),
			'SBA'              => __( 'SBA 504 or 7(a) commercial loan', 'mcp-ai-wpoos-pro' ),
			'CRE CLO'          => __( 'CRE CLO securitized transitional loan', 'mcp-ai-wpoos-pro' ),
			'Preferred Equity' => __( 'Preferred equity position', 'mcp-ai-wpoos-pro' ),
		);

		foreach ( $default_loan_types as $name => $description ) {
			if ( ! term_exists( $name, 'mcp_ai_cre_loan_type' ) ) {
				wp_insert_term(
					$name,
					'mcp_ai_cre_loan_type',
					array( 'description' => $description )
				);
			}
		}

		// ── Property Type taxonomy ────────────────────────────────────
		$property_type_labels = array(
			'name'          => __( 'Property Types', 'mcp-ai-wpoos-pro' ),
			'singular_name' => __( 'Property Type', 'mcp-ai-wpoos-pro' ),
			'search_items'  => __( 'Search Property Types', 'mcp-ai-wpoos-pro' ),
			'all_items'     => __( 'All Property Types', 'mcp-ai-wpoos-pro' ),
			'edit_item'     => __( 'Edit Property Type', 'mcp-ai-wpoos-pro' ),
			'update_item'   => __( 'Update Property Type', 'mcp-ai-wpoos-pro' ),
			'add_new_item'  => __( 'Add New Property Type', 'mcp-ai-wpoos-pro' ),
			'new_item_name' => __( 'New Property Type Name', 'mcp-ai-wpoos-pro' ),
			'menu_name'     => __( 'Property Types', 'mcp-ai-wpoos-pro' ),
		);

		register_taxonomy(
			'mcp_ai_cre_prop_type',
			array( self::PROPERTY_POST_TYPE ),
			array(
				'hierarchical'      => true,
				'labels'            => $property_type_labels,
				'show_ui'           => true,
				'show_admin_column' => true,
				'query_var'         => true,
				'show_in_rest'      => true,
			)
		);

		// Seed default property types (CREFC / NCREIF classification).
		$default_property_types = array(
			'Office'          => __( 'Office buildings (CBD, suburban, medical office)', 'mcp-ai-wpoos-pro' ),
			'Multifamily'     => __( 'Apartment / multifamily residential', 'mcp-ai-wpoos-pro' ),
			'Retail'          => __( 'Retail (anchored, unanchored, single-tenant NNN)', 'mcp-ai-wpoos-pro' ),
			'Industrial'      => __( 'Industrial / logistics / warehouse', 'mcp-ai-wpoos-pro' ),
			'Hotel'           => __( 'Hospitality / hotel / resort', 'mcp-ai-wpoos-pro' ),
			'Mixed-Use'       => __( 'Mixed-use development (retail + residential, etc.)', 'mcp-ai-wpoos-pro' ),
			'Self-Storage'    => __( 'Self-storage facilities', 'mcp-ai-wpoos-pro' ),
			'Senior Housing'  => __( 'Senior housing / assisted living', 'mcp-ai-wpoos-pro' ),
			'Student Housing' => __( 'Purpose-built student housing', 'mcp-ai-wpoos-pro' ),
			'Data Center'     => __( 'Data center / colocation facilities', 'mcp-ai-wpoos-pro' ),
			'Healthcare'      => __( 'Healthcare / medical (hospitals, MOBs)', 'mcp-ai-wpoos-pro' ),
			'Land'            => __( 'Land / development site', 'mcp-ai-wpoos-pro' ),
		);

		foreach ( $default_property_types as $name => $description ) {
			if ( ! term_exists( $name, 'mcp_ai_cre_prop_type' ) ) {
				wp_insert_term(
					$name,
					'mcp_ai_cre_prop_type',
					array( 'description' => $description )
				);
			}
		}
	}

	/**
	 * Add meta boxes for Loan and Property details.
	 */
	public static function add_meta_boxes() {
		// Loan meta boxes.
		add_meta_box(
			'mcp_ai_cre_loan_details',
			__( 'Loan Details (CREFC-Aligned)', 'mcp-ai-wpoos-pro' ),
			array( __CLASS__, 'render_loan_details_metabox' ),
			self::LOAN_POST_TYPE,
			'normal',
			'high'
		);

		add_meta_box(
			'mcp_ai_cre_loan_metrics',
			__( 'Loan Metrics & Performance', 'mcp-ai-wpoos-pro' ),
			array( __CLASS__, 'render_loan_metrics_metabox' ),
			self::LOAN_POST_TYPE,
			'side',
			'default'
		);

		// Property meta boxes.
		add_meta_box(
			'mcp_ai_cre_property_details',
			__( 'Property Details', 'mcp-ai-wpoos-pro' ),
			array( __CLASS__, 'render_property_details_metabox' ),
			self::PROPERTY_POST_TYPE,
			'normal',
			'high'
		);

		add_meta_box(
			'mcp_ai_cre_property_financials',
			__( 'Property Financials', 'mcp-ai-wpoos-pro' ),
			array( __CLASS__, 'render_property_financials_metabox' ),
			self::PROPERTY_POST_TYPE,
			'side',
			'default'
		);
	}

	/**
	 * Render loan details metabox.
	 *
	 * Fields aligned with CREFC IRP loan-level data standards.
	 *
	 * @param WP_Post $post Current post object.
	 */
	public static function render_loan_details_metabox( $post ) {
		wp_nonce_field( 'mcp_ai_cre_loan_details', 'mcp_ai_cre_loan_nonce' );

		$fields = array(
			'_cre_borrower_name'    => get_post_meta( $post->ID, '_cre_borrower_name', true ),
			'_cre_borrower_entity'  => get_post_meta( $post->ID, '_cre_borrower_entity', true ),
			'_cre_loan_amount'      => get_post_meta( $post->ID, '_cre_loan_amount', true ),
			'_cre_interest_rate'    => get_post_meta( $post->ID, '_cre_interest_rate', true ),
			'_cre_rate_type'        => get_post_meta( $post->ID, '_cre_rate_type', true ),
			'_cre_origination_date' => get_post_meta( $post->ID, '_cre_origination_date', true ),
			'_cre_maturity_date'    => get_post_meta( $post->ID, '_cre_maturity_date', true ),
			'_cre_amortization'     => get_post_meta( $post->ID, '_cre_amortization', true ),
			'_cre_io_period'        => get_post_meta( $post->ID, '_cre_io_period', true ),
			'_cre_prepay_type'      => get_post_meta( $post->ID, '_cre_prepay_type', true ),
			'_cre_loan_status'      => get_post_meta( $post->ID, '_cre_loan_status', true ),
			'_cre_property_id'      => get_post_meta( $post->ID, '_cre_property_id', true ),
			'_cre_notes'            => get_post_meta( $post->ID, '_cre_notes', true ),
		);
		?>
		<table class="form-table">
			<tr>
				<th><label for="cre_borrower_name"><?php esc_html_e( 'Borrower / Sponsor Name', 'mcp-ai-wpoos-pro' ); ?></label></th>
				<td>
					<input type="text" id="cre_borrower_name" name="cre_borrower_name" value="<?php echo esc_attr( $fields['_cre_borrower_name'] ); ?>" class="regular-text" />
				</td>
			</tr>
			<tr>
				<th><label for="cre_borrower_entity"><?php esc_html_e( 'Borrower Entity Type', 'mcp-ai-wpoos-pro' ); ?></label></th>
				<td>
					<select id="cre_borrower_entity" name="cre_borrower_entity" class="regular-text">
						<option value=""><?php esc_html_e( '— Select —', 'mcp-ai-wpoos-pro' ); ?></option>
						<?php
						$entity_types = array( 'LLC', 'LP', 'Corporation', 'Trust', 'Joint Venture', 'REIT', 'Individual', 'Other' );
						foreach ( $entity_types as $type ) :
							?>
							<option value="<?php echo esc_attr( $type ); ?>" <?php selected( $fields['_cre_borrower_entity'], $type ); ?>><?php echo esc_html( $type ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="cre_loan_amount"><?php esc_html_e( 'Loan Amount ($)', 'mcp-ai-wpoos-pro' ); ?></label></th>
				<td>
					<input type="number" id="cre_loan_amount" name="cre_loan_amount" value="<?php echo esc_attr( $fields['_cre_loan_amount'] ); ?>" step="0.01" class="regular-text" />
					<p class="description"><?php esc_html_e( 'Original loan balance at origination', 'mcp-ai-wpoos-pro' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="cre_interest_rate"><?php esc_html_e( 'Interest Rate (%)', 'mcp-ai-wpoos-pro' ); ?></label></th>
				<td>
					<input type="number" id="cre_interest_rate" name="cre_interest_rate" value="<?php echo esc_attr( $fields['_cre_interest_rate'] ); ?>" step="0.001" class="small-text" />
				</td>
			</tr>
			<tr>
				<th><label for="cre_rate_type"><?php esc_html_e( 'Rate Type', 'mcp-ai-wpoos-pro' ); ?></label></th>
				<td>
					<select id="cre_rate_type" name="cre_rate_type">
						<option value=""><?php esc_html_e( '— Select —', 'mcp-ai-wpoos-pro' ); ?></option>
						<?php
						$rate_types = array( 'Fixed', 'Floating (SOFR)', 'Floating (Prime)', 'Step-Rate', 'Adjustable' );
						foreach ( $rate_types as $rt ) :
							?>
							<option value="<?php echo esc_attr( $rt ); ?>" <?php selected( $fields['_cre_rate_type'], $rt ); ?>><?php echo esc_html( $rt ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="cre_origination_date"><?php esc_html_e( 'Origination Date', 'mcp-ai-wpoos-pro' ); ?></label></th>
				<td><input type="date" id="cre_origination_date" name="cre_origination_date" value="<?php echo esc_attr( $fields['_cre_origination_date'] ); ?>" /></td>
			</tr>
			<tr>
				<th><label for="cre_maturity_date"><?php esc_html_e( 'Maturity Date', 'mcp-ai-wpoos-pro' ); ?></label></th>
				<td><input type="date" id="cre_maturity_date" name="cre_maturity_date" value="<?php echo esc_attr( $fields['_cre_maturity_date'] ); ?>" /></td>
			</tr>
			<tr>
				<th><label for="cre_amortization"><?php esc_html_e( 'Amortization (months)', 'mcp-ai-wpoos-pro' ); ?></label></th>
				<td>
					<input type="number" id="cre_amortization" name="cre_amortization" value="<?php echo esc_attr( $fields['_cre_amortization'] ); ?>" min="0" max="480" class="small-text" />
					<p class="description"><?php esc_html_e( 'Amortization period in months (0 = interest-only)', 'mcp-ai-wpoos-pro' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="cre_io_period"><?php esc_html_e( 'IO Period (months)', 'mcp-ai-wpoos-pro' ); ?></label></th>
				<td>
					<input type="number" id="cre_io_period" name="cre_io_period" value="<?php echo esc_attr( $fields['_cre_io_period'] ); ?>" min="0" max="240" class="small-text" />
					<p class="description"><?php esc_html_e( 'Interest-only period before amortization begins', 'mcp-ai-wpoos-pro' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="cre_prepay_type"><?php esc_html_e( 'Prepayment Protection', 'mcp-ai-wpoos-pro' ); ?></label></th>
				<td>
					<select id="cre_prepay_type" name="cre_prepay_type">
						<option value=""><?php esc_html_e( '— Select —', 'mcp-ai-wpoos-pro' ); ?></option>
						<?php
						$prepay_types = array( 'Defeasance', 'Yield Maintenance', 'Step-Down', 'Lockout', 'Open', 'None' );
						foreach ( $prepay_types as $pp ) :
							?>
							<option value="<?php echo esc_attr( $pp ); ?>" <?php selected( $fields['_cre_prepay_type'], $pp ); ?>><?php echo esc_html( $pp ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="cre_loan_status"><?php esc_html_e( 'Loan Status', 'mcp-ai-wpoos-pro' ); ?></label></th>
				<td>
					<select id="cre_loan_status" name="cre_loan_status">
						<option value=""><?php esc_html_e( '— Select —', 'mcp-ai-wpoos-pro' ); ?></option>
						<?php
						$statuses = array(
							'performing'      => __( 'Performing', 'mcp-ai-wpoos-pro' ),
							'watchlist'       => __( 'Watchlist', 'mcp-ai-wpoos-pro' ),
							'special_service' => __( 'Special Servicing', 'mcp-ai-wpoos-pro' ),
							'delinquent_30'   => __( 'Delinquent 30+', 'mcp-ai-wpoos-pro' ),
							'delinquent_60'   => __( 'Delinquent 60+', 'mcp-ai-wpoos-pro' ),
							'delinquent_90'   => __( 'Delinquent 90+', 'mcp-ai-wpoos-pro' ),
							'foreclosure'     => __( 'Foreclosure', 'mcp-ai-wpoos-pro' ),
							'reo'             => __( 'REO', 'mcp-ai-wpoos-pro' ),
							'paid_off'        => __( 'Paid Off', 'mcp-ai-wpoos-pro' ),
							'defeased'        => __( 'Defeased', 'mcp-ai-wpoos-pro' ),
						);
						foreach ( $statuses as $val => $label ) :
							?>
							<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $fields['_cre_loan_status'], $val ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="cre_property_id"><?php esc_html_e( 'Linked Property', 'mcp-ai-wpoos-pro' ); ?></label></th>
				<td>
					<?php
					$properties = get_posts(
						array(
							'post_type'      => self::PROPERTY_POST_TYPE,
							'post_status'    => 'publish',
							'posts_per_page' => -1,
							'orderby'        => 'title',
							'order'          => 'ASC',
						)
					);
					?>
					<select id="cre_property_id" name="cre_property_id" class="regular-text">
						<option value=""><?php esc_html_e( '— None —', 'mcp-ai-wpoos-pro' ); ?></option>
						<?php foreach ( $properties as $prop ) : ?>
							<option value="<?php echo esc_attr( $prop->ID ); ?>" <?php selected( $fields['_cre_property_id'], $prop->ID ); ?>>
								<?php echo esc_html( $prop->post_title ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'Collateral property for this loan', 'mcp-ai-wpoos-pro' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="cre_notes"><?php esc_html_e( 'Notes', 'mcp-ai-wpoos-pro' ); ?></label></th>
				<td>
					<textarea id="cre_notes" name="cre_notes" rows="3" class="large-text"><?php echo esc_textarea( $fields['_cre_notes'] ); ?></textarea>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Render loan metrics metabox (sidebar — read / calculated values).
	 *
	 * @param WP_Post $post Current post object.
	 */
	public static function render_loan_metrics_metabox( $post ) {
		$dscr       = get_post_meta( $post->ID, '_cre_dscr', true );
		$ltv        = get_post_meta( $post->ID, '_cre_ltv', true );
		$debt_yield = get_post_meta( $post->ID, '_cre_debt_yield', true );
		$cur_bal    = get_post_meta( $post->ID, '_cre_current_balance', true );
		?>
		<div class="mcp-ai-cre-metrics">
			<p>
				<label><strong><?php esc_html_e( 'Current Balance ($)', 'mcp-ai-wpoos-pro' ); ?></strong></label><br>
				<input type="number" name="cre_current_balance" value="<?php echo esc_attr( $cur_bal ); ?>" step="0.01" class="widefat" />
			</p>
			<p>
				<label><strong><?php esc_html_e( 'DSCR', 'mcp-ai-wpoos-pro' ); ?></strong></label><br>
				<input type="number" name="cre_dscr" value="<?php echo esc_attr( $dscr ); ?>" step="0.01" class="widefat" />
				<span class="description"><?php esc_html_e( 'Debt Service Coverage Ratio', 'mcp-ai-wpoos-pro' ); ?></span>
			</p>
			<p>
				<label><strong><?php esc_html_e( 'LTV (%)', 'mcp-ai-wpoos-pro' ); ?></strong></label><br>
				<input type="number" name="cre_ltv" value="<?php echo esc_attr( $ltv ); ?>" step="0.1" class="widefat" />
				<span class="description"><?php esc_html_e( 'Loan-to-Value ratio', 'mcp-ai-wpoos-pro' ); ?></span>
			</p>
			<p>
				<label><strong><?php esc_html_e( 'Debt Yield (%)', 'mcp-ai-wpoos-pro' ); ?></strong></label><br>
				<input type="number" name="cre_debt_yield" value="<?php echo esc_attr( $debt_yield ); ?>" step="0.01" class="widefat" />
				<span class="description"><?php esc_html_e( 'NOI / Loan Amount', 'mcp-ai-wpoos-pro' ); ?></span>
			</p>
			<p class="description" style="margin-top:12px;">
				<strong><?php esc_html_e( 'Tip:', 'mcp-ai-wpoos-pro' ); ?></strong>
				<?php esc_html_e( 'Use AI tools to calculate DSCR, LTV, and Debt Yield automatically from property NOI and loan terms.', 'mcp-ai-wpoos-pro' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Render property details metabox.
	 *
	 * @param WP_Post $post Current post object.
	 */
	public static function render_property_details_metabox( $post ) {
		wp_nonce_field( 'mcp_ai_cre_property_details', 'mcp_ai_cre_property_nonce' );

		$fields = array(
			'_cre_prop_address'    => get_post_meta( $post->ID, '_cre_prop_address', true ),
			'_cre_prop_city'       => get_post_meta( $post->ID, '_cre_prop_city', true ),
			'_cre_prop_state'      => get_post_meta( $post->ID, '_cre_prop_state', true ),
			'_cre_prop_zip'        => get_post_meta( $post->ID, '_cre_prop_zip', true ),
			'_cre_prop_sqft'       => get_post_meta( $post->ID, '_cre_prop_sqft', true ),
			'_cre_prop_units'      => get_post_meta( $post->ID, '_cre_prop_units', true ),
			'_cre_prop_year_built' => get_post_meta( $post->ID, '_cre_prop_year_built', true ),
			'_cre_prop_occupancy'  => get_post_meta( $post->ID, '_cre_prop_occupancy', true ),
			'_cre_prop_market'     => get_post_meta( $post->ID, '_cre_prop_market', true ),
			'_cre_prop_notes'      => get_post_meta( $post->ID, '_cre_prop_notes', true ),
		);
		?>
		<table class="form-table">
			<tr>
				<th><label for="cre_prop_address"><?php esc_html_e( 'Street Address', 'mcp-ai-wpoos-pro' ); ?></label></th>
				<td><input type="text" id="cre_prop_address" name="cre_prop_address" value="<?php echo esc_attr( $fields['_cre_prop_address'] ); ?>" class="regular-text" /></td>
			</tr>
			<tr>
				<th><label for="cre_prop_city"><?php esc_html_e( 'City', 'mcp-ai-wpoos-pro' ); ?></label></th>
				<td><input type="text" id="cre_prop_city" name="cre_prop_city" value="<?php echo esc_attr( $fields['_cre_prop_city'] ); ?>" class="regular-text" /></td>
			</tr>
			<tr>
				<th><label for="cre_prop_state"><?php esc_html_e( 'State', 'mcp-ai-wpoos-pro' ); ?></label></th>
				<td><input type="text" id="cre_prop_state" name="cre_prop_state" value="<?php echo esc_attr( $fields['_cre_prop_state'] ); ?>" class="small-text" maxlength="2" /></td>
			</tr>
			<tr>
				<th><label for="cre_prop_zip"><?php esc_html_e( 'ZIP Code', 'mcp-ai-wpoos-pro' ); ?></label></th>
				<td><input type="text" id="cre_prop_zip" name="cre_prop_zip" value="<?php echo esc_attr( $fields['_cre_prop_zip'] ); ?>" class="small-text" maxlength="10" /></td>
			</tr>
			<tr>
				<th><label for="cre_prop_sqft"><?php esc_html_e( 'Net Rentable Area (sq ft)', 'mcp-ai-wpoos-pro' ); ?></label></th>
				<td><input type="number" id="cre_prop_sqft" name="cre_prop_sqft" value="<?php echo esc_attr( $fields['_cre_prop_sqft'] ); ?>" min="0" class="regular-text" /></td>
			</tr>
			<tr>
				<th><label for="cre_prop_units"><?php esc_html_e( 'Units / Keys', 'mcp-ai-wpoos-pro' ); ?></label></th>
				<td>
					<input type="number" id="cre_prop_units" name="cre_prop_units" value="<?php echo esc_attr( $fields['_cre_prop_units'] ); ?>" min="0" class="small-text" />
					<p class="description"><?php esc_html_e( 'Number of units (multifamily) or keys (hotel)', 'mcp-ai-wpoos-pro' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="cre_prop_year_built"><?php esc_html_e( 'Year Built', 'mcp-ai-wpoos-pro' ); ?></label></th>
				<td><input type="number" id="cre_prop_year_built" name="cre_prop_year_built" value="<?php echo esc_attr( $fields['_cre_prop_year_built'] ); ?>" min="1800" max="2100" class="small-text" /></td>
			</tr>
			<tr>
				<th><label for="cre_prop_occupancy"><?php esc_html_e( 'Occupancy (%)', 'mcp-ai-wpoos-pro' ); ?></label></th>
				<td><input type="number" id="cre_prop_occupancy" name="cre_prop_occupancy" value="<?php echo esc_attr( $fields['_cre_prop_occupancy'] ); ?>" step="0.1" min="0" max="100" class="small-text" /></td>
			</tr>
			<tr>
				<th><label for="cre_prop_market"><?php esc_html_e( 'Market / MSA', 'mcp-ai-wpoos-pro' ); ?></label></th>
				<td>
					<input type="text" id="cre_prop_market" name="cre_prop_market" value="<?php echo esc_attr( $fields['_cre_prop_market'] ); ?>" class="regular-text" />
					<p class="description"><?php esc_html_e( 'Metropolitan Statistical Area (e.g., New York-Newark-Jersey City)', 'mcp-ai-wpoos-pro' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="cre_prop_notes"><?php esc_html_e( 'Notes', 'mcp-ai-wpoos-pro' ); ?></label></th>
				<td><textarea id="cre_prop_notes" name="cre_prop_notes" rows="3" class="large-text"><?php echo esc_textarea( $fields['_cre_prop_notes'] ); ?></textarea></td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Render property financials metabox (sidebar).
	 *
	 * @param WP_Post $post Current post object.
	 */
	public static function render_property_financials_metabox( $post ) {
		$noi        = get_post_meta( $post->ID, '_cre_prop_noi', true );
		$value      = get_post_meta( $post->ID, '_cre_prop_value', true );
		$cap_rate   = get_post_meta( $post->ID, '_cre_prop_cap_rate', true );
		$opex_ratio = get_post_meta( $post->ID, '_cre_prop_opex_ratio', true );
		?>
		<div class="mcp-ai-cre-property-financials">
			<p>
				<label><strong><?php esc_html_e( 'NOI ($)', 'mcp-ai-wpoos-pro' ); ?></strong></label><br>
				<input type="number" name="cre_prop_noi" value="<?php echo esc_attr( $noi ); ?>" step="0.01" class="widefat" />
				<span class="description"><?php esc_html_e( 'Net Operating Income', 'mcp-ai-wpoos-pro' ); ?></span>
			</p>
			<p>
				<label><strong><?php esc_html_e( 'Appraised Value ($)', 'mcp-ai-wpoos-pro' ); ?></strong></label><br>
				<input type="number" name="cre_prop_value" value="<?php echo esc_attr( $value ); ?>" step="0.01" class="widefat" />
			</p>
			<p>
				<label><strong><?php esc_html_e( 'Cap Rate (%)', 'mcp-ai-wpoos-pro' ); ?></strong></label><br>
				<input type="number" name="cre_prop_cap_rate" value="<?php echo esc_attr( $cap_rate ); ?>" step="0.01" class="widefat" />
			</p>
			<p>
				<label><strong><?php esc_html_e( 'OpEx Ratio (%)', 'mcp-ai-wpoos-pro' ); ?></strong></label><br>
				<input type="number" name="cre_prop_opex_ratio" value="<?php echo esc_attr( $opex_ratio ); ?>" step="0.1" class="widefat" />
				<span class="description"><?php esc_html_e( 'Operating Expense Ratio', 'mcp-ai-wpoos-pro' ); ?></span>
			</p>
		</div>
		<?php
	}

	/**
	 * Save loan meta box data.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public static function save_loan_meta( $post_id, $post ) {
		if ( ! isset( $_POST['mcp_ai_cre_loan_nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mcp_ai_cre_loan_nonce'] ) ), 'mcp_ai_cre_loan_details' ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// Text fields.
		$text_fields = array( 'cre_borrower_name', 'cre_borrower_entity', 'cre_rate_type', 'cre_prepay_type', 'cre_loan_status', 'cre_origination_date', 'cre_maturity_date' );
		foreach ( $text_fields as $field ) {
			if ( isset( $_POST[ $field ] ) ) {
				update_post_meta( $post_id, '_' . $field, sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) );
			}
		}

		// Numeric fields.
		$numeric_fields = array( 'cre_loan_amount', 'cre_interest_rate', 'cre_amortization', 'cre_io_period', 'cre_current_balance', 'cre_dscr', 'cre_ltv', 'cre_debt_yield' );
		foreach ( $numeric_fields as $field ) {
			if ( isset( $_POST[ $field ] ) ) {
				update_post_meta( $post_id, '_' . $field, floatval( $_POST[ $field ] ) );
			}
		}

		// Property ID (absint).
		if ( isset( $_POST['cre_property_id'] ) ) {
			update_post_meta( $post_id, '_cre_property_id', absint( $_POST['cre_property_id'] ) );
		}

		// Notes.
		if ( isset( $_POST['cre_notes'] ) ) {
			update_post_meta( $post_id, '_cre_notes', sanitize_textarea_field( wp_unslash( $_POST['cre_notes'] ) ) );
		}
	}

	/**
	 * Save property meta box data.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public static function save_property_meta( $post_id, $post ) {
		if ( ! isset( $_POST['mcp_ai_cre_property_nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mcp_ai_cre_property_nonce'] ) ), 'mcp_ai_cre_property_details' ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// Text fields.
		$text_fields = array( 'cre_prop_address', 'cre_prop_city', 'cre_prop_state', 'cre_prop_zip', 'cre_prop_market' );
		foreach ( $text_fields as $field ) {
			if ( isset( $_POST[ $field ] ) ) {
				update_post_meta( $post_id, '_' . $field, sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) );
			}
		}

		// Numeric fields.
		$numeric_fields = array( 'cre_prop_sqft', 'cre_prop_units', 'cre_prop_year_built', 'cre_prop_occupancy', 'cre_prop_noi', 'cre_prop_value', 'cre_prop_cap_rate', 'cre_prop_opex_ratio' );
		foreach ( $numeric_fields as $field ) {
			if ( isset( $_POST[ $field ] ) ) {
				update_post_meta( $post_id, '_' . $field, floatval( $_POST[ $field ] ) );
			}
		}

		// Notes.
		if ( isset( $_POST['cre_prop_notes'] ) ) {
			update_post_meta( $post_id, '_cre_prop_notes', sanitize_textarea_field( wp_unslash( $_POST['cre_prop_notes'] ) ) );
		}
	}
}
