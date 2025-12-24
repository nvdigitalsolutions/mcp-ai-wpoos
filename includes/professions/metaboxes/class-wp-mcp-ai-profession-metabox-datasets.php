<?php
/**
 * Profession Datasets Metabox.
 *
 * Handles preferred HuggingFace datasets for professions.
 *
 * @package WP_MCP_AI
 * @since 1.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Profession datasets metabox.
 */
class WP_MCP_AI_Profession_Metabox_Datasets extends WP_MCP_AI_Profession_Metabox_Base {

	/**
	 * Get the metabox ID.
	 *
	 * @return string
	 */
	public function get_id() {
		return 'wp_mcp_ai_profession_datasets';
	}

	/**
	 * Get the metabox title.
	 *
	 * @return string
	 */
	public function get_title() {
		return __( 'Preferred Datasets', 'wp-mcp-ai' );
	}

	/**
	 * Get metabox context.
	 *
	 * @return string
	 */
	public function get_context() {
		return 'normal';
	}

	/**
	 * Get metabox priority.
	 *
	 * @return string
	 */
	public function get_priority() {
		return 'low';
	}

	/**
	 * Render the metabox content.
	 *
	 * @param WP_Post $post The post object.
	 * @return void
	 */
	public function render( $post ) {
		// Check if HuggingFace Datasets integration is enabled.
		$settings = WP_MCP_AI_Admin_Settings::get_settings();
		if ( empty( $settings['enable_huggingface_datasets'] ) ) {
			?>
			<p class="description">
				<?php
				printf(
					/* translators: %s: URL to settings page */
					esc_html__( 'HuggingFace Datasets integration is not enabled. Please enable it in %s.', 'wp-mcp-ai' ),
					'<a href="' . esc_url( admin_url( 'admin.php?page=wp-mcp-ai-settings' ) ) . '">' . esc_html__( 'WP oOS Settings', 'wp-mcp-ai' ) . '</a>'
				);
				?>
			</p>
			<?php
			return;
		}

		wp_nonce_field( 'wp_mcp_ai_save_profession_datasets', 'wp_mcp_ai_profession_datasets_nonce' );

		// Get currently assigned datasets.
		$preferred_datasets = get_post_meta( $post->ID, WP_MCP_AI_Profession_CPT::META_PREFERRED_DATASETS, true );
		if ( ! is_array( $preferred_datasets ) ) {
			$preferred_datasets = array();
		}

		// Load dataset mappings to get available datasets.
		require_once WP_MCP_AI_PATH . 'includes/professions/profession-dataset-mappings.php';
		$all_mappings = wp_mcp_ai_get_all_profession_dataset_mappings();

		// Build a flat list of all available datasets.
		$available_datasets = array();
		foreach ( $all_mappings as $profession_slug => $datasets ) {
			foreach ( $datasets as $dataset ) {
				$key = $dataset['dataset'];
				if ( ! isset( $available_datasets[ $key ] ) ) {
					$available_datasets[ $key ] = array(
						'dataset'  => $dataset['dataset'],
						'name'     => $dataset['name'],
						'category' => $dataset['category'],
					);
				}
			}
		}

		// Sort by name.
		uasort(
			$available_datasets,
			function( $a, $b ) {
				return strcmp( $a['name'], $b['name'] );
			}
		);

		?>
		<div class="wp-mcp-ai-profession-datasets">
			<p class="description">
				<?php esc_html_e( 'Select up to 10 HuggingFace datasets that are most relevant for this profession. These datasets will be recommended when creating assistants from this profession template.', 'wp-mcp-ai' ); ?>
			</p>

			<?php if ( ! empty( $post->post_name ) ) : ?>
				<p class="description" style="margin-bottom: 15px;">
					<strong><?php esc_html_e( 'Profession Slug:', 'wp-mcp-ai' ); ?></strong> 
					<code><?php echo esc_html( $post->post_name ); ?></code>
					<br>
					<em><?php esc_html_e( 'Auto-assignment is based on this slug in the dataset mappings file.', 'wp-mcp-ai' ); ?></em>
				</p>
			<?php endif; ?>

			<div class="wp-mcp-ai-datasets-filters" style="margin: 15px 0;">
				<label>
					<?php esc_html_e( 'Filter by category:', 'wp-mcp-ai' ); ?>
					<select id="wp-mcp-ai-dataset-category-filter" style="margin-left: 5px;">
						<option value=""><?php esc_html_e( 'All Categories', 'wp-mcp-ai' ); ?></option>
						<option value="nlp"><?php esc_html_e( 'NLP (Natural Language)', 'wp-mcp-ai' ); ?></option>
						<option value="vision"><?php esc_html_e( 'Vision (Image)', 'wp-mcp-ai' ); ?></option>
						<option value="audio"><?php esc_html_e( 'Audio', 'wp-mcp-ai' ); ?></option>
						<option value="multimodal"><?php esc_html_e( 'Multimodal', 'wp-mcp-ai' ); ?></option>
					</select>
				</label>
			</div>

			<div class="wp-mcp-ai-datasets-list" style="max-height: 400px; overflow-y: auto; border: 1px solid #ddd; padding: 15px; background: #fafafa;">
				<?php if ( empty( $available_datasets ) ) : ?>
					<p><?php esc_html_e( 'No datasets available. Datasets are defined in the profession-dataset-mappings.php file.', 'wp-mcp-ai' ); ?></p>
				<?php else : ?>
					<?php
					$selected_dataset_ids = array();
					foreach ( $preferred_datasets as $pref ) {
						if ( isset( $pref['dataset'] ) ) {
							$selected_dataset_ids[] = $pref['dataset'];
						}
					}
					?>
					<?php foreach ( $available_datasets as $dataset_id => $dataset ) : ?>
						<div class="wp-mcp-ai-dataset-item" data-category="<?php echo esc_attr( $dataset['category'] ); ?>" style="margin-bottom: 12px; padding-bottom: 12px; border-bottom: 1px solid #e5e5e5;">
							<label style="display: flex; align-items: flex-start; cursor: pointer;">
								<input 
									type="checkbox" 
									name="profession_preferred_datasets[]" 
									value="<?php echo esc_attr( $dataset_id ); ?>" 
									<?php checked( in_array( $dataset_id, $selected_dataset_ids, true ) ); ?>
									style="margin: 2px 10px 0 0; flex-shrink: 0;"
								>
								<span style="flex: 1;">
									<strong><?php echo esc_html( $dataset['name'] ); ?></strong>
									<br>
									<code style="font-size: 11px; color: #666;"><?php echo esc_html( $dataset['dataset'] ); ?></code>
									<br>
									<span class="wp-mcp-ai-dataset-category" style="display: inline-block; margin-top: 4px; padding: 2px 8px; background: #e8f5e9; border-radius: 3px; font-size: 11px; color: #2e7d32;">
										<?php
										$category_labels = array(
											'nlp'        => __( 'NLP', 'wp-mcp-ai' ),
											'vision'     => __( 'Vision', 'wp-mcp-ai' ),
											'audio'      => __( 'Audio', 'wp-mcp-ai' ),
											'multimodal' => __( 'Multimodal', 'wp-mcp-ai' ),
										);
										echo esc_html( isset( $category_labels[ $dataset['category'] ] ) ? $category_labels[ $dataset['category'] ] : $dataset['category'] );
										?>
									</span>
								</span>
							</label>
						</div>
					<?php endforeach; ?>
				<?php endif; ?>
			</div>

			<p class="description" style="margin-top: 15px;">
				<strong><?php esc_html_e( 'Selected:', 'wp-mcp-ai' ); ?></strong>
				<span id="wp-mcp-ai-selected-count"><?php echo esc_html( count( $preferred_datasets ) ); ?></span> / 10
			</p>

			<?php if ( ! empty( $preferred_datasets ) ) : ?>
				<div style="margin-top: 15px; padding: 10px; background: #fff; border: 1px solid #ddd;">
					<strong><?php esc_html_e( 'Currently Selected Datasets:', 'wp-mcp-ai' ); ?></strong>
					<ul style="margin: 10px 0 0 20px;">
						<?php foreach ( $preferred_datasets as $pref ) : ?>
							<li>
								<?php echo esc_html( $pref['name'] ); ?>
								<code style="font-size: 11px; color: #666;">(<?php echo esc_html( $pref['dataset'] ); ?>)</code>
								- <em><?php echo esc_html( ucfirst( $pref['category'] ) ); ?></em>
								- <?php echo esc_html( ucfirst( $pref['priority'] ) ); ?> priority
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>
		</div>

		<script type="text/javascript">
		jQuery(document).ready(function($) {
			// Category filter
			$('#wp-mcp-ai-dataset-category-filter').on('change', function() {
				var category = $(this).val();
				if (category === '') {
					$('.wp-mcp-ai-dataset-item').show();
				} else {
					$('.wp-mcp-ai-dataset-item').hide();
					$('.wp-mcp-ai-dataset-item[data-category="' + category + '"]').show();
				}
			});

			// Update selected count
			function updateSelectedCount() {
				var count = $('input[name="profession_preferred_datasets[]"]:checked').length;
				$('#wp-mcp-ai-selected-count').text(count);
				
				// Disable checkboxes if 10 are selected
				if (count >= 10) {
					$('input[name="profession_preferred_datasets[]"]:not(:checked)').prop('disabled', true);
				} else {
					$('input[name="profession_preferred_datasets[]"]').prop('disabled', false);
				}
			}

			$('input[name="profession_preferred_datasets[]"]').on('change', updateSelectedCount);
			updateSelectedCount();
		});
		</script>

		<style>
		.wp-mcp-ai-dataset-item:last-child {
			border-bottom: none;
			padding-bottom: 0;
			margin-bottom: 0;
		}
		</style>
		<?php
	}

