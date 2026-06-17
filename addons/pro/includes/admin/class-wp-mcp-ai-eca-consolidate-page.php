<?php
/**
 * ECA Consolidate & Add Page
 *
 * Provides a unified interface for viewing and managing all ECA-related records
 * with an agentic flow to guide users through adding necessary records.
 * Includes document generation tools for data extraction, report generation,
 * and document processing when the Document Generation toolkit is enabled.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ECA Consolidation Admin Page
 */
class WP_MCP_AI_ECA_Consolidate_Page {

	/**
	 * Page slug.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'consolidate-eca';

	/**
	 * Default tools for ECA consolidation chat interface.
	 *
	 * Covers full ECA management and includes document generation tools
	 * for data extraction, report generation, and document processing.
	 *
	 * @var array
	 */
	const CHAT_TOOLS = array(
		// ECA management (core CRUD).
		'research_eca',
		'create_eca',
		'get_eca',
		'list_ecas',
		'update_eca',
		'delete_eca',
		// ECA scheduling & enrollment.
		'set_eca_schedule',
		'get_eca_timetable',
		'check_eca_conflicts',
		'enroll_student_eca',
		'withdraw_student_eca',
		'bulk_enroll_students',
		'manage_eca_waitlist',
		'manage_eca_term',
		// Attendance & reporting.
		'mark_eca_attendance',
		'get_eca_attendance_report',
		'get_student_participation_summary',
		'generate_eca_analytics',
		'generate_eca_participation_report',
		// Student management.
		'create_student',
		'get_student',
		'list_students',
		'update_student',
		'delete_student',
		// Notifications & communication.
		'configure_eca_notifications',
		'send_eca_notification',
		'send_eca_parent_report',
		// Workflow & exports.
		'create_eca_workflow_rule',
		'export_eca_data',
		'import_ecas_csv',
		// Calendar & research.
		'get_calendar_view',
		'web_search',
		'search_content',
		'semantic_content_search',
		// Document processing tools (from Document Generation toolkit).
		'extract_pdf_text',          // Extract text from ECA documents, schedules, curricula.
		'pro_pdf_document',          // Generate professional ECA reports as PDFs.
		'pro_word_document',         // Generate ECA documents in Word format.
		'pro_excel_document',        // Export ECA data to Excel for analysis.
		'generate_pdf',              // Quick PDF generation for schedules/reports.
		'generate_word',             // Quick Word document generation.
		'generate_excel',            // Quick Excel generation for ECA data.
		'html_to_pdf',               // Convert ECA content from HTML to PDF.
		'merge_pdfs',                // Combine multiple ECA documents.
		'add_watermark_to_pdf',      // Add branding/confidentiality watermarks.
		'excel_data_import',         // Import ECA data from spreadsheets.
		'excel_data_export',         // Export consolidated ECA data.
		'generate_invoice_pdf',      // Generate ECA fee/invoice documents.
	);

