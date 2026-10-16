<?php
/**
 * F&B Management — Ingest & Preview Dashboard.
 *
 * Review surface for the F&B data-ingestion pipeline: pick a transactional
 * table and preview what `fnb_import_table` would do (structural-match
 * confidence, missing columns, out-of-band flags, and sample rows) without
 * writing anything. Mirrors the Medical Vitals dashboard anatomy — submenu
 * under F&B Management at `edit_posts`, bundled Chart.js + inline JS/CSS, and
 * an AJAX endpoint backed by the same dry-run path the import tool uses.
 *
 * The write step itself stays ACT-tier (`manage_options`) and is NOT exposed
 * here — this page is review-only, matching the read/draft vs write split.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.6.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * F&B ingest-review dashboard page.
 *
 * @since 1.6.0
 */
class WP_MCP_AI_Fnb_Ingest_Dashboard_Page {

	const PAGE_SLUG = 'nvoos-fnb-ingest-preview';

	/**
	 * Hook the menu, assets and AJAX handler.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu_page' ), 28 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_ajax_wp_mcp_ai_fnb_ingest_preview', array( __CLASS__, 'ajax_preview' ) );
	}

	/**
	 * Add the preview submenu under F&B Management.
	 *
	 * @return void
	 */
	public static function add_menu_page() {
		add_submenu_page(
			WP_MCP_AI_Fnb_Admin_Menu::PARENT_SLUG,
			__( 'Ingest & Preview', 'mcp-ai-wpoos-pro' ),
			__( 'Ingest & Preview', 'mcp-ai-wpoos-pro' ),
			'edit_posts',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Enqueue Chart.js and the page-specific inline assets.
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public static function enqueue_assets( $hook ) {
		if ( 'f-and-b-management_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		wp_enqueue_script(
			'wp-mcp-ai-chartjs',
			WP_MCP_AI_URL . 'assets/js/vendor/chart.min.js',
			array(),
			WP_MCP_AI_VERSION,
			true
		);

		wp_add_inline_script( 'wp-mcp-ai-chartjs', self::get_dashboard_js(), 'after' );
		wp_add_inline_style( 'wp-admin', self::get_dashboard_css() );

		wp_localize_script(
			'wp-mcp-ai-chartjs',
			'wpMcpAiFnbIngest',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'wp_mcp_ai_fnb_ingest_preview' ),
				'strings' => array(
					'loading'     => __( 'Running dry-run preview…', 'mcp-ai-wpoos-pro' ),
					'error'       => __( 'Failed to load the preview. See the message below.', 'mcp-ai-wpoos-pro' ),
					'selectTable' => __( 'Select a table to preview.', 'mcp-ai-wpoos-pro' ),
					'noMissing'   => __( 'No missing required columns.', 'mcp-ai-wpoos-pro' ),
					'noFlags'     => __( 'No out-of-band flags.', 'mcp-ai-wpoos-pro' ),
					'noPreview'   => __( 'No source rows resolved.', 'mcp-ai-wpoos-pro' ),
				),
			)
		);
	}

