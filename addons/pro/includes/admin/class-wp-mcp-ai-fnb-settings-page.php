<?php
/**
 * Food & Beverage Toolkit — Admin Settings Page.
 *
 * Registers the "Food & Beverage" submenu under the NV oOS Pro admin menu
 * (`nvoos-pro-dashboard`) and renders the toolkit configuration form (data
 * adapter, Drive folder IDs, CSV directory, drafts folder, as-of date, demo
 * mode, native storage).
 *
 * All values are stored in the dedicated `wp_mcp_ai_fnb_settings` option
 * managed by WP_MCP_AI_Fnb_Settings.
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
 * Admin settings page handler for the Food & Beverage Management Toolkit.
 *
 * @since 1.6.0
 */
class WP_MCP_AI_Fnb_Settings_Page {

	/**
	 * WordPress settings option name (owned by WP_MCP_AI_Fnb_Settings).
	 *
	 * @var string
	 */
	const OPTION_NAME = 'wp_mcp_ai_fnb_settings';

	/**
	 * Settings group name used with settings_fields().
	 *
	 * @var string
	 */
	const SETTINGS_GROUP = 'wp_mcp_ai_fnb_settings_group';

	/**
	 * Option key holding the auto-map review proposals.
	 *
	 * @var string
	 */
	const PROPOSALS_KEY = 'wp_mcp_ai_fnb_table_map_proposals';

