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
				'description' => __( 'Drive: read sheets exported from the demo workbook via the Google Workspace toolkit. CSV: read local fixture/export files (also used when WP_MCP_AI_FNB_USE_FIXTURES is defined).', 'mcp-ai-wpoos-pro' ),
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
				'description' => __( 'Remote Sites connection ID used to resolve the Google Drive credentials. Leave empty to use the default workspace credentials.', 'mcp-ai-wpoos-pro' ),
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
				'description' => __( 'Optional: disambiguate which spreadsheet to use by (partial, case-insensitive) file name. Only used when Workbook ID is empty — e.g. “Surf Club” selects “Surf Club Midigama - Demo Data”.', 'mcp-ai-wpoos-pro' ),
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
				'description' => __( 'When enabled, the toolkit prefers the bundled demo fixtures and the drafts-only output discipline of the Surf Club demo.', 'mcp-ai-wpoos-pro' ),
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
			__( 'Auto-Approval Threshold (LKR)', 'mcp-ai-wpoos-pro' ),
			array( __CLASS__, 'render_text_field' ),
			self::PAGE_SLUG,
			'wp_mcp_ai_fnb_pr',
			array(
				'setting_key' => 'pr_auto_approval_threshold',
				'description' => __( 'Release requires a finance co-sign when the request total exceeds this amount. 0 = co-sign never required.', 'mcp-ai-wpoos-pro' ),
			)
		);
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

		$submitted_threshold                   = isset( $input['pr_auto_approval_threshold'] ) ? sanitize_text_field( (string) $input['pr_auto_approval_threshold'] ) : '';
		$current['pr_auto_approval_threshold'] = is_numeric( $submitted_threshold ) ? max( 0.0, (float) $submitted_threshold ) : 0.0;

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
								<?php submit_button( __( 'Seed assistant packs', 'mcp-ai-wpoos-pro' ), 'secondary', 'submit', false ); ?>
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
							<?php submit_button( __( 'Export workbook to CSV', 'mcp-ai-wpoos-pro' ), 'secondary', 'submit', false ); ?>
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
			<?php esc_html_e( 'Enabled', 'mcp-ai-wpoos-pro' ); ?>
		</label>
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
