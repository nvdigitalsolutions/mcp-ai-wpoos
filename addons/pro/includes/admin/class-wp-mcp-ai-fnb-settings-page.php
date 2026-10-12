<?php
/**
 * Food & Beverage Toolkit — Admin Settings Page.
 *
 * Registers the "Food & Beverage" submenu under the NV oOS admin menu and
 * renders the toolkit configuration form (data adapter, Drive folder IDs,
 * CSV directory, drafts folder, as-of date, demo mode).
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
	}

	/**
	 * Add the settings page under the NV oOS admin menu.
	 *
	 * @return void
	 */
	public static function add_menu() {
		add_submenu_page(
			'wp-mcp-ai-dashboard',
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
			array( __CLASS__, 'render_text_field' ),
			self::PAGE_SLUG,
			'wp_mcp_ai_fnb_data',
			array(
				'setting_key' => 'data_folder_id',
				'description' => __( 'Google Drive folder ID containing the exported data-table sheets.', 'mcp-ai-wpoos-pro' ),
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
			'csv_data_dir',
			__( 'CSV Data Directory', 'mcp-ai-wpoos-pro' ),
			array( __CLASS__, 'render_text_field' ),
			self::PAGE_SLUG,
			'wp_mcp_ai_fnb_data',
			array(
				'setting_key' => 'csv_data_dir',
				'description' => __( 'Absolute server path to a directory of CSV table exports (CSV adapter). Also used as the local drafts directory.', 'mcp-ai-wpoos-pro' ),
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

		$current['connection_id']    = isset( $input['connection_id'] ) ? sanitize_text_field( $input['connection_id'] ) : '';
		$current['data_folder_id']   = isset( $input['data_folder_id'] ) ? sanitize_text_field( $input['data_folder_id'] ) : '';
		$current['setup_folder_id']  = isset( $input['setup_folder_id'] ) ? sanitize_text_field( $input['setup_folder_id'] ) : '';
		$current['drafts_folder_id'] = isset( $input['drafts_folder_id'] ) ? sanitize_text_field( $input['drafts_folder_id'] ) : '';
		$current['csv_data_dir']     = isset( $input['csv_data_dir'] ) ? sanitize_text_field( $input['csv_data_dir'] ) : '';

		$submitted_date        = isset( $input['as_of_date'] ) ? sanitize_text_field( $input['as_of_date'] ) : '';
		$current['as_of_date'] = ( '' === $submitted_date || (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $submitted_date ) ) ? $submitted_date : '';

		$current['demo_mode'] = ! empty( $input['demo_mode'] );

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
					</td>
				</tr>
			</tbody>
		</table>
		<?php
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
