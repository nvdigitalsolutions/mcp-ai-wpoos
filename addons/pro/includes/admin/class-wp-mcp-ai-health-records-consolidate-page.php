<?php
/**
 * Health Records Consolidate & Add Page
 *
 * Provides a unified interface for viewing and managing all health-related records
 * for members with an agentic flow to guide users through adding necessary records.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Health Records Consolidation Admin Page
 */
class WP_MCP_AI_Health_Records_Consolidate_Page {

	/**
	 * Page slug.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'health-records-consolidate';

	/**
	 * Default tools for health consolidation chat interface.
	 *
	 * @var array
	 */
	const CHAT_TOOLS = array(
		// Member management.
		'create_member',
		'get_member',
		'list_members',
		'update_member',
		'delete_member',
		'get_member_health_summary',
		// Medical records.
		'create_medical_record',
		'get_medical_record',
		'list_medical_records',
		'update_medical_record',
		'delete_medical_record',
		'search_medical_records',
		// Checkups.
		'create_checkup',
		'get_checkup',
		'list_checkups',
		'update_checkup',
		'delete_checkup',
		'get_upcoming_checkups',
		// Prescriptions.
		'create_prescription',
		'get_prescription',
		'list_prescriptions',
		'update_prescription',
		'delete_prescription',
		'search_prescriptions',
		// Allergies.
		'create_allergy',
		'get_allergy',
		'list_allergies',
		'update_allergy',
		'delete_allergy',
		// Health tools.
		'generate_health_chart',
		'guide_health_record_creation',
		'parse_health_information',
		// Research tools.
		'web_search',
		'search_content',
		'semantic_content_search',
	);