	/**
	 * AJAX: return a dry-run preview for a table (never writes).
	 *
	 * @return void
	 */
	public static function ajax_preview() {
		check_ajax_referer( 'wp_mcp_ai_fnb_ingest_preview', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) ), 403 );
		}

		$table_id = isset( $_POST['table_id'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['table_id'] ) ) : '';

		if ( '' === $table_id ) {
			wp_send_json_error( array( 'message' => __( 'Table ID is required.', 'mcp-ai-wpoos-pro' ) ), 400 );
		}

		if ( ! class_exists( 'WP_MCP_AI_Tool_Fnb_Import_Table' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/tools/food-beverage/class-wp-mcp-ai-tool-fnb-import-table.php';
		}

		$tool   = new WP_MCP_AI_Tool_Fnb_Import_Table();
		$result = $tool->execute(
			array(
				'table_id' => $table_id,
				'dry_run'  => true,
			),
			array( 'user_id' => get_current_user_id() )
		);

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
			return;
		}

		wp_send_json_success( $result );
	}

	/**
	 * Render the dashboard page.
	 *
	 * @return void
	 */
	public static function render_page() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mcp-ai-wpoos-pro' ) );
		}

		$tables = array(
			'6.0'  => __( 'Daily sales', 'mcp-ai-wpoos-pro' ),
			'8.0'  => __( 'Purchases', 'mcp-ai-wpoos-pro' ),
			'9.0'  => __( 'Stock counts', 'mcp-ai-wpoos-pro' ),
			'10.0' => __( 'Waste log', 'mcp-ai-wpoos-pro' ),
			'16.0' => __( 'Bookings', 'mcp-ai-wpoos-pro' ),
		);

		?>
		<div class="wrap wp-mcp-ai-fnb-ingest-page">
			<h1><?php esc_html_e( 'F&B Ingest & Preview', 'mcp-ai-wpoos-pro' ); ?></h1>
			<hr class="wp-header-end">

			<p class="description">
				<?php esc_html_e( 'Preview what the import would do for a transactional table — mapping confidence, missing columns, out-of-band flags and sample rows — without writing anything. The actual import remains a separate, higher-privilege action.', 'mcp-ai-wpoos-pro' ); ?>
			</p>

			<div class="fnb-ingest-bar">
				<label for="fnb-ingest-table-select"><strong><?php esc_html_e( 'Table:', 'mcp-ai-wpoos-pro' ); ?></strong></label>
				<select id="fnb-ingest-table-select">
					<option value=""><?php esc_html_e( '— Select a table —', 'mcp-ai-wpoos-pro' ); ?></option>
					<?php foreach ( $tables as $id => $label ) : ?>
						<option value="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $id . ' · ' . $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<button type="button" id="fnb-ingest-preview-btn" class="button button-primary">
					<?php esc_html_e( 'Preview Import', 'mcp-ai-wpoos-pro' ); ?>
				</button>
			</div>

			<div id="fnb-ingest-message" class="notice inline" style="display:none"></div>

			<div id="fnb-ingest-content" style="display:none">
				<div class="fnb-ingest-grid">
					<div class="fnb-ingest-card">
						<h2><?php esc_html_e( 'Summary', 'mcp-ai-wpoos-pro' ); ?></h2>
						<canvas id="fnb-ingest-summary-chart" height="120"></canvas>
					</div>
					<div class="fnb-ingest-card">
						<h2><?php esc_html_e( 'Mapping confidence', 'mcp-ai-wpoos-pro' ); ?></h2>
						<p id="fnb-ingest-confidence"><strong>—</strong></p>
						<ul id="fnb-ingest-missing"></ul>
					</div>
				</div>

				<div class="fnb-ingest-card">
					<h2><?php esc_html_e( 'Out-of-band flags', 'mcp-ai-wpoos-pro' ); ?></h2>
					<ul id="fnb-ingest-flags"></ul>
				</div>

				<div class="fnb-ingest-card">
					<h2><?php esc_html_e( 'Preview rows', 'mcp-ai-wpoos-pro' ); ?></h2>
					<pre id="fnb-ingest-preview-rows" style="white-space:pre-wrap"></pre>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Inline page styles.
	 *
	 * @return string
	 */
	private static function get_dashboard_css() {
		return '
			.wp-mcp-ai-fnb-ingest-page .fnb-ingest-bar { margin: 12px 0 16px; display:flex; align-items:center; gap:10px; }
			.wp-mcp-ai-fnb-ingest-page .fnb-ingest-bar select { min-width: 220px; }
			.fnb-ingest-grid { display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:16px; }
			.fnb-ingest-card { background:#fff; border:1px solid #c3c4c7; border-radius:4px; padding:12px 16px; margin-bottom:16px; }
			.fnb-ingest-card h2 { margin-top:0; }
			.fnb-ingest-card ul { margin:0 0 0 18px; }
			.fnb-ingest-card ul li { margin:2px 0; }
		';
	}

	/**
	 * Inline page script (Chart.js + AJAX wiring).
	 *
	 * @return string
	 */
	private static function get_dashboard_js() {
		$lines = array(
			'(function () {',
			'	var summaryChart = null;',
			'	function errorMessage(msg) {',
			'		var el = document.getElementById("fnb-ingest-message");',
			'		el.style.display = "block";',
			'		el.className = "notice notice-error inline";',
			'		el.textContent = msg;',
			'	}',
			'	function render(data) {',
			'		document.getElementById("fnb-ingest-message").style.display = "none";',
			'		document.getElementById("fnb-ingest-content").style.display = "block";',
			'		var total = (data.summary && data.summary.total) || 0;',
			'		var errors = (data.summary && data.summary.errors) || 0;',
			'		if (summaryChart) { summaryChart.destroy(); }',
			'		summaryChart = new Chart(document.getElementById("fnb-ingest-summary-chart"), {',
			'			type: "bar",',
			'			data: {',
			'				labels: ["Rows"],',
			'				datasets: [',
			'					{ label: "Rows", data: [total], backgroundColor: "#2271b1" },',
			'					{ label: "Errors", data: [errors], backgroundColor: "#d63638" }',
			'				]',
			'			},',
			'			options: { responsive: true, scales: { y: { beginAtZero: true } } }',
			'		});',
			'		var mapping = data.mapping;',
			'		var confEl = document.getElementById("fnb-ingest-confidence");',
			'		if (mapping && typeof mapping.confidence !== "undefined") {',
			'			confEl.innerHTML = "<strong>" + Math.round(mapping.confidence * 100) + "%</strong>";',
			'		} else {',
			'			confEl.textContent = "n/a";',
			'		}',
			'		var missingEl = document.getElementById("fnb-ingest-missing");',
			'		missingEl.innerHTML = "";',
			'		if (mapping && mapping.missing && mapping.missing.length) {',
			'			mapping.missing.forEach(function (col) {',
			'				var li = document.createElement("li");',
			'				li.textContent = col;',
			'				missingEl.appendChild(li);',
			'			});',
			'		} else {',
			'			var li = document.createElement("li");',
			'			li.textContent = wpMcpAiFnbIngest.strings.noMissing;',
			'			missingEl.appendChild(li);',
			'		}',
			'		var flagsEl = document.getElementById("fnb-ingest-flags");',
			'		flagsEl.innerHTML = "";',
			'		if (data.flags && data.flags.count > 0 && data.flags.flags) {',
			'			data.flags.flags.forEach(function (flag) {',
			'				var fl = document.createElement("li");',
			'				fl.textContent = "[" + flag.severity + "] " + flag.message;',
			'				flagsEl.appendChild(fl);',
			'			});',
			'		} else {',
			'			var fli = document.createElement("li");',
			'			fli.textContent = wpMcpAiFnbIngest.strings.noFlags;',
			'			flagsEl.appendChild(fli);',
			'		}',
			'		var previewEl = document.getElementById("fnb-ingest-preview-rows");',
			'		if (data.preview && data.preview.length) {',
			'			previewEl.textContent = JSON.stringify(data.preview, null, 2);',
			'		} else {',
			'			previewEl.textContent = wpMcpAiFnbIngest.strings.noPreview;',
			'		}',
			'	}',
			'	document.getElementById("fnb-ingest-preview-btn").addEventListener("click", function () {',
			'		var tableId = document.getElementById("fnb-ingest-table-select").value;',
			'		if (!tableId) { errorMessage(wpMcpAiFnbIngest.strings.selectTable); return; }',
			'		var msgEl = document.getElementById("fnb-ingest-message");',
			'		msgEl.style.display = "block";',
			'		msgEl.className = "notice notice-info inline";',
			'		msgEl.textContent = wpMcpAiFnbIngest.strings.loading;',
			'		var form = new FormData();',
			'		form.append("action", "wp_mcp_ai_fnb_ingest_preview");',
			'		form.append("nonce", wpMcpAiFnbIngest.nonce);',
			'		form.append("table_id", tableId);',
			'		fetch(wpMcpAiFnbIngest.ajaxUrl, { method: "POST", credentials: "same-origin", body: form })',
			'			.then(function (r) { return r.json(); })',
			'			.then(function (res) {',
			'				if (res && res.success) { render(res.data); } else { errorMessage((res && res.data && res.data.message) || wpMcpAiFnbIngest.strings.error); }',
			'			})',
			'			.catch(function () { errorMessage(wpMcpAiFnbIngest.strings.error); });',
			'	});',
			'})();',
		);

		return implode( "\n", $lines );
	}
}
