<?php
/**
 * Research & Add page for CRE Debt Loans and Properties.
 *
 * Provides a dedicated page for AI-powered research and creation of CRE loans
 * and properties, following the same pattern as Financial Account and Member
 * research pages with chat interface for AI assistance.
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

require_once __DIR__ . '/trait-wp-mcp-ai-research-page-featured-image.php';
require_once __DIR__ . '/trait-wp-mcp-ai-research-page-enhancements.php';

/**
 * CRE Debt Research & Add Admin Page
 *
 * Adds a submenu page under CRE Debt menu for AI-powered CRE loan and
 * property research, deal analysis, and entity creation.
 */
class WP_MCP_AI_CRE_Debt_Research_Page {
	use WP_MCP_AI_Research_Page_Mode_Tabs;

	/**
	 * Page slug.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'research-cre-debt';

	/**
	 * Initialize the page.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu_page' ), 20 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_ajax_wp_mcp_ai_create_cre_loan_from_research', array( __CLASS__, 'handle_create_loan' ) );
		add_action( 'wp_ajax_wp_mcp_ai_create_cre_property_from_research', array( __CLASS__, 'handle_create_property' ) );
	}

	/**
	 * Add submenu page under CRE Debt menu.
	 */
	public static function add_menu_page() {
		add_submenu_page(
			'edit.php?post_type=mcp_ai_cre_loan',
			__( 'Research & Add', 'mcp-ai-wpoos-pro' ),
			__( 'Research & Add', 'mcp-ai-wpoos-pro' ),
			'edit_posts',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Enqueue assets for the research page.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function enqueue_assets( $hook ) {
		if ( 'mcp_ai_cre_loan_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		// Enqueue chat assets.
		if ( class_exists( 'WP_MCP_AI_Shortcode' ) ) {
			$shortcode_instance = new WP_MCP_AI_Shortcode();
			$shortcode_instance->register_assets();
			wp_enqueue_style( WP_MCP_AI_Shortcode::STYLE_HANDLE );
			wp_enqueue_script( WP_MCP_AI_Shortcode::SCRIPT_HANDLE );
		}

		wp_add_inline_style( 'wp-admin', self::get_page_css() );
	}

	/**
	 * Render the research page.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mcp-ai-wpoos-pro' ) );
		}

		// Get the configured assistant.
		$cre_settings = get_option( 'wp_mcp_ai_cre_debt_settings', array() );
		$assistant_id = isset( $cre_settings['assistant_id'] ) ? absint( $cre_settings['assistant_id'] ) : 0;

		if ( ! $assistant_id ) {
			$assistants   = get_posts(
				array(
					'post_type'      => 'mcp_ai_assistant',
					'post_status'    => 'publish',
					'posts_per_page' => 1,
					'orderby'        => 'date',
					'order'          => 'DESC',
					'fields'         => 'ids',
				)
			);
			$assistant_id = ! empty( $assistants ) ? $assistants[0] : 0;
		}

		?>
		<div class="wrap cre-research-page">
			<h1><?php esc_html_e( 'CRE Debt Research & Add', 'mcp-ai-wpoos-pro' ); ?></h1>
			<hr class="wp-header-end">

			<!-- Research Tips -->
			<div class="cre-research-tips">
				<h3><?php esc_html_e( 'Research Tips', 'mcp-ai-wpoos-pro' ); ?></h3>
				<div class="cre-tips-grid">
					<div class="cre-tip-card">
						<strong>🏢 <?php esc_html_e( 'Deal Analysis', 'mcp-ai-wpoos-pro' ); ?></strong>
						<p><?php esc_html_e( 'Ask the assistant to analyze a deal: "Screen this deal: $25M office loan, 1.35x DSCR, 65% LTV, 7.2% cap rate in Dallas MSA"', 'mcp-ai-wpoos-pro' ); ?></p>
					</div>
					<div class="cre-tip-card">
						<strong>📊 <?php esc_html_e( 'Underwriting', 'mcp-ai-wpoos-pro' ); ?></strong>
						<p><?php esc_html_e( 'Run calculations: "Calculate NOI from $3.2M PGI, 5% vacancy, $1.1M OpEx" or "Size a loan at 1.25x DSCR and 75% LTV"', 'mcp-ai-wpoos-pro' ); ?></p>
					</div>
					<div class="cre-tip-card">
						<strong>🔍 <?php esc_html_e( 'Market Comps', 'mcp-ai-wpoos-pro' ); ?></strong>
						<p><?php esc_html_e( 'Research comparables: "What are current cap rates for Class A multifamily in the Southeast?"', 'mcp-ai-wpoos-pro' ); ?></p>
					</div>
					<div class="cre-tip-card">
						<strong>📋 <?php esc_html_e( 'Quick Create', 'mcp-ai-wpoos-pro' ); ?></strong>
						<p><?php esc_html_e( 'Create records: "Create a CRE loan for $15M bridge loan at SOFR+350 on a 200-unit multifamily in Austin"', 'mcp-ai-wpoos-pro' ); ?></p>
					</div>
				</div>
			</div>

			<!-- AI Chat Interface -->
			<div class="cre-research-chat-wrap">
				<?php if ( $assistant_id ) : ?>
					<?php
					// The shortcode renders a complete chat UI with necessary HTML attributes.
					echo do_shortcode( '[mcp_ai_chat assistant_id="' . absint( $assistant_id ) . '" height="500px"]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Shortcode handles its own escaping.
					?>
				<?php else : ?>
					<div class="notice notice-warning">
						<p><?php esc_html_e( 'No AI assistant found. Please create an assistant first or configure one in CRE Debt Settings.', 'mcp-ai-wpoos-pro' ); ?></p>
					</div>
				<?php endif; ?>
			</div>

			<!-- Available Tools Reference -->
			<div class="cre-research-tools">
				<h3><?php esc_html_e( 'Available CRE Debt Tools', 'mcp-ai-wpoos-pro' ); ?></h3>
				<p class="description"><?php esc_html_e( 'The AI assistant has access to 57 CRE debt tools. Here are some key ones you can use:', 'mcp-ai-wpoos-pro' ); ?></p>
				<div class="cre-tools-grid">
					<div class="cre-tool-group">
						<strong><?php esc_html_e( 'Originations', 'mcp-ai-wpoos-pro' ); ?></strong>
						<ul>
							<li><?php esc_html_e( 'Deal Pipeline Manager', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Loan Quote Generator', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Term Sheet Comparator', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Closing Checklist', 'mcp-ai-wpoos-pro' ); ?></li>
						</ul>
					</div>
					<div class="cre-tool-group">
						<strong><?php esc_html_e( 'Underwriting', 'mcp-ai-wpoos-pro' ); ?></strong>
						<ul>
							<li><?php esc_html_e( 'NOI Calculator', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Loan Sizer', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'DCF Modeler', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Stress Test Modeler', 'mcp-ai-wpoos-pro' ); ?></li>
						</ul>
					</div>
					<div class="cre-tool-group">
						<strong><?php esc_html_e( 'CMBS / Securitization', 'mcp-ai-wpoos-pro' ); ?></strong>
						<ul>
							<li><?php esc_html_e( 'Pool Analyzer', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Bond Cash Flow Modeler', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Defeasance Calculator', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Surveillance Monitor', 'mcp-ai-wpoos-pro' ); ?></li>
						</ul>
					</div>
					<div class="cre-tool-group">
						<strong><?php esc_html_e( 'Asset Management', 'mcp-ai-wpoos-pro' ); ?></strong>
						<ul>
							<li><?php esc_html_e( 'Watchlist Manager', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Hold/Sell Analyzer', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Workout Scenario Modeler', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Loan Surveillance', 'mcp-ai-wpoos-pro' ); ?></li>
						</ul>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * AJAX: Create a CRE Loan from research.
	 */
	public static function handle_create_loan() {
		check_ajax_referer( 'wp_mcp_ai_cre_research', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) ), 403 );
		}

		$title = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		if ( empty( $title ) ) {
			wp_send_json_error( array( 'message' => __( 'Loan title is required.', 'mcp-ai-wpoos-pro' ) ) );
		}

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_cre_loan',
				'post_title'  => $title,
				'post_status' => 'publish',
				'post_author' => get_current_user_id(),
			)
		);

		if ( is_wp_error( $post_id ) ) {
			wp_send_json_error( array( 'message' => $post_id->get_error_message() ) );
		}

		// Save loan meta from research data.
		$text_fields    = array( 'borrower_name', 'borrower_entity', 'rate_type', 'origination_date', 'maturity_date', 'prepay_type', 'loan_status' );
		$numeric_fields = array( 'loan_amount', 'interest_rate', 'amortization', 'io_period' );

		foreach ( $text_fields as $field ) {
			$post_key = 'cre_' . $field;
			if ( isset( $_POST[ $post_key ] ) ) {
				update_post_meta( $post_id, '_cre_' . $field, sanitize_text_field( wp_unslash( $_POST[ $post_key ] ) ) );
			}
		}
		foreach ( $numeric_fields as $field ) {
			$post_key = 'cre_' . $field;
			if ( isset( $_POST[ $post_key ] ) ) {
				update_post_meta( $post_id, '_cre_' . $field, floatval( $_POST[ $post_key ] ) );
			}
		}

		wp_send_json_success(
			array(
				'post_id'  => $post_id,
				'edit_url' => get_edit_post_link( $post_id, 'raw' ),
				'message'  => __( 'CRE Loan created successfully.', 'mcp-ai-wpoos-pro' ),
			)
		);
	}

	/**
	 * AJAX: Create a CRE Property from research.
	 */
	public static function handle_create_property() {
		check_ajax_referer( 'wp_mcp_ai_cre_research', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) ), 403 );
		}

		$title = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		if ( empty( $title ) ) {
			wp_send_json_error( array( 'message' => __( 'Property name is required.', 'mcp-ai-wpoos-pro' ) ) );
		}

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_cre_property',
				'post_title'  => $title,
				'post_status' => 'publish',
				'post_author' => get_current_user_id(),
			)
		);

		if ( is_wp_error( $post_id ) ) {
			wp_send_json_error( array( 'message' => $post_id->get_error_message() ) );
		}

		// Save property meta.
		$text_fields    = array( 'prop_address', 'prop_city', 'prop_state', 'prop_zip', 'prop_market' );
		$numeric_fields = array( 'prop_sqft', 'prop_units', 'prop_year_built', 'prop_occupancy', 'prop_noi', 'prop_value', 'prop_cap_rate' );

		foreach ( $text_fields as $field ) {
			$post_key = 'cre_' . $field;
			if ( isset( $_POST[ $post_key ] ) ) {
				update_post_meta( $post_id, '_cre_' . $field, sanitize_text_field( wp_unslash( $_POST[ $post_key ] ) ) );
			}
		}
		foreach ( $numeric_fields as $field ) {
			$post_key = 'cre_' . $field;
			if ( isset( $_POST[ $post_key ] ) ) {
				update_post_meta( $post_id, '_cre_' . $field, floatval( $_POST[ $post_key ] ) );
			}
		}

		wp_send_json_success(
			array(
				'post_id'  => $post_id,
				'edit_url' => get_edit_post_link( $post_id, 'raw' ),
				'message'  => __( 'CRE Property created successfully.', 'mcp-ai-wpoos-pro' ),
			)
		);
	}

	/**
	 * Inline CSS for the research page.
	 *
	 * @return string
	 */
	private static function get_page_css() {
		return '
.cre-research-tips{background:#fff;border:1px solid #dcdcde;border-radius:4px;padding:20px 24px;margin:20px 0;}
.cre-tips-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:12px;margin-top:12px;}
.cre-tip-card{background:#f9f9f9;border:1px solid #e0e0e0;border-radius:6px;padding:14px;}
.cre-tip-card p{margin:6px 0 0;font-size:12px;color:#555;}
.cre-research-chat-wrap{margin:20px 0;}
.cre-research-tools{background:#fff;border:1px solid #dcdcde;border-radius:4px;padding:20px 24px;margin:20px 0;}
.cre-tools-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:16px;margin-top:12px;}
.cre-tool-group ul{margin:6px 0 0 16px;font-size:13px;color:#555;}
		';
	}
}