	/**
	 * Save metabox data.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 * @return void
	 */
	public function save( $post_id, $post ) {
		if ( ! isset( $_POST['wp_mcp_ai_profession_datasets_nonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wp_mcp_ai_profession_datasets_nonce'] ) ), 'wp_mcp_ai_save_profession_datasets' ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Load dataset mappings.
		require_once WP_MCP_AI_PATH . 'includes/professions/profession-dataset-mappings.php';
		$all_mappings = wp_mcp_ai_get_all_profession_dataset_mappings();

		// Build lookup table for dataset info.
		$dataset_lookup = array();
		foreach ( $all_mappings as $profession_slug => $datasets ) {
			foreach ( $datasets as $dataset ) {
				$key = $dataset['dataset'];
				if ( ! isset( $dataset_lookup[ $key ] ) ) {
					$dataset_lookup[ $key ] = $dataset;
				}
			}
		}

		// Get selected datasets from form.
		$selected_datasets = array();
		if ( isset( $_POST['profession_preferred_datasets'] ) && is_array( $_POST['profession_preferred_datasets'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized below.
			$raw_datasets = wp_unslash( $_POST['profession_preferred_datasets'] );

			foreach ( $raw_datasets as $dataset_id ) {
				$dataset_id = sanitize_text_field( $dataset_id );
				
				// Build dataset entry with info from lookup.
				if ( isset( $dataset_lookup[ $dataset_id ] ) ) {
					$selected_datasets[] = array(
						'dataset'  => $dataset_id,
						'name'     => $dataset_lookup[ $dataset_id ]['name'],
						'category' => $dataset_lookup[ $dataset_id ]['category'],
						'priority' => isset( $dataset_lookup[ $dataset_id ]['priority'] ) ? $dataset_lookup[ $dataset_id ]['priority'] : 'medium',
					);
				}
			}
		}

		// Sanitize and save.
		$sanitized_datasets = WP_MCP_AI_Profession_CPT::sanitize_preferred_datasets( $selected_datasets );
		update_post_meta( $post_id, WP_MCP_AI_Profession_CPT::META_PREFERRED_DATASETS, $sanitized_datasets );
	}
}
