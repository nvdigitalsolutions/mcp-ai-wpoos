<?php
/**
 * Research & Add page for Law Firm Matters and Clients.
 *
 * Provides a dedicated page for AI-powered research and creation of legal
 * matters and clients, following the same pattern as CRE Debt and Financial
 * Account research pages with chat interface for AI assistance.
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
 * Law Firm Research & Add Admin Page
 *
 * Adds a submenu page under Law Firm menu for AI-powered legal research,
 * case law analysis, matter management, and entity creation.
 */
class WP_MCP_AI_Law_Firm_Research_Page {
	use WP_MCP_AI_Research_Page_Mode_Tabs;

	/**
	 * Page slug.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'research-law-firm';

	/**
	 * Initialize the page.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu_page' ), 20 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_ajax_wp_mcp_ai_create_lf_matter_from_research', array( __CLASS__, 'handle_create_matter' ) );
		add_action( 'wp_ajax_wp_mcp_ai_create_lf_client_from_research', array( __CLASS__, 'handle_create_client' ) );
	}

	/**
	 * Add submenu page under Law Firm menu.
	 */
	public static function add_menu_page() {
		add_submenu_page(
			'edit.php?post_type=mcp_ai_lf_matter',
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
		if ( 'mcp_ai_lf_matter_page_' . self::PAGE_SLUG !== $hook ) {
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
		$lf_settings  = get_option( 'wp_mcp_ai_law_firm_settings', array() );
		$assistant_id = isset( $lf_settings['assistant_id'] ) ? absint( $lf_settings['assistant_id'] ) : 0;

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
		<div class="wrap lf-research-page">
			<h1><?php esc_html_e( 'Law Firm Research & Add', 'mcp-ai-wpoos-pro' ); ?></h1>
			<hr class="wp-header-end">

			<!-- Research Tips -->
			<div class="lf-research-tips">
				<h3><?php esc_html_e( 'Research Tips', 'mcp-ai-wpoos-pro' ); ?></h3>
				<div class="lf-tips-grid">
					<div class="lf-tip-card">
						<strong>⚖️ <?php esc_html_e( 'Case Research', 'mcp-ai-wpoos-pro' ); ?></strong>
						<p><?php esc_html_e( 'Ask the assistant to research case law: "Find cases about breach of fiduciary duty in Delaware corporate law from the last 5 years"', 'mcp-ai-wpoos-pro' ); ?></p>
					</div>
					<div class="lf-tip-card">
						<strong>📋 <?php esc_html_e( 'Matter Management', 'mcp-ai-wpoos-pro' ); ?></strong>
						<p><?php esc_html_e( 'Create and track matters: "Create a new personal injury matter for client John Smith, auto accident, SOL expires March 2027"', 'mcp-ai-wpoos-pro' ); ?></p>
					</div>
					<div class="lf-tip-card">
						<strong>📝 <?php esc_html_e( 'Document Drafting', 'mcp-ai-wpoos-pro' ); ?></strong>
						<p><?php esc_html_e( 'Draft documents: "Draft a motion to compel discovery responses in Smith v. Jones, Case No. 2026-CV-1234"', 'mcp-ai-wpoos-pro' ); ?></p>
					</div>
					<div class="lf-tip-card">
						<strong>💰 <?php esc_html_e( 'Billing & Trust', 'mcp-ai-wpoos-pro' ); ?></strong>
						<p><?php esc_html_e( 'Manage billing: "Record 2.5 hours at $400/hr for legal research on the Acme Corp merger matter"', 'mcp-ai-wpoos-pro' ); ?></p>
					</div>
				</div>
			</div>

			<!-- AI Chat Interface -->
			<div class="lf-research-chat-wrap">
				<?php if ( $assistant_id ) : ?>
					<?php
					// The shortcode renders a complete chat UI with necessary HTML attributes.
					echo do_shortcode( '[mcp_ai_chat assistant_id="' . absint( $assistant_id ) . '" height="500px" additional_tools="generate_research_report,create_post_from_research"]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Shortcode handles its own escaping.
					?>
				<?php else : ?>
					<div class="notice notice-warning">
						<p><?php esc_html_e( 'No AI assistant found. Please create an assistant first or configure one in Law Firm Settings.', 'mcp-ai-wpoos-pro' ); ?></p>
					</div>
				<?php endif; ?>
			</div>

			<!-- Available Tools Reference -->
			<div class="lf-research-tools">
				<h3><?php esc_html_e( 'Available Law Firm Tools', 'mcp-ai-wpoos-pro' ); ?></h3>
				<p class="description"><?php esc_html_e( 'The AI assistant has access to the full Law Firm toolkit. Here are some key tools you can use:', 'mcp-ai-wpoos-pro' ); ?></p>
				<div class="lf-tools-grid">
					<div class="lf-tool-group">
						<strong><?php esc_html_e( 'Client Intake', 'mcp-ai-wpoos-pro' ); ?></strong>
						<ul>
							<li><?php esc_html_e( 'Client Manager', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Conflict Checker', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Engagement Letter Generator', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Intake Form Builder', 'mcp-ai-wpoos-pro' ); ?></li>
						</ul>
					</div>
					<div class="lf-tool-group">
						<strong><?php esc_html_e( 'Matter Management', 'mcp-ai-wpoos-pro' ); ?></strong>
						<ul>
							<li><?php esc_html_e( 'Matter Manager', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Deadline Tracker', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Case Timeline Builder', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Task Assignment Manager', 'mcp-ai-wpoos-pro' ); ?></li>
						</ul>
					</div>
					<div class="lf-tool-group">
						<strong><?php esc_html_e( 'Document Automation', 'mcp-ai-wpoos-pro' ); ?></strong>
						<ul>
							<li><?php esc_html_e( 'Document Drafter', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Contract Analyzer', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Template Manager', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'E-Signature Tracker', 'mcp-ai-wpoos-pro' ); ?></li>
						</ul>
					</div>
					<div class="lf-tool-group">
						<strong><?php esc_html_e( 'Billing & Trust', 'mcp-ai-wpoos-pro' ); ?></strong>
						<ul>
							<li><?php esc_html_e( 'Time Entry Manager', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Invoice Generator', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Trust Account Manager', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Expense Tracker', 'mcp-ai-wpoos-pro' ); ?></li>
						</ul>
					</div>
					<div class="lf-tool-group">
						<strong><?php esc_html_e( 'Compliance & Ethics', 'mcp-ai-wpoos-pro' ); ?></strong>
						<ul>
							<li><?php esc_html_e( 'Ethics Rule Checker', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Bar Compliance Tracker', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'CLE Credit Manager', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Privilege Log Builder', 'mcp-ai-wpoos-pro' ); ?></li>
						</ul>
					</div>
					<div class="lf-tool-group">
						<strong><?php esc_html_e( 'Litigation Support', 'mcp-ai-wpoos-pro' ); ?></strong>
						<ul>
							<li><?php esc_html_e( 'Discovery Manager', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Deposition Summarizer', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Exhibit Organizer', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Motion Drafter', 'mcp-ai-wpoos-pro' ); ?></li>
						</ul>
					</div>
					<div class="lf-tool-group">
						<strong><?php esc_html_e( 'Research & Analytics', 'mcp-ai-wpoos-pro' ); ?></strong>
						<ul>
							<li><?php esc_html_e( 'Case Law Researcher', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Statute Lookup', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Firm Analytics Dashboard', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Outcome Predictor', 'mcp-ai-wpoos-pro' ); ?></li>
						</ul>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * AJAX: Create a Matter from research.
	 */
	public static function handle_create_matter() {
		check_ajax_referer( 'wp_mcp_ai_lf_research', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) ), 403 );
		}

		$title = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		if ( empty( $title ) ) {
			wp_send_json_error( array( 'message' => __( 'Matter title is required.', 'mcp-ai-wpoos-pro' ) ) );
		}

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_lf_matter',
				'post_title'  => $title,
				'post_status' => 'publish',
				'post_author' => get_current_user_id(),
			)
		);

		if ( is_wp_error( $post_id ) ) {
			wp_send_json_error( array( 'message' => $post_id->get_error_message() ) );
		}

		// Save matter meta from research data.
		$text_fields = array( 'case_number', 'practice_area', 'status', 'jurisdiction', 'description' );

		foreach ( $text_fields as $field ) {
			$post_key = 'lf_' . $field;
			if ( isset( $_POST[ $post_key ] ) ) {
				update_post_meta( $post_id, '_lf_' . $field, sanitize_text_field( wp_unslash( $_POST[ $post_key ] ) ) );
			}
		}

		// Client ID is stored as an integer reference.
		if ( isset( $_POST['lf_client_id'] ) ) {
			update_post_meta( $post_id, '_lf_client_id', absint( $_POST['lf_client_id'] ) );
		}

		wp_send_json_success(
			array(
				'post_id'  => $post_id,
				'edit_url' => get_edit_post_link( $post_id, 'raw' ),
				'message'  => __( 'Matter created successfully.', 'mcp-ai-wpoos-pro' ),
			)
		);
	}

	/**
	 * AJAX: Create a Client from research.
	 */
	public static function handle_create_client() {
		check_ajax_referer( 'wp_mcp_ai_lf_research', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) ), 403 );
		}

		$title = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		if ( empty( $title ) ) {
			wp_send_json_error( array( 'message' => __( 'Client name is required.', 'mcp-ai-wpoos-pro' ) ) );
		}

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_lf_client',
				'post_title'  => $title,
				'post_status' => 'publish',
				'post_author' => get_current_user_id(),
			)
		);