	/**
	 * Initialize the page.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu_page' ), 25 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_ajax_wp_mcp_ai_get_member_records_preview', array( __CLASS__, 'handle_get_member_preview' ) );
		add_action( 'wp_ajax_wp_mcp_ai_check_record_completeness', array( __CLASS__, 'handle_check_completeness' ) );
		add_action( 'wp_ajax_wp_mcp_ai_bulk_import_health_info', array( __CLASS__, 'handle_bulk_import' ) );
		add_action( 'wp_ajax_wp_mcp_ai_upload_health_document', array( __CLASS__, 'handle_document_upload' ) );
	}

	/**
	 * Add submenu page under Health & Wellness menu.
	 */
	public static function add_menu_page() {
		add_submenu_page(
			'edit.php?post_type=mcp_ai_member',
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
		if ( 'mcp_ai_member_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		// Enqueue chat assets.
		if ( class_exists( 'WP_MCP_AI_Shortcode' ) ) {
			$shortcode_instance = new WP_MCP_AI_Shortcode();
			$shortcode_instance->register_assets();
			wp_enqueue_style( WP_MCP_AI_Shortcode::STYLE_HANDLE );
			wp_enqueue_script( WP_MCP_AI_Shortcode::SCRIPT_HANDLE );
		}

		// Enqueue consolidation page specific styles.
		wp_enqueue_style(
			'wp-mcp-ai-health-consolidate',
			WP_MCP_AI_PRO_URL . 'assets/css/health-consolidate.css',
			array(),
			WP_MCP_AI_PRO_VERSION
		);

		// Enqueue consolidation page script.
		wp_enqueue_script(
			'wp-mcp-ai-health-consolidate',
			WP_MCP_AI_PRO_URL . 'assets/js/health-consolidate.js',
			array( 'jquery', 'wp-api' ),
			WP_MCP_AI_PRO_VERSION,
			true
		);

		// Localize script.
		wp_localize_script(
			'wp-mcp-ai-health-consolidate',
			'wpMcpAiHealthConsolidate',
			array(
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'nonce'         => wp_create_nonce( 'wp_mcp_ai_health_consolidate' ),
				'membersUrl'    => admin_url( 'edit.php?post_type=mcp_ai_member' ),
				'addMemberUrl'  => admin_url( 'post-new.php?post_type=mcp_ai_member' ),
				'addRecordUrl'  => admin_url( 'post-new.php?post_type=mcp_ai_med_record' ),
				'addCheckupUrl' => admin_url( 'post-new.php?post_type=mcp_ai_checkup' ),
				'addPrescUrl'   => admin_url( 'post-new.php?post_type=mcp_ai_prescription' ),
				'addAllergyUrl' => admin_url( 'post-new.php?post_type=mcp_ai_allergy' ),
				'addPolicyUrl'  => admin_url( 'post-new.php?post_type=mcp_ai_policy' ),
				'strings'       => array(
					'loading'         => __( 'Loading member data...', 'mcp-ai-wpoos-pro' ),
					'loadMember'      => __( 'Load Member Records', 'mcp-ai-wpoos-pro' ),
					'error'           => __( 'An error occurred. Please try again.', 'mcp-ai-wpoos-pro' ),
					'selectMember'    => __( 'Select a member to view their health records.', 'mcp-ai-wpoos-pro' ),
					'noRecords'       => __( 'No records found for this member.', 'mcp-ai-wpoos-pro' ),
					'analyzing'       => __( 'Analyzing record completeness...', 'mcp-ai-wpoos-pro' ),
					'aiAssisting'     => __( 'AI is guiding you through record creation...', 'mcp-ai-wpoos-pro' ),
					'enterHealthInfo' => __( 'Please enter health information to import.', 'mcp-ai-wpoos-pro' ),
				),
			)
		);
	}

	/**
	 * Render the consolidation page.
	 */
	public static function render_page() {
		// Get assistant from settings.
		$settings     = get_option( 'wp_mcp_ai_member_settings', array() );
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

		// Get all members for the dropdown.
		$members = get_posts(
			array(
				'post_type'      => 'mcp_ai_member',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		?>
		<div class="wrap wp-mcp-ai-health-consolidate-page">
			<h1 class="wp-heading-inline">
				<?php esc_html_e( 'Consolidate & Add Health Records', 'mcp-ai-wpoos-pro' ); ?>
			</h1>

			<hr class="wp-header-end">

			<div class="wp-mcp-ai-consolidate-container">
				<div class="wp-mcp-ai-consolidate-sidebar">
					<div class="wp-mcp-ai-consolidate-intro">
						<h2><?php esc_html_e( 'How It Works', 'mcp-ai-wpoos-pro' ); ?></h2>
						<ol>
							<li><?php esc_html_e( 'Select a member to view their complete health profile', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Review the consolidated view of all health records', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'AI identifies missing or incomplete records', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Follow the guided flow to add necessary records', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Maintain a thorough dataset for each member', 'mcp-ai-wpoos-pro' ); ?></li>
						</ol>
					</div>

					<div class="wp-mcp-ai-member-selector">
						<h3><?php esc_html_e( 'Select Member', 'mcp-ai-wpoos-pro' ); ?></h3>
						<?php if ( ! empty( $members ) ) : ?>
							<select id="wp-mcp-ai-member-select" class="widefat">
								<option value=""><?php esc_html_e( '-- Select a Member --', 'mcp-ai-wpoos-pro' ); ?></option>
								<?php foreach ( $members as $member ) : ?>
									<?php
									$member_types = wp_get_object_terms( $member->ID, 'mcp_ai_member_type', array( 'fields' => 'names' ) );
									$member_type  = ! empty( $member_types ) && ! is_wp_error( $member_types ) ? $member_types[0] : '';
									?>
									<option value="<?php echo esc_attr( $member->ID ); ?>">
										<?php
										echo esc_html( $member->post_title );
										if ( $member_type ) {
											echo ' (' . esc_html( ucfirst( $member_type ) ) . ')';
										}
										?>
									</option>
								<?php endforeach; ?>
							</select>
							<p>
								<button type="button" id="wp-mcp-ai-load-member-btn" class="button button-primary">
									<?php esc_html_e( 'Load Member Records', 'mcp-ai-wpoos-pro' ); ?>
								</button>
							</p>
						<?php else : ?>
							<p class="description">
								<?php
								echo wp_kses_post(
									sprintf(
										/* translators: %s: Link to add member */
										__( 'No members found. <a href="%s">Create a member</a> first.', 'mcp-ai-wpoos-pro' ),
										admin_url( 'post-new.php?post_type=mcp_ai_member' )
									)
								);
								?>
							</p>
						<?php endif; ?>
					</div>

					<div class="wp-mcp-ai-consolidate-tips">
						<h3><?php esc_html_e( 'Consolidation Tips', 'mcp-ai-wpoos-pro' ); ?></h3>
						<ul>
							<li><strong><?php esc_html_e( 'Complete profiles:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Ensure each member has all critical health information', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><strong><?php esc_html_e( 'Regular updates:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Add new medical records, checkups, and prescriptions as they occur', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><strong><?php esc_html_e( 'AI guidance:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Let the AI assistant help identify gaps and guide record creation', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><strong><?php esc_html_e( 'Track allergies:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Always document all known allergies and reactions', 'mcp-ai-wpoos-pro' ); ?></li>
						</ul>
					</div>

					<div class="wp-mcp-ai-consolidate-actions">
						<h3><?php esc_html_e( 'Quick Actions', 'mcp-ai-wpoos-pro' ); ?></h3>
						<p>
							<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_member' ) ); ?>" class="button">
								<?php esc_html_e( 'View All Members', 'mcp-ai-wpoos-pro' ); ?>
							</a>
						</p>
						<p>
							<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=mcp_ai_member' ) ); ?>" class="button">
								<?php esc_html_e( 'Add New Member', 'mcp-ai-wpoos-pro' ); ?>
							</a>
						</p>
					</div>
				</div>

				<div class="wp-mcp-ai-consolidate-main">
					<!-- Workflow Mode Selector -->
					<div class="wp-mcp-ai-workflow-selector">
						<h2><?php esc_html_e( 'Choose Your Workflow', 'mcp-ai-wpoos-pro' ); ?></h2>
						<div class="workflow-options">
							<button type="button" class="workflow-option active" data-workflow="bulk">
								<span class="dashicons dashicons-upload"></span>
								<strong><?php esc_html_e( 'Quick Import', 'mcp-ai-wpoos-pro' ); ?></strong>
								<p><?php esc_html_e( 'Dump everything - AI organizes it', 'mcp-ai-wpoos-pro' ); ?></p>
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

					<!-- Quick Import Mode -->
					<div id="workflow-bulk" class="workflow-content active">
						<div class="wp-mcp-ai-bulk-import-section">
							<h2><?php esc_html_e( 'Quick Import - Dump Everything Here', 'mcp-ai-wpoos-pro' ); ?></h2>
							<p class="description">
								<?php esc_html_e( 'Paste or type all your health information below, or upload documents (PDFs, images, scans). The AI will automatically parse, categorize, and organize it into structured records. Original files are preserved in the media library for future validation and auditing.', 'mcp-ai-wpoos-pro' ); ?>
							</p>
							
							<div class="bulk-import-tips">
								<h4><?php esc_html_e( 'Tips for better results:', 'mcp-ai-wpoos-pro' ); ?></h4>
								<ul>
									<li><?php esc_html_e( '✓ Include dates when available (e.g., "diagnosed 3/15/2024")', 'mcp-ai-wpoos-pro' ); ?></li>
									<li><?php esc_html_e( '✓ Mention record type keywords: allergy, prescription, checkup, diagnosis, policy', 'mcp-ai-wpoos-pro' ); ?></li>
									<li><?php esc_html_e( '✓ Add doctor/provider names when known (e.g., "Dr. Smith")', 'mcp-ai-wpoos-pro' ); ?></li>
									<li><?php esc_html_e( '✓ Separate different items with blank lines', 'mcp-ai-wpoos-pro' ); ?></li>
									<li><?php esc_html_e( '✓ Upload original documents - they will be kept as attachments', 'mcp-ai-wpoos-pro' ); ?></li>
								</ul>
							</div>

							<div class="bulk-import-form">
								<!-- File Upload Section -->
								<div class="bulk-import-file-section">
									<h3><?php esc_html_e( 'Upload Documents (Optional)', 'mcp-ai-wpoos-pro' ); ?></h3>
									<p class="description">
										<?php esc_html_e( 'Upload medical records, test results, prescription images, insurance cards, etc. Original files are preserved in your media library for compliance and future reference.', 'mcp-ai-wpoos-pro' ); ?>
									</p>
									<div class="file-upload-area">
										<input type="file" id="wp-mcp-ai-file-upload" multiple accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.txt" style="display: none;">
										<button type="button" id="wp-mcp-ai-file-upload-btn" class="button">
											<span class="dashicons dashicons-upload"></span>
											<?php esc_html_e( 'Choose Files to Upload', 'mcp-ai-wpoos-pro' ); ?>
										</button>
										<span class="file-upload-note"><?php esc_html_e( 'Accepted: PDF, JPG, PNG, DOC, DOCX, TXT', 'mcp-ai-wpoos-pro' ); ?></span>
									</div>
									<div id="wp-mcp-ai-file-list" class="file-upload-list" style="display: none;">
										<h4><?php esc_html_e( 'Files to Upload:', 'mcp-ai-wpoos-pro' ); ?></h4>
										<ul id="wp-mcp-ai-file-items"></ul>
									</div>
								</div>

								<hr class="form-section-divider">

								<!-- Text Import Section -->
								<h3><?php esc_html_e( 'Or Paste/Type Health Information', 'mcp-ai-wpoos-pro' ); ?></h3>
								<textarea 
									id="wp-mcp-ai-bulk-import-text" 
									class="widefat" 
									rows="12" 
									placeholder="<?php esc_attr_e( 'Example:\nAllergy: Peanuts - severe reaction, causes anaphylaxis\n\nPrescription: Lisinopril 10mg daily for blood pressure\nStarted 1/10/2024, Dr. Johnson\n\nCheckup: Annual physical scheduled for 3/15/2024 with Dr. Smith at Main Street Clinic\n\nDiagnosis: Hypertension diagnosed 1/10/2024\nTreatment: medication and lifestyle changes\n\nInsurance: Blue Cross PPO policy #12345\nProvider: Blue Cross Blue Shield', 'mcp-ai-wpoos-pro' ); ?>"
								></textarea>
								
								<div class="bulk-import-options">
									<label>
										<input type="checkbox" id="wp-mcp-ai-bulk-auto-create" checked>
										<?php esc_html_e( 'Automatically create records (recommended)', 'mcp-ai-wpoos-pro' ); ?>
									</label>
									<label>
										<input type="checkbox" id="wp-mcp-ai-bulk-require-confirmation">
										<?php esc_html_e( 'Review before creating (for meticulous users)', 'mcp-ai-wpoos-pro' ); ?>
									</label>
								</div>

								<p>
									<button type="button" id="wp-mcp-ai-bulk-import-btn" class="button button-primary button-large">
										<span class="dashicons dashicons-update"></span>
										<?php esc_html_e( 'Import & Organize with AI', 'mcp-ai-wpoos-pro' ); ?>
									</button>
									<button type="button" id="wp-mcp-ai-bulk-clear-btn" class="button button-secondary">
										<?php esc_html_e( 'Clear', 'mcp-ai-wpoos-pro' ); ?>
									</button>
								</p>
								<div id="wp-mcp-ai-bulk-import-result" class="bulk-import-result" style="display: none;"></div>
							</div>
						</div>
					</div>

					<!-- Guided Entry Mode -->
					<div id="workflow-guided" class="workflow-content" style="display: none;">
						<div class="wp-mcp-ai-guided-section">
							<h2><?php esc_html_e( 'Guided Record Entry', 'mcp-ai-wpoos-pro' ); ?></h2>
							<p class="description">
								<?php esc_html_e( 'Follow the step-by-step process to add health records. The AI will guide you through each field and ensure all necessary information is captured.', 'mcp-ai-wpoos-pro' ); ?>
							</p>

							<div class="guided-steps">
								<div class="step-selector">
									<h3><?php esc_html_e( 'What would you like to add?', 'mcp-ai-wpoos-pro' ); ?></h3>
									<div class="record-type-buttons">
										<button type="button" class="record-type-btn" data-type="medical_record">
											<span class="dashicons dashicons-clipboard"></span>
											<?php esc_html_e( 'Medical Record', 'mcp-ai-wpoos-pro' ); ?>
										</button>
										<button type="button" class="record-type-btn" data-type="checkup">
											<span class="dashicons dashicons-calendar-alt"></span>
											<?php esc_html_e( 'Checkup/Appointment', 'mcp-ai-wpoos-pro' ); ?>
										</button>
										<button type="button" class="record-type-btn" data-type="prescription">
											<span class="dashicons dashicons-media-document"></span>
											<?php esc_html_e( 'Prescription', 'mcp-ai-wpoos-pro' ); ?>
										</button>
										<button type="button" class="record-type-btn" data-type="policy">
											<span class="dashicons dashicons-shield"></span>
											<?php esc_html_e( 'Insurance Policy', 'mcp-ai-wpoos-pro' ); ?>
										</button>
										<button type="button" class="record-type-btn" data-type="allergy">
											<span class="dashicons dashicons-warning"></span>
											<?php esc_html_e( 'Allergy', 'mcp-ai-wpoos-pro' ); ?>
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

						<div id="wp-mcp-ai-records-preview" class="wp-mcp-ai-records-preview" style="display: none;">
							<!-- Member preview will be loaded here via AJAX -->
						</div>

						<div id="wp-mcp-ai-no-selection" class="notice notice-info inline">
							<p><?php esc_html_e( 'Select a member from the sidebar to view their consolidated health records.', 'mcp-ai-wpoos-pro' ); ?></p>
						</div>
					</div>

					<hr class="wp-mcp-ai-section-divider">

					<?php if ( $assistant_id > 0 ) : ?>
						<div class="wp-mcp-ai-consolidate-ai-section">
							<h2><?php esc_html_e( 'AI Assistant for Record Management', 'mcp-ai-wpoos-pro' ); ?></h2>
							<p class="description">
								<?php esc_html_e( 'Use the AI assistant below to help you create and manage health records. The AI can guide you through adding missing records, suggest necessary checkups, and help maintain complete health profiles.', 'mcp-ai-wpoos-pro' ); ?>
							</p>
							<div class="wp-mcp-ai-consolidate-chat">
								<?php
								// Render chat interface with comprehensive health management tools.
								echo do_shortcode(
									'[mcp_ai_chat assistant="' . absint( $assistant_id ) . '" additional_tools="' . esc_attr( implode( ',', self::CHAT_TOOLS ) ) . '"]'
								);
								?>
							</div>
						</div>
					<?php else : ?>
						<div class="notice notice-error inline">
							<p>
								<?php
								echo wp_kses_post(
									sprintf(
										/* translators: %s: Link to create assistant */
										__( 'No AI assistant found. Please <a href="%s">create an assistant</a> first to enable AI-guided record management.', 'mcp-ai-wpoos-pro' ),
										admin_url( 'post-new.php?post_type=mcp_ai_assistant' )
									)
								);
								?>
							</p>
						</div>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Handle AJAX request to get member records preview.
	 */
	public static function handle_get_member_preview() {
		// Verify nonce.
		check_ajax_referer( 'wp_mcp_ai_health_consolidate', 'nonce' );

		// Check user capability.
		if ( ! current_user_can( 'read' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to view member records.', 'mcp-ai-wpoos-pro' ) ) );
		}

		// Get member ID.
		$member_id = isset( $_POST['member_id'] ) ? absint( $_POST['member_id'] ) : 0;

		if ( ! $member_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid member ID.', 'mcp-ai-wpoos-pro' ) ) );
		}

		// Use the get_member_health_summary tool to get comprehensive data.
		if ( ! class_exists( 'WP_MCP_AI_Tool_Get_Member_Health_Summary' ) ) {
			wp_send_json_error( array( 'message' => __( 'Health summary tool not available.', 'mcp-ai-wpoos-pro' ) ) );
		}

		$tool   = new WP_MCP_AI_Tool_Get_Member_Health_Summary();
		$result = $tool->execute(
			array(
				'member_id'       => $member_id,
				'include_records' => true,
			),
			array( 'user_id' => get_current_user_id() )
		);

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		// Get associated policy information.
		$policies_query = new WP_Query(
			array(
				'post_type'      => 'mcp_ai_policy',
				'post_status'    => 'publish',
				'meta_key'       => '_policy_member_id',
				'meta_value'     => $member_id,
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		$policies = array();
		if ( $policies_query->have_posts() ) {
			while ( $policies_query->have_posts() ) {
				$policies_query->the_post();
				$policy_id    = get_the_ID();
				$policy_types = wp_get_object_terms( $policy_id, 'mcp_ai_policy_type', array( 'fields' => 'names' ) );
				$policies[]   = array(
					'id'            => $policy_id,
					'name'          => get_the_title(),
					'type'          => ! empty( $policy_types ) && ! is_wp_error( $policy_types ) ? $policy_types[0] : '',
					'provider'      => get_post_meta( $policy_id, '_policy_provider', true ),
					'policy_number' => get_post_meta( $policy_id, '_policy_number', true ),
					'status'        => get_post_meta( $policy_id, '_policy_status', true ),
				);
			}
			wp_reset_postdata();
		}

		$result['policies'] = $policies;

		// Render the preview HTML.
		ob_start();
		self::render_member_preview( $result );
		$html = ob_get_clean();

		wp_send_json_success(
			array(
				'html'        => $html,
				'member_data' => $result,
			)
		);
	}

	/**
	 * Render member health records preview.
	 *
	 * @param array $data Member health data from get_member_health_summary.
	 */
	private static function render_member_preview( $data ) {
		$member = $data['member'];
		?>
		<div class="wp-mcp-ai-member-preview-header">
			<h2>
				<?php echo esc_html( $member['name'] ); ?>
				<span class="member-type-badge"><?php echo esc_html( ucfirst( $member['type'] ) ); ?></span>
			</h2>
			<div class="member-demographics">
				<?php if ( ! empty( $member['date_of_birth'] ) ) : ?>
					<div class="demo-item">
						<strong><?php esc_html_e( 'DOB:', 'mcp-ai-wpoos-pro' ); ?></strong>
						<?php echo esc_html( $member['date_of_birth'] ); ?>
					</div>
				<?php endif; ?>
				<?php if ( ! empty( $member['gender'] ) ) : ?>
					<div class="demo-item">
						<strong><?php esc_html_e( 'Gender:', 'mcp-ai-wpoos-pro' ); ?></strong>
						<?php echo esc_html( $member['gender'] ); ?>
					</div>
				<?php endif; ?>
				<?php if ( ! empty( $member['blood_type'] ) ) : ?>
					<div class="demo-item">
						<strong><?php esc_html_e( 'Blood Type:', 'mcp-ai-wpoos-pro' ); ?></strong>
						<?php echo esc_html( $member['blood_type'] ); ?>
					</div>
				<?php endif; ?>
				<?php if ( 'pet' === $member['type'] ) : ?>
					<?php if ( ! empty( $member['species'] ) ) : ?>
						<div class="demo-item">
							<strong><?php esc_html_e( 'Species:', 'mcp-ai-wpoos-pro' ); ?></strong>
							<?php echo esc_html( $member['species'] ); ?>
						</div>
					<?php endif; ?>
					<?php if ( ! empty( $member['breed'] ) ) : ?>
						<div class="demo-item">
							<strong><?php esc_html_e( 'Breed:', 'mcp-ai-wpoos-pro' ); ?></strong>
							<?php echo esc_html( $member['breed'] ); ?>
						</div>
					<?php endif; ?>
				<?php endif; ?>
			</div>
		</div>

		<div class="wp-mcp-ai-records-grid">
			<!-- Policies Section -->
			<div class="record-section">
				<h3>
					<span class="dashicons dashicons-shield"></span>
					<?php esc_html_e( 'Insurance Policies', 'mcp-ai-wpoos-pro' ); ?>
					<span class="count-badge"><?php echo esc_html( count( $data['policies'] ) ); ?></span>
				</h3>
				<?php if ( ! empty( $data['policies'] ) ) : ?>
					<ul class="record-list">
						<?php foreach ( $data['policies'] as $policy ) : ?>
							<li>
								<a href="<?php echo esc_url( admin_url( 'post.php?post=' . $policy['id'] . '&action=edit' ) ); ?>">
									<?php echo esc_html( $policy['name'] ); ?>
								</a>
								<?php if ( $policy['type'] ) : ?>
									<span class="record-type">(<?php echo esc_html( $policy['type'] ); ?>)</span>
								<?php endif; ?>
								<?php if ( $policy['status'] ) : ?>
									<span class="status-badge status-<?php echo esc_attr( sanitize_title( $policy['status'] ) ); ?>">
										<?php echo esc_html( $policy['status'] ); ?>
									</span>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php else : ?>
					<p class="no-records">
						<?php esc_html_e( 'No policies found.', 'mcp-ai-wpoos-pro' ); ?>
						<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=mcp_ai_policy' ) ); ?>" class="add-record-link">
							<?php esc_html_e( 'Add Policy', 'mcp-ai-wpoos-pro' ); ?>
						</a>
					</p>
				<?php endif; ?>
			</div>

			<!-- Allergies Section -->
			<div class="record-section">
				<h3>
					<span class="dashicons dashicons-warning"></span>
					<?php esc_html_e( 'Allergies', 'mcp-ai-wpoos-pro' ); ?>
					<span class="count-badge"><?php echo esc_html( count( $data['allergies'] ) ); ?></span>
				</h3>
				<?php if ( ! empty( $data['allergies'] ) ) : ?>
					<ul class="record-list">
						<?php foreach ( $data['allergies'] as $allergy ) : ?>
							<li>
								<a href="<?php echo esc_url( admin_url( 'post.php?post=' . $allergy['id'] . '&action=edit' ) ); ?>">
									<?php echo esc_html( $allergy['allergen'] ); ?>
								</a>
								<?php if ( $allergy['severity'] ) : ?>
									<span class="severity-badge severity-<?php echo esc_attr( sanitize_title( $allergy['severity'] ) ); ?>">
										<?php echo esc_html( $allergy['severity'] ); ?>
									</span>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php else : ?>
					<p class="no-records">
						<?php esc_html_e( 'No allergies recorded.', 'mcp-ai-wpoos-pro' ); ?>
						<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=mcp_ai_allergy' ) ); ?>" class="add-record-link">
							<?php esc_html_e( 'Add Allergy', 'mcp-ai-wpoos-pro' ); ?>
						</a>
					</p>
				<?php endif; ?>
			</div>

			<!-- Active Prescriptions Section -->
			<div class="record-section">
				<h3>
					<span class="dashicons dashicons-media-document"></span>
					<?php esc_html_e( 'Active Prescriptions', 'mcp-ai-wpoos-pro' ); ?>
					<span class="count-badge"><?php echo esc_html( count( $data['active_prescriptions'] ) ); ?></span>
				</h3>
				<?php if ( ! empty( $data['active_prescriptions'] ) ) : ?>
					<ul class="record-list">
						<?php foreach ( $data['active_prescriptions'] as $prescription ) : ?>
							<li>
								<a href="<?php echo esc_url( admin_url( 'post.php?post=' . $prescription['id'] . '&action=edit' ) ); ?>">
									<?php echo esc_html( $prescription['medication'] ); ?>
								</a>
								<?php if ( $prescription['dosage'] ) : ?>
									<span class="record-detail"><?php echo esc_html( $prescription['dosage'] ); ?></span>
								<?php endif; ?>
								<?php if ( $prescription['frequency'] ) : ?>
									<span class="record-detail"><?php echo esc_html( $prescription['frequency'] ); ?></span>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php else : ?>
					<p class="no-records">
						<?php esc_html_e( 'No active prescriptions.', 'mcp-ai-wpoos-pro' ); ?>
						<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=mcp_ai_prescription' ) ); ?>" class="add-record-link">
							<?php esc_html_e( 'Add Prescription', 'mcp-ai-wpoos-pro' ); ?>
						</a>
					</p>
				<?php endif; ?>
			</div>

			<!-- Upcoming Checkups Section -->
			<div class="record-section">
				<h3>
					<span class="dashicons dashicons-calendar-alt"></span>
					<?php esc_html_e( 'Upcoming Checkups', 'mcp-ai-wpoos-pro' ); ?>
					<span class="count-badge"><?php echo esc_html( count( $data['upcoming_checkups'] ) ); ?></span>
				</h3>
				<?php if ( ! empty( $data['upcoming_checkups'] ) ) : ?>
					<ul class="record-list">
						<?php foreach ( $data['upcoming_checkups'] as $checkup ) : ?>
							<li>
								<a href="<?php echo esc_url( admin_url( 'post.php?post=' . $checkup['id'] . '&action=edit' ) ); ?>">
									<?php echo esc_html( $checkup['title'] ); ?>
								</a>
								<?php if ( $checkup['date'] ) : ?>
									<span class="record-detail"><?php echo esc_html( gmdate( 'M j, Y', strtotime( $checkup['date'] ) ) ); ?></span>
								<?php endif; ?>
								<?php if ( $checkup['provider'] ) : ?>
									<span class="record-detail"><?php echo esc_html( $checkup['provider'] ); ?></span>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php else : ?>
					<p class="no-records">
						<?php esc_html_e( 'No upcoming checkups scheduled.', 'mcp-ai-wpoos-pro' ); ?>
						<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=mcp_ai_checkup' ) ); ?>" class="add-record-link">
							<?php esc_html_e( 'Schedule Checkup', 'mcp-ai-wpoos-pro' ); ?>
						</a>
					</p>
				<?php endif; ?>
			</div>

			<!-- Recent Medical Records Section -->
			<?php if ( ! empty( $data['recent_medical_records'] ) ) : ?>
				<div class="record-section wide">
					<h3>
						<span class="dashicons dashicons-clipboard"></span>
						<?php esc_html_e( 'Recent Medical Records', 'mcp-ai-wpoos-pro' ); ?>
						<span class="count-badge"><?php echo esc_html( count( $data['recent_medical_records'] ) ); ?></span>
					</h3>
					<ul class="record-list">
						<?php foreach ( $data['recent_medical_records'] as $record ) : ?>
							<li>
								<a href="<?php echo esc_url( admin_url( 'post.php?post=' . $record['id'] . '&action=edit' ) ); ?>">
									<?php echo esc_html( $record['title'] ); ?>
								</a>
								<?php if ( $record['type'] ) : ?>
									<span class="record-type"><?php echo esc_html( $record['type'] ); ?></span>
								<?php endif; ?>
								<?php if ( $record['date'] ) : ?>
									<span class="record-detail"><?php echo esc_html( gmdate( 'M j, Y', strtotime( $record['date'] ) ) ); ?></span>
								<?php endif; ?>
								<?php if ( $record['description'] ) : ?>
									<p class="record-description"><?php echo esc_html( $record['description'] ); ?></p>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php else : ?>
				<div class="record-section wide">
					<h3>
						<span class="dashicons dashicons-clipboard"></span>
						<?php esc_html_e( 'Medical Records', 'mcp-ai-wpoos-pro' ); ?>
						<span class="count-badge">0</span>
					</h3>
					<p class="no-records">
						<?php esc_html_e( 'No medical records found.', 'mcp-ai-wpoos-pro' ); ?>
						<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=mcp_ai_med_record' ) ); ?>" class="add-record-link">
							<?php esc_html_e( 'Add Medical Record', 'mcp-ai-wpoos-pro' ); ?>
						</a>
					</p>
				</div>
			<?php endif; ?>
		</div>

		<div class="wp-mcp-ai-completeness-indicator">
			<h3><?php esc_html_e( 'Profile Completeness', 'mcp-ai-wpoos-pro' ); ?></h3>
			<div class="completeness-bar">
				<?php
				// Calculate completeness based on actual sections.
				$sections = array(
					'policies'               => ! empty( $data['policies'] ),
					'allergies'              => ! empty( $data['allergies'] ),
					'active_prescriptions'   => ! empty( $data['active_prescriptions'] ),
					'upcoming_checkups'      => ! empty( $data['upcoming_checkups'] ),
					'recent_medical_records' => ! empty( $data['recent_medical_records'] ),
					'demographics'           => ( ! empty( $member['date_of_birth'] ) && ! empty( $member['gender'] ) ),
				);

				$filled_sections         = count( array_filter( $sections ) );
				$total_sections          = count( $sections );
				$completeness_percentage = ( $filled_sections / $total_sections ) * 100;
				?>
				<div class="completeness-progress" style="width: <?php echo esc_attr( $completeness_percentage ); ?>%;"></div>
			</div>
			<p class="completeness-text">
				<?php
				/* translators: %d: Completeness percentage */
				echo esc_html( sprintf( __( '%d%% Complete', 'mcp-ai-wpoos-pro' ), round( $completeness_percentage ) ) );
				?>
			</p>
			<?php if ( $completeness_percentage < 100 ) : ?>
				<p class="completeness-suggestion">
					<?php esc_html_e( 'Use the AI assistant below to help complete this member\'s health profile.', 'mcp-ai-wpoos-pro' ); ?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Handle AJAX request to check record completeness and suggest next steps.
	 */
	public static function handle_check_completeness() {
		// Verify nonce.
		check_ajax_referer( 'wp_mcp_ai_health_consolidate', 'nonce' );

		// Check user capability.
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to check record completeness.', 'mcp-ai-wpoos-pro' ) ) );
		}

		// Get member ID.
		$member_id = isset( $_POST['member_id'] ) ? absint( $_POST['member_id'] ) : 0;

		if ( ! $member_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid member ID.', 'mcp-ai-wpoos-pro' ) ) );
		}

		// Analyze what's missing.
		$suggestions = array();
		$member      = get_post( $member_id );

		if ( ! $member ) {
			wp_send_json_error( array( 'message' => __( 'Member not found.', 'mcp-ai-wpoos-pro' ) ) );
		}

		// Check demographics.
		if ( empty( get_post_meta( $member_id, '_member_date_of_birth', true ) ) ) {
			$suggestions[] = __( 'Add date of birth to member profile', 'mcp-ai-wpoos-pro' );
		}
		if ( empty( get_post_meta( $member_id, '_member_gender', true ) ) ) {
			$suggestions[] = __( 'Add gender to member profile', 'mcp-ai-wpoos-pro' );
		}
		if ( empty( get_post_meta( $member_id, '_member_emergency_contact', true ) ) ) {
			$suggestions[] = __( 'Add emergency contact information', 'mcp-ai-wpoos-pro' );
		}

		// Check for policies.
		$policies = get_posts(
			array(
				'post_type'      => 'mcp_ai_policy',
				'meta_key'       => '_policy_member_id',
				'meta_value'     => $member_id,
				'posts_per_page' => 1,
			)
		);
		if ( empty( $policies ) ) {
			$suggestions[] = __( 'Add insurance policy information', 'mcp-ai-wpoos-pro' );
		}

		// Check for allergies.
		$allergies = get_posts(
			array(
				'post_type'      => 'mcp_ai_allergy',
				'meta_key'       => '_allergy_member_id',
				'meta_value'     => $member_id,
				'posts_per_page' => 1,
			)
		);
		if ( empty( $allergies ) ) {
			$suggestions[] = __( 'Document any known allergies (or note "None known")', 'mcp-ai-wpoos-pro' );
		}

		// Check for medical records.
		$records = get_posts(
			array(
				'post_type'      => 'mcp_ai_med_record',
				'meta_key'       => '_record_member_id',
				'meta_value'     => $member_id,
				'posts_per_page' => 1,
			)
		);
		if ( empty( $records ) ) {
			$suggestions[] = __( 'Add medical history and records', 'mcp-ai-wpoos-pro' );
		}

		// Check for upcoming checkups.
		$checkups = get_posts(
			array(
				'post_type'      => 'mcp_ai_checkup',
				'meta_key'       => '_checkup_member_id',
				'meta_value'     => $member_id,
				'posts_per_page' => 1,
			)
		);
		if ( empty( $checkups ) ) {
			$suggestions[] = __( 'Schedule upcoming health checkups', 'mcp-ai-wpoos-pro' );
		}

		wp_send_json_success(
			array(
				'suggestions'          => $suggestions,
				'completeness_message' => count( $suggestions ) === 0
					? __( 'This member\'s health profile is complete!', 'mcp-ai-wpoos-pro' )
					: sprintf(
						/* translators: %d: Number of suggestions */
						__( '%d areas need attention to complete this health profile.', 'mcp-ai-wpoos-pro' ),
						count( $suggestions )
					),
			)
		);
	}

	/**
	 * Handle AJAX request for bulk import of health information.
	 */
	public static function handle_bulk_import() {
		// Verify nonce.
		check_ajax_referer( 'wp_mcp_ai_health_consolidate', 'nonce' );

		// Check user capability.
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to import health records.', 'mcp-ai-wpoos-pro' ) ) );
		}

		// Get parameters.
		$member_id             = isset( $_POST['member_id'] ) ? absint( $_POST['member_id'] ) : 0;
		$raw_information       = isset( $_POST['raw_information'] ) ? wp_kses_post( wp_unslash( $_POST['raw_information'] ) ) : '';
		$auto_create           = isset( $_POST['auto_create'] ) ? (bool) $_POST['auto_create'] : true;
		$confirmation_required = isset( $_POST['require_confirmation'] ) ? (bool) $_POST['require_confirmation'] : false;
		$attachment_ids        = isset( $_POST['attachment_ids'] ) ? array_map( 'absint', (array) $_POST['attachment_ids'] ) : array();

		if ( ! $member_id ) {
			wp_send_json_error( array( 'message' => __( 'Please select a member first.', 'mcp-ai-wpoos-pro' ) ) );
		}

		if ( empty( $raw_information ) && empty( $attachment_ids ) ) {
			wp_send_json_error( array( 'message' => __( 'Please provide health information or upload documents to import.', 'mcp-ai-wpoos-pro' ) ) );
		}

		// Use the parse_health_information tool.
		if ( ! class_exists( 'WP_MCP_AI_Tool_Parse_Health_Information' ) ) {
			wp_send_json_error( array( 'message' => __( 'Health information parsing tool not available.', 'mcp-ai-wpoos-pro' ) ) );
		}

		$tool   = new WP_MCP_AI_Tool_Parse_Health_Information();
		$result = $tool->execute(
			array(
				'member_id'             => $member_id,
				'raw_information'       => $raw_information,
				'auto_create_records'   => $auto_create,
				'confirmation_required' => $confirmation_required,
				'attachment_ids'        => $attachment_ids,
			),
			array( 'user_id' => get_current_user_id() )
		);

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		// Generate HTML summary.
		ob_start();
		self::render_import_summary( $result );
		$summary_html = ob_get_clean();

		wp_send_json_success(
			array(
				'message'      => __( 'Health information parsed successfully!', 'mcp-ai-wpoos-pro' ),
				'summary_html' => $summary_html,
				'result'       => $result,
			)
		);
	}

	/**
	 * Render import summary HTML.
	 *
	 * @param array $result Import result data.
	 */
	private static function render_import_summary( $result ) {
		?>
		<div class="import-summary-container">
			<h3><?php esc_html_e( 'Import Complete!', 'mcp-ai-wpoos-pro' ); ?></h3>
			
			<div class="import-stats">
				<h4><?php esc_html_e( 'What was imported:', 'mcp-ai-wpoos-pro' ); ?></h4>
				<ul class="import-stats-list">
					<?php if ( ! empty( $result['parsed_data']['medical_records'] ) ) : ?>
						<li>
							<span class="dashicons dashicons-clipboard"></span>
							<?php
							echo esc_html(
								sprintf(
									/* translators: %d: number of records */
									_n( '%d Medical Record', '%d Medical Records', count( $result['parsed_data']['medical_records'] ), 'mcp-ai-wpoos-pro' ),
									count( $result['parsed_data']['medical_records'] )
								)
							);
							?>
						</li>
					<?php endif; ?>

					<?php if ( ! empty( $result['parsed_data']['checkups'] ) ) : ?>
						<li>
							<span class="dashicons dashicons-calendar-alt"></span>
							<?php
							echo esc_html(
								sprintf(
									/* translators: %d: number of checkups */
									_n( '%d Checkup', '%d Checkups', count( $result['parsed_data']['checkups'] ), 'mcp-ai-wpoos-pro' ),
									count( $result['parsed_data']['checkups'] )
								)
							);
							?>
						</li>
					<?php endif; ?>

					<?php if ( ! empty( $result['parsed_data']['prescriptions'] ) ) : ?>
						<li>
							<span class="dashicons dashicons-media-document"></span>
							<?php
							echo esc_html(
								sprintf(
									/* translators: %d: number of prescriptions */
									_n( '%d Prescription', '%d Prescriptions', count( $result['parsed_data']['prescriptions'] ), 'mcp-ai-wpoos-pro' ),
									count( $result['parsed_data']['prescriptions'] )
								)
							);
							?>
						</li>
					<?php endif; ?>

					<?php if ( ! empty( $result['parsed_data']['policies'] ) ) : ?>
						<li>
							<span class="dashicons dashicons-shield"></span>
							<?php
							echo esc_html(
								sprintf(
									/* translators: %d: number of policies */
									_n( '%d Insurance Policy', '%d Insurance Policies', count( $result['parsed_data']['policies'] ), 'mcp-ai-wpoos-pro' ),
									count( $result['parsed_data']['policies'] )
								)
							);
							?>
						</li>
					<?php endif; ?>

					<?php if ( ! empty( $result['parsed_data']['allergies'] ) ) : ?>
						<li>
							<span class="dashicons dashicons-warning"></span>
							<?php
							echo esc_html(
								sprintf(
									/* translators: %d: number of allergies */
									_n( '%d Allergy', '%d Allergies', count( $result['parsed_data']['allergies'] ), 'mcp-ai-wpoos-pro' ),
									count( $result['parsed_data']['allergies'] )
								)
							);
							?>
						</li>
					<?php endif; ?>
				</ul>
			</div>

			<?php if ( $result['records_created'] && ! empty( $result['created_records'] ) ) : ?>
				<div class="import-created">
					<h4><?php esc_html_e( 'Records created in system:', 'mcp-ai-wpoos-pro' ); ?></h4>
					<?php
					$total_created = array_sum( array_map( 'count', $result['created_records'] ) );
					?>
					<p class="success-message">
						<span class="dashicons dashicons-yes-alt"></span>
						<?php
						echo esc_html(
							sprintf(
								/* translators: %d: number of records */
								_n( '%d record successfully created!', '%d records successfully created!', $total_created, 'mcp-ai-wpoos-pro' ),
								$total_created
							)
						);
						?>
					</p>
					<p>
						<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_member' ) ); ?>" class="button">
							<?php esc_html_e( 'View All Records', 'mcp-ai-wpoos-pro' ); ?>
						</a>
					</p>
				</div>
			<?php elseif ( $result['confirmation_required'] ) : ?>
				<div class="import-confirmation-needed">
					<p class="description">
						<?php esc_html_e( 'Review the parsed data above and confirm to create these records.', 'mcp-ai-wpoos-pro' ); ?>
					</p>
					<p>
						<button type="button" class="button button-primary" id="wp-mcp-ai-confirm-import">
							<?php esc_html_e( 'Confirm & Create Records', 'mcp-ai-wpoos-pro' ); ?>
						</button>
						<button type="button" class="button button-secondary" id="wp-mcp-ai-cancel-import">
							<?php esc_html_e( 'Cancel', 'mcp-ai-wpoos-pro' ); ?>
						</button>
					</p>
				</div>
			<?php endif; ?>

			<div class="import-summary-text">
				<h4><?php esc_html_e( 'AI Analysis:', 'mcp-ai-wpoos-pro' ); ?></h4>
				<pre><?php echo esc_html( $result['parsing_summary'] ); ?></pre>
			</div>
		</div>
		<?php
	}

	/**
	 * Handle AJAX request to upload health documents.
	 *
	 * Uploads files to WordPress media library and stores metadata about
	 * the original source for compliance and audit trail purposes.
	 */
	public static function handle_document_upload() {
		// Verify nonce.
		check_ajax_referer( 'wp_mcp_ai_health_consolidate', 'nonce' );

		// Check user capability.
		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to upload files.', 'mcp-ai-wpoos-pro' ) ) );
		}

		// Get member ID.
		$member_id = isset( $_POST['member_id'] ) ? absint( $_POST['member_id'] ) : 0;

		if ( ! $member_id ) {
			wp_send_json_error( array( 'message' => __( 'Please select a member first.', 'mcp-ai-wpoos-pro' ) ) );
		}

		// Verify member exists.
		$member = get_post( $member_id );
		if ( ! $member || 'mcp_ai_member' !== $member->post_type ) {
			wp_send_json_error( array( 'message' => __( 'Invalid member.', 'mcp-ai-wpoos-pro' ) ) );
		}

		// Check if file was uploaded.
		if ( empty( $_FILES['file'] ) ) {
			wp_send_json_error( array( 'message' => __( 'No file was uploaded.', 'mcp-ai-wpoos-pro' ) ) );
		}

		// Validate file type.
		$allowed_types = array(
			'application/pdf',
			'image/jpeg',
			'image/jpg',
			'image/png',
			'application/msword',
			'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			'text/plain',
		);

		if ( ! isset( $_FILES['file']['type'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid file upload.', 'mcp-ai-wpoos-pro' ) ) );
		}

		$file_type = sanitize_text_field( wp_unslash( $_FILES['file']['type'] ) );
		if ( ! in_array( $file_type, $allowed_types, true ) ) {
			wp_send_json_error( array( 'message' => __( 'File type not allowed. Please upload PDF, JPG, PNG, DOC, DOCX, or TXT files.', 'mcp-ai-wpoos-pro' ) ) );
		}

		// Handle the upload using WordPress functions.
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$attachment_id = media_handle_upload( 'file', 0 );

		if ( is_wp_error( $attachment_id ) ) {
			wp_send_json_error( array( 'message' => $attachment_id->get_error_message() ) );
		}

		// Add metadata to track this as a health document source.
		update_post_meta( $attachment_id, '_wp_mcp_ai_health_document', true );
		update_post_meta( $attachment_id, '_wp_mcp_ai_member_id', $member_id );
		update_post_meta( $attachment_id, '_wp_mcp_ai_upload_date', current_time( 'mysql' ) );
		update_post_meta( $attachment_id, '_wp_mcp_ai_upload_user', get_current_user_id() );

		// Get file details.
		$attachment = get_post( $attachment_id );
		$file_url   = wp_get_attachment_url( $attachment_id );
		$file_name  = basename( get_attached_file( $attachment_id ) );

		wp_send_json_success(
			array(
				'message'       => __( 'File uploaded successfully!', 'mcp-ai-wpoos-pro' ),
				'attachment_id' => $attachment_id,
				'file_name'     => $file_name,
				'file_url'      => $file_url,
				'file_type'     => $file_type,
			)
		);
	}
}

// Initialize.
WP_MCP_AI_Health_Records_Consolidate_Page::init();
