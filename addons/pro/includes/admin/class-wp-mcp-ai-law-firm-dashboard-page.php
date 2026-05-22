<?php
/**
 * Law Firm Toolkit Dashboard Page
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
 * Law Firm Toolkit Dashboard Page.
 *
 * Provides a dashboard with charts powered by Chart.js for the
 * Law Firm Toolkit custom post types.
 */
class WP_MCP_AI_Law_Firm_Dashboard_Page {

	const PAGE_SLUG   = 'law-firm-dashboard';
	const PARENT_SLUG = 'edit.php?post_type=mcp_ai_lf_matter';

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu_page' ), 24 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/**
	 * Register the dashboard submenu page.
	 */
	public static function add_menu_page() {
		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Firm Dashboard', 'mcp-ai-wpoos-pro' ),
			__( 'Dashboard', 'mcp-ai-wpoos-pro' ),
			'edit_posts',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Enqueue Chart.js and inline scripts/styles on the dashboard page.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function enqueue_assets( $hook ) {
		if ( 'mcp_ai_lf_matter_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}
		wp_enqueue_script( 'wp-mcp-ai-chartjs', WP_MCP_AI_URL . 'assets/js/vendor/chart.min.js', array(), '4.4.1', true );
		wp_add_inline_script( 'wp-mcp-ai-chartjs', self::get_dashboard_js(), 'after' );
		wp_add_inline_style( 'wp-admin', self::get_dashboard_css() );
	}

	/**
	 * Render the dashboard page HTML.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}
		$data = self::get_dashboard_data();
		echo '<div class="wrap"><h1>' . esc_html__( 'Firm Dashboard', 'mcp-ai-wpoos-pro' ) . '</h1>';
		echo '<div class="lf-dashboard-cards">';
		$cards = array(
			array(
				'label' => __( 'Active Matters', 'mcp-ai-wpoos-pro' ),
				'value' => $data['active_matters'],
			),
			array(
				'label' => __( 'Total Clients', 'mcp-ai-wpoos-pro' ),
				'value' => $data['total_clients'],
			),
			array(
				'label' => __( 'Time Entries', 'mcp-ai-wpoos-pro' ),
				'value' => $data['total_time_entries'],
			),
			array(
				'label' => __( 'Trust Transactions', 'mcp-ai-wpoos-pro' ),
				'value' => $data['total_trust_txns'],
			),
		);
		foreach ( $cards as $card ) {
			echo '<div class="lf-card"><h3>' . esc_html( $card['value'] ) . '</h3><p>' . esc_html( $card['label'] ) . '</p></div>';
		}
		echo '</div>';
		echo '<div class="lf-charts-grid"><div class="lf-chart-container"><canvas id="lf-pipeline-chart"></canvas></div>';
		echo '<div class="lf-chart-container"><canvas id="lf-practice-chart"></canvas></div></div>';
		echo '</div>';
	}

	/**
	 * Gather dashboard statistics from custom post types.
	 *
	 * @return array Dashboard data.
	 */
	private static function get_dashboard_data(): array {
		$active       = new WP_Query(
			array(
				'post_type'      => 'mcp_ai_lf_matter',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'tax_query'      => array(
					array(
						'taxonomy' => 'lf_matter_status',
						'field'    => 'slug',
						'terms'    => 'active',
					),
				),
			)
		);
		$clients      = wp_count_posts( 'mcp_ai_lf_client' );
		$time_entries = wp_count_posts( 'mcp_ai_lf_time_entry' );
		$trust_txns   = wp_count_posts( 'mcp_ai_lf_trust_txn' );
		return array(
			'active_matters'     => $active->found_posts,
			'total_clients'      => isset( $clients->publish ) ? $clients->publish : 0,
			'total_time_entries' => isset( $time_entries->publish ) ? $time_entries->publish : 0,
			'total_trust_txns'   => isset( $trust_txns->publish ) ? $trust_txns->publish : 0,
		);
	}

	/**
	 * Return inline JavaScript for Chart.js rendering.
	 *
	 * @return string Inline script.
	 */
	private static function get_dashboard_js(): string {
		$statuses = array( 'prospect', 'active', 'pending', 'closed', 'archived' );
		$counts   = array();
		foreach ( $statuses as $s ) {
			$q        = new WP_Query(
				array(
					'post_type'      => 'mcp_ai_lf_matter',
					'post_status'    => 'publish',
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'tax_query'      => array(
						array(
							'taxonomy' => 'lf_matter_status',
							'field'    => 'slug',
							'terms'    => $s,
						),
					),
				)
			);
			$counts[] = $q->found_posts;
		}
		$labels_json = wp_json_encode( array_map( 'ucfirst', $statuses ) );
		$counts_json = wp_json_encode( $counts );
		return "document.addEventListener('DOMContentLoaded',function(){var ctx=document.getElementById('lf-pipeline-chart');if(ctx){new Chart(ctx,{type:'bar',data:{labels:{$labels_json},datasets:[{label:'Matters',data:{$counts_json},backgroundColor:['#42a5f5','#66bb6a','#ffa726','#bdbdbd','#ef5350']}]},options:{responsive:true,plugins:{title:{display:true,text:'Matter Pipeline'}}}});}});";
	}

	/**
	 * Return inline CSS for the dashboard cards and charts.
	 *
	 * @return string Inline stylesheet.
	 */
	private static function get_dashboard_css(): string {
		return '.lf-dashboard-cards{display:flex;gap:16px;margin:20px 0;flex-wrap:wrap;}.lf-card{background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:20px;min-width:180px;text-align:center;}.lf-card h3{margin:0;font-size:28px;color:#1d2327;}.lf-card p{margin:8px 0 0;color:#646970;}.lf-charts-grid{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-top:20px;}.lf-chart-container{background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:16px;}';
	}
}