	/**
	 * Initialize the page.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu_page' ), 25 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_ajax_wp_mcp_ai_get_eca_records_preview', array( __CLASS__, 'handle_get_eca_preview' ) );
		add_action( 'wp_ajax_wp_mcp_ai_check_eca_completeness', array( __CLASS__, 'handle_check_eca_completeness' ) );
		add_action( 'wp_ajax_wp_mcp_ai_bulk_import_eca_info', array( __CLASS__, 'handle_bulk_import' ) );
		add_action( 'wp_ajax_wp_mcp_ai_upload_eca_document', array( __CLASS__, 'handle_document_upload' ) );
	}

	/**
	 * Add submenu page under ECAs menu.
	 */
	public static function add_menu_page() {
		add_submenu_page(
			'edit.php?post_type=mcp_ai_eca',
			__( 'Consolidate & Add Records', 'mcp-ai-wpoos-pro' ),
			__( 'Consolidate & Add', 'mcp-ai-wpoos-pro' ),
			'edit_posts',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Enqueue assets for the consolidation page.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function enqueue_assets( $hook ) {
		// Only load on our consolidation page.
		if ( 'mcp_ai_eca_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		// Enqueue chat assets.
		if ( class_exists( 'WP_MCP_AI_Shortcode' ) ) {
			$shortcode_instance = new WP_MCP_AI_Shortcode();
			$shortcode_instance->register_assets();
			wp_enqueue_style( WP_MCP_AI_Shortcode::STYLE_HANDLE );
			wp_enqueue_script( WP_MCP_AI_Shortcode::SCRIPT_HANDLE );
		}

		// Enqueue the shared research-page stylesheet so .wp-mcp-ai-research-chat is styled.
		wp_enqueue_style(
			'wp-mcp-ai-enhanced-research-page',
			WP_MCP_AI_URL . 'assets/css/enhanced-research-page.css',
			array(),
			WP_MCP_AI_VERSION
		);

		// Enqueue consolidation page specific styles.
		wp_enqueue_style(
			'wp-mcp-ai-eca-consolidate',
			WP_MCP_AI_PRO_URL . 'assets/css/eca-consolidate.css',
			array(),
			WP_MCP_AI_PRO_VERSION
		);

		// Enqueue consolidation page script.
		wp_enqueue_script(
			'wp-mcp-ai-eca-consolidate',
			WP_MCP_AI_PRO_URL . 'assets/js/eca-consolidate.js',
			array( 'jquery' ),
			WP_MCP_AI_PRO_VERSION,
			true
		);

		// Localize script.
		wp_localize_script(
			'wp-mcp-ai-eca-consolidate',
			'wpMcpAiEcaConsolidate',
			array(
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'nonce'         => wp_create_nonce( 'wp_mcp_ai_eca_consolidate' ),
				'ecasUrl'       => admin_url( 'edit.php?post_type=mcp_ai_eca' ),
				'addEcaUrl'     => admin_url( 'post-new.php?post_type=mcp_ai_eca' ),
				'addStudentUrl' => admin_url( 'post-new.php?post_type=mcp_ai_student' ),
				'strings'       => array(
					'loading'           => __( 'Loading ECA data...', 'mcp-ai-wpoos-pro' ),
					'loadEca'           => __( 'Load ECA Records', 'mcp-ai-wpoos-pro' ),
					'error'             => __( 'An error occurred. Please try again.', 'mcp-ai-wpoos-pro' ),
					'selectEca'         => __( 'Select an ECA to view its consolidated records.', 'mcp-ai-wpoos-pro' ),
					'noRecords'         => __( 'No records found for this ECA.', 'mcp-ai-wpoos-pro' ),
					'analyzing'         => __( 'Analyzing ECA completeness...', 'mcp-ai-wpoos-pro' ),
					'aiAssisting'       => __( 'AI is guiding you through ECA management...', 'mcp-ai-wpoos-pro' ),
					'enterEcaInfo'      => __( 'Please enter ECA information to import.', 'mcp-ai-wpoos-pro' ),
					'noDocGenAvailable' => __( 'Document Generation toolkit is not enabled. Enable it in Settings to unlock PDF, Word, and Excel document tools.', 'mcp-ai-wpoos-pro' ),
				),
			)
		);
	}

	/**
	 * Render the consolidation page.
	 */
	public static function render_page() {
		// Get assistant from settings.
		$settings     = get_option( 'wp_mcp_ai_eca_settings', array() );
		$assistant_id = isset( $settings['assistant_id'] ) ? absint( $settings['assistant_id'] ) : 0;

		// If no assistant configured or invalid, get the first available assistant.
		if ( ! $assistant_id || 'publish' !== get_post_status( $assistant_id ) ) {
			$assistants = get_posts(
				array(
					'post_type'      => 'mcp_ai_assistant',
					'post_status'    => 'publish',
					'posts_per_page' => 1,
					'orderby'        => 'date',
					'order'          => 'DESC',
				)
			);

			$assistant_id = ! empty( $assistants ) ? $assistants[0]->ID : 0;
		}

		// Get all ECAs for the dropdown.
		$ecas = get_posts(
			array(
				'post_type'      => 'mcp_ai_eca',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		// Check if Document Generation toolkit is enabled.
		$dg_settings     = get_option( 'wp_mcp_ai_settings', array() );
		$doc_gen_enabled = ! empty( $dg_settings['enable_document_generation_toolkit'] );

		?>
		<div class="wrap wp-mcp-ai-eca-consolidate-page">
			<h1 class="wp-heading-inline">
				<?php esc_html_e( 'Consolidate & Add ECA Records', 'mcp-ai-wpoos-pro' ); ?>
			</h1>

			<hr class="wp-header-end">

			<div class="wp-mcp-ai-consolidate-container">
				<div class="wp-mcp-ai-consolidate-sidebar">
					<div class="wp-mcp-ai-consolidate-intro">
						<h2><?php esc_html_e( 'How It Works', 'mcp-ai-wpoos-pro' ); ?></h2>
						<ol>
							<li><?php esc_html_e( 'Select an ECA to view its complete profile', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Review the consolidated view of all ECA data', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'AI identifies missing or incomplete information', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Follow the guided flow to add necessary records', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Generate reports and documents for distribution', 'mcp-ai-wpoos-pro' ); ?></li>
						</ol>
					</div>

					<div class="wp-mcp-ai-eca-selector">
						<h3><?php esc_html_e( 'Select ECA', 'mcp-ai-wpoos-pro' ); ?></h3>
						<?php if ( ! empty( $ecas ) ) : ?>
							<select id="wp-mcp-ai-eca-select" class="widefat">
								<option value=""><?php esc_html_e( '-- Select an ECA --', 'mcp-ai-wpoos-pro' ); ?></option>
								<?php foreach ( $ecas as $eca ) : ?>
									<?php
									$eca_category = get_post_meta( $eca->ID, 'category', true );
									?>
									<option value="<?php echo esc_attr( $eca->ID ); ?>">
										<?php
										echo esc_html( $eca->post_title );
										if ( $eca_category ) {
											echo ' (' . esc_html( ucfirst( $eca_category ) ) . ')';
										}
										?>
									</option>
								<?php endforeach; ?>
							</select>
							<p>
								<button type="button" id="wp-mcp-ai-load-eca-btn" class="button button-primary">
									<?php esc_html_e( 'Load ECA Records', 'mcp-ai-wpoos-pro' ); ?>
								</button>
							</p>
						<?php else : ?>
							<p class="description">
								<?php
								echo wp_kses_post(
									sprintf(
										/* translators: %s: Link to add ECA */
										__( 'No ECAs found. <a href="%s">Create an ECA</a> first.', 'mcp-ai-wpoos-pro' ),
										admin_url( 'post-new.php?post_type=mcp_ai_eca' )
									)
								);
								?>
							</p>
						<?php endif; ?>
					</div>

					<div class="wp-mcp-ai-consolidate-tips">
						<h3><?php esc_html_e( 'Consolidation Tips', 'mcp-ai-wpoos-pro' ); ?></h3>
						<ul>
							<li><strong><?php esc_html_e( 'Complete profiles:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Ensure each ECA has schedule, capacity, location, and instructor details', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><strong><?php esc_html_e( 'Regular updates:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Update enrollment numbers, schedules, and attendance as they change', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><strong><?php esc_html_e( 'AI guidance:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Let the AI assistant help identify gaps and suggest improvements', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><strong><?php esc_html_e( 'Track attendance:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Regularly mark and review attendance records', 'mcp-ai-wpoos-pro' ); ?></li>
						</ul>
					</div>

					<?php if ( $doc_gen_enabled ) : ?>
					<div class="wp-mcp-ai-document-tools-info">
						<h3><?php esc_html_e( '📄 Document Processing Tools', 'mcp-ai-wpoos-pro' ); ?></h3>
						<p><strong><?php esc_html_e( 'The AI assistant now has access to 13 document tools:', 'mcp-ai-wpoos-pro' ); ?></strong></p>
						<ul>
							<li><?php esc_html_e( 'Extract text from PDFs (schedules, curricula)', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Generate ECA reports (PDF, Word, Excel)', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Import/export ECA data from spreadsheets', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Merge multiple ECA documents', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Add branding and watermarks', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Generate ECA fee/invoice documents', 'mcp-ai-wpoos-pro' ); ?></li>
						</ul>
						<p>
							<em><?php esc_html_e( 'Try: "Extract text from this ECA schedule PDF" or "Generate an attendance report for this ECA"', 'mcp-ai-wpoos-pro' ); ?></em>
						</p>
					</div>
					<?php else : ?>
					<div class="wp-mcp-ai-document-tools-info" style="background: #fff8e5; border-left: 4px solid #ffb900;">
						<h3><?php esc_html_e( '📄 Document Generation Not Enabled', 'mcp-ai-wpoos-pro' ); ?></h3>
						<p><?php esc_html_e( 'Enable the Document Generation toolkit in Settings to unlock PDF, Word, and Excel document tools for ECA reports, schedules, and data extraction.', 'mcp-ai-wpoos-pro' ); ?></p>
						<p>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-document-generation-toolkit-settings' ) ); ?>" class="button button-small">
								<?php esc_html_e( 'Enable Document Generation', 'mcp-ai-wpoos-pro' ); ?>
							</a>
						</p>
					</div>
					<?php endif; ?>

					<div class="wp-mcp-ai-consolidate-actions">
						<h3><?php esc_html_e( 'Quick Actions', 'mcp-ai-wpoos-pro' ); ?></h3>
						<p>
							<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_eca' ) ); ?>" class="button">
								<?php esc_html_e( 'View All ECAs', 'mcp-ai-wpoos-pro' ); ?>
							</a>
						</p>
						<p>
							<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=mcp_ai_eca' ) ); ?>" class="button">
								<?php esc_html_e( 'Add New ECA', 'mcp-ai-wpoos-pro' ); ?>
							</a>
						</p>
						<p>
							<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_eca&page=research-eca' ) ); ?>" class="button">
								<span class="dashicons dashicons-search" style="vertical-align: middle;"></span>
								<?php esc_html_e( 'Research & Add', 'mcp-ai-wpoos-pro' ); ?>
							</a>
						</p>
					</div>
				</div>

				<div class="wp-mcp-ai-consolidate-main">
					<!-- Workflow Mode Selector -->
					<div class="wp-mcp-ai-workflow-selector">
						<h2><?php esc_html_e( 'Choose Your Workflow', 'mcp-ai-wpoos-pro' ); ?></h2>
						<div class="workflow-options">
							<button type="button" class="workflow-option active" data-workflow="ai">
								<span class="dashicons dashicons-format-chat"></span>
								<strong><?php esc_html_e( 'AI Research', 'mcp-ai-wpoos-pro' ); ?></strong>
								<p><?php esc_html_e( 'Research and manage ECAs with AI assistance', 'mcp-ai-wpoos-pro' ); ?></p>
							</button>
							<button type="button" class="workflow-option" data-workflow="bulk">
								<span class="dashicons dashicons-upload"></span>
								<strong><?php esc_html_e( 'Quick Import', 'mcp-ai-wpoos-pro' ); ?></strong>
								<p><?php esc_html_e( 'Bulk import ECA data - AI organizes it', 'mcp-ai-wpoos-pro' ); ?></p>
							</button>
							<button type="button" class="workflow-option" data-workflow="guided">
								<span class="dashicons dashicons-list-view"></span>
								<strong><?php esc_html_e( 'Guided Entry', 'mcp-ai-wpoos-pro' ); ?></strong>
								<p><?php esc_html_e( 'Step-by-step with AI assistance', 'mcp-ai-wpoos-pro' ); ?></p>
							</button>
							<button type="button" class="workflow-option" data-workflow="review">
								<span class="dashicons dashicons-visibility"></span>
								<strong><?php esc_html_e( 'Review & Consolidate', 'mcp-ai-wpoos-pro' ); ?></strong>
								<p><?php esc_html_e( 'View and manage existing records', 'mcp-ai-wpoos-pro' ); ?></p>
							</button>
						</div>
					</div>

					<!-- AI Research Mode (default) -->
					<div id="workflow-ai" class="workflow-content active">
						<div class="wp-mcp-ai-research-chat">
							<?php if ( $assistant_id > 0 ) : ?>
								<?php
								// Render chat interface with all ECA tools and document generation tools.
								$eca_consolidate_tools = self::CHAT_TOOLS;
								echo do_shortcode(
									'[mcp_ai_chat assistant="' . absint( $assistant_id ) . '" additional_tools="' . esc_attr( implode( ',', $eca_consolidate_tools ) ) . '"]'
								);
								?>
							<?php else : ?>
								<div class="notice notice-error inline">
									<p>
										<?php
										echo wp_kses_post(
											sprintf(
												/* translators: %s: Link to create assistant */
												__( 'No AI assistant found. Please <a href="%s">create an assistant</a> first to enable AI-guided ECA management.', 'mcp-ai-wpoos-pro' ),
												admin_url( 'post-new.php?post_type=mcp_ai_assistant' )
											)
										);
										?>
									</p>
								</div>
							<?php endif; ?>
						</div>
					</div>

					<!-- Quick Import Mode -->
					<div id="workflow-bulk" class="workflow-content" style="display: none;">
						<div class="wp-mcp-ai-bulk-import-section">
							<h2><?php esc_html_e( 'Quick Import - Dump Everything Here', 'mcp-ai-wpoos-pro' ); ?></h2>
							<p class="description">
								<?php esc_html_e( 'Paste or type all your ECA information below, or upload documents (PDFs, schedules, spreadsheets). The AI will automatically parse, categorize, and organize it into structured records. Original files are preserved in the media library for future validation and auditing.', 'mcp-ai-wpoos-pro' ); ?>
							</p>

							<div class="bulk-import-tips">
								<h4><?php esc_html_e( 'Tips for better results:', 'mcp-ai-wpoos-pro' ); ?></h4>
								<ul>
									<li><?php esc_html_e( '✓ Include dates when available (e.g., "starts 9/1/2024")', 'mcp-ai-wpoos-pro' ); ?></li>
									<li><?php esc_html_e( '✓ Mention ECA type keywords: sports, academic, arts, STEM, music', 'mcp-ai-wpoos-pro' ); ?></li>
									<li><?php esc_html_e( '✓ Add instructor/supervisor names when known', 'mcp-ai-wpoos-pro' ); ?></li>
									<li><?php esc_html_e( '✓ Separate different ECAs with blank lines', 'mcp-ai-wpoos-pro' ); ?></li>
									<li><?php esc_html_e( '✓ Upload original documents - they will be kept as attachments', 'mcp-ai-wpoos-pro' ); ?></li>
								</ul>
							</div>

							<div class="bulk-import-form">
								<!-- File Upload Section -->
								<div class="bulk-import-file-section">
									<h3><?php esc_html_e( 'Upload Documents (Optional)', 'mcp-ai-wpoos-pro' ); ?></h3>
									<p class="description">
										<?php esc_html_e( 'Upload ECA schedules, curricula, registration forms, etc. Original files are preserved in your media library for compliance and future reference.', 'mcp-ai-wpoos-pro' ); ?>
									</p>
									<div class="file-upload-area">
										<input type="file" id="wp-mcp-ai-eca-file-upload" multiple accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.txt,.csv,.xlsx" style="display: none;">
										<button type="button" id="wp-mcp-ai-eca-file-upload-btn" class="button">
											<span class="dashicons dashicons-upload"></span>
											<?php esc_html_e( 'Choose Files to Upload', 'mcp-ai-wpoos-pro' ); ?>
										</button>
										<span class="file-upload-note"><?php esc_html_e( 'Accepted: PDF, JPG, PNG, DOC, DOCX, TXT, CSV, XLSX', 'mcp-ai-wpoos-pro' ); ?></span>
									</div>
									<div id="wp-mcp-ai-eca-file-list" class="file-upload-list" style="display: none;">
										<h4><?php esc_html_e( 'Files to Upload:', 'mcp-ai-wpoos-pro' ); ?></h4>
										<ul id="wp-mcp-ai-eca-file-items"></ul>
									</div>
								</div>

								<hr class="form-section-divider">

								<!-- Text Import Section -->
								<h3><?php esc_html_e( 'Or Paste/Type ECA Information', 'mcp-ai-wpoos-pro' ); ?></h3>
								<textarea
									id="wp-mcp-ai-eca-bulk-import-text"
									class="widefat"
									rows="12"
									placeholder="<?php esc_attr_e( 'Example:\nECA: Robotics Club\nCategory: STEM\nSchedule: Tuesdays 3:30-5:00 PM\nLocation: Room 204\nCapacity: 20 students\nInstructor: Mr. Johnson\n\nECA: Debate Team\nCategory: Academic\nSchedule: Thursdays 4:00-5:30 PM\nLocation: Library\nCapacity: 15 students\nInstructor: Ms. Williams', 'mcp-ai-wpoos-pro' ); ?>"
								></textarea>

								<div class="bulk-import-options">
									<label>
										<input type="checkbox" id="wp-mcp-ai-eca-bulk-auto-create" checked>
										<?php esc_html_e( 'Automatically create ECAs (recommended)', 'mcp-ai-wpoos-pro' ); ?>
									</label>
									<label>
										<input type="checkbox" id="wp-mcp-ai-eca-bulk-require-confirmation">
										<?php esc_html_e( 'Review before creating (for meticulous users)', 'mcp-ai-wpoos-pro' ); ?>
									</label>
								</div>

								<p>
									<button type="button" id="wp-mcp-ai-eca-bulk-import-btn" class="button button-primary button-large">
										<span class="dashicons dashicons-update"></span>
										<?php esc_html_e( 'Import & Organize with AI', 'mcp-ai-wpoos-pro' ); ?>
									</button>
									<button type="button" id="wp-mcp-ai-eca-bulk-clear-btn" class="button button-secondary">
										<?php esc_html_e( 'Clear', 'mcp-ai-wpoos-pro' ); ?>
									</button>
								</p>
								<div id="wp-mcp-ai-eca-bulk-import-result" class="bulk-import-result" style="display: none;"></div>
							</div>
						</div>
					</div>

					<!-- Guided Entry Mode -->
					<div id="workflow-guided" class="workflow-content" style="display: none;">
						<div class="wp-mcp-ai-guided-section">
							<h2><?php esc_html_e( 'Guided ECA Entry', 'mcp-ai-wpoos-pro' ); ?></h2>
							<p class="description">
								<?php esc_html_e( 'Follow the step-by-step process to add ECA records. The AI will guide you through each field and ensure all necessary information is captured.', 'mcp-ai-wpoos-pro' ); ?>
							</p>

							<div class="guided-steps">
								<div class="step-selector">
									<h3><?php esc_html_e( 'What would you like to add?', 'mcp-ai-wpoos-pro' ); ?></h3>
									<div class="record-type-buttons">
										<button type="button" class="record-type-btn" data-type="eca">
											<span class="dashicons dashicons-calendar-alt"></span>
											<?php esc_html_e( 'New ECA', 'mcp-ai-wpoos-pro' ); ?>
										</button>
										<button type="button" class="record-type-btn" data-type="student">
											<span class="dashicons dashicons-groups"></span>
											<?php esc_html_e( 'New Student', 'mcp-ai-wpoos-pro' ); ?>
										</button>
										<button type="button" class="record-type-btn" data-type="enrollment">
											<span class="dashicons dashicons-yes-alt"></span>
											<?php esc_html_e( 'Enroll Student', 'mcp-ai-wpoos-pro' ); ?>
										</button>
										<button type="button" class="record-type-btn" data-type="attendance">
											<span class="dashicons dashicons-clipboard"></span>
											<?php esc_html_e( 'Mark Attendance', 'mcp-ai-wpoos-pro' ); ?>
										</button>
										<button type="button" class="record-type-btn" data-type="term">
											<span class="dashicons dashicons-backup"></span>
											<?php esc_html_e( 'Manage Term', 'mcp-ai-wpoos-pro' ); ?>
										</button>
									</div>
								</div>

								<div id="guided-form-container" class="guided-form-container" style="display: none;">
									<!-- Dynamic form will be loaded here -->
								</div>
							</div>
						</div>
					</div>

					<!-- Review & Consolidate Mode -->
					<div id="workflow-review" class="workflow-content" style="display: none;">

						<div id="wp-mcp-ai-eca-records-preview" class="wp-mcp-ai-records-preview" style="display: none;">
							<!-- ECA preview will be loaded here via AJAX -->
						</div>

						<div id="wp-mcp-ai-eca-no-selection" class="notice notice-info inline">
							<p><?php esc_html_e( 'Select an ECA from the sidebar to view its consolidated records.', 'mcp-ai-wpoos-pro' ); ?></p>
						</div>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Handle AJAX request to get ECA records preview.
	 */
	public static function handle_get_eca_preview() {
		// Verify nonce.
		check_ajax_referer( 'wp_mcp_ai_eca_consolidate', 'nonce' );

		// Check user capability.
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to view ECA records.', 'mcp-ai-wpoos-pro' ) ) );
		}

		$eca_id = isset( $_POST['eca_id'] ) ? absint( $_POST['eca_id'] ) : 0;

		if ( ! $eca_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid ECA ID.', 'mcp-ai-wpoos-pro' ) ) );
		}

		$eca = get_post( $eca_id );

		if ( ! $eca || 'mcp_ai_eca' !== $eca->post_type ) {
			wp_send_json_error( array( 'message' => __( 'ECA not found.', 'mcp-ai-wpoos-pro' ) ) );
		}

		// Gather ECA data.
		$category   = get_post_meta( $eca_id, 'category', true );
		$schedule   = get_post_meta( $eca_id, 'schedule', true );
		$location   = get_post_meta( $eca_id, 'location', true );
		$capacity   = get_post_meta( $eca_id, 'capacity', true );
		$instructor = get_post_meta( $eca_id, 'instructor', true );
		$enrolled   = get_post_meta( $eca_id, 'enrolled_count', true );
		$term       = get_post_meta( $eca_id, 'term', true );

		wp_send_json_success(
			array(
				'eca' => array(
					'id'         => $eca_id,
					'title'      => $eca->post_title,
					'content'    => wp_kses_post( $eca->post_content ),
					'category'   => $category,
					'schedule'   => $schedule,
					'location'   => $location,
					'capacity'   => $capacity,
					'instructor' => $instructor,
					'enrolled'   => $enrolled,
					'term'       => $term,
					'status'     => $eca->post_status,
					'edit_url'   => admin_url( 'post.php?post=' . $eca_id . '&action=edit' ),
				),
			)
		);
	}

	/**
	 * Handle AJAX request to check ECA data completeness.
	 */
	public static function handle_check_eca_completeness() {
		// Verify nonce.
		check_ajax_referer( 'wp_mcp_ai_eca_consolidate', 'nonce' );

		// Check user capability.
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission.', 'mcp-ai-wpoos-pro' ) ) );
		}

		$eca_id = isset( $_POST['eca_id'] ) ? absint( $_POST['eca_id'] ) : 0;

		if ( ! $eca_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid ECA ID.', 'mcp-ai-wpoos-pro' ) ) );
		}

		$eca = get_post( $eca_id );

		// Analyze completeness.
		$missing  = array();
		$complete = 0;
		$total    = 8; // Title, content, category, schedule, location, capacity, instructor, term.

		if ( empty( $eca->post_title ) ) {
			$missing[] = __( 'Title', 'mcp-ai-wpoos-pro' );
		} else {
			++$complete;
		}

		if ( empty( $eca->post_content ) ) {
			$missing[] = __( 'Description', 'mcp-ai-wpoos-pro' );
		} else {
			++$complete;
		}

		$fields = array( 'category', 'schedule', 'location', 'capacity', 'instructor', 'term' );
		foreach ( $fields as $field ) {
			$value = get_post_meta( $eca_id, $field, true );
			if ( empty( $value ) ) {
				$missing[] = ucfirst( $field );
			} else {
				++$complete;
			}
		}

		$percentage = $total > 0 ? round( ( $complete / $total ) * 100 ) : 0;

		wp_send_json_success(
			array(
				'percentage'  => $percentage,
				'complete'    => $complete,
				'total'       => $total,
				'missing'     => $missing,
				'suggestions' => array(
					__( 'Add a detailed description of the activity', 'mcp-ai-wpoos-pro' ),
					__( 'Specify the schedule and location', 'mcp-ai-wpoos-pro' ),
					__( 'Set capacity limits and instructor details', 'mcp-ai-wpoos-pro' ),
					__( 'Assign to a term or season', 'mcp-ai-wpoos-pro' ),
				),
			)
		);
	}

	/**
	 * Handle AJAX bulk import request.
	 */
	public static function handle_bulk_import() {
		// Verify nonce.
		check_ajax_referer( 'wp_mcp_ai_eca_consolidate', 'nonce' );

		// Check user capability.
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to import ECAs.', 'mcp-ai-wpoos-pro' ) ) );
		}

		// Get import text from request.
		$import_text = isset( $_POST['import_text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['import_text'] ) ) : '';

		if ( empty( $import_text ) ) {
			wp_send_json_error( array( 'message' => __( 'No import data provided.', 'mcp-ai-wpoos-pro' ) ) );
		}

		// Return the import data for AI processing.
		wp_send_json_success(
			array(
				'message'     => __( 'ECA data received. Use the AI chat to process and create ECAs from this data.', 'mcp-ai-wpoos-pro' ),
				'import_text' => $import_text,
			)
		);
	}

	/**
	 * Handle AJAX document upload request.
	 */
	public static function handle_document_upload() {
		// Verify nonce.
		check_ajax_referer( 'wp_mcp_ai_eca_consolidate', 'nonce' );

		// Check user capability.
		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to upload files.', 'mcp-ai-wpoos-pro' ) ) );
		}

		if ( empty( $_FILES['file'] ) ) {
			wp_send_json_error( array( 'message' => __( 'No file uploaded.', 'mcp-ai-wpoos-pro' ) ) );
		}

		// Use WordPress media upload.
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$attachment_id = media_handle_upload( 'file', 0 );

		if ( is_wp_error( $attachment_id ) ) {
			wp_send_json_error( array( 'message' => $attachment_id->get_error_message() ) );
		}

		$attachment_url = wp_get_attachment_url( $attachment_id );

		wp_send_json_success(
			array(
				'message'       => __( 'File uploaded successfully.', 'mcp-ai-wpoos-pro' ),
				'attachment_id' => $attachment_id,
				'url'           => $attachment_url,
			)
		);
	}
}

// Initialize.
WP_MCP_AI_ECA_Consolidate_Page::init();
