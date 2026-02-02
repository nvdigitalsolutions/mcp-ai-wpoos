<?php
/**
 * Research Page Enhancement Traits
 *
 * Provides import, consolidation, and validation features for research pages.
 * Based on Health & Wellness consolidation page patterns.
 *
 * @package WP_MCP_AI_Pro
 * @since 2.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound

/**
 * Import Handler Trait
 *
 * Provides data import functionality for research pages.
 */
trait WP_MCP_AI_Research_Page_Import_Handler {

	/**
	 * Get supported import formats for this entity.
	 * Override in implementing class.
	 *
	 * @return array Import formats.
	 */
	protected static function get_import_formats() {
		return array(
			'csv'  => 'CSV',
			'json' => 'JSON',
		);
	}

	/**
	 * Render import section.
	 */
	protected static function render_import_section() {
		$formats = static::get_import_formats();
		?>
		<div class="wp-mcp-ai-import-container">
			<div class="wp-mcp-ai-import-header">
				<h2><?php esc_html_e( 'Import Data', 'mcp-ai-wpoos-pro' ); ?></h2>
				<p><?php esc_html_e( 'Upload files or paste data to quickly import multiple items.', 'mcp-ai-wpoos-pro' ); ?></p>
			</div>

			<div class="wp-mcp-ai-import-methods">
				<div class="import-method">
					<h3><?php esc_html_e( '📁 Upload File', 'mcp-ai-wpoos-pro' ); ?></h3>
					<p class="description">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: Comma-separated list of formats */
								__( 'Supported formats: %s', 'mcp-ai-wpoos-pro' ),
								implode( ', ', $formats )
							)
						);
						?>
					</p>
					<form id="wp-mcp-ai-import-file-form" enctype="multipart/form-data">
						<?php wp_nonce_field( 'wp_mcp_ai_import_data', 'wp_mcp_ai_import_nonce' ); ?>
						<input type="file" id="import-file" name="import_file" accept="<?php echo esc_attr( static::get_file_accept_attribute() ); ?>">
						<label for="import-file" class="button button-secondary">
							<?php esc_html_e( 'Choose File', 'mcp-ai-wpoos-pro' ); ?>
						</label>
						<span class="selected-file-name"></span>
					</form>
				</div>

				<div class="import-method">
					<h3><?php esc_html_e( '📋 Paste Data', 'mcp-ai-wpoos-pro' ); ?></h3>
					<p class="description"><?php esc_html_e( 'Paste CSV, JSON, or other formatted data', 'mcp-ai-wpoos-pro' ); ?></p>
					<textarea id="import-data-paste" rows="8" class="large-text" placeholder="<?php esc_attr_e( 'Paste your data here...', 'mcp-ai-wpoos-pro' ); ?>"></textarea>
				</div>
			</div>

			<div class="wp-mcp-ai-import-options">
				<h3><?php esc_html_e( 'Import Options', 'mcp-ai-wpoos-pro' ); ?></h3>
				<label>
					<input type="checkbox" id="import-validate" checked>
					<?php esc_html_e( 'Validate data before importing', 'mcp-ai-wpoos-pro' ); ?>
				</label>
				<label>
					<input type="checkbox" id="import-auto-create" checked>
					<?php esc_html_e( 'Automatically create items', 'mcp-ai-wpoos-pro' ); ?>
				</label>
			</div>

			<div class="wp-mcp-ai-import-actions">
				<button type="button" id="wp-mcp-ai-import-btn" class="button button-primary button-large">
					<?php esc_html_e( 'Import & Process', 'mcp-ai-wpoos-pro' ); ?>
				</button>
				<span class="spinner"></span>
			</div>

			<div id="wp-mcp-ai-import-results" class="wp-mcp-ai-import-results" style="display: none;"></div>

			<?php static::render_import_tips(); ?>
		</div>
		<?php
	}

	/**
	 * Render import tips.
	 */
	protected static function render_import_tips() {
		?>
		<div class="wp-mcp-ai-import-tips">
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
	 * Get file accept attribute for file inputs.
	 *
	 * @return string Accept attribute value.
	 */
	protected static function get_file_accept_attribute() {
		$formats = array_keys( static::get_import_formats() );
		return '.' . implode( ',.', $formats );
	}

	/**
	 * Handle AJAX import request.
	 */
	public static function ajax_handle_import() {
		check_ajax_referer( 'wp_mcp_ai_import_data', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions', 'mcp-ai-wpoos-pro' ) ) );
		}

		$data   = isset( $_POST['data'] ) ? sanitize_textarea_field( wp_unslash( $_POST['data'] ) ) : '';
		$format = isset( $_POST['format'] ) ? sanitize_key( $_POST['format'] ) : 'csv';

		$result = static::process_import_data( $data, $format );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( $result );
	}

	/**
	 * Process imported data.
	 * Override in implementing class.
	 *
	 * @param string $data   Import data.
	 * @param string $format Data format.
	 * @return array|WP_Error Result or error.
	 */
	protected static function process_import_data( $data, $format ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		return new WP_Error( 'not_implemented', __( 'Import processing not implemented', 'mcp-ai-wpoos-pro' ) );
	}
}

