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
		add_action( 'wp_ajax_wp_mcp_ai_fnb_ingest_scan', array( __CLASS__, 'ajax_scan' ) );
		add_action( 'wp_ajax_wp_mcp_ai_fnb_ingest_import', array( __CLASS__, 'ajax_import' ) );
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
		// Top-level-menu submenu hooks are sanitization-dependent, so match by
		// slug substring rather than an exact "{parent}_page_{slug}" guess — the
		// same pattern NV_oOS_Graphify_Settings / WebLLM use. An exact-=== check
		// here silently fails and the page's JS never enqueues.
		if ( false === strpos( $hook, self::PAGE_SLUG ) ) {
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
					'loading'        => __( 'Running dry-run preview…', 'mcp-ai-wpoos-pro' ),
					'error'          => __( 'Failed to load the preview. See the message below.', 'mcp-ai-wpoos-pro' ),
					'selectTable'    => __( 'Select a table to preview.', 'mcp-ai-wpoos-pro' ),
					'noMissing'      => __( 'No missing required columns.', 'mcp-ai-wpoos-pro' ),
					'noFlags'        => __( 'No out-of-band flags.', 'mcp-ai-wpoos-pro' ),
					'noPreview'      => __( 'No source rows resolved.', 'mcp-ai-wpoos-pro' ),
					'noFiles'        => __( 'No recognised source files found.', 'mcp-ai-wpoos-pro' ),
					'importing'      => __( 'Importing into native storage…', 'mcp-ai-wpoos-pro' ),
					/* translators: 1: rows processed so far, 2: total source rows */
					'importProgress' => __( 'Importing… %1$d of %2$d source rows processed.', 'mcp-ai-wpoos-pro' ),
					/* translators: 1: rows imported, 2: inserted, 3: updated, 4: errors */
					'importComplete' => __( 'Import complete: %1$d rows processed (%2$d inserted, %3$d updated, %4$d errors).', 'mcp-ai-wpoos-pro' ),
					/* translators: 1: rows processed so far, 2: total source rows */
					'importStopped'  => __( 'Import stopped after %1$d of %2$d source rows. Click Import again to continue.', 'mcp-ai-wpoos-pro' ),
					/* translators: 1: number of retries attempted so far */
					'importRetrying' => __( 'A batch failed — retrying (attempt %1$d)…', 'mcp-ai-wpoos-pro' ),
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
	 * AJAX: scan the configured source directories and auto-detect each table.
	 *
	 * @return void
	 */
	public static function ajax_scan() {
		check_ajax_referer( 'wp_mcp_ai_fnb_ingest_preview', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) ), 403 );
		}

		if ( ! class_exists( 'WP_MCP_AI_Fnb_Loader' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/tools/food-beverage/class-wp-mcp-ai-fnb-loader.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_Fnb_Settings' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/tools/food-beverage/class-wp-mcp-ai-fnb-settings.php';
		}

		$files = WP_MCP_AI_Fnb_Loader::scan_sources();

		wp_send_json_success(
			array(
				'files' => $files,
				'count' => count( $files ),
			)
		);
	}

	/**
	 * AJAX: import a transactional table into native storage (ACT-tier write).
	 *
	 * @return void
	 */
	public static function ajax_import() {
		check_ajax_referer( 'wp_mcp_ai_fnb_ingest_preview', 'nonce' );

		// The write is ACT-tier and must stay a higher privilege than review.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to import data.', 'mcp-ai-wpoos-pro' ) ), 403 );
		}

		$table_id = isset( $_POST['table_id'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['table_id'] ) ) : '';
		if ( '' === $table_id ) {
			wp_send_json_error( array( 'message' => __( 'Table ID is required.', 'mcp-ai-wpoos-pro' ) ), 400 );
		}

		$offset = isset( $_POST['offset'] ) ? absint( wp_unslash( $_POST['offset'] ) ) : 0;
		$limit  = isset( $_POST['limit'] ) ? absint( wp_unslash( $_POST['limit'] ) ) : 0;

		if ( ! class_exists( 'WP_MCP_AI_Tool_Fnb_Import_Table' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/tools/food-beverage/class-wp-mcp-ai-tool-fnb-import-table.php';
		}

		$tool   = new WP_MCP_AI_Tool_Fnb_Import_Table();
		$result = $tool->execute(
			array(
				'table_id' => $table_id,
				'dry_run'  => false,
				'offset'   => $offset,
				'limit'    => $limit,
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
				<button type="button" id="fnb-ingest-scan-btn" class="button">
					<?php esc_html_e( 'Scan source folder', 'mcp-ai-wpoos-pro' ); ?>
				</button>
				<?php if ( current_user_can( 'manage_options' ) ) : ?>
					<button type="button" id="fnb-ingest-import-btn" class="button button-danger">
						<?php esc_html_e( 'Import to native storage (write)', 'mcp-ai-wpoos-pro' ); ?>
					</button>
				<?php endif; ?>
			</div>

			<div class="fnb-ingest-card" id="fnb-ingest-scan-card" style="display:none">
				<h2><?php esc_html_e( 'Detected source files', 'mcp-ai-wpoos-pro' ); ?></h2>
				<ul id="fnb-ingest-scan-list"></ul>
			</div>

			<div id="fnb-ingest-message" class="notice inline" style="display:none"></div>

			<div id="fnb-ingest-content" style="display:none">
				<div class="fnb-ingest-grid">
					<div class="fnb-ingest-card">
						<h2><?php esc_html_e( 'Summary', 'mcp-ai-wpoos-pro' ); ?></h2>
						<div class="fnb-ingest-chart-wrap"><canvas id="fnb-ingest-summary-chart"></canvas></div>
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
			/* Chart.js with maintainAspectRatio:false needs a fixed-height parent. */
			.fnb-ingest-chart-wrap { position:relative; height:180px; }
			.fnb-ingest-chart-wrap canvas { display:block; width:100% !important; height:100% !important; }
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
			'	function boot() {',
			'		if (typeof Chart === "undefined") { return; }',
			'		var btn = document.getElementById("fnb-ingest-preview-btn");',
			'		if (!btn) { return; }',
			'		var summaryChart = null;',
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
			'			options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true } } }',
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
			'	} else {',
			'			previewEl.textContent = wpMcpAiFnbIngest.strings.noPreview;',
			'		}',
			'	}',
			'	btn.addEventListener("click", function () {',
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
			'	var scanBtn = document.getElementById("fnb-ingest-scan-btn");',
			'	if (scanBtn) {',
			'		scanBtn.addEventListener("click", function () {',
			'			var form = new FormData();',
			'			form.append("action", "wp_mcp_ai_fnb_ingest_scan");',
			'			form.append("nonce", wpMcpAiFnbIngest.nonce);',
			'			fetch(wpMcpAiFnbIngest.ajaxUrl, { method: "POST", credentials: "same-origin", body: form })',
			'				.then(function (r) { return r.json(); })',
			'				.then(function (res) {',
			'					var listEl = document.getElementById("fnb-ingest-scan-list");',
			'					var cardEl = document.getElementById("fnb-ingest-scan-card");',
			'					listEl.innerHTML = "";',
			'					var files = (res && res.data && res.data.files) || [];',
			'					if (!files.length) {',
			'						var li = document.createElement("li");',
			'						li.textContent = wpMcpAiFnbIngest.strings.noFiles;',
			'						listEl.appendChild(li);',
			'					} else {',
			'						files.forEach(function (f) {',
			'							var li = document.createElement("li");',
			'							li.textContent = f.file + " → " + f.table_id + " (" + Math.round(f.confidence * 100) + "%)";',
			'							listEl.appendChild(li);',
			'						});',
			'					}',
			'					cardEl.style.display = "block";',
			'				})',
			'		.catch(function () {});',
			'	});',
			'	}',
			'var importBtn = document.getElementById("fnb-ingest-import-btn");',
			'	if (importBtn) {',
			'		// The last offset we successfully handed off to the server (or were',
			'		// told to resume from). Persisted across Import clicks so a failed run',
			'		// can be resumed instead of restarted from scratch on a 15k-row table.',
			'		var resumeCursor = 0;',
			'		var formatString = function (template, args) {',
			'			return String(template).replace(/%(\d+)\$d/g, function (match, num) {',
			'				var i = parseInt(num, 10) - 1;',
			'				return typeof args[i] !== "undefined" ? args[i] : "";',
			'			});',
			'		};',
			'		// Send one batch. Returns a Promise resolving with the batch result.',
			'		var sendBatch = function (tableId, offset) {',
			'			var form = new FormData();',
			'			form.append("action", "wp_mcp_ai_fnb_ingest_import");',
			'			form.append("nonce", wpMcpAiFnbIngest.nonce);',
			'			form.append("table_id", tableId);',
			'			form.append("offset", offset);',
			'			form.append("limit", 500);',
			'			return fetch(wpMcpAiFnbIngest.ajaxUrl, { method: "POST", credentials: "same-origin", body: form })',
			'				.then(function (r) { return r.json(); });',
			'		};',
			'		var runImportBatch = function (tableId, offset, totals, msgEl, attempt) {',
			'			attempt = attempt || 0;',
			'			sendBatch(tableId, offset).then(function (res) {',
			'				if (!res || !res.success) {',
			'					var errMsg = (res && res.data && res.data.message) || wpMcpAiFnbIngest.strings.error;',
			'					// A non-success result is a server-side, deterministic error',
			'					// (bad table, missing source, permissions, …). Retrying it is',
			'					// pointless and would just re-hit the same failure.',
			'					errorMessage(errMsg);',
			'					return;',
			'				}',
			'				var data = res.data || {};',
			'				var s = data.summary || {};',
			'				totals.total += s.total || 0;',
			'				totals.inserted += s.inserted || 0;',
			'				totals.updated += s.updated || 0;',
			'				totals.errors += s.errors || 0;',
			'				var sourceTotal = data.source_total || totals.total;',
			'				// Advance the resume cursor only once the server has confirmed',
			'				// this batch processed, so a later failure preserves the point',
			'				// where the NEXT resume should pick up.',
			'				if (typeof data.next_offset !== "undefined" && data.next_offset !== null) {',
			'					resumeCursor = data.next_offset;',
			'				}',
			'				if (data.has_more && typeof data.next_offset !== "undefined" && data.next_offset !== null) {',
			'					msgEl.textContent = formatString(wpMcpAiFnbIngest.strings.importProgress, [data.next_offset, sourceTotal]);',
			'					runImportBatch(tableId, data.next_offset, totals, msgEl, 0);',
			'					return;',
			'				}',
			'				resumeCursor = 0;',
			'				msgEl.className = "notice notice-success inline";',
			'				msgEl.textContent = data.complete === false',
			'					? formatString(wpMcpAiFnbIngest.strings.importStopped, [totals.total, sourceTotal])',
			'					: formatString(wpMcpAiFnbIngest.strings.importComplete, [totals.total, totals.inserted, totals.updated, totals.errors]);',
			'			}).catch(function () {',
			'				// Network error / PHP timeout — likely transient. Retry the SAME',
			'				// offset (never the advanced cursor) a few times before giving up,',
			'				// so a big import survives a dropped request without restarting.',
			'if (attempt < 3) {',
			'msgEl.textContent = formatString(wpMcpAiFnbIngest.strings.importRetrying, [attempt + 1]);',
			'window.setTimeout(function () {',
			'runImportBatch(tableId, offset, totals, msgEl, attempt + 1);',
			'}, 1000 * (attempt + 1));',
			'return;',
			'}',
			'// Exhausted retries: report the resume point so the next click',
			'// continues rather than starting over.',
			'msgEl.className = "notice notice-error inline";',
			'msgEl.textContent = formatString(wpMcpAiFnbIngest.strings.importStopped, [resumeCursor, totals.total]);',
			'});',
			'};',
			'importBtn.addEventListener("click", function () {',
			'var tableId = document.getElementById("fnb-ingest-table-select").value;',
			'if (!tableId) { errorMessage(wpMcpAiFnbIngest.strings.selectTable); return; }',
			'if (!window.confirm("Import " + tableId + " into native storage? This writes to the WordPress posts.")) { return; }',
			'var msgEl = document.getElementById("fnb-ingest-message");',
			'msgEl.style.display = "block";',
			'msgEl.className = "notice notice-info inline";',
			'msgEl.textContent = resumeCursor > 0',
			'? formatString(wpMcpAiFnbIngest.strings.importProgress, [resumeCursor, resumeCursor])',
			': wpMcpAiFnbIngest.strings.importing;',
			'// Seed the running total with the already-imported rows so the',
			'// final "Import complete" count reflects prior runs too. On a fresh',
			'// import resumeCursor is 0, so this is a no-op. inserted/updated/',
			'// errors stay 0 until this run writes, and correctly report only',
			'// the work done now (unlike a re-import, a resume never re-sends the',
			'// rows below resumeCursor).',
			'runImportBatch(tableId, resumeCursor, { total: resumeCursor, inserted: 0, updated: 0, errors: 0 }, msgEl, 0);',
			'});',
			'}',
			' 	}',
			'if (typeof jQuery !== "undefined") { jQuery(document).ready(boot); } else if (document.readyState === "loading") { document.addEventListener("DOMContentLoaded", boot); } else { boot(); }',
			'})();',
		);

		return implode( "\n", $lines );
	}
}
