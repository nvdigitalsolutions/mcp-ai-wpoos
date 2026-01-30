<?php
/**
 * Document Research Page for Regulatory Registration Toolkit.
 *
 * Provides AI-assisted document research and management interface.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/trait-wp-mcp-ai-research-page-featured-image.php';
require_once __DIR__ . '/trait-wp-mcp-ai-research-page-enhancements.php';

/**
 * Document Research Page class.
 */
class WP_MCP_AI_Reg_Document_Research_Page {
	use WP_MCP_AI_Research_Page_Featured_Image;
	use WP_MCP_AI_Research_Page_Import_Handler;
	use WP_MCP_AI_Research_Page_Consolidation;
	use WP_MCP_AI_Research_Page_Data_Validation;
	use WP_MCP_AI_Research_Page_Mode_Tabs;

	/**
	 * Page slug.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'wp-mcp-ai-reg-document-research';

	/**
	 * Initialize the class.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu_page' ), 21 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_ajax_wp_mcp_ai_upload_reg_document_from_research', array( __CLASS__, 'handle_upload_from_research' ) );
		add_action( 'wp_ajax_wp_mcp_ai_import_reg_document', array( __CLASS__, 'ajax_handle_import' ) );
	}

	/**
	 * Add menu page.
	 */
	public static function add_menu_page() {
		add_submenu_page(
			'edit.php?post_type=mcp_ai_reg_document',
			__( 'Research & Add Documents', 'mcp-ai-wpoos-pro' ),
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
		// Only load on our research page.
		if ( 'mcp_ai_reg_document_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		// Enqueue chat assets.
		if ( class_exists( 'WP_MCP_AI_Shortcode' ) ) {
			$shortcode_instance = new WP_MCP_AI_Shortcode();
			$shortcode_instance->register_assets();
			wp_enqueue_style( WP_MCP_AI_Shortcode::STYLE_HANDLE );
			wp_enqueue_script( WP_MCP_AI_Shortcode::SCRIPT_HANDLE );
		}

		// Enqueue enhanced research page styles.
		wp_enqueue_style(
			'wp-mcp-ai-enhanced-research-page',
			WP_MCP_AI_URL . 'assets/css/enhanced-research-page.css',
			array(),
			WP_MCP_AI_VERSION
		);

		// Enqueue enhanced research page script.
		wp_enqueue_script(
			'wp-mcp-ai-enhanced-research-page',
			WP_MCP_AI_URL . 'assets/js/enhanced-research-page.js',
			array( 'jquery' ),
			WP_MCP_AI_VERSION,
			true
		);

		// Localize script.
		wp_localize_script(
			'wp-mcp-ai-enhanced-research-page',
			'wpMcpAiResearchPage',
			array(
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( 'wp_mcp_ai_research_reg_document' ),
				'entityType' => 'reg_document',
			)
		);
	}

	/**
	 * Render the research page.
	 */
	public static function render_page() {
		// Get assistant from settings.
		$settings     = get_option( 'wp_mcp_ai_reg_document_settings', array() );
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

		?>
		<div class="wrap wp-mcp-ai-research-page">
			<h1 class="wp-heading-inline">
				<?php esc_html_e( 'Research & Add Document', 'mcp-ai-wpoos-pro' ); ?>
			</h1>

			<hr class="wp-header-end">

			<?php self::render_chat_interface( $assistant_id ); ?>
		</div>
		<?php
	}

	/**
	 * Render the chat interface.
	 *
	 * @param int $assistant_id Assistant ID.
	 */
	protected static function render_chat_interface( $assistant_id ) {
		?>
			<div class="wp-mcp-ai-research-container">
				<div class="wp-mcp-ai-research-sidebar">
					<div class="wp-mcp-ai-research-intro">
						<h2><?php esc_html_e( 'How It Works', 'mcp-ai-wpoos-pro' ); ?></h2>
						<ol>
							<li><?php esc_html_e( 'Search existing documents or research requirements', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Check expiry dates and renewal timelines', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Upload documents with metadata and categorization', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Link documents to products or registrations', 'mcp-ai-wpoos-pro' ); ?></li>
						</ol>
					</div>

					<div class="wp-mcp-ai-research-tips">
						<h3><?php esc_html_e( 'Research Tips', 'mcp-ai-wpoos-pro' ); ?></h3>
						<ul>
							<li><strong><?php esc_html_e( 'Search first:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Check if document already uploaded', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><strong><?php esc_html_e( 'Document types:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Use correct document type categorization', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><strong><?php esc_html_e( 'Expiry tracking:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Always set expiry dates for renewals', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><strong><?php esc_html_e( 'Version control:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Track document versions properly', 'mcp-ai-wpoos-pro' ); ?></li>
						</ul>
					</div>

					<div class="wp-mcp-ai-research-examples">
						<h3><?php esc_html_e( 'Example Queries', 'mcp-ai-wpoos-pro' ); ?></h3>
						<ul class="wp-mcp-ai-example-list">
							<li><button type="button" class="button button-secondary wp-mcp-ai-example-query" data-query="Research what documents are required for Sri Lanka NMRA cosmetic registration including LOA, FSC, and CoA">
								<?php esc_html_e( '"Research required documents for NMRA..."', 'mcp-ai-wpoos-pro' ); ?>
							</button></li>
							<li><button type="button" class="button button-secondary wp-mcp-ai-example-query" data-query="Find information about Certificate of Free Sale requirements and validity periods for different countries">
								<?php esc_html_e( '"Find information about Free Sale Certificate..."', 'mcp-ai-wpoos-pro' ); ?>
							</button></li>
							<li><button type="button" class="button button-secondary wp-mcp-ai-example-query" data-query="Check which documents are expiring in the next 90 days and need renewal">
								<?php esc_html_e( '"Check expiring documents in 90 days..."', 'mcp-ai-wpoos-pro' ); ?>
							</button></li>
						</ul>
					</div>

					<div class="wp-mcp-ai-research-actions">
						<h3><?php esc_html_e( 'Quick Actions', 'mcp-ai-wpoos-pro' ); ?></h3>
						<p>
							<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_reg_document' ) ); ?>" class="button">
								<?php esc_html_e( 'View All Documents', 'mcp-ai-wpoos-pro' ); ?>
							</a>
						</p>
						<p>
							<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=mcp_ai_reg_document' ) ); ?>" class="button">
								<?php esc_html_e( 'Add Document Manually', 'mcp-ai-wpoos-pro' ); ?>
							</a>
						</p>
						<p>
							<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_reg_document&page=wp-mcp-ai-reg-document' ) ); ?>" class="button">
								<?php esc_html_e( 'Document Management', 'mcp-ai-wpoos-pro' ); ?>
							</a>
						</p>
					</div>
				</div>

				<div class="wp-mcp-ai-research-main">
					<!-- Workflow Mode Selector -->
					<div class="wp-mcp-ai-workflow-selector">
						<h2><?php esc_html_e( 'Choose Your Workflow', 'mcp-ai-wpoos-pro' ); ?></h2>
						<div class="workflow-options">
							<button type="button" class="workflow-option active" data-workflow="research">
								<span class="dashicons dashicons-format-chat"></span>
								<strong><?php esc_html_e( 'AI Research', 'mcp-ai-wpoos-pro' ); ?></strong>
								<p><?php esc_html_e( 'Research and upload documents with AI assistance', 'mcp-ai-wpoos-pro' ); ?></p>
							</button>
							<button type="button" class="workflow-option" data-workflow="import">
								<span class="dashicons dashicons-upload"></span>
								<strong><?php esc_html_e( 'Bulk Upload', 'mcp-ai-wpoos-pro' ); ?></strong>
								<p><?php esc_html_e( 'Upload multiple documents at once', 'mcp-ai-wpoos-pro' ); ?></p>
							</button>
							<button type="button" class="workflow-option" data-workflow="review">
								<span class="dashicons dashicons-analytics"></span>
								<strong><?php esc_html_e( 'Review & Expiry', 'mcp-ai-wpoos-pro' ); ?></strong>
								<p><?php esc_html_e( 'View document status and expiry tracking', 'mcp-ai-wpoos-pro' ); ?></p>
							</button>
						</div>
					</div>

					<!-- AI Research Workflow (Default) -->
					<div id="workflow-research" class="workflow-content active">
					<?php if ( $assistant_id > 0 ) : ?>
						<div class="wp-mcp-ai-research-chat">
							<?php
							// Render chat interface with comprehensive document tools.
							echo do_shortcode(
								'[mcp_ai_chat assistant="' . absint( $assistant_id ) . '" additional_tools="upload_reg_document,list_reg_documents,get_reg_document,validate_document_checklist,list_registrations,get_registration,web_search"]'
							);
							?>
						</div>

					<?php else : ?>
						<div class="notice notice-error">
							<p>
								<?php
								echo wp_kses_post(
									sprintf(
										/* translators: %s: Link to create assistant */
										__( 'No AI assistant found. Please <a href="%s">create an assistant</a> first.', 'mcp-ai-wpoos-pro' ),
										admin_url( 'post-new.php?post_type=mcp_ai_assistant' )
									)
								);
								?>
							</p>
						</div>
					<?php endif; ?>
					</div>

					<!-- Import Data Workflow -->
					<div id="workflow-import" class="workflow-content">
						<?php self::render_import_workflow(); ?>
					</div>

					<!-- Review & Quality Workflow -->
					<div id="workflow-review" class="workflow-content">
						<?php self::render_review_workflow(); ?>
					</div>
				</div>
			</div>
		<?php
	}

	/**
	 * Render import workflow section.
	 */
	protected static function render_import_workflow() {
		self::render_import_section();
	}

	/**
	 * Render review workflow section.
	 */
	protected static function render_review_workflow() {
		self::render_consolidation_dashboard();
	}

	/**
	 * Handle AJAX request to upload document from research.
	 */
	public static function handle_upload_from_research() {
		// Verify nonce.
		check_ajax_referer( 'wp_mcp_ai_research_reg_document', 'nonce' );

		// Check user capability.
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to upload documents.', 'mcp-ai-wpoos-pro' ) ) );
		}

		// Get research data from request.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Data is sanitized by tool execute method.
		$research_data_raw = isset( $_POST['research_data'] ) ? wp_unslash( $_POST['research_data'] ) : '';

		if ( empty( $research_data_raw ) ) {
			wp_send_json_error( array( 'message' => __( 'No research data provided.', 'mcp-ai-wpoos-pro' ) ) );
		}

		$research_data = json_decode( $research_data_raw, true );

		// Validate JSON decoding.
		if ( null === $research_data || JSON_ERROR_NONE !== json_last_error() ) {
			wp_send_json_error( array( 'message' => __( 'Invalid JSON data format.', 'mcp-ai-wpoos-pro' ) ) );
		}

		if ( empty( $research_data['title'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Document title is required.', 'mcp-ai-wpoos-pro' ) ) );
		}

		// Use the upload_reg_document tool to upload the document.
		if ( ! class_exists( 'WP_MCP_AI_Tool_Upload_Reg_Document' ) ) {
			wp_send_json_error( array( 'message' => __( 'Upload Document tool not available.', 'mcp-ai-wpoos-pro' ) ) );
		}

		$tool   = new WP_MCP_AI_Tool_Upload_Reg_Document();
		$result = $tool->execute(
			$research_data,
			array( 'user_id' => get_current_user_id() )
		);

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		// Return success with document ID and edit URL.
		$document_id = isset( $result['document_id'] ) ? $result['document_id'] : 0;
		$edit_url    = $document_id > 0 ? admin_url( 'post.php?post=' . $document_id . '&action=edit' ) : '';

		wp_send_json_success(
			array(
				'message'     => __( 'Document uploaded successfully!', 'mcp-ai-wpoos-pro' ),
				'document_id' => $document_id,
				'edit_url'    => $edit_url,
			)
		);
	}

	/**
	 * Get supported import formats.
	 *
	 * @return array Import formats.
	 */
	protected static function get_import_formats() {
		return array(
			'pdf'  => 'PDF',
			'doc'  => 'Word',
			'docx' => 'Word (DOCX)',
			'jpg'  => 'Image (JPG)',
			'png'  => 'Image (PNG)',
		);
	}

	/**
	 * Process imported data.
	 *
	 * @param string $data   Import data.
	 * @param string $format Data format.
	 * @return array|WP_Error Result or error.
	 */
	protected static function process_import_data( $data, $format ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed,Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Required by trait interface.
		// Document upload handled through file upload tool.
		return new WP_Error( 'not_implemented', __( 'Document upload should use file upload interface.', 'mcp-ai-wpoos-pro' ) );
	}

	/**
	 * Calculate completeness.
	 *
	 * @return array Completeness data.
	 */
	protected static function calculate_completeness() {
		$documents = get_posts(
			array(
				'post_type'      => 'mcp_ai_reg_document',
				'post_status'    => 'any',
				'posts_per_page' => -1,
			)
		);

		$total         = count( $documents );
		$complete      = 0;
		$missing_items = array();

		foreach ( $documents as $document ) {
			$meta        = get_post_meta( $document->ID );
			$has_type    = ! empty( $meta['document_type'][0] ?? '' );
			$has_expiry  = ! empty( $meta['expiry_date'][0] ?? '' );
			$has_link    = ! empty( $meta['registration_id'][0] ?? '' ) || ! empty( $meta['product_id'][0] ?? '' );

			if ( $has_type && $has_expiry && $has_link ) {
				$complete++;
			} else {
				if ( ! $has_type ) {
					$missing_items[] = sprintf( '%s: Missing document type', $document->post_title );
				}
				if ( ! $has_expiry ) {
					$missing_items[] = sprintf( '%s: Missing expiry date', $document->post_title );
				}
				if ( ! $has_link ) {
					$missing_items[] = sprintf( '%s: Not linked to product/registration', $document->post_title );
				}
			}
		}

		$percentage = $total > 0 ? round( ( $complete / $total ) * 100 ) : 0;

		return array(
			'percentage'  => $percentage,
			'missing'     => array_slice( $missing_items, 0, 10 ),
			'suggestions' => array(
				__( 'Categorize all documents by type', 'mcp-ai-wpoos-pro' ),
				__( 'Set expiry dates for renewal tracking', 'mcp-ai-wpoos-pro' ),
				__( 'Link documents to products or registrations', 'mcp-ai-wpoos-pro' ),
			),
		);
	}

	/**
	 * Get items for review.
	 *
	 * @return array Items.
	 */
	protected static function get_items_for_review() {
		$documents = get_posts(
			array(
				'post_type'      => 'mcp_ai_reg_document',
				'post_status'    => 'any',
				'posts_per_page' => 20,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		$items = array();
		foreach ( $documents as $document ) {
			$items[] = array(
				'id'    => $document->ID,
				'title' => $document->post_title,
				'meta'  => get_post_meta( $document->ID ),
			);
		}

		return $items;
	}

	/**
	 * Calculate quality score for item.
	 *
	 * @param array $item Item data.
	 * @return array Quality data.
	 */
	protected static function calculate_quality_score( $item ) {
		$score  = 0;
		$issues = array();
		$meta   = $item['meta'] ?? array();

		// Check required fields (20 points each).
		$required_fields = array(
			'document_type' => __( 'Document Type', 'mcp-ai-wpoos-pro' ),
			'issue_date'    => __( 'Issue Date', 'mcp-ai-wpoos-pro' ),
			'expiry_date'   => __( 'Expiry Date', 'mcp-ai-wpoos-pro' ),
			'file_url'      => __( 'File Upload', 'mcp-ai-wpoos-pro' ),
		);

		foreach ( $required_fields as $field => $label ) {
			if ( ! empty( $meta[ $field ][0] ?? '' ) ) {
				$score += 25;
			} else {
				$issues[] = sprintf(
					/* translators: %s: Field label */
					__( 'Missing %s', 'mcp-ai-wpoos-pro' ),
					$label
				);
			}
		}

		// Check expiry status.
		$expiry_date = $meta['expiry_date'][0] ?? '';
		if ( ! empty( $expiry_date ) ) {
			$expiry_timestamp = strtotime( $expiry_date );
			$now              = time();
			$days_until_expiry = ( $expiry_timestamp - $now ) / DAY_IN_SECONDS;

			if ( $days_until_expiry < 0 ) {
				$issues[] = __( 'Document expired', 'mcp-ai-wpoos-pro' );
			} elseif ( $days_until_expiry < 90 ) {
				$issues[] = __( 'Expires soon (within 90 days)', 'mcp-ai-wpoos-pro' );
			}
		}

		// Determine quality level.
		if ( $score >= 90 ) {
			$level = 'high';
		} elseif ( $score >= 60 ) {
			$level = 'medium';
		} else {
			$level = 'low';
		}

		return array(
			'score'  => $score,
			'level'  => $level,
			'status' => $score >= 90 ? __( 'Complete', 'mcp-ai-wpoos-pro' ) : __( 'Incomplete', 'mcp-ai-wpoos-pro' ),
			'issues' => $issues,
		);
	}
}

WP_MCP_AI_Reg_Document_Research_Page::init();