	/**
	 * Admin page slug.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'wp-mcp-ai-fnb';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ), 28 );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_post_wp_mcp_ai_fnb_seed_assistants', array( __CLASS__, 'handle_seed_submission' ) );
		add_action( 'admin_post_wp_mcp_ai_fnb_export_csv', array( __CLASS__, 'handle_export_submission' ) );
		add_action( 'admin_post_wp_mcp_ai_fnb_automap_scan', array( __CLASS__, 'handle_automap_scan' ) );
		add_action( 'admin_post_wp_mcp_ai_fnb_automap_apply', array( __CLASS__, 'handle_automap_apply' ) );
	}

	/**
	 * Add the settings page under the NV oOS Pro admin menu.
	 *
	 * @return void
	 */
	public static function add_menu() {
		add_submenu_page(
			'nvoos-pro-dashboard',
			__( 'Food & Beverage Toolkit', 'mcp-ai-wpoos-pro' ),
			__( 'Food & Beverage', 'mcp-ai-wpoos-pro' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Register settings fields.
	 *
	 * @return void
	 */
	public static function register_settings() {
		register_setting(
			self::SETTINGS_GROUP,
			self::OPTION_NAME,
			array(
				'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ),
			)
		);

		// Data source section.
		add_settings_section(
			'wp_mcp_ai_fnb_data',
			__( 'Data Source', 'mcp-ai-wpoos-pro' ),
			function () {
				echo '<p>' . esc_html__( 'The toolkit reads the 17 spec data tables from Google Drive (exported CSV sheets) or from local CSV files. Table and column names may drift; the reader normalises headers per table.', 'mcp-ai-wpoos-pro' ) . '</p>';
			},
			self::PAGE_SLUG
		);

		add_settings_field(
			'adapter',
			__( 'Adapter', 'mcp-ai-wpoos-pro' ),
			array( __CLASS__, 'render_adapter_select' ),
			self::PAGE_SLUG,
			'wp_mcp_ai_fnb_data',
			array(
				'setting_key' => 'adapter',
				'description' => __( 'Drive: read sheets via the Google Workspace toolkit. CSV: read local CSV/export files (also used when WP_MCP_AI_FNB_USE_FIXTURES is defined).', 'mcp-ai-wpoos-pro' ),
			)
		);

		add_settings_field(
			'ingest_mode',
			__( 'Ingest Mode', 'mcp-ai-wpoos-pro' ),
			array( __CLASS__, 'render_ingest_mode_select' ),
			self::PAGE_SLUG,
			'wp_mcp_ai_fnb_data',
			array(
				'setting_key' => 'ingest_mode',
				'description' => __( 'Folder: scan a single Google Drive folder of unorganised files and auto-map them to the data tables. Workbook: read one shared spreadsheet whose tabs hold the tables. Split: one file per table matched by name.', 'mcp-ai-wpoos-pro' ),
			)
		);

		add_settings_field(
			'connection_id',
			__( 'Drive Connection', 'mcp-ai-wpoos-pro' ),
			array( __CLASS__, 'render_text_field' ),
			self::PAGE_SLUG,
			'wp_mcp_ai_fnb_data',
			array(
				'setting_key' => 'connection_id',
				'description' => __( 'Default Remote Sites connection ID used to resolve the Google Drive credentials. Leave empty to use the only enabled Drive connection automatically (an error is raised when several are enabled — map each table explicitly under Table Sources instead).', 'mcp-ai-wpoos-pro' ),
			)
		);

		add_settings_field(
			'data_folder_id',
			__( 'Data Folder ID', 'mcp-ai-wpoos-pro' ),
			array( __CLASS__, 'render_data_folder_select' ),
			self::PAGE_SLUG,
			'wp_mcp_ai_fnb_data',
			array(
				'setting_key' => 'data_folder_id',
				'description' => __( 'Google Drive folder containing the exported data-table sheets or the workbook to export. Choose from the detected folders, or leave “Auto-detect” to search all configured sources.', 'mcp-ai-wpoos-pro' ),
			)
		);

		add_settings_field(
			'setup_folder_id',
			__( 'Setup Folder ID', 'mcp-ai-wpoos-pro' ),
			array( __CLASS__, 'render_text_field' ),
			self::PAGE_SLUG,
			'wp_mcp_ai_fnb_data',
			array(
				'setting_key' => 'setup_folder_id',
				'description' => __( 'Google Drive folder ID containing the setup artifacts (report library, metric definitions, investor pack, character bibles).', 'mcp-ai-wpoos-pro' ),
			)
		);

		add_settings_field(
			'data_workbook_id',
			__( 'Workbook ID', 'mcp-ai-wpoos-pro' ),
			array( __CLASS__, 'render_text_field' ),
			self::PAGE_SLUG,
			'wp_mcp_ai_fnb_data',
			array(
				'setting_key' => 'data_workbook_id',
				'description' => __( 'Google Drive file ID of a single shared workbook whose worksheets/tabs hold each data table (e.g. “Menu”, “Daily Sales”). When set, this workbook is read directly and the per-table file lookup is skipped. Leave empty to auto-detect a spreadsheet in the data/setup folders.', 'mcp-ai-wpoos-pro' ),
			)
		);

		add_settings_field(
			'data_workbook_name',
			__( 'Workbook Name', 'mcp-ai-wpoos-pro' ),
			array( __CLASS__, 'render_text_field' ),
			self::PAGE_SLUG,
			'wp_mcp_ai_fnb_data',
			array(
				'setting_key' => 'data_workbook_name',
				'description' => __( 'Optional: disambiguate which spreadsheet to use by (partial, case-insensitive) file name. Only used when Workbook ID is empty.', 'mcp-ai-wpoos-pro' ),
			)
		);

		add_settings_field(
			'csv_data_dir',
			__( 'CSV Data Directory', 'mcp-ai-wpoos-pro' ),
			array( __CLASS__, 'render_text_field' ),
			self::PAGE_SLUG,
			'wp_mcp_ai_fnb_data',
			array(
				'setting_key' => 'csv_data_dir',
				'description' => __( 'Absolute server path to a directory of CSV table exports (CSV adapter). Also used as the local drafts directory. The folder must already exist and be readable by PHP; for drafts it must also be writable. Name files by sheet slug (menu.csv, recipes.csv, daily_sales.csv, …), not by table ID.', 'mcp-ai-wpoos-pro' ),
			)
		);

		// Drafts & dates section.
		add_settings_section(
			'wp_mcp_ai_fnb_output',
			__( 'Drafts & Dates', 'mcp-ai-wpoos-pro' ),
			function () {
				echo '<p>' . esc_html__( 'Every generated report is saved as a draft — the toolkit never sends, orders or changes records.', 'mcp-ai-wpoos-pro' ) . '</p>';
			},
			self::PAGE_SLUG
		);

		add_settings_field(
			'drafts_folder_id',
			__( 'Drafts Folder ID', 'mcp-ai-wpoos-pro' ),
			array( __CLASS__, 'render_text_field' ),
			self::PAGE_SLUG,
			'wp_mcp_ai_fnb_output',
			array(
				'setting_key' => 'drafts_folder_id',
				'description' => __( 'Google Drive folder ID where fnb_save_draft writes report drafts (Drive adapter).', 'mcp-ai-wpoos-pro' ),
			)
		);

		add_settings_field(
			'as_of_date',
			__( 'As-Of Date', 'mcp-ai-wpoos-pro' ),
			array( __CLASS__, 'render_text_field' ),
			self::PAGE_SLUG,
			'wp_mcp_ai_fnb_output',
			array(
				'setting_key' => 'as_of_date',
				'description' => __( 'Default reference date (Y-m-d) for unit-price joins, days-of-cover and report generation. Leave empty for today.', 'mcp-ai-wpoos-pro' ),
			)
		);

		add_settings_field(
			'demo_mode',
			__( 'Demo Mode', 'mcp-ai-wpoos-pro' ),
			array( __CLASS__, 'render_checkbox_field' ),
			self::PAGE_SLUG,
			'wp_mcp_ai_fnb_output',
			array(
				'setting_key' => 'demo_mode',
				'description' => __( 'When enabled, the toolkit prefers the bundled demo fixtures as the data source. Leave off for your own data.', 'mcp-ai-wpoos-pro' ),
			)
		);

		// Native storage section (Phase 9).
		add_settings_section(
			'wp_mcp_ai_fnb_native',
			__( 'Native Storage', 'mcp-ai-wpoos-pro' ),
			function () {
				echo '<p>' . esc_html__( 'Store the five transactional tables (Daily sales, Purchases, Stock counts, Waste log, Bookings) as WordPress posts so they can be edited in wp-admin and read without Drive access. Reference tables keep the Drive/CSV adapter either way. Import rows with the fnb_import_table tool or the F&B Management menu.', 'mcp-ai-wpoos-pro' ) . '</p>';
			},
			self::PAGE_SLUG
		);

		add_settings_field(
			'enable_fnb_native_storage',
			__( 'Enable Native Storage', 'mcp-ai-wpoos-pro' ),
			array( __CLASS__, 'render_checkbox_field' ),
			self::PAGE_SLUG,
			'wp_mcp_ai_fnb_native',
			array(
				'setting_key' => 'enable_fnb_native_storage',
				'description' => __( 'When enabled, metrics and reports read the transactional tables from the native CPTs. Turn this off to read them straight from Drive/CSV again (rows are kept).', 'mcp-ai-wpoos-pro' ),
			)
		);

		// Purchase requests section (Phase 9b, Appendix A).
		add_settings_section(
			'wp_mcp_ai_fnb_pr',
			__( 'Purchase Requests', 'mcp-ai-wpoos-pro' ),
			function () {
				echo '<p>' . esc_html__( 'Purchase requests use the QMS approval lifecycle (draft → in review → approved → released) with segregation of duties, content-hash freeze, and an immutable audit trail. Enable the Document Generation toolkit with QMS compliance to use the fnb_pr_* tools.', 'mcp-ai-wpoos-pro' ) . '</p>';
			},
			self::PAGE_SLUG
		);

		add_settings_field(
			'pr_auto_approval_threshold',
			__( 'Auto-Approval Threshold', 'mcp-ai-wpoos-pro' ),
			array( __CLASS__, 'render_text_field' ),
			self::PAGE_SLUG,
			'wp_mcp_ai_fnb_pr',
			array(
				'setting_key' => 'pr_auto_approval_threshold',
				'description' => __( 'Release requires a finance co-sign when the request total exceeds this amount (in the tenant currency). 0 = co-sign never required.', 'mcp-ai-wpoos-pro' ),
			)
		);

		// Media Worker offload section (Proposal 079).
		add_settings_section(
			'wp_mcp_ai_fnb_worker',
			__( 'Media Worker Offload', 'mcp-ai-wpoos-pro' ),
			function () {
				echo '<p>' . esc_html__( 'When a Media Worker sidecar is configured (Settings > Media Worker), spreadsheet, PDF and photo parsing can run in the sidecar instead of the WordPress PHP process. Every parse falls back to the local PHP parsers automatically whenever the sidecar is unreachable or fails.', 'mcp-ai-wpoos-pro' ) . '</p>';
			},
			self::PAGE_SLUG
		);

		add_settings_field(
			'use_media_worker',
			__( 'Use Media Worker for parsing', 'mcp-ai-wpoos-pro' ),
			array( __CLASS__, 'render_select_field' ),
			self::PAGE_SLUG,
			'wp_mcp_ai_fnb_worker',
			array(
				'setting_key' => 'use_media_worker',
				'choices'     => array(
					'auto' => __( 'Auto (use when configured and reachable)', 'mcp-ai-wpoos-pro' ),
					'off'  => __( 'Off (always parse in WordPress)', 'mcp-ai-wpoos-pro' ),
				),
				'description' => __( 'Auto offloads XLSX/XLS/ODS, PDF and photo files to the sidecar for parsing (photos are OCR\'d there). Note: enabling this sends the owner file contents to the Media Worker container.', 'mcp-ai-wpoos-pro' ),
			)
		);

		add_settings_field(
			'ocr_vision_fallback',
			__( 'Vision OCR fallback for photos', 'mcp-ai-wpoos-pro' ),
			array( __CLASS__, 'render_checkbox_field' ),
			self::PAGE_SLUG,
			'wp_mcp_ai_fnb_worker',
			array(
				'setting_key' => 'ocr_vision_fallback',
				'description' => __( 'When a photo cannot be OCR\'d confidently (or no Media Worker is configured), send it to the plugin\'s configured vision provider (extract_image_text) instead. Uses vision API credits; the image is staged as a temporary attachment and deleted afterwards.', 'mcp-ai-wpoos-pro' ),
			)
		);

		self::register_tenant_profile_section();
		self::register_table_sources_section();
	}

	/**
	 * Register the Table Sources section (per-table connection/folder mapping).
	 *
	 * @return void
	 */
	private static function register_table_sources_section() {
		add_settings_section(
			'wp_mcp_ai_fnb_sources',
			__( 'Table Sources', 'mcp-ai-wpoos-pro' ),
			array( __CLASS__, 'render_table_sources_intro' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			'table_map',
			__( 'Per-Table Sources', 'mcp-ai-wpoos-pro' ),
			array( __CLASS__, 'render_table_sources_grid' ),
			self::PAGE_SLUG,
			'wp_mcp_ai_fnb_sources',
			array(
				'description' => __( 'Route each data table to the Drive connection and folder that holds it. Tools and metrics then read the right connection automatically — no connection IDs in prompts. Leave a row empty to use the default Data Source settings above.', 'mcp-ai-wpoos-pro' ),
			)
		);
	}

	/**
	 * Register the Tenant Profile settings section.
	 *
	 * @return void
	 */
	private static function register_tenant_profile_section() {
		add_settings_section(
			'wp_mcp_ai_fnb_tenant',
			__( 'Tenant Profile', 'mcp-ai-wpoos-pro' ),
			function () {
				echo '<p>' . esc_html__( 'These values are rendered into the seeded assistant prompts and every money label, so the toolkit describes your business and currency rather than a fixed demo venue.', 'mcp-ai-wpoos-pro' ) . '</p>';
			},
			self::PAGE_SLUG
		);

		foreach ( array(
			'venue_name' => __( 'Venue Name', 'mcp-ai-wpoos-pro' ),
			'venue_type' => __( 'Venue Type', 'mcp-ai-wpoos-pro' ),
			'currency'   => __( 'Currency (ISO code)', 'mcp-ai-wpoos-pro' ),
			'locale'     => __( 'Locale', 'mcp-ai-wpoos-pro' ),
			'owner_role' => __( 'Owner Role', 'mcp-ai-wpoos-pro' ),
		) as $key => $label ) {
			add_settings_field(
				$key,
				$label,
				array( __CLASS__, 'render_text_field' ),
				self::PAGE_SLUG,
				'wp_mcp_ai_fnb_tenant',
				array( 'setting_key' => $key )
			);
		}
	}

	/**
	 * Sanitize the submitted settings.
	 *
	 * Merges over the stored option so unchecked checkboxes and omitted fields
	 * are preserved.
	 *
	 * @param array $input Raw input values.
	 * @return array Sanitized settings.
	 */
	public static function sanitize_settings( $input ) {
		$current = get_option( self::OPTION_NAME, array() );
		$current = is_array( $current ) ? $current : array();

		$submitted_adapter  = isset( $input['adapter'] ) ? $input['adapter'] : 'drive';
		$current['adapter'] = in_array( $submitted_adapter, array( 'drive', 'csv' ), true )
			? sanitize_text_field( $submitted_adapter )
			: 'drive';

		$submitted_mode         = isset( $input['ingest_mode'] ) ? $input['ingest_mode'] : 'folder';
		$current['ingest_mode'] = in_array( $submitted_mode, array( 'folder', 'workbook', 'split' ), true )
			? sanitize_text_field( $submitted_mode )
			: 'folder';

		$current['connection_id']      = isset( $input['connection_id'] ) ? sanitize_text_field( $input['connection_id'] ) : '';
		$current['data_folder_id']     = isset( $input['data_folder_id'] ) ? sanitize_text_field( $input['data_folder_id'] ) : '';
		$current['setup_folder_id']    = isset( $input['setup_folder_id'] ) ? sanitize_text_field( $input['setup_folder_id'] ) : '';
		$current['data_workbook_id']   = isset( $input['data_workbook_id'] ) ? sanitize_text_field( $input['data_workbook_id'] ) : '';
		$current['data_workbook_name'] = isset( $input['data_workbook_name'] ) ? sanitize_text_field( $input['data_workbook_name'] ) : '';
		$current['drafts_folder_id']   = isset( $input['drafts_folder_id'] ) ? sanitize_text_field( $input['drafts_folder_id'] ) : '';
		$current['csv_data_dir']       = isset( $input['csv_data_dir'] ) ? sanitize_text_field( $input['csv_data_dir'] ) : '';

		$submitted_date        = isset( $input['as_of_date'] ) ? sanitize_text_field( $input['as_of_date'] ) : '';
		$current['as_of_date'] = ( '' === $submitted_date || (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $submitted_date ) ) ? $submitted_date : '';

		$current['demo_mode'] = ! empty( $input['demo_mode'] );

		$current['enable_fnb_native_storage'] = ! empty( $input['enable_fnb_native_storage'] );

		foreach ( array( 'venue_name', 'venue_type', 'currency', 'locale', 'owner_role' ) as $profile_key ) {
			if ( isset( $input[ $profile_key ] ) ) {
				$current[ $profile_key ] = sanitize_text_field( (string) $input[ $profile_key ] );
			}
		}

		$submitted_threshold                   = isset( $input['pr_auto_approval_threshold'] ) ? sanitize_text_field( (string) $input['pr_auto_approval_threshold'] ) : '';
		$current['pr_auto_approval_threshold'] = is_numeric( $submitted_threshold ) ? max( 0.0, (float) $submitted_threshold ) : 0.0;

		$submitted_worker            = isset( $input['use_media_worker'] ) ? sanitize_text_field( (string) $input['use_media_worker'] ) : 'auto';
		$current['use_media_worker'] = in_array( $submitted_worker, array( 'auto', 'off' ), true ) ? $submitted_worker : 'auto';

		$current['ocr_vision_fallback'] = ! empty( $input['ocr_vision_fallback'] );

		// Per-table source mapping (Table Sources grid).
		if ( isset( $input['table_map'] ) && is_array( $input['table_map'] ) ) {
			if ( ! class_exists( 'WP_MCP_AI_Fnb_Data_Source' ) ) {
				require_once WP_MCP_AI_PRO_PATH . 'includes/tools/food-beverage/class-wp-mcp-ai-fnb-data-source.php';
			}
			$known_tables = wp_list_pluck( WP_MCP_AI_Fnb_Data_Source::list_tables(), 'id' );
			$known_tables = array_map( 'strval', $known_tables );
			$known        = array_flip( $known_tables );
			$clean_map    = array();

			foreach ( $input['table_map'] as $table_id => $row ) {
				$table_id = (string) $table_id;
				if ( ! is_array( $row ) || ! isset( $known[ $table_id ] ) ) {
					continue;
				}
				$entry = array();
				foreach ( array( 'connection_id', 'folder_id', 'file', 'sheet' ) as $key ) {
					$value = isset( $row[ $key ] ) ? sanitize_text_field( (string) $row[ $key ] ) : '';
					if ( 'connection_id' === $key ) {
						$value = sanitize_key( $value );
					}
					if ( '' !== $value ) {
						$entry[ $key ] = $value;
					}
				}
				if ( ! empty( $entry ) ) {
					$clean_map[ $table_id ] = $entry;
				}
			}

			$current['table_map'] = $clean_map;
		}

		return $current;
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Food & Beverage Toolkit', 'mcp-ai-wpoos-pro' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Restaurant operating metrics (M-01…M-21) and reports (R-01…R-13) computed deterministically from Drive/CSV data, with drafts-only output.', 'mcp-ai-wpoos-pro' ); ?>
			</p>
			<?php self::render_seed_notice(); ?>
			<?php self::render_status_card(); ?>
			<?php self::render_automap_review(); ?>
			<form method="post" action="options.php">
				<?php
				settings_fields( self::SETTINGS_GROUP );
				do_settings_sections( self::PAGE_SLUG );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render the toolkit status card.
	 *
	 * @return void
	 */
	private static function render_status_card() {
		$toolkit_enabled = class_exists( 'WP_MCP_AI_Fnb_Settings' ) && WP_MCP_AI_Fnb_Settings::is_enabled();
		$drive_ready     = class_exists( 'WP_MCP_AI_Pro_Google_Drive_Client' );
		$adapter         = class_exists( 'WP_MCP_AI_Fnb_Settings' ) ? WP_MCP_AI_Fnb_Settings::get( 'adapter', 'drive' ) : 'drive';
		$demo_mode       = class_exists( 'WP_MCP_AI_Fnb_Settings' ) && WP_MCP_AI_Fnb_Settings::is_demo_mode();
		?>
		<table class="widefat striped" style="max-width: 720px; margin: 1em 0;">
			<tbody>
				<tr>
					<th scope="row"><?php esc_html_e( 'Toolkit', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<?php if ( $toolkit_enabled ) : ?>
							<span style="color: #2271b1;">&#9679;</span> <?php esc_html_e( 'Enabled', 'mcp-ai-wpoos-pro' ); ?>
						<?php else : ?>
							<span style="color: #b32d2e;">&#9679;</span> <?php esc_html_e( 'Disabled — enable it under NV oOS Settings → Tools → Pro Features.', 'mcp-ai-wpoos-pro' ); ?>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Adapter', 'mcp-ai-wpoos-pro' ); ?></th>
					<td><?php echo esc_html( 'drive' === $adapter ? __( 'Google Drive', 'mcp-ai-wpoos-pro' ) : __( 'CSV (local files)', 'mcp-ai-wpoos-pro' ) ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'CSV data directory', 'mcp-ai-wpoos-pro' ); ?></th>
					<td><?php self::render_csv_dir_status(); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Drive client', 'mcp-ai-wpoos-pro' ); ?></th>
					<td><?php $drive_ready ? esc_html_e( 'Available', 'mcp-ai-wpoos-pro' ) : esc_html_e( 'Not available — install/enable the Google Workspace toolkit for Drive mode.', 'mcp-ai-wpoos-pro' ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Demo mode', 'mcp-ai-wpoos-pro' ); ?></th>
					<td><?php $demo_mode ? esc_html_e( 'On', 'mcp-ai-wpoos-pro' ) : esc_html_e( 'Off', 'mcp-ai-wpoos-pro' ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Assistant packs', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<?php
						/* translators: %s: WP-CLI command. */
						printf( esc_html__( 'Seed the four A1–A4 assistant packs with: %s', 'mcp-ai-wpoos-pro' ), '<code>wp mcp-ai pro fnb seed-assistants</code>' );
						?>
						<?php if ( self::seeder_available() ) : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top: 0.5em;">
								<?php wp_nonce_field( 'wp_mcp_ai_fnb_seed_assistants', 'wp_mcp_ai_fnb_seed_nonce' ); ?>
								<input type="hidden" name="action" value="wp_mcp_ai_fnb_seed_assistants" />
								<button type="button" class="button button-secondary" onclick="if ( confirm( '<?php echo esc_js( __( 'Seed the four F&B assistant packs? Existing assistants with matching slugs or titles will be skipped (idempotent).', 'mcp-ai-wpoos-pro' ) ); ?>' ) ) { this.closest('form').submit(); }"><?php esc_html_e( 'Seed assistant packs', 'mcp-ai-wpoos-pro' ); ?></button>
							</form>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Export workbook', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top: 0.5em;">
							<?php wp_nonce_field( 'wp_mcp_ai_fnb_export_csv', 'wp_mcp_ai_fnb_export_nonce' ); ?>
							<input type="hidden" name="action" value="wp_mcp_ai_fnb_export_csv" />
							<button type="button" class="button button-secondary" onclick="this.closest('form').submit();"><?php esc_html_e( 'Export workbook to CSV', 'mcp-ai-wpoos-pro' ); ?></button>
						</form>
						<p class="description"><?php esc_html_e( 'Reads the configured Drive workbook and writes one CSV per table into the CSV data directory, then switches the adapter to CSV so reads work offline.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Native storage', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<?php
						$native_on = class_exists( 'WP_MCP_AI_Fnb_Settings' ) && WP_MCP_AI_Fnb_Settings::is_native_storage_enabled();
						if ( $native_on ) :
							echo '<span style="color: #2271b1;">&#9679;</span> ' . esc_html__( 'Enabled — transactional tables resolve from WordPress native storage.', 'mcp-ai-wpoos-pro' );
						else :
							echo '<span style="color: #b32d2e;">&#9679;</span> ' . esc_html__( 'Disabled — transactional tables resolve from the Drive/CSV adapter.', 'mcp-ai-wpoos-pro' );
						endif;
						?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Native tables', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<?php
						if ( class_exists( 'WP_MCP_AI_Fnb_Cpt_Reader' ) ) {
							$native_rows = array();
							foreach ( WP_MCP_AI_Fnb_Cpt_Reader::native_tables() as $native_table ) {
								$counts        = post_type_exists( $native_table['post_type'] ) ? wp_count_posts( $native_table['post_type'] ) : null;
								$count         = null !== $counts ? (int) $counts->publish : 0;
								$native_rows[] = esc_html( $native_table['id'] . ' → ' . $native_table['post_type'] . ' (' . $count . ')' );
							}
							echo wp_kses_post( implode( '<br>', $native_rows ) );
						} else {
							esc_html_e( 'Not loaded.', 'mcp-ai-wpoos-pro' );
						}
						?>
					</td>
				</tr>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render the CSV data directory status (read/write check).
	 *
	 * @return void
	 */
	private static function render_csv_dir_status() {
		$dir = '';
		if ( class_exists( 'WP_MCP_AI_Fnb_Settings' ) ) {
			$dir = WP_MCP_AI_Fnb_Settings::get( 'csv_data_dir', '' );
		}
		$dir = is_string( $dir ) ? untrailingslashit( trim( $dir ) ) : '';

		if ( '' === $dir ) {
			echo '<span style="color: #b32d2e;">&#9679;</span> ' . esc_html__( 'Not configured — set the CSV Data Directory field (below) to an absolute server path.', 'mcp-ai-wpoos-pro' );
			return;
		}

		if ( ! file_exists( $dir ) ) {
			echo '<span style="color: #b32d2e;">&#9679;</span> ';
			/* translators: %s: directory path */
			printf( esc_html__( 'Missing — the directory %s does not exist. Create it and ensure it is readable by PHP.', 'mcp-ai-wpoos-pro' ), '<code>' . esc_html( $dir ) . '</code>' );
			return;
		}

		if ( ! is_dir( $dir ) ) {
			echo '<span style="color: #b32d2e;">&#9679;</span> ';
			/* translators: %s: path */
			printf( esc_html__( 'Not a directory — %s is not a folder.', 'mcp-ai-wpoos-pro' ), '<code>' . esc_html( $dir ) . '</code>' );
			return;
		}

		$readable = is_readable( $dir );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Read-only capability probe for the admin status row; the WP_Filesystem direct transport is not guaranteed on Docker hosts.
		$writable = is_writable( $dir );

		if ( $readable && $writable ) {
			echo '<span style="color: #2271b1;">&#9679;</span> ';
			/* translators: %s: directory path */
			printf( esc_html__( 'Readable and writable — %s', 'mcp-ai-wpoos-pro' ), '<code>' . esc_html( $dir ) . '</code>' );
			return;
		}

		if ( $readable && ! $writable ) {
			echo '<span style="color: #dba617;">&#9679;</span> ';
			/* translators: %s: directory path */
			printf( esc_html__( 'Readable, not writable — drafts cannot be saved without write access to %s.', 'mcp-ai-wpoos-pro' ), '<code>' . esc_html( $dir ) . '</code>' );
			return;
		}

		echo '<span style="color: #b32d2e;">&#9679;</span> ';
		/* translators: %s: directory path */
		printf( esc_html__( 'Not readable — PHP cannot read %s.', 'mcp-ai-wpoos-pro' ), '<code>' . esc_html( $dir ) . '</code>' );
	}

	/**
	 * Whether the shared assistant seeder service is available.
	 *
	 * @return bool
	 */
	private static function seeder_available() {
		return class_exists( 'WP_MCP_AI_Fnb_Assistant_Seeder' );
	}

	/**
	 * Handle the "Seed assistant packs" button submission.
	 *
	 * Runs the shared seeder service (identical to the WP-CLI command) and
	 * redirects back to the settings page with a transient result notice.
	 *
	 * @return void
	 */
	public static function handle_seed_submission() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to seed assistant packs.', 'mcp-ai-wpoos-pro' ) );
		}

		check_admin_referer( 'wp_mcp_ai_fnb_seed_assistants', 'wp_mcp_ai_fnb_seed_nonce' );

		if ( ! class_exists( 'WP_MCP_AI_Fnb_Assistant_Seeder' ) ) {
			$result = new WP_Error(
				'wp_mcp_ai_fnb_seeder_missing',
				__( 'The assistant seeder service is not available. Enable the Food & Beverage toolkit first.', 'mcp-ai-wpoos-pro' )
			);
		} else {
			$result = WP_MCP_AI_Fnb_Assistant_Seeder::seed();
		}

		if ( is_wp_error( $result ) ) {
			$notice = array(
				'type'    => 'error',
				'message' => $result->get_error_message(),
			);
		} else {
			$notice = array(
				'type'    => 'success',
				/* translators: 1: created, 2: updated, 3: skipped, 4: errors */
				'message' => sprintf(
					__( 'F&B assistant packs seeded: %1$d created, %2$d updated, %3$d skipped, %4$d errors.', 'mcp-ai-wpoos-pro' ),
					isset( $result['created'] ) ? (int) $result['created'] : 0,
					isset( $result['updated'] ) ? (int) $result['updated'] : 0,
					isset( $result['skipped'] ) ? (int) $result['skipped'] : 0,
					isset( $result['errors'] ) ? (int) $result['errors'] : 0
				),
			);
		}

		set_transient( 'wp_mcp_ai_fnb_seed_notice', $notice, 60 );

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
		exit;
	}

	/**
	 * Handle the "Export workbook to CSV" button submission.
	 *
	 * Runs the shared CSV exporter service, then redirects back to the settings
	 * page with a transient result notice.
	 *
	 * @return void
	 */
	public static function handle_export_submission() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to export the F&B workbook.', 'mcp-ai-wpoos-pro' ) );
		}

		check_admin_referer( 'wp_mcp_ai_fnb_export_csv', 'wp_mcp_ai_fnb_export_nonce' );

		if ( ! class_exists( 'WP_MCP_AI_Fnb_Csv_Exporter' ) ) {
			require_once dirname( __DIR__, 2 ) . '/tools/food-beverage/class-wp-mcp-ai-fnb-csv-exporter.php';
		}

		$result = WP_MCP_AI_Fnb_Csv_Exporter::export_workbook();

		if ( is_wp_error( $result ) ) {
			$notice = array(
				'type'    => 'error',
				'message' => $result->get_error_message(),
			);
		} else {
			$notice = array(
				'type'    => 'success',
				/* translators: 1: exported count, 2: skipped count, 3: directory path */
				'message' => sprintf(
					__( 'Workbook exported: %1$d CSV file(s) written to %3$s (%2$d skipped). The adapter is now set to CSV.', 'mcp-ai-wpoos-pro' ),
					isset( $result['exported'] ) ? (int) $result['exported'] : 0,
					isset( $result['skipped'] ) ? (int) $result['skipped'] : 0,
					isset( $result['dir'] ) ? (string) $result['dir'] : ''
				),
			);
		}

		set_transient( 'wp_mcp_ai_fnb_seed_notice', $notice, 60 );

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
		exit;
	}

	/**
	 * Render the one-time seed-result admin notice, if present.
	 *
	 * @return void
	 */
	private static function render_seed_notice() {
		$notice = get_transient( 'wp_mcp_ai_fnb_seed_notice' );
		if ( ! is_array( $notice ) || empty( $notice['message'] ) ) {
			return;
		}

		delete_transient( 'wp_mcp_ai_fnb_seed_notice' );

		$type = 'success' === $notice['type'] ? 'success' : 'error';
		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $type ),
			esc_html( $notice['message'] )
		);
	}

	/**
	 * The settings page URL (with a source-map tab anchor when given).
	 *
	 * @param string $anchor Optional in-page anchor.
	 * @return string
	 */
	private static function settings_url( $anchor = '' ) {
		$url = admin_url( 'admin.php?page=' . self::PAGE_SLUG );

		return '' !== $anchor ? $url . '#' . $anchor : $url;
	}

	/**
	 * Enabled Remote Sites Drive connections, as id => name.
	 *
	 * @return array<string,string>
	 */
	private static function drive_connections() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			return array();
		}

		$out = array();
		foreach ( WP_MCP_AI_Pro_Remote_Site_Manager::get_all_connections() as $id => $connection ) {
			if ( ! is_array( $connection ) || empty( $connection['enabled'] ) ) {
				continue;
			}
			$type = isset( $connection['connection_type'] ) ? sanitize_key( (string) $connection['connection_type'] ) : '';
			if ( 'google_drive' !== $type ) {
				continue;
			}
			$out[ (string) $id ] = isset( $connection['name'] ) ? trim( (string) $connection['name'] ) : (string) $id;
		}

		return $out;
	}

	/**
	 * Render the Table Sources section intro.
	 *
	 * Rendered inside the main settings form, so it must never emit a <form>
	 * element of its own — the HTML parser drops nested form tags and the inner
	 * </form> closes the outer settings form early, orphaning every section
	 * rendered after it. The proposal review panel therefore lives in
	 * render_automap_review(), rendered before the main form opens.
	 *
	 * @return void
	 */
	public static function render_table_sources_intro() {
		$proposals = get_option( self::PROPOSALS_KEY, array() );
		$proposals = is_array( $proposals ) ? $proposals : array();
		$scan_url  = wp_nonce_url( admin_url( 'admin-post.php?action=wp_mcp_ai_fnb_automap_scan' ), 'wp_mcp_ai_fnb_automap' );
		?>
		<p>
			<?php esc_html_e( 'Each table can live on a different Drive connection and folder. Map them here once and every tool and metric (including cross-connection calculations like dish margin) reads the right source automatically — the assistant never passes connection IDs.', 'mcp-ai-wpoos-pro' ); ?>
		</p>
		<p>
			<a class="button" href="<?php echo esc_url( $scan_url ); ?>">
				<?php esc_html_e( 'Scan sources & propose mappings', 'mcp-ai-wpoos-pro' ); ?>
			</a>
			<span class="description">
				<?php esc_html_e( 'Scans the default data folder plus every folder mapped below, then proposes a table per file with a confidence score for your review.', 'mcp-ai-wpoos-pro' ); ?>
			</span>
		</p>
		<?php if ( ! empty( $proposals ) ) : ?>
			<p class="description">
				<?php esc_html_e( 'Pending mapping proposals are shown in the review panel above this form — apply or discard them there before saving.', 'mcp-ai-wpoos-pro' ); ?>
			</p>
			<?php
			endif;
	}

	/**
	 * Render the auto-map proposal review panel.
	 *
	 * Rendered by render_page() BEFORE the main settings form opens. HTML does
	 * not allow nested forms: when this panel was rendered as a section callback
	 * inside the options.php form, browsers dropped the inner <form> tag, so the
	 * Apply and Discard buttons submitted the outer settings form instead of the
	 * admin-post handler and the proposals could never be cleared.
	 *
	 * @return void
	 */
	public static function render_automap_review() {
		$proposals = get_option( self::PROPOSALS_KEY, array() );
		$proposals = is_array( $proposals ) ? $proposals : array();
		if ( empty( $proposals ) ) {
			return;
		}

		$high = array();
		$low  = array();
		foreach ( $proposals as $proposal ) {
			if ( (float) $proposal['confidence'] >= 0.7 ) {
				$high[] = $proposal;
			} else {
				$low[] = $proposal;
			}
		}

		$apply_confirm   = __( 'Apply the selected mapping proposals to the Table Sources map? Existing operator mappings are kept unless overwrite is checked.', 'mcp-ai-wpoos-pro' );
		$discard_confirm = __( 'Discard all mapping proposals?', 'mcp-ai-wpoos-pro' );
		?>
		<div class="card" style="max-width:none; margin-bottom:12px;">
			<h3 style="margin-top:0;"><?php esc_html_e( 'Mapping proposals (review before applying)', 'mcp-ai-wpoos-pro' ); ?></h3>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'wp_mcp_ai_fnb_automap_apply' ); ?>
				<input type="hidden" name="action" value="wp_mcp_ai_fnb_automap_apply" />
				<input type="hidden" name="discard" value="" />
				<?php if ( ! empty( $high ) ) : ?>
					<p>
						<strong><?php esc_html_e( 'High confidence (auto-accept candidates)', 'mcp-ai-wpoos-pro' ); ?></strong>
						<button type="button" class="button button-primary" onclick="if ( confirm( '<?php echo esc_js( $apply_confirm ); ?>' ) ) { this.closest('form').submit(); }">
							<?php esc_html_e( 'Apply accepted mappings', 'mcp-ai-wpoos-pro' ); ?>
						</button>
					</p>
					<table class="widefat striped" style="margin-bottom:12px;">
						<thead>
							<tr>
								<td style="width:36px;"></td>
								<th><?php esc_html_e( 'Table', 'mcp-ai-wpoos-pro' ); ?></th>
								<th><?php esc_html_e( 'Connection', 'mcp-ai-wpoos-pro' ); ?></th>
								<th><?php esc_html_e( 'Folder', 'mcp-ai-wpoos-pro' ); ?></th>
								<th><?php esc_html_e( 'File', 'mcp-ai-wpoos-pro' ); ?></th>
								<th><?php esc_html_e( 'Confidence', 'mcp-ai-wpoos-pro' ); ?></th>
								<th><?php esc_html_e( 'Sample headers', 'mcp-ai-wpoos-pro' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $high as $proposal ) : ?>
								<tr>
									<td><input type="checkbox" name="proposals[]" value="<?php echo esc_attr( $proposal['table_id'] ); ?>" checked></td>
									<td><?php echo esc_html( $proposal['table_id'] . ' · ' . self::table_label( $proposal['table_id'] ) ); ?></td>
									<td><?php echo esc_html( '' !== $proposal['connection_id'] ? self::connection_label( $proposal['connection_id'] ) : __( '(default)', 'mcp-ai-wpoos-pro' ) ); ?></td>
									<td><code><?php echo esc_html( $proposal['folder_id'] ); ?></code></td>
									<td><?php echo esc_html( $proposal['file'] ); ?></td>
									<td><?php echo esc_html( round( (float) $proposal['confidence'] * 100 ) . '%' ); ?></td>
									<td><code><?php echo esc_html( implode( ', ', array_slice( (array) $proposal['sample_columns'], 0, 5 ) ) ); ?></code></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>

				<?php if ( ! empty( $low ) ) : ?>
					<p><strong><?php esc_html_e( 'Low confidence (review individually)', 'mcp-ai-wpoos-pro' ); ?></strong></p>
					<table class="widefat striped" style="margin-bottom:12px;">
						<thead>
							<tr>
								<td style="width:36px;"></td>
								<th><?php esc_html_e( 'Table', 'mcp-ai-wpoos-pro' ); ?></th>
								<th><?php esc_html_e( 'Connection', 'mcp-ai-wpoos-pro' ); ?></th>
								<th><?php esc_html_e( 'Folder', 'mcp-ai-wpoos-pro' ); ?></th>
								<th><?php esc_html_e( 'File', 'mcp-ai-wpoos-pro' ); ?></th>
								<th><?php esc_html_e( 'Confidence', 'mcp-ai-wpoos-pro' ); ?></th>
								<th><?php esc_html_e( 'Sample headers', 'mcp-ai-wpoos-pro' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $low as $proposal ) : ?>
								<tr>
									<td><input type="checkbox" name="proposals[]" value="<?php echo esc_attr( $proposal['table_id'] ); ?>"></td>
									<td><?php echo esc_html( $proposal['table_id'] . ' · ' . self::table_label( $proposal['table_id'] ) ); ?></td>
									<td><?php echo esc_html( '' !== $proposal['connection_id'] ? self::connection_label( $proposal['connection_id'] ) : __( '(default)', 'mcp-ai-wpoos-pro' ) ); ?></td>
									<td><code><?php echo esc_html( $proposal['folder_id'] ); ?></code></td>
									<td><?php echo esc_html( $proposal['file'] ); ?></td>
									<td><?php echo esc_html( round( (float) $proposal['confidence'] * 100 ) . '%' ); ?></td>
									<td><code><?php echo esc_html( implode( ', ', array_slice( (array) $proposal['sample_columns'], 0, 5 ) ) ); ?></code></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>

				<p class="description">
					<label>
						<input type="checkbox" name="overwrite" value="1">
						<?php esc_html_e( 'Overwrite existing mappings for the selected tables (off by default: operator-authored mappings win).', 'mcp-ai-wpoos-pro' ); ?>
					</label>
				</p>
				<p>
					<button type="button" class="button button-primary" onclick="if ( confirm( '<?php echo esc_js( $apply_confirm ); ?>' ) ) { this.closest('form').submit(); }"><?php esc_html_e( 'Apply accepted mappings', 'mcp-ai-wpoos-pro' ); ?></button>
					<button type="button" class="button" onclick="if ( confirm( '<?php echo esc_js( $discard_confirm ); ?>' ) ) { this.closest('form').querySelector('input[name=discard]').value = '1'; this.closest('form').submit(); }"><?php esc_html_e( 'Discard proposals', 'mcp-ai-wpoos-pro' ); ?></button>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Human label for a spec table ID.
	 *
	 * @param string $table_id Spec table ID.
	 * @return string
	 */
	private static function table_label( $table_id ) {
		if ( ! class_exists( 'WP_MCP_AI_Fnb_Data_Source' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/tools/food-beverage/class-wp-mcp-ai-fnb-data-source.php';
		}

		$definition = WP_MCP_AI_Fnb_Data_Source::table_definition( $table_id );

		return is_array( $definition ) && ! empty( $definition['label'] ) ? (string) $definition['label'] : (string) $table_id;
	}

	/**
	 * Human label for a connection ID.
	 *
	 * @param string $connection_id Remote Sites connection ID.
	 * @return string
	 */
	private static function connection_label( $connection_id ) {
		$connections = self::drive_connections();

		return isset( $connections[ $connection_id ] ) ? $connections[ $connection_id ] . ' (' . $connection_id . ')' : $connection_id;
	}

	/**
	 * Redirect and terminate the request.
	 *
	 * Mirrors the repo-wide redirect_and_exit() contract: when the
	 * `wp_redirect` filter short-circuits the redirect — how the PHPUnit
	 * suite captures redirects — the request aborts via WPDieException
	 * instead of a bare exit killing the test process.
	 *
	 * @param string $location Redirect target URL.
	 * @return void
	 *
	 * @throws WPDieException When running under PHPUnit and the redirect is blocked.
	 */
	private static function redirect_and_exit( $location ) {
		if ( wp_safe_redirect( $location ) ) {
			exit;
		}

		if ( defined( 'WP_MCP_AI_TESTS_RUNNING' ) && WP_MCP_AI_TESTS_RUNNING && class_exists( 'WPDieException' ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the exception message is not rendered anywhere; it only aborts the request flow under tests.
			throw new WPDieException( is_string( $location ) ? $location : '' );
		}
	}

	/**
	 * Render the per-table source mapping grid.
	 *
	 * @param array $args Field arguments.
	 * @return void
	 */
	public static function render_table_sources_grid( $args ) {
		if ( ! class_exists( 'WP_MCP_AI_Fnb_Data_Source' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/tools/food-beverage/class-wp-mcp-ai-fnb-data-source.php';
		}

		$tables      = WP_MCP_AI_Fnb_Data_Source::list_tables();
		$map         = WP_MCP_AI_Fnb_Settings::table_map();
		$connections = self::drive_connections();
		?>
		<table class="widefat striped" id="fnb-table-sources">
			<thead>
				<tr>
					<th style="width:220px;"><?php esc_html_e( 'Table', 'mcp-ai-wpoos-pro' ); ?></th>
					<th><?php esc_html_e( 'Drive connection', 'mcp-ai-wpoos-pro' ); ?></th>
					<th><?php esc_html_e( 'Folder ID', 'mcp-ai-wpoos-pro' ); ?></th>
					<th><?php esc_html_e( 'File or sheet hint', 'mcp-ai-wpoos-pro' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $tables as $table ) : ?>
					<?php
					$table_id = (string) $table['id'];
					$entry    = isset( $map[ $table_id ] ) ? $map[ $table_id ] : array();
					$base     = 'wp_mcp_ai_fnb_settings[table_map][' . $table_id . ']';
					?>
					<tr>
						<td><strong><?php echo esc_html( $table_id . ' · ' . $table['label'] ); ?></strong></td>
						<td>
							<select name="<?php echo esc_attr( $base . '[connection_id]' ); ?>">
								<option value=""><?php esc_html_e( '— Default source —', 'mcp-ai-wpoos-pro' ); ?></option>
								<?php foreach ( $connections as $connection_id => $name ) : ?>
									<option value="<?php echo esc_attr( $connection_id ); ?>" <?php selected( isset( $entry['connection_id'] ) ? $entry['connection_id'] : '', $connection_id ); ?>>
										<?php echo esc_html( $name . ' (' . $connection_id . ')' ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
						<td>
							<input type="text" class="regular-text code" name="<?php echo esc_attr( $base . '[folder_id]' ); ?>" value="<?php echo esc_attr( isset( $entry['folder_id'] ) ? $entry['folder_id'] : '' ); ?>" placeholder="<?php esc_attr_e( 'Drive folder ID', 'mcp-ai-wpoos-pro' ); ?>">
						</td>
						<td>
							<input type="text" class="regular-text" name="<?php echo esc_attr( $base . '[file]' ); ?>" value="<?php echo esc_attr( isset( $entry['file'] ) ? $entry['file'] : '' ); ?>" placeholder="<?php esc_attr_e( 'e.g. menu.xlsx', 'mcp-ai-wpoos-pro' ); ?>">
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		self::render_field_description( $args );
	}

	/**
	 * Expand scan pairs whose connection is empty across the given candidate
	 * connections.
	 *
	 * The default source (empty connection_id) resolves through the site-wide
	 * settings credentials or the single healthy Drive connection. When several
	 * healthy connections are enabled the Drive client refuses to guess — for the
	 * scan, that ambiguity is a discovery task rather than an error: substitute
	 * every candidate connection so the folder is scanned under each account.
	 * The folder only exists in one of them, so the wrong accounts contribute no
	 * proposals and the winners carry their connection_id back into the review.
	 *
	 * Pairs with an explicit connection and pairs without a folder are kept
	 * as-is. When no candidates are known the input is returned unchanged so the
	 * original resolution error still surfaces.
	 *
	 * @param array $pairs      Pairs of [ connection_id, folder_id ].
	 * @param array $candidates Connection IDs to substitute for an empty connection.
	 * @return array Expanded pairs (no duplicates).
	 */
	public static function expand_scan_pairs( array $pairs, array $candidates ) {
		if ( empty( $candidates ) ) {
			return $pairs;
		}

		$expanded = array();
		$add      = function ( $connection_id, $folder_id ) use ( &$expanded ) {
			$connection_id = sanitize_key( (string) $connection_id );
			$folder_id     = trim( (string) $folder_id );
			if ( '' === $folder_id ) {
				return;
			}
			$pair = array( $connection_id, $folder_id );
			if ( ! in_array( $pair, $expanded, true ) ) {
				$expanded[] = $pair;
			}
		};

		foreach ( $pairs as $pair ) {
			if ( '' !== (string) $pair[0] ) {
				$add( $pair[0], $pair[1] );
				continue;
			}

			foreach ( $candidates as $connection_id ) {
				$add( $connection_id, $pair[1] );
			}
		}

		return $expanded;
	}

	/**
	 * Handle the "Scan sources & propose mappings" action.
	 *
	 * Scans the toolkit-default source plus every (connection, folder) pair
	 * already mapped below, keeps the best proposal per table, and stores them
	 * for review. Nothing is applied automatically.
	 *
	 * @return void
	 */
	public static function handle_automap_scan() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to scan the F&B sources.', 'mcp-ai-wpoos-pro' ) );
		}

		check_admin_referer( 'wp_mcp_ai_fnb_automap' );

		if ( ! class_exists( 'WP_MCP_AI_Fnb_Settings' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/tools/food-beverage/class-wp-mcp-ai-fnb-settings.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_Fnb_Universal_Reader' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/tools/food-beverage/class-wp-mcp-ai-fnb-universal-reader.php';
		}

		// Collect the (connection, folder) pairs to scan: the default source and
		// every distinct pair already mapped below.
		$pairs = array();
		$add   = function ( $connection_id, $folder_id ) use ( &$pairs ) {
			$connection_id = sanitize_key( (string) $connection_id );
			$folder_id     = trim( (string) $folder_id );
			if ( '' === $folder_id ) {
				return;
			}
			$pair = array( $connection_id, $folder_id );
			if ( ! in_array( $pair, $pairs, true ) ) {
				$pairs[] = $pair;
			}
		};

		$add(
			WP_MCP_AI_Fnb_Settings::get( 'connection_id', '' ),
			WP_MCP_AI_Fnb_Settings::get( 'data_folder_id', '' )
		);
		foreach ( WP_MCP_AI_Fnb_Settings::table_map() as $entry ) {
			$add(
				isset( $entry['connection_id'] ) ? $entry['connection_id'] : WP_MCP_AI_Fnb_Settings::get( 'connection_id', '' ),
				isset( $entry['folder_id'] ) ? $entry['folder_id'] : ''
			);
		}

		if ( empty( $pairs ) ) {
			set_transient(
				'wp_mcp_ai_fnb_seed_notice',
				array(
					'type'    => 'error',
					'message' => __( 'No sources to scan — set the Data Folder ID above, or map at least one table to a connection + folder below.', 'mcp-ai-wpoos-pro' ),
				),
				60
			);

			self::redirect_and_exit( self::settings_url() );
		}

		// A pair with an empty connection_id resolves through the site-wide
		// settings credentials or the single healthy Drive connection. When
		// several healthy connections are enabled the Drive client refuses to
		// guess — expand the pair across every candidate connection so the scan
		// discovers which account owns the folder instead of failing outright.
		$candidates = array();
		if ( class_exists( 'WP_MCP_AI_Pro_Google_Drive_Client' ) ) {
			$probe = WP_MCP_AI_Pro_Google_Drive_Client::resolve_credentials( '' );
			if ( is_wp_error( $probe ) && 'wp_mcp_ai_drive_ambiguous_connection' === $probe->get_error_code() ) {
				$candidates = array_keys( WP_MCP_AI_Pro_Google_Drive_Client::get_auto_connection_candidates() );
			}
		}
		$pairs = self::expand_scan_pairs( $pairs, $candidates );

		$reader    = new WP_MCP_AI_Fnb_Universal_Reader();
		$proposals = array();
		$errors    = array();
		foreach ( $pairs as $pair ) {
			$scan = $reader->scan_folder( $pair[0], $pair[1] );
			if ( '' !== $scan['error'] ) {
				$errors[] = sprintf(
					/* translators: 1: connection id, 2: folder id, 3: error message */
					__( 'Connection %1$s / folder %2$s: %3$s', 'mcp-ai-wpoos-pro' ),
					'' !== $pair[0] ? $pair[0] : __( '(default)', 'mcp-ai-wpoos-pro' ),
					$pair[1],
					$scan['error']
				);
				continue;
			}
			$proposals = array_merge( $proposals, $scan['proposals'] );
		}

		// Keep the best proposal per table.
		$best = array();
		foreach ( $proposals as $proposal ) {
			$table_id = $proposal['table_id'];
			if ( ! isset( $best[ $table_id ] ) || (float) $proposal['confidence'] > (float) $best[ $table_id ]['confidence'] ) {
				$best[ $table_id ] = $proposal;
			}
		}

		update_option( self::PROPOSALS_KEY, array_values( $best ), false );

		$high = 0;
		foreach ( $best as $proposal ) {
			if ( (float) $proposal['confidence'] >= 0.7 ) {
				++$high;
			}
		}

		set_transient(
			'wp_mcp_ai_fnb_seed_notice',
			array(
				'type'    => empty( $best ) ? 'error' : 'success',
				/* translators: 1: total proposals, 2: high-confidence count, 3: source count, 4: error list */
				'message' => empty( $best )
					? sprintf( __( 'No table mappings could be proposed. %1$s', 'mcp-ai-wpoos-pro' ), implode( ' ', $errors ) )
					: sprintf(
						__( 'Scanned %3$d source(s) and proposed %1$d table mapping(s) (%2$d high-confidence). Review and apply them in the Table Sources section.', 'mcp-ai-wpoos-pro' ),
						count( $best ),
						$high,
						count( $pairs )
					),
			),
			60
		);

		self::redirect_and_exit( self::settings_url() );
	}

	/**
	 * Handle the "Apply accepted mappings" action.
	 *
	 * Writes the checked proposals into the operator-authored table_map.
	 * Existing operator mappings are preserved unless overwrite is checked.
	 *
	 * @return void
	 */
	public static function handle_automap_apply() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to apply F&B table mappings.', 'mcp-ai-wpoos-pro' ) );
		}

		check_admin_referer( 'wp_mcp_ai_fnb_automap_apply' );

		$discard = isset( $_POST['discard'] ) ? sanitize_key( (string) wp_unslash( $_POST['discard'] ) ) : '';
		if ( '1' === $discard ) {
			delete_option( self::PROPOSALS_KEY );

			set_transient(
				'wp_mcp_ai_fnb_seed_notice',
				array(
					'type'    => 'success',
					'message' => __( 'Mapping proposals discarded.', 'mcp-ai-wpoos-pro' ),
				),
				60
			);

			self::redirect_and_exit( self::settings_url() );
		}

		// Table IDs travel as checkbox VALUES ("6.0", "2.0", …), never as
		// form key segments: sanitize_key() on a dotted key ("6.0" → "60")
		// silently mismatches every spec table and the apply becomes a no-op.
		$raw      = isset( $_POST['proposals'] ) ? (array) wp_unslash( $_POST['proposals'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each element is sanitised with sanitize_text_field() in the loop below.
		$selected = array();
		foreach ( $raw as $value ) {
			$value = sanitize_text_field( (string) $value );
			if ( '' !== $value ) {
				$selected[] = $value;
			}
		}
		$overwrite = ! empty( $_POST['overwrite'] );
		$proposals = get_option( self::PROPOSALS_KEY, array() );
		$proposals = is_array( $proposals ) ? $proposals : array();

		$all = get_option( self::OPTION_NAME, array() );
		$all = is_array( $all ) ? $all : array();
		$map = isset( $all['table_map'] ) && is_array( $all['table_map'] ) ? $all['table_map'] : array();

		$applied = 0;
		$skipped = 0;
		foreach ( $proposals as $proposal ) {
			$table_id = (string) $proposal['table_id'];
			if ( ! in_array( $table_id, $selected, true ) ) {
				++$skipped;
				continue;
			}

			// Operator-authored mappings win unless overwrite is requested.
			if ( ! empty( $map[ $table_id ] ) && ! $overwrite ) {
				++$skipped;
				continue;
			}

			$map[ $table_id ] = array(
				'connection_id' => sanitize_key( (string) $proposal['connection_id'] ),
				'folder_id'     => trim( (string) $proposal['folder_id'] ),
				'file'          => isset( $proposal['file'] ) ? sanitize_text_field( (string) $proposal['file'] ) : '',
			);
			++$applied;
		}

		$all['table_map'] = $map;
		update_option( self::OPTION_NAME, $all );
		delete_option( self::PROPOSALS_KEY );

		set_transient(
			'wp_mcp_ai_fnb_seed_notice',
			array(
				'type'    => 'success',
				/* translators: 1: applied count, 2: skipped count */
				'message' => sprintf( __( 'Table Sources updated: %1$d mapping(s) applied, %2$d skipped.', 'mcp-ai-wpoos-pro' ), $applied, $skipped ),
			),
			60
		);

		self::redirect_and_exit( self::settings_url() );
	}

	/**
	 * Render the adapter select field.
	 *
	 * @param array $args Field arguments including 'setting_key' and 'description'.
	 * @return void
	 */
	public static function render_adapter_select( $args ) {
		$current = WP_MCP_AI_Fnb_Settings::get( $args['setting_key'], 'drive' );
		?>
		<select name="<?php echo esc_attr( self::OPTION_NAME . '[' . $args['setting_key'] . ']' ); ?>">
			<option value="drive" <?php selected( $current, 'drive' ); ?>><?php esc_html_e( 'Google Drive', 'mcp-ai-wpoos-pro' ); ?></option>
			<option value="csv" <?php selected( $current, 'csv' ); ?>><?php esc_html_e( 'CSV (local files)', 'mcp-ai-wpoos-pro' ); ?></option>
		</select>
		<?php self::render_field_description( $args ); ?>
		<?php
	}

	/**
	 * Render the Ingest Mode dropdown.
	 *
	 * @param array $args Field arguments including 'setting_key' and 'description'.
	 * @return void
	 */
	public static function render_ingest_mode_select( $args ) {
		$current = WP_MCP_AI_Fnb_Settings::get( $args['setting_key'], 'folder' );
		?>
		<select name="<?php echo esc_attr( self::OPTION_NAME . '[' . $args['setting_key'] . ']' ); ?>">
			<option value="folder" <?php selected( $current, 'folder' ); ?>><?php esc_html_e( 'Folder (universal ingest)', 'mcp-ai-wpoos-pro' ); ?></option>
			<option value="workbook" <?php selected( $current, 'workbook' ); ?>><?php esc_html_e( 'Workbook (single spreadsheet)', 'mcp-ai-wpoos-pro' ); ?></option>
			<option value="split" <?php selected( $current, 'split' ); ?>><?php esc_html_e( 'Split (one file per table)', 'mcp-ai-wpoos-pro' ); ?></option>
		</select>
		<?php self::render_field_description( $args ); ?>
		<?php
	}

	/**
	 * Render the Data Folder (export source) dropdown.
	 *
	 * Offers the configured data/setup folder IDs plus any per-connection scoped
	 * folder IDs. The value maps onto the existing `data_folder_id` setting so the
	 * choice persists through the normal settings save.
	 *
	 * @param array $args Field arguments including 'setting_key' and 'description'.
	 * @return void
	 */
	public static function render_data_folder_select( $args ) {
		$current = WP_MCP_AI_Fnb_Settings::get( $args['setting_key'], '' );

		$folders = array();
		if ( class_exists( 'WP_MCP_AI_Fnb_Csv_Exporter' ) ) {
			$folders = WP_MCP_AI_Fnb_Csv_Exporter::list_drive_folders();
		}
		?>
		<select name="<?php echo esc_attr( self::OPTION_NAME . '[' . $args['setting_key'] . ']' ); ?>">
			<option value=""><?php esc_html_e( '— Auto-detect —', 'mcp-ai-wpoos-pro' ); ?></option>
			<?php if ( '' !== $current && ! wp_list_filter( $folders, array( 'id' => $current ) ) ) : ?>
				<option value="<?php echo esc_attr( $current ); ?>" selected><?php echo esc_html( $current ); ?></option>
			<?php endif; ?>
			<?php foreach ( $folders as $folder ) : ?>
				<option value="<?php echo esc_attr( $folder['id'] ); ?>" <?php selected( $current, $folder['id'] ); ?>><?php echo esc_html( $folder['label'] ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php self::render_field_description( $args ); ?>
		<?php
	}

	/**
	 * Render a text field.
	 *
	 * @param array $args Field arguments including 'setting_key' and 'description'.
	 * @return void
	 */
	public static function render_text_field( $args ) {
		$current = WP_MCP_AI_Fnb_Settings::get( $args['setting_key'], '' );
		?>
		<input
			type="text"
			class="regular-text"
			name="<?php echo esc_attr( self::OPTION_NAME . '[' . $args['setting_key'] . ']' ); ?>"
			value="<?php echo esc_attr( $current ); ?>"
		/>
		<?php self::render_field_description( $args ); ?>
		<?php
	}

	/**
	 * Render a checkbox field.
	 *
	 * @param array $args Field arguments including 'setting_key' and 'description'.
	 * @return void
	 */
	public static function render_checkbox_field( $args ) {
		$current = (bool) WP_MCP_AI_Fnb_Settings::get( $args['setting_key'], false );
		?>
		<label>
			<input
				type="checkbox"
				name="<?php echo esc_attr( self::OPTION_NAME . '[' . $args['setting_key'] . ']' ); ?>"
				value="1"
				<?php checked( $current ); ?>
			/>
			<?php self::render_field_description( $args ); ?>
		</label>
		<?php
	}

	/**
	 * Render a select field for fixed-choice settings.
	 *
	 * @param array $args Field arguments including 'setting_key', 'choices'
	 *                    and 'description'.
	 * @return void
	 */
	public static function render_select_field( $args ) {
		$current = WP_MCP_AI_Fnb_Settings::get( $args['setting_key'], 'auto' );
		$choices = isset( $args['choices'] ) && is_array( $args['choices'] ) ? $args['choices'] : array();
		?>
		<select name="<?php echo esc_attr( self::OPTION_NAME . '[' . $args['setting_key'] . ']' ); ?>">
			<?php foreach ( $choices as $value => $label ) : ?>
				<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current, $value ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php self::render_field_description( $args ); ?>
		<?php
	}

	/**
	 * Render a field description paragraph.
	 *
	 * @param array $args Field arguments including 'description'.
	 * @return void
	 */
	private static function render_field_description( $args ) {
		if ( ! empty( $args['description'] ) ) {
			echo '<p class="description">' . esc_html( $args['description'] ) . '</p>';
		}
	}
}
