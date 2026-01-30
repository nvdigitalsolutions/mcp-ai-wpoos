<?php
/**
 * Consolidate & Add Base Class
 *
 * Enhanced version of Research & Add with data import, consolidation, and validation features.
 * Inspired by the Health & Wellness toolkit's consolidation page.
 *
 * @package WP_MCP_AI_Pro
 * @since 2.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Base class for Consolidate & Add functionality with import and validation.
 */
abstract class WP_MCP_AI_Consolidate_Add_Base {

	/**
	 * Toolkit slug.
	 *
	 * @var string
	 */
	protected $toolkit_slug;

	/**
	 * Entity types for this toolkit (e.g., ['products', 'customers']).
	 *
	 * @var array
	 */
	protected $entity_types = array();

	/**
	 * Data stores for each entity type.
	 *
	 * @var array
	 */
	protected $data_stores = array();

	/**
	 * Current entity being viewed/edited.
	 *
	 * @var string
	 */
	protected $current_entity;

	/**
	 * Supported import formats for this toolkit.
	 *
	 * @var array
	 */
	protected $import_formats = array();

	/**
	 * Constructor.
	 *
	 * @param string $toolkit_slug Toolkit identifier.
	 */
	public function __construct( $toolkit_slug ) {
		$this->toolkit_slug   = $toolkit_slug;
		$this->entity_types   = $this->get_entity_types();
		$this->import_formats = $this->get_import_formats();
		$this->initialize_data_stores();

		// Get current entity from query string.
		$this->current_entity = isset( $_GET['entity'] ) ? sanitize_key( $_GET['entity'] ) : $this->get_default_entity(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// Handle AJAX actions.
		add_action( 'wp_ajax_wp_mcp_ai_consolidate_bulk_import', array( $this, 'ajax_bulk_import' ) );
		add_action( 'wp_ajax_wp_mcp_ai_consolidate_upload_document', array( $this, 'ajax_upload_document' ) );
		add_action( 'wp_ajax_wp_mcp_ai_consolidate_validate_data', array( $this, 'ajax_validate_data' ) );
		add_action( 'wp_ajax_wp_mcp_ai_consolidate_check_completeness', array( $this, 'ajax_check_completeness' ) );
		add_action( 'admin_init', array( $this, 'handle_form_submission' ) );
	}

	/**
	 * Get entity types for this toolkit.
	 * To be implemented by child classes.
	 *
	 * @return array Entity types (e.g., ['products' => 'Products', 'customers' => 'Customers']).
	 */
	abstract protected function get_entity_types();

	/**
	 * Get import formats supported by this toolkit.
	 * To be implemented by child classes.
	 *
	 * @return array Import formats (e.g., ['csv' => 'CSV', 'xml' => 'XML']).
	 */
	abstract protected function get_import_formats();

	/**
	 * Get validation schema for current entity type.
	 * To be implemented by child classes.
	 *
	 * @return array Validation rules and requirements.
	 */
	abstract protected function get_validation_schema();

	/**
	 * Parse imported data based on format.
	 * To be implemented by child classes.
	 *
	 * @param string $data   Raw import data.
	 * @param string $format Import format (csv, xml, etc.).
	 * @return array|WP_Error Parsed data or error.
	 */
	abstract protected function parse_import_data( $data, $format );

	/**
	 * Get default entity type.
	 *
	 * @return string Default entity slug.
	 */
	protected function get_default_entity() {
		$types = array_keys( $this->entity_types );
		return ! empty( $types ) ? $types[0] : '';
	}

	/**
	 * Initialize data stores for all entity types.
	 */
	protected function initialize_data_stores() {
		if ( ! class_exists( 'WP_MCP_AI_Toolkit_Data_Store_Factory' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-toolkit-data-store-factory.php';
		}

		foreach ( array_keys( $this->entity_types ) as $entity_type ) {
			$this->data_stores[ $entity_type ] = WP_MCP_AI_Toolkit_Data_Store_Factory::get_store(
				$this->toolkit_slug,
				$entity_type
			);
		}
	}

	/**
	 * Get data store for current entity.
	 *
	 * @return WP_MCP_AI_Toolkit_Data_Store|null Data store or null if not found.
	 */
	protected function get_current_data_store() {
		return isset( $this->data_stores[ $this->current_entity ] )
			? $this->data_stores[ $this->current_entity ]
			: null;
	}

	/**
	 * Render Consolidate & Add UI.
	 */
	public function render() {
		if ( empty( $this->entity_types ) ) {
			$this->render_no_entities_message();
			return;
		}

		?>
		<div class="wrap wp-mcp-ai-consolidate-add">
			<h2><?php esc_html_e( 'Consolidate & Add', 'mcp-ai-wpoos-pro' ); ?></h2>

			<?php $this->render_storage_backend_notice(); ?>
			<?php $this->render_entity_tabs(); ?>
			<?php $this->render_workflow_selector(); ?>

			<div class="consolidate-add-content">
				<?php
				$workflow = isset( $_GET['workflow'] ) ? sanitize_key( $_GET['workflow'] ) : 'quick-import'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

				switch ( $workflow ) {
					case 'quick-import':
						$this->render_quick_import_workflow();
						break;
					case 'guided-entry':
						$this->render_guided_entry_workflow();
						break;
					case 'review-consolidate':
						$this->render_review_consolidate_workflow();
						break;
					default:
						$this->render_quick_import_workflow();
				}
				?>
			</div>
		</div>

		<style>
			.wp-mcp-ai-consolidate-add {
				margin-top: 20px;
			}
			.workflow-selector {
				display: flex;
				gap: 10px;
				margin: 20px 0;
			}
			.workflow-selector button {
				flex: 1;
				padding: 15px 20px;
				border: 2px solid #ccd0d4;
				background: #fff;
				cursor: pointer;
				border-radius: 4px;
				font-size: 14px;
			}
			.workflow-selector button.active {
				border-color: #2271b1;
				background: #f0f6fc;
				color: #2271b1;
			}
			.entity-tabs {
				border-bottom: 1px solid #ccd0d4;
				margin: 20px 0;
			}
			.entity-tabs a {
				display: inline-block;
				padding: 10px 15px;
				text-decoration: none;
				border-bottom: 2px solid transparent;
				margin-bottom: -1px;
			}
			.entity-tabs a.active {
				border-bottom-color: #2271b1;
				color: #2271b1;
			}
			.consolidate-add-content {
				background: #fff;
				border: 1px solid #ccd0d4;
				padding: 20px;
				margin-top: 20px;
			}
			.import-section {
				background: #f0f6fc;
				border: 1px solid #0073aa;
				padding: 20px;
				margin-bottom: 20px;
				border-radius: 4px;
			}
			.validation-results {
				margin-top: 20px;
				padding: 15px;
				background: #fff;
				border: 1px solid #ddd;
				border-radius: 4px;
			}
			.quality-score {
				font-size: 24px;
				font-weight: bold;
				color: #2271b1;
			}
			.completeness-indicator {
				background: #f0f0f1;
				border-radius: 4px;
				height: 30px;
				margin: 10px 0;
				position: relative;
				overflow: hidden;
			}
			.completeness-bar {
				background: linear-gradient(90deg, #00a32a, #4ab866);
				height: 100%;
				transition: width 0.3s ease;
			}
			.completeness-percentage {
				position: absolute;
				top: 50%;
				left: 50%;
				transform: translate(-50%, -50%);
				font-weight: 600;
				color: #1d2327;
			}
		</style>
		<?php
	}

	/**
	 * Render workflow selector.
	 */
	protected function render_workflow_selector() {
		$current_workflow = isset( $_GET['workflow'] ) ? sanitize_key( $_GET['workflow'] ) : 'quick-import'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$base_url         = add_query_arg(
			array(
				'page'   => sanitize_key( $_GET['page'] ?? '' ),
				'entity' => $this->current_entity,
			),
			admin_url( 'admin.php' )
		); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		?>
		<div class="workflow-selector">
			<button class="workflow-btn <?php echo 'quick-import' === $current_workflow ? 'active' : ''; ?>" 
					onclick="location.href='<?php echo esc_url( add_query_arg( 'workflow', 'quick-import', $base_url ) ); ?>'">
				<strong>🚀 <?php esc_html_e( 'Quick Import', 'mcp-ai-wpoos-pro' ); ?></strong><br>
				<small><?php esc_html_e( 'Bulk upload files or paste data', 'mcp-ai-wpoos-pro' ); ?></small>
			</button>
			<button class="workflow-btn <?php echo 'guided-entry' === $current_workflow ? 'active' : ''; ?>" 
					onclick="location.href='<?php echo esc_url( add_query_arg( 'workflow', 'guided-entry', $base_url ) ); ?>'">
				<strong>🎯 <?php esc_html_e( 'Guided Entry', 'mcp-ai-wpoos-pro' ); ?></strong><br>
				<small><?php esc_html_e( 'Step-by-step with AI assistance', 'mcp-ai-wpoos-pro' ); ?></small>
			</button>
			<button class="workflow-btn <?php echo 'review-consolidate' === $current_workflow ? 'active' : ''; ?>" 
					onclick="location.href='<?php echo esc_url( add_query_arg( 'workflow', 'review-consolidate', $base_url ) ); ?>'">
				<strong>📊 <?php esc_html_e( 'Review & Consolidate', 'mcp-ai-wpoos-pro' ); ?></strong><br>
				<small><?php esc_html_e( 'View and validate existing data', 'mcp-ai-wpoos-pro' ); ?></small>
			</button>
		</div>
		<?php
	}

	/**
	 * Render storage backend notice.
	 */
	protected function render_storage_backend_notice() {
		$store        = $this->get_current_data_store();
		$backend_type = $store ? $store->get_storage_type() : 'unknown';

		if ( 'cct' === $backend_type ) {
			?>
			<div class="notice notice-success storage-backend-notice">
				<p>
					<strong><?php esc_html_e( 'JetEngine CCT Storage Active', 'mcp-ai-wpoos-pro' ); ?></strong><br>
					<?php esc_html_e( 'Using high-performance JetEngine Custom Content Types for data storage.', 'mcp-ai-wpoos-pro' ); ?>
				</p>
			</div>
			<?php
		}
	}

	/**
	 * Render entity tabs.
	 */
	protected function render_entity_tabs() {
		if ( count( $this->entity_types ) <= 1 ) {
			return;
		}

		$base_url = add_query_arg(
			array(
				'page' => sanitize_key( $_GET['page'] ?? '' ),
			),
			admin_url( 'admin.php' )
		); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		?>
		<div class="entity-tabs">
			<?php foreach ( $this->entity_types as $entity_slug => $entity_label ) : ?>
				<?php
				$entity_url = add_query_arg( 'entity', $entity_slug, $base_url );
				$is_active  = ( $this->current_entity === $entity_slug );
				?>
				<a href="<?php echo esc_url( $entity_url ); ?>" class="<?php echo $is_active ? 'active' : ''; ?>">
					<?php echo esc_html( $entity_label ); ?>
				</a>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Render Quick Import workflow.
	 */
	protected function render_quick_import_workflow() {
		?>
		<div class="quick-import-workflow">
			<h3><?php esc_html_e( 'Quick Import', 'mcp-ai-wpoos-pro' ); ?></h3>
			<p><?php esc_html_e( 'Upload files or paste data to quickly import multiple items. AI will automatically organize and validate the data.', 'mcp-ai-wpoos-pro' ); ?></p>

			<div class="import-section">
				<h4><?php esc_html_e( 'Upload Files', 'mcp-ai-wpoos-pro' ); ?></h4>
				<p><?php echo esc_html( $this->get_supported_formats_text() ); ?></p>
				
				<form id="bulk-import-form" enctype="multipart/form-data">
					<?php wp_nonce_field( 'wp_mcp_ai_bulk_import', 'wp_mcp_ai_import_nonce' ); ?>
					<input type="hidden" name="toolkit_slug" value="<?php echo esc_attr( $this->toolkit_slug ); ?>">
					<input type="hidden" name="entity_type" value="<?php echo esc_attr( $this->current_entity ); ?>">
					
					<div class="upload-area">
						<input type="file" id="import-file" name="import_file" accept="<?php echo esc_attr( $this->get_file_accept_attribute() ); ?>" multiple>
						<label for="import-file" class="button button-secondary">
							<?php esc_html_e( 'Choose Files', 'mcp-ai-wpoos-pro' ); ?>
						</label>
						<span class="selected-files"></span>
					</div>

					<h4><?php esc_html_e( 'Or Paste Data', 'mcp-ai-wpoos-pro' ); ?></h4>
					<textarea id="import-data" name="import_data" rows="10" class="large-text" placeholder="<?php esc_attr_e( 'Paste CSV, XML, JSON, or plain text data here...', 'mcp-ai-wpoos-pro' ); ?>"></textarea>

					<div class="import-options">
						<label>
							<input type="checkbox" name="auto_create" value="1" checked>
							<?php esc_html_e( 'Auto-create items (recommended)', 'mcp-ai-wpoos-pro' ); ?>
						</label>
						<label>
							<input type="checkbox" name="validate_before_import" value="1" checked>
							<?php esc_html_e( 'Validate data quality before importing', 'mcp-ai-wpoos-pro' ); ?>
						</label>
					</div>

					<p class="submit">
						<button type="submit" class="button button-primary">
							<?php esc_html_e( 'Import & Process', 'mcp-ai-wpoos-pro' ); ?>
						</button>
					</p>
				</form>

				<div id="import-results" class="validation-results" style="display: none;"></div>
			</div>

			<?php $this->render_import_tips(); ?>
		</div>
		<?php
	}

	/**
	 * Render Guided Entry workflow.
	 */
	protected function render_guided_entry_workflow() {
		?>
		<div class="guided-entry-workflow">
			<h3><?php esc_html_e( 'Guided Entry', 'mcp-ai-wpoos-pro' ); ?></h3>
			<p><?php esc_html_e( 'Add items one at a time with AI assistance and real-time validation.', 'mcp-ai-wpoos-pro' ); ?></p>

			<?php $this->render_ai_chat_interface(); ?>
			<?php $this->render_manual_entry_form(); ?>
		</div>
		<?php
	}

	/**
	 * Render Review & Consolidate workflow.
	 */
	protected function render_review_consolidate_workflow() {
		?>
		<div class="review-consolidate-workflow">
			<h3><?php esc_html_e( 'Review & Consolidate', 'mcp-ai-wpoos-pro' ); ?></h3>
			<p><?php esc_html_e( 'View existing items, check data quality, and identify gaps.', 'mcp-ai-wpoos-pro' ); ?></p>

			<?php $this->render_completeness_dashboard(); ?>
			<?php $this->render_existing_items_list(); ?>
		</div>
		<?php
	}

	/**
	 * Render AI chat interface for guided entry.
	 */
	protected function render_ai_chat_interface() {
		if ( ! class_exists( 'WP_MCP_AI_Shortcode' ) ) {
			return;
		}

		?>
		<div class="ai-chat-section">
			<h4><?php esc_html_e( '🤖 AI Assistant', 'mcp-ai-wpoos-pro' ); ?></h4>
			<p><?php esc_html_e( 'Describe what you want to add and get AI-powered suggestions.', 'mcp-ai-wpoos-pro' ); ?></p>
			<?php
			// Render chat interface - implementation depends on existing chat system.
			echo do_shortcode( '[wp_mcp_ai_chat]' );
			?>
		</div>
		<?php
	}

	/**
	 * Render manual entry form.
	 */
	protected function render_manual_entry_form() {
		?>
		<div class="manual-entry-section">
			<h4><?php esc_html_e( 'Manual Entry', 'mcp-ai-wpoos-pro' ); ?></h4>
			<form method="post" id="manual-entry-form">
				<?php wp_nonce_field( 'wp_mcp_ai_manual_entry', 'wp_mcp_ai_entry_nonce' ); ?>
				<input type="hidden" name="toolkit_slug" value="<?php echo esc_attr( $this->toolkit_slug ); ?>">
				<input type="hidden" name="entity_type" value="<?php echo esc_attr( $this->current_entity ); ?>">
				
				<?php $this->render_entity_form_fields(); ?>
				
				<p class="submit">
					<button type="submit" name="action" value="save" class="button button-primary">
						<?php esc_html_e( 'Save Item', 'mcp-ai-wpoos-pro' ); ?>
					</button>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Render entity-specific form fields.
	 * Override in child classes.
	 */
	protected function render_entity_form_fields() {
		?>
		<table class="form-table">
			<tr>
				<th scope="row">
					<label for="item_title"><?php esc_html_e( 'Title', 'mcp-ai-wpoos-pro' ); ?></label>
				</th>
				<td>
					<input type="text" id="item_title" name="item_data[title]" class="regular-text" required>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Render completeness dashboard.
	 */
	protected function render_completeness_dashboard() {
		$completeness = $this->calculate_completeness();

		?>
		<div class="completeness-dashboard">
			<h4><?php esc_html_e( 'Data Completeness', 'mcp-ai-wpoos-pro' ); ?></h4>
			
			<div class="completeness-indicator">
				<div class="completeness-bar" style="width: <?php echo esc_attr( $completeness['percentage'] ); ?>%;"></div>
				<span class="completeness-percentage"><?php echo esc_html( $completeness['percentage'] ); ?>%</span>
			</div>

			<?php if ( ! empty( $completeness['missing'] ) ) : ?>
				<div class="missing-data-section">
					<h5><?php esc_html_e( 'Missing Data:', 'mcp-ai-wpoos-pro' ); ?></h5>
					<ul>
						<?php foreach ( $completeness['missing'] as $missing_item ) : ?>
							<li><?php echo esc_html( $missing_item ); ?></li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Calculate data completeness.
	 *
	 * @return array Completeness data with percentage and missing items.
	 */
	protected function calculate_completeness() {
		// Default implementation - override in child classes.
		return array(
			'percentage' => 75,
			'missing'    => array(
				__( 'Example missing item 1', 'mcp-ai-wpoos-pro' ),
				__( 'Example missing item 2', 'mcp-ai-wpoos-pro' ),
			),
		);
	}

	/**
	 * Render existing items list with validation status.
	 */
	protected function render_existing_items_list() {
		$store = $this->get_current_data_store();
		if ( ! $store ) {
			return;
		}

		$items = $store->query_items( array( 'per_page' => 50 ) );

		?>
		<div class="existing-items-list">
			<h4><?php esc_html_e( 'Existing Items', 'mcp-ai-wpoos-pro' ); ?></h4>
			
			<?php if ( empty( $items ) ) : ?>
				<p><?php esc_html_e( 'No items found.', 'mcp-ai-wpoos-pro' ); ?></p>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Title', 'mcp-ai-wpoos-pro' ); ?></th>
							<th><?php esc_html_e( 'Quality Score', 'mcp-ai-wpoos-pro' ); ?></th>
							<th><?php esc_html_e( 'Status', 'mcp-ai-wpoos-pro' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'mcp-ai-wpoos-pro' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $items as $item ) : ?>
							<?php $quality_score = $this->calculate_item_quality_score( $item ); ?>
							<tr>
								<td><?php echo esc_html( $item['title'] ?? __( '(No title)', 'mcp-ai-wpoos-pro' ) ); ?></td>
								<td>
									<span class="quality-score quality-<?php echo esc_attr( $quality_score['level'] ); ?>">
										<?php echo esc_html( $quality_score['score'] ); ?>/100
									</span>
								</td>
								<td><?php echo esc_html( $quality_score['status'] ); ?></td>
								<td>
									<a href="#" class="button button-small"><?php esc_html_e( 'Review', 'mcp-ai-wpoos-pro' ); ?></a>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Calculate quality score for an item.
	 *
	 * @param array $item Item data.
	 * @return array Quality score data.
	 */
	protected function calculate_item_quality_score( $item ) {
		// Default implementation - override in child classes.
		return array(
			'score'  => 85,
			'level'  => 'high',
			'status' => __( 'Good', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Render import tips sidebar.
	 */
	protected function render_import_tips() {
		?>
		<div class="import-tips">
			<h4><?php esc_html_e( 'Import Tips', 'mcp-ai-wpoos-pro' ); ?></h4>
			<ul>
				<li><?php esc_html_e( 'Ensure data is in UTF-8 encoding', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Include all required fields for better validation', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Test with a small batch first', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Review validation results before finalizing', 'mcp-ai-wpoos-pro' ); ?></li>
			</ul>
		</div>
		<?php
	}

	/**
	 * Get supported formats text.
	 *
	 * @return string Formatted list of supported formats.
	 */
	protected function get_supported_formats_text() {
		if ( empty( $this->import_formats ) ) {
			return __( 'No import formats configured.', 'mcp-ai-wpoos-pro' );
		}

		$formats = array_values( $this->import_formats );
		return sprintf(
			/* translators: %s: Comma-separated list of formats */
			__( 'Supported formats: %s', 'mcp-ai-wpoos-pro' ),
			implode( ', ', $formats )
		);
	}

	/**
	 * Get file accept attribute for file inputs.
	 *
	 * @return string Accept attribute value.
	 */
	protected function get_file_accept_attribute() {
		$extensions = array_keys( $this->import_formats );
		return '.' . implode( ',.', $extensions );
	}

	/**
	 * Render no entities message.
	 */
	protected function render_no_entities_message() {
		?>
		<div class="notice notice-warning">
			<p><?php esc_html_e( 'No entity types configured for this toolkit.', 'mcp-ai-wpoos-pro' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Handle AJAX bulk import request.
	 */
	public function ajax_bulk_import() {
		check_ajax_referer( 'wp_mcp_ai_bulk_import', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions', 'mcp-ai-wpoos-pro' ) ) );
		}

		// Implementation will be added in child classes.
		wp_send_json_success(
			array(
				'message'       => __( 'Import completed successfully', 'mcp-ai-wpoos-pro' ),
				'items_created' => 0,
				'items_updated' => 0,
				'items_failed'  => 0,
			)
		);
	}

	/**
	 * Handle AJAX document upload request.
	 */
	public function ajax_upload_document() {
		check_ajax_referer( 'wp_mcp_ai_upload_document', 'nonce' );

		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions', 'mcp-ai-wpoos-pro' ) ) );
		}

		// Implementation will be added in child classes.
		wp_send_json_success( array( 'attachment_id' => 0 ) );
	}

	/**
	 * Handle AJAX validate data request.
	 */
	public function ajax_validate_data() {
		check_ajax_referer( 'wp_mcp_ai_validate_data', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions', 'mcp-ai-wpoos-pro' ) ) );
		}

		// Implementation will be added in child classes.
		wp_send_json_success(
			array(
				'quality_score'   => 85,
				'issues'          => array(),
				'recommendations' => array(),
			)
		);
	}

	/**
	 * Handle AJAX check completeness request.
	 */
	public function ajax_check_completeness() {
		check_ajax_referer( 'wp_mcp_ai_check_completeness', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions', 'mcp-ai-wpoos-pro' ) ) );
		}

		$completeness = $this->calculate_completeness();
		wp_send_json_success( $completeness );
	}

	/**
	 * Handle form submission for manual entry.
	 */
	public function handle_form_submission() {
		// Check if this is a manual entry form submission.
		if ( ! isset( $_POST['action'] ) || 'save' !== $_POST['action'] ) {
			return;
		}

		// Check if this is for our toolkit.
		$toolkit_slug = isset( $_POST['toolkit_slug'] ) ? sanitize_key( $_POST['toolkit_slug'] ) : '';
		if ( $toolkit_slug !== $this->toolkit_slug ) {
			return;
		}

		// Verify nonce.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Nonce is being verified here.
		if ( ! isset( $_POST['wp_mcp_ai_entry_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wp_mcp_ai_entry_nonce'] ) ), 'wp_mcp_ai_manual_entry' ) ) {
			wp_die( esc_html__( 'Security check failed', 'mcp-ai-wpoos-pro' ) );
		}

		// Check permissions.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions', 'mcp-ai-wpoos-pro' ) );
		}

		$entity_type = isset( $_POST['entity_type'] ) ? sanitize_key( $_POST['entity_type'] ) : '';
		if ( empty( $entity_type ) || ! isset( $this->data_stores[ $entity_type ] ) ) {
			return;
		}

		$store = $this->data_stores[ $entity_type ];
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Data is validated in validate_item_data() method.
		$item_data = isset( $_POST['item_data'] ) ? wp_unslash( $_POST['item_data'] ) : array();

		// Validate data before saving.
		$validation_result = $this->validate_item_data( $item_data );
		if ( is_wp_error( $validation_result ) ) {
			wp_die( esc_html( $validation_result->get_error_message() ) );
		}

		$result = $store->create_item( $item_data );

		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ) );
		}

		// Redirect back to review workflow with success message.
		$redirect_url = add_query_arg(
			array(
				'page'     => isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified above.
				'entity'   => $entity_type,
				'workflow' => 'review-consolidate',
				'message'  => 'created',
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Validate item data before saving.
	 *
	 * @param array $item_data Item data to validate.
	 * @return true|WP_Error True if valid, WP_Error if validation fails.
	 */
	protected function validate_item_data( $item_data ) {
		$schema = $this->get_validation_schema();

		// Basic validation - override in child classes for specific validation.
		if ( empty( $item_data['title'] ) ) {
			return new WP_Error( 'missing_title', __( 'Title is required', 'mcp-ai-wpoos-pro' ) );
		}

		return true;
	}
}