/**
 * Consolidation Dashboard Trait
 *
 * Provides data consolidation and review functionality.
 */
trait WP_MCP_AI_Research_Page_Consolidation {

	/**
	 * Render consolidation dashboard.
	 */
	protected static function render_consolidation_dashboard() {
		?>
		<div class="wp-mcp-ai-consolidation-container">
			<div class="wp-mcp-ai-consolidation-header">
				<h2><?php esc_html_e( 'Review & Consolidate', 'mcp-ai-wpoos-pro' ); ?></h2>
				<p><?php esc_html_e( 'View existing items, check data quality, and identify gaps.', 'mcp-ai-wpoos-pro' ); ?></p>
			</div>

			<?php static::render_completeness_widget(); ?>
			<?php static::render_quality_table(); ?>
		</div>
		<?php
	}

	/**
	 * Render completeness widget.
	 */
	protected static function render_completeness_widget() {
		$completeness = static::calculate_completeness();
		?>
		<div class="completeness-widget">
			<h3><?php esc_html_e( 'Data Completeness', 'mcp-ai-wpoos-pro' ); ?></h3>
			
			<div class="completeness-meter">
				<div class="completeness-bar" style="width: <?php echo esc_attr( $completeness['percentage'] ); ?>%;"></div>
				<span class="completeness-percentage"><?php echo esc_html( $completeness['percentage'] ); ?>%</span>
			</div>

			<?php if ( ! empty( $completeness['missing'] ) ) : ?>
				<div class="missing-data">
					<h4><?php esc_html_e( 'Missing or Incomplete:', 'mcp-ai-wpoos-pro' ); ?></h4>
					<ul>
						<?php foreach ( $completeness['missing'] as $item ) : ?>
							<li><?php echo esc_html( $item ); ?></li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>

			<?php if ( ! empty( $completeness['suggestions'] ) ) : ?>
				<div class="completeness-suggestions">
					<h4><?php esc_html_e( 'Suggestions:', 'mcp-ai-wpoos-pro' ); ?></h4>
					<ul>
						<?php foreach ( $completeness['suggestions'] as $suggestion ) : ?>
							<li><?php echo esc_html( $suggestion ); ?></li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render quality table.
	 */
	protected static function render_quality_table() {
		$items = static::get_items_for_review();
		?>
		<div class="quality-table">
			<h3><?php esc_html_e( 'Item Quality Scores', 'mcp-ai-wpoos-pro' ); ?></h3>
			
			<?php if ( empty( $items ) ) : ?>
				<p><?php esc_html_e( 'No items found.', 'mcp-ai-wpoos-pro' ); ?></p>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Title', 'mcp-ai-wpoos-pro' ); ?></th>
							<th><?php esc_html_e( 'Quality Score', 'mcp-ai-wpoos-pro' ); ?></th>
							<th><?php esc_html_e( 'Status', 'mcp-ai-wpoos-pro' ); ?></th>
							<th><?php esc_html_e( 'Issues', 'mcp-ai-wpoos-pro' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'mcp-ai-wpoos-pro' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $items as $item ) : ?>
							<?php
							$quality  = static::calculate_quality_score( $item );
							$edit_url = static::get_edit_url( $item );
							?>
							<tr>
								<td>
									<strong><?php echo esc_html( $item['title'] ?? __( '(No title)', 'mcp-ai-wpoos-pro' ) ); ?></strong>
								</td>
								<td>
									<span class="quality-score quality-<?php echo esc_attr( $quality['level'] ); ?>">
										<?php echo esc_html( $quality['score'] ); ?>/100
									</span>
								</td>
								<td><?php echo esc_html( $quality['status'] ); ?></td>
								<td>
									<?php if ( ! empty( $quality['issues'] ) ) : ?>
										<span class="dashicons dashicons-warning" style="color: #f56e28;"></span>
										<?php echo esc_html( count( $quality['issues'] ) ); ?>
									<?php else : ?>
										<span class="dashicons dashicons-yes-alt" style="color: #00a32a;"></span>
									<?php endif; ?>
								</td>
								<td>
									<a href="<?php echo esc_url( $edit_url ); ?>" class="button button-small">
										<?php esc_html_e( 'Edit', 'mcp-ai-wpoos-pro' ); ?>
									</a>
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
	 * Calculate completeness.
	 * Override in implementing class.
	 *
	 * @return array Completeness data.
	 */
	protected static function calculate_completeness() {
		return array(
			'percentage'  => 0,
			'missing'     => array(),
			'suggestions' => array(),
		);
	}

	/**
	 * Get items for review.
	 * Override in implementing class.
	 *
	 * @return array Items.
	 */
	protected static function get_items_for_review() {
		return array();
	}

	/**
	 * Calculate quality score for item.
	 * Override in implementing class.
	 *
	 * @param array $item Item data.
	 * @return array Quality data.
	 */
	protected static function calculate_quality_score( $item ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		return array(
			'score'  => 0,
			'level'  => 'low',
			'status' => __( 'Unknown', 'mcp-ai-wpoos-pro' ),
			'issues' => array(),
		);
	}

	/**
	 * Get edit URL for item.
	 * Override in implementing class.
	 *
	 * @param array $item Item data.
	 * @return string Edit URL.
	 */
	protected static function get_edit_url( $item ) {
		return admin_url( 'post.php?post=' . absint( $item['id'] ) . '&action=edit' );
	}
}

/**
 * Data Validation Trait
 *
 * Provides data validation functionality based on industry standards.
 */
trait WP_MCP_AI_Research_Page_Data_Validation {

	/**
	 * Get validation schema for this entity type.
	 * Override in implementing class.
	 *
	 * @return array Validation schema.
	 */
	protected static function get_validation_schema() {
		return array(
			'required_fields'    => array(),
			'recommended_fields' => array(),
			'validation_rules'   => array(),
			'quality_dimensions' => array(),
		);
	}

	/**
	 * Validate item data.
	 *
	 * @param array $data Item data.
	 * @return true|WP_Error True if valid, error otherwise.
	 */
	protected static function validate_item_data( $data ) {
		$schema = static::get_validation_schema();
		$errors = array();

		// Check required fields.
		foreach ( $schema['required_fields'] as $field => $label ) {
			if ( empty( $data[ $field ] ) ) {
				$errors[] = sprintf(
					/* translators: %s: Field label */
					__( 'Required field missing: %s', 'mcp-ai-wpoos-pro' ),
					$label
				);
			}
		}

		// Apply validation rules.
		foreach ( $schema['validation_rules'] as $field => $rules ) {
			if ( ! isset( $data[ $field ] ) ) {
				continue;
			}

			$value = $data[ $field ];

			// Type validation.
			if ( isset( $rules['type'] ) ) {
				switch ( $rules['type'] ) {
					case 'numeric':
						if ( ! is_numeric( $value ) ) {
							$errors[] = sprintf(
								/* translators: %s: Field name */
								__( '%s must be a number', 'mcp-ai-wpoos-pro' ),
								$field
							);
						}
						break;
					case 'email':
						if ( ! is_email( $value ) ) {
							$errors[] = sprintf(
								/* translators: %s: Field name */
								__( '%s must be a valid email', 'mcp-ai-wpoos-pro' ),
								$field
							);
						}
						break;
					case 'url':
						if ( ! filter_var( $value, FILTER_VALIDATE_URL ) ) {
							$errors[] = sprintf(
								/* translators: %s: Field name */
								__( '%s must be a valid URL', 'mcp-ai-wpoos-pro' ),
								$field
							);
						}
						break;
				}
			}

			// Min/max value validation.
			if ( isset( $rules['min_value'] ) && is_numeric( $value ) && $value < $rules['min_value'] ) {
				$errors[] = sprintf(
					/* translators: 1: Field name, 2: Minimum value */
					__( '%1$s must be at least %2$s', 'mcp-ai-wpoos-pro' ),
					$field,
					$rules['min_value']
				);
			}

			if ( isset( $rules['max_value'] ) && is_numeric( $value ) && $value > $rules['max_value'] ) {
				$errors[] = sprintf(
					/* translators: 1: Field name, 2: Maximum value */
					__( '%1$s must be at most %2$s', 'mcp-ai-wpoos-pro' ),
					$field,
					$rules['max_value']
				);
			}

			// Length validation.
			if ( isset( $rules['max_length'] ) && strlen( $value ) > $rules['max_length'] ) {
				$errors[] = sprintf(
					/* translators: 1: Field name, 2: Maximum length */
					__( '%1$s must be %2$d characters or less', 'mcp-ai-wpoos-pro' ),
					$field,
					$rules['max_length']
				);
			}
		}

		if ( ! empty( $errors ) ) {
			return new WP_Error( 'validation_failed', implode( '<br>', $errors ) );
		}

		return true;
	}
}

/**
 * Mode Tabs Trait
 *
 * Provides tabbed navigation between Chat, Import, and Consolidate modes.
 */
trait WP_MCP_AI_Research_Page_Mode_Tabs {

	/**
	 * Render mode tabs.
	 *
	 * @param string $current_mode Current active mode.
	 */
	protected static function render_mode_tabs( $current_mode = '' ) {
		// Get current mode from query string if not provided.
		if ( empty( $current_mode ) ) {
			$current_mode = self::get_current_mode();
		}

		$modes = array(
			'chat'        => array(
				'icon'  => '💬',
				'label' => __( 'AI Assistant', 'mcp-ai-wpoos-pro' ),
			),
			'import'      => array(
				'icon'  => '📁',
				'label' => __( 'Import Data', 'mcp-ai-wpoos-pro' ),
			),
			'consolidate' => array(
				'icon'  => '📊',
				'label' => __( 'Review & Consolidate', 'mcp-ai-wpoos-pro' ),
			),
		);

		?>
		<div class="wp-mcp-ai-mode-tabs">
			<?php foreach ( $modes as $mode => $data ) : ?>
				<?php
				$url    = add_query_arg( 'mode', $mode );
				$active = ( $mode === $current_mode ) ? 'active' : '';
				?>
				<a href="<?php echo esc_url( $url ); ?>" class="mode-tab <?php echo esc_attr( $active ); ?>">
					<span class="mode-icon"><?php echo esc_html( $data['icon'] ); ?></span>
					<span class="mode-label"><?php echo esc_html( $data['label'] ); ?></span>
				</a>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Get current mode from query string.
	 *
	 * @return string Current mode.
	 */
	protected static function get_current_mode() {
		$mode = isset( $_GET['mode'] ) ? sanitize_key( $_GET['mode'] ) : 'chat'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$valid_modes = array( 'chat', 'import', 'consolidate' );
		if ( ! in_array( $mode, $valid_modes, true ) ) {
			$mode = 'chat';
		}

		return $mode;
	}
}