		if ( is_wp_error( $post_id ) ) {
			wp_send_json_error( array( 'message' => $post_id->get_error_message() ) );
		}

		// Save client meta from research data.
		$text_fields = array( 'email', 'phone', 'company', 'client_type' );

		foreach ( $text_fields as $field ) {
			$post_key = 'lf_' . $field;
			if ( isset( $_POST[ $post_key ] ) ) {
				update_post_meta( $post_id, '_lf_' . $field, sanitize_text_field( wp_unslash( $_POST[ $post_key ] ) ) );
			}
		}

		wp_send_json_success(
			array(
				'post_id'  => $post_id,
				'edit_url' => get_edit_post_link( $post_id, 'raw' ),
				'message'  => __( 'Client created successfully.', 'mcp-ai-wpoos-pro' ),
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
.lf-research-tips{background:#fff;border:1px solid #dcdcde;border-radius:4px;padding:20px 24px;margin:20px 0;}
.lf-tips-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:12px;margin-top:12px;}
.lf-tip-card{background:#f9f9f9;border:1px solid #e0e0e0;border-radius:6px;padding:14px;}
.lf-tip-card p{margin:6px 0 0;font-size:12px;color:#555;}
.lf-research-chat-wrap{margin:20px 0;}
.lf-research-tools{background:#fff;border:1px solid #dcdcde;border-radius:4px;padding:20px 24px;margin:20px 0;}
.lf-tools-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:16px;margin-top:12px;}
.lf-tool-group ul{margin:6px 0 0 16px;font-size:13px;color:#555;}
		';
	}
}
