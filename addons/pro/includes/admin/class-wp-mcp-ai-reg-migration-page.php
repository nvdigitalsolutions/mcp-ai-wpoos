<?php
/**
 * Excel Migration Page for Regulatory Registration Toolkit.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Excel Migration Page class.
 */
class WP_MCP_AI_Reg_Migration_Page {
	/**
	 * Initialize the class.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu_page' ), 25 );
	}

	/**
	 * Add menu page.
	 */
	public static function add_menu_page() {
		add_submenu_page(
			'edit.php?post_type=mcp_ai_reg_product',
			__( 'Import from Excel', 'mcp-ai-wpoos-pro' ),
			__( 'Import Excel', 'mcp-ai-wpoos-pro' ),
			'manage_options',
			'wp-mcp-ai-reg-migration',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Render the migration page.
	 */
	public static function render_page() {
		// Handle form submission.
		if ( isset( $_POST['wp_mcp_ai_import_excel'] ) && check_admin_referer( 'wp_mcp_ai_import_excel_nonce' ) ) {
			self::handle_import();
		}
		?>
		<div class="wrap wp-mcp-ai-import-page">
			<h1><?php echo esc_html__( 'Import from Excel', 'mcp-ai-wpoos-pro' ); ?></h1>
			<p><?php echo esc_html__( 'Import existing registration data from Excel files into the system.', 'mcp-ai-wpoos-pro' ); ?></p>
			
			<div class="notice notice-info">
				<p><?php echo esc_html__( 'This tool helps you migrate data from your current Excel tracker to the WordPress-based system.', 'mcp-ai-wpoos-pro' ); ?></p>
			</div>

			<div class="import-section">
				<h2><?php esc_html_e( 'Import Options', 'mcp-ai-wpoos-pro' ); ?></h2>

				<form method="post" enctype="multipart/form-data" class="import-form">
					<?php wp_nonce_field( 'wp_mcp_ai_import_excel_nonce' ); ?>
					
					<div class="import-type-selector">
						<label>
							<input type="radio" name="import_type" value="products" checked />
							<strong><?php esc_html_e( 'Import Products', 'mcp-ai-wpoos-pro' ); ?></strong>
							<p class="description"><?php esc_html_e( 'Import regulatory products with ingredients, formulations, and basic information.', 'mcp-ai-wpoos-pro' ); ?></p>
						</label>
						
						<label>
							<input type="radio" name="import_type" value="registrations" />
							<strong><?php esc_html_e( 'Import Registrations', 'mcp-ai-wpoos-pro' ); ?></strong>
							<p class="description"><?php esc_html_e( 'Import registration records with submission dates, approval dates, and status information.', 'mcp-ai-wpoos-pro' ); ?></p>
						</label>
					</div>
					
					<table class="form-table">
						<tr>
							<th scope="row">
								<label for="excel_file"><?php esc_html_e( 'Excel File', 'mcp-ai-wpoos-pro' ); ?></label>
							</th>
							<td>
								<input type="file" name="excel_file" id="excel_file" accept=".xlsx,.xls" required />
								<p class="description">
									<?php esc_html_e( 'Select an Excel file (.xlsx or .xls) containing your data.', 'mcp-ai-wpoos-pro' ); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="start_row"><?php esc_html_e( 'Start Row', 'mcp-ai-wpoos-pro' ); ?></label>
							</th>
							<td>
								<input type="number" name="start_row" id="start_row" value="2" min="1" class="small-text" />
								<p class="description">
									<?php esc_html_e( 'Row number to start importing from (usually row 2 if row 1 contains headers).', 'mcp-ai-wpoos-pro' ); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="skip_duplicates"><?php esc_html_e( 'Skip Duplicates', 'mcp-ai-wpoos-pro' ); ?></label>
							</th>
							<td>
								<label>
									<input type="checkbox" name="skip_duplicates" id="skip_duplicates" value="1" checked />
									<?php esc_html_e( 'Skip records that already exist in the system', 'mcp-ai-wpoos-pro' ); ?>
								</label>
							</td>
						</tr>
					</table>

					<p class="submit">
						<input type="submit" name="wp_mcp_ai_import_excel" class="button button-primary" value="<?php esc_attr_e( 'Upload and Preview', 'mcp-ai-wpoos-pro' ); ?>" />
					</p>
				</form>
			</div>

			<div class="import-help">
				<h3><?php esc_html_e( 'Excel Format Requirements', 'mcp-ai-wpoos-pro' ); ?></h3>
				
				<div class="format-tabs">
					<h4><?php esc_html_e( 'For Products Import:', 'mcp-ai-wpoos-pro' ); ?></h4>
					<p><?php esc_html_e( 'Your Excel file should contain the following columns:', 'mcp-ai-wpoos-pro' ); ?></p>
					<ul>
						<li><strong><?php esc_html_e( 'Product Name', 'mcp-ai-wpoos-pro' ); ?></strong> - <?php esc_html_e( 'Required', 'mcp-ai-wpoos-pro' ); ?></li>
						<li><strong><?php esc_html_e( 'Brand', 'mcp-ai-wpoos-pro' ); ?></strong> - <?php esc_html_e( 'Optional', 'mcp-ai-wpoos-pro' ); ?></li>
						<li><strong><?php esc_html_e( 'Manufacturer', 'mcp-ai-wpoos-pro' ); ?></strong> - <?php esc_html_e( 'Optional', 'mcp-ai-wpoos-pro' ); ?></li>
						<li><strong><?php esc_html_e( 'Category', 'mcp-ai-wpoos-pro' ); ?></strong> - <?php esc_html_e( 'Optional (e.g., Skincare, Haircare, Makeup)', 'mcp-ai-wpoos-pro' ); ?></li>
						<li><strong><?php esc_html_e( 'Ingredients', 'mcp-ai-wpoos-pro' ); ?></strong> - <?php esc_html_e( 'Optional (comma-separated INCI names)', 'mcp-ai-wpoos-pro' ); ?></li>
						<li><strong><?php esc_html_e( 'HS Code', 'mcp-ai-wpoos-pro' ); ?></strong> - <?php esc_html_e( 'Optional', 'mcp-ai-wpoos-pro' ); ?></li>
					</ul>

					<h4><?php esc_html_e( 'For Registrations Import:', 'mcp-ai-wpoos-pro' ); ?></h4>
					<p><?php esc_html_e( 'Your Excel file should contain the following columns:', 'mcp-ai-wpoos-pro' ); ?></p>
					<ul>
						<li><strong><?php esc_html_e( 'Product Name', 'mcp-ai-wpoos-pro' ); ?></strong> - <?php esc_html_e( 'Required (must match existing product)', 'mcp-ai-wpoos-pro' ); ?></li>
						<li><strong><?php esc_html_e( 'Country', 'mcp-ai-wpoos-pro' ); ?></strong> - <?php esc_html_e( 'Required (e.g., Sri Lanka, UAE, Saudi Arabia)', 'mcp-ai-wpoos-pro' ); ?></li>
						<li><strong><?php esc_html_e( 'Authority', 'mcp-ai-wpoos-pro' ); ?></strong> - <?php esc_html_e( 'Optional (e.g., NMRA, MOHAP, SFDA)', 'mcp-ai-wpoos-pro' ); ?></li>
						<li><strong><?php esc_html_e( 'Registration Number', 'mcp-ai-wpoos-pro' ); ?></strong> - <?php esc_html_e( 'Optional', 'mcp-ai-wpoos-pro' ); ?></li>
						<li><strong><?php esc_html_e( 'Submission Date', 'mcp-ai-wpoos-pro' ); ?></strong> - <?php esc_html_e( 'Optional (YYYY-MM-DD format)', 'mcp-ai-wpoos-pro' ); ?></li>
						<li><strong><?php esc_html_e( 'Approval Date', 'mcp-ai-wpoos-pro' ); ?></strong> - <?php esc_html_e( 'Optional (YYYY-MM-DD format)', 'mcp-ai-wpoos-pro' ); ?></li>
						<li><strong><?php esc_html_e( 'Expiry Date', 'mcp-ai-wpoos-pro' ); ?></strong> - <?php esc_html_e( 'Optional (YYYY-MM-DD format)', 'mcp-ai-wpoos-pro' ); ?></li>
						<li><strong><?php esc_html_e( 'Status', 'mcp-ai-wpoos-pro' ); ?></strong> - <?php esc_html_e( 'Optional (Draft, Submitted, Approved, etc.)', 'mcp-ai-wpoos-pro' ); ?></li>
					</ul>
				</div>

				<div class="notice notice-warning inline">
					<p><strong><?php esc_html_e( 'Important:', 'mcp-ai-wpoos-pro' ); ?></strong></p>
					<ul>
						<li><?php esc_html_e( 'Make sure your Excel file has headers in the first row', 'mcp-ai-wpoos-pro' ); ?></li>
						<li><?php esc_html_e( 'Column names should match the requirements above (case-insensitive)', 'mcp-ai-wpoos-pro' ); ?></li>
						<li><?php esc_html_e( 'Dates should be in YYYY-MM-DD format or Excel date format', 'mcp-ai-wpoos-pro' ); ?></li>
						<li><?php esc_html_e( 'For registrations import, products must already exist in the system', 'mcp-ai-wpoos-pro' ); ?></li>
					</ul>
				</div>

				<h3><?php esc_html_e( 'Using AI Assistant for Import', 'mcp-ai-wpoos-pro' ); ?></h3>
				<p><?php esc_html_e( 'You can also use the AI Assistant to import data:', 'mcp-ai-wpoos-pro' ); ?></p>
				<ol>
					<li><?php esc_html_e( 'Upload your Excel file to the WordPress Media Library', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'In the AI chat, ask: "Import products from the Excel file I uploaded"', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'The AI will use the import_products_from_excel or import_registrations_from_excel tool', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'The AI can handle field mapping and data validation automatically', 'mcp-ai-wpoos-pro' ); ?></li>
				</ol>
			</div>
		</div>

		<style>
			.wp-mcp-ai-import-page .import-section {
				background: #fff;
				border: 1px solid #ccd0d4;
				padding: 20px;
				margin: 20px 0;
			}
			.wp-mcp-ai-import-page .import-type-selector {
				display: grid;
				gap: 15px;
				margin: 20px 0;
			}
			.wp-mcp-ai-import-page .import-type-selector label {
				display: block;
				padding: 15px;
				border: 2px solid #ccd0d4;
				border-radius: 4px;
				cursor: pointer;
			}
			.wp-mcp-ai-import-page .import-type-selector input[type="radio"]:checked + strong {
				color: #2271b1;
			}
			.wp-mcp-ai-import-page .import-type-selector label:has(input:checked) {
				border-color: #2271b1;
				background: #f6f7f7;
			}
			.wp-mcp-ai-import-page .import-type-selector input[type="radio"] {
				margin-right: 10px;
			}
			.wp-mcp-ai-import-page .import-help {
				background: #fff;
				border: 1px solid #ccd0d4;
				padding: 20px;
				margin: 20px 0;
			}
			.wp-mcp-ai-import-page .import-help ul {
				list-style: disc;
				margin-left: 30px;
			}
			.wp-mcp-ai-import-page .import-help h3 {
				margin-top: 0;
			}
			.wp-mcp-ai-import-page .import-help h4 {
				margin-top: 20px;
				color: #1d2327;
			}
			.wp-mcp-ai-import-page .format-tabs {
				margin: 15px 0;
			}
		</style>
		<?php
	}

	/**
	 * Handle import form submission.
	 *
	 * Note: Nonce verification is performed by the calling function render_page().
	 */
	private static function handle_import() {
		// Check capabilities.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to import data', 'mcp-ai-wpoos-pro' ) );
		}

		// Validate file upload.
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		if ( ! isset( $_FILES['excel_file'] ) || ! isset( $_FILES['excel_file']['error'] ) || UPLOAD_ERR_OK !== $_FILES['excel_file']['error'] ) {
			add_settings_error(
				'wp_mcp_ai_import',
				'file_upload_error',
				__( 'File upload failed. Please try again.', 'mcp-ai-wpoos-pro' ),
				'error'
			);
			return;
		}

		// Validate file type.
		$file_name = isset( $_FILES['excel_file']['name'] ) ? sanitize_file_name( wp_unslash( $_FILES['excel_file']['name'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$file_ext  = strtolower( pathinfo( $file_name, PATHINFO_EXTENSION ) );

		if ( ! in_array( $file_ext, array( 'xlsx', 'xls' ), true ) ) {
			add_settings_error(
				'wp_mcp_ai_import',
				'invalid_file_type',
				__( 'Invalid file type. Please upload an Excel file (.xlsx or .xls).', 'mcp-ai-wpoos-pro' ),
				'error'
			);
			return;
		}

		// Show success message for now (actual import will be handled by AI tools).
		add_settings_error(
			'wp_mcp_ai_import',
			'file_uploaded',
			sprintf(
				/* translators: %s: file name */
				__( 'File "%s" uploaded successfully. Use the AI Assistant with the import tools to process this file with custom field mapping.', 'mcp-ai-wpoos-pro' ),
				$file_name
			),
			'success'
		);
	}
}

WP_MCP_AI_Reg_Migration_Page::init();
