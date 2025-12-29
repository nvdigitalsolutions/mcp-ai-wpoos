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
					'<a href="' . esc_url( admin_url( 'admin.php?page=wp-mcp-ai-settings' ) ) . '">' . esc_html__( 'NV oOS Settings', 'wp-mcp-ai' ) . '</a>'
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

		// Get available datasets from the catalog (same as Assistant metabox).
		$available_datasets = $this->get_dataset_catalog();

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
						<option value="nlp"><?php esc_html_e( 'NLP', 'wp-mcp-ai' ); ?></option>
						<option value="vision"><?php esc_html_e( 'Vision', 'wp-mcp-ai' ); ?></option>
						<option value="audio"><?php esc_html_e( 'Audio', 'wp-mcp-ai' ); ?></option>
						<option value="multimodal"><?php esc_html_e( 'Multimodal', 'wp-mcp-ai' ); ?></option>
					</select>
				</label>
				<label style="margin-left: 15px;">
					<?php esc_html_e( 'Search:', 'wp-mcp-ai' ); ?>
					<input type="text" id="wp-mcp-ai-dataset-search" placeholder="<?php esc_attr_e( 'Search datasets...', 'wp-mcp-ai' ); ?>" style="width: 250px; margin-left: 5px;">
				</label>
			</div>

			<table class="widefat striped" id="wp-mcp-ai-datasets-table">
				<thead>
					<tr>
						<th style="width: 40px;"></th>
						<th><?php esc_html_e( 'Dataset', 'wp-mcp-ai' ); ?></th>
						<th><?php esc_html_e( 'Category', 'wp-mcp-ai' ); ?></th>
						<th><?php esc_html_e( 'Priority', 'wp-mcp-ai' ); ?></th>
						<th><?php esc_html_e( 'Description', 'wp-mcp-ai' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php
					// Build selected datasets array for checking.
					$selected_datasets_json = array();
					foreach ( $preferred_datasets as $pref ) {
						if ( isset( $pref['dataset'] ) ) {
							$selected_datasets_json[] = wp_json_encode( $pref );
						}
					}
					?>
					<?php foreach ( $available_datasets as $dataset_info ) : ?>
						<?php
						$dataset_json = wp_json_encode(
							array(
								'dataset'  => $dataset_info['dataset'],
								'name'     => $dataset_info['name'],
								'category' => $dataset_info['category'],
								'priority' => $dataset_info['priority'],
							)
						);
						$is_checked   = in_array( $dataset_json, $selected_datasets_json, true );
						$tags_string  = isset( $dataset_info['tags'] ) ? implode( ' ', $dataset_info['tags'] ) : '';
						?>
						<tr class="wp-mcp-ai-dataset-row" 
							data-category="<?php echo esc_attr( $dataset_info['category'] ); ?>" 
							data-name="<?php echo esc_attr( strtolower( $dataset_info['name'] ) ); ?>" 
							data-description="<?php echo esc_attr( strtolower( $dataset_info['description'] ) ); ?>"
							data-tags="<?php echo esc_attr( $tags_string ); ?>">
							<td>
								<input type="checkbox" 
									name="profession_preferred_datasets[]" 
									value="<?php echo esc_attr( $dataset_json ); ?>" 
									class="wp-mcp-ai-dataset-checkbox" 
									<?php checked( $is_checked ); ?>>
							</td>
							<td>
								<strong><?php echo esc_html( $dataset_info['name'] ); ?></strong>
								<br>
								<code style="font-size: 11px; color: #666;"><?php echo esc_html( $dataset_info['dataset'] ); ?></code>
							</td>
							<td>
								<span class="wp-mcp-ai-category-badge wp-mcp-ai-category-<?php echo esc_attr( $dataset_info['category'] ); ?>">
									<?php echo esc_html( ucfirst( $dataset_info['category'] ) ); ?>
								</span>
							</td>
							<td>
								<span class="wp-mcp-ai-priority-badge wp-mcp-ai-priority-<?php echo esc_attr( $dataset_info['priority'] ); ?>">
									<?php echo esc_html( ucfirst( $dataset_info['priority'] ) ); ?>
								</span>
							</td>
							<td>
								<?php echo esc_html( $dataset_info['description'] ); ?>
								<br>
								<small style="color: #666;">
									<?php
									/* translators: %s: Dataset size */
									printf( esc_html__( 'Size: %s', 'wp-mcp-ai' ), esc_html( $dataset_info['size'] ) );
									?>
								</small>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<p class="description" style="margin-top: 10px;">
				<?php esc_html_e( 'Maximum 10 datasets can be selected. Additional selections will uncheck earlier selections.', 'wp-mcp-ai' ); ?>
			</p>
		</div>

		<style>
			.wp-mcp-ai-category-badge,
			.wp-mcp-ai-priority-badge {
				display: inline-block;
				padding: 2px 8px;
				border-radius: 3px;
				font-size: 11px;
				font-weight: 600;
			}
			.wp-mcp-ai-category-badge {
				background: #f0f0f1;
			}
			.wp-mcp-ai-category-nlp { background: #e3f2fd; color: #1976d2; }
			.wp-mcp-ai-category-vision { background: #f3e5f5; color: #7b1fa2; }
			.wp-mcp-ai-category-audio { background: #e8f5e9; color: #388e3c; }
			.wp-mcp-ai-category-multimodal { background: #fff3e0; color: #f57c00; }
			.wp-mcp-ai-priority-critical { background: #ffebee; color: #c62828; }
			.wp-mcp-ai-priority-high { background: #fff8e1; color: #f57f17; }
			.wp-mcp-ai-priority-medium { background: #e0f2f1; color: #00796b; }
			.wp-mcp-ai-datasets-filters {
				background: #f9f9f9;
				padding: 10px;
				border: 1px solid #ddd;
				border-radius: 3px;
			}
		</style>

		<script type="text/javascript">
		( function() {
			var maxDatasets = 10;
			
			document.addEventListener( 'DOMContentLoaded', function() {
				var checkboxes = document.querySelectorAll( '.wp-mcp-ai-dataset-checkbox' );
				var categoryFilter = document.getElementById( 'wp-mcp-ai-dataset-category-filter' );
				var searchInput = document.getElementById( 'wp-mcp-ai-dataset-search' );
				var rows = document.querySelectorAll( '.wp-mcp-ai-dataset-row' );
				
				// Handle checkbox selection with max limit.
				checkboxes.forEach( function( checkbox ) {
					checkbox.addEventListener( 'change', function() {
						var checked = document.querySelectorAll( '.wp-mcp-ai-dataset-checkbox:checked' );
						
						if ( checked.length > maxDatasets ) {
							// Uncheck the first checked item.
							checked[0].checked = false;
						}
					} );
				} );
				
				// Handle filtering.
				function filterDatasets() {
					var category = categoryFilter.value.toLowerCase();
					var search = searchInput.value.toLowerCase().trim();
					
					rows.forEach( function( row ) {
						var rowCategory = row.getAttribute( 'data-category' ).toLowerCase();
						var rowName = row.getAttribute( 'data-name' ).toLowerCase();
						var rowDesc = row.getAttribute( 'data-description' ).toLowerCase();
						var rowTags = row.getAttribute( 'data-tags' ).toLowerCase();
						
						var categoryMatch = ! category || rowCategory === category;
						var searchMatch = ! search || 
							rowName.indexOf( search ) !== -1 || 
							rowDesc.indexOf( search ) !== -1 ||
							rowTags.indexOf( search ) !== -1;
						
						if ( categoryMatch && searchMatch ) {
							row.style.display = '';
						} else {
							row.style.display = 'none';
						}
					} );
				}
				
				if ( categoryFilter ) {
					categoryFilter.addEventListener( 'change', filterDatasets );
				}
				
				if ( searchInput ) {
					searchInput.addEventListener( 'input', filterDatasets );
				}
			} );
		} )();
		</script>
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

		// Get selected datasets from form.
		$selected_datasets = array();
		if ( isset( $_POST['profession_preferred_datasets'] ) && is_array( $_POST['profession_preferred_datasets'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized below via json_decode.
			$raw_datasets = wp_unslash( $_POST['profession_preferred_datasets'] );

			foreach ( $raw_datasets as $dataset_json ) {
				// Decode the JSON value.
				$dataset_data = json_decode( $dataset_json, true );

				if ( is_array( $dataset_data ) && isset( $dataset_data['dataset'], $dataset_data['name'], $dataset_data['category'], $dataset_data['priority'] ) ) {
					$selected_datasets[] = array(
						'dataset'  => sanitize_text_field( $dataset_data['dataset'] ),
						'name'     => sanitize_text_field( $dataset_data['name'] ),
						'category' => sanitize_text_field( $dataset_data['category'] ),
						'priority' => sanitize_text_field( $dataset_data['priority'] ),
					);
				}
			}
		}

		// Sanitize and save.
		$sanitized_datasets = WP_MCP_AI_Profession_CPT::sanitize_preferred_datasets( $selected_datasets );
		update_post_meta( $post_id, WP_MCP_AI_Profession_CPT::META_PREFERRED_DATASETS, $sanitized_datasets );
	}

	/**
	 * Get dataset catalog.
	 * Uses the same catalog as the Assistant metabox for consistency.
	 *
	 * @return array Array of dataset information.
	 */
	private function get_dataset_catalog() {
		// Include a subset of top datasets for the UI. Full catalog is in the tool.
		return array(
			// NLP Datasets.
			array(
				'dataset'     => 'rajpurkar/squad',
				'name'        => 'SQuAD',
				'category'    => 'nlp',
				'priority'    => 'critical',
				'description' => 'Question answering dataset with 100K+ question-answer pairs',
				'size'        => '100K rows',
				'tags'        => array( 'qa', 'question', 'answer', 'chatbot', 'assistant' ),
			),
			array(
				'dataset'     => 'stanfordnlp/imdb',
				'name'        => 'IMDB Movie Reviews',
				'category'    => 'nlp',
				'priority'    => 'critical',
				'description' => 'Sentiment analysis dataset with 50K movie reviews',
				'size'        => '50K rows',
				'tags'        => array( 'sentiment', 'review', 'comment', 'moderation', 'analysis' ),
			),
			array(
				'dataset'     => 'abisee/cnn_dailymail',
				'name'        => 'CNN/DailyMail',
				'category'    => 'nlp',
				'priority'    => 'critical',
				'description' => 'Text summarization dataset with 300K news articles',
				'size'        => '300K rows',
				'tags'        => array( 'summarization', 'summary', 'article', 'content', 'news' ),
			),
			array(
				'dataset'     => 'EdinburghNLP/xsum',
				'name'        => 'XSum',
				'category'    => 'nlp',
				'priority'    => 'critical',
				'description' => 'Extreme summarization with single-sentence summaries',
				'size'        => '227K rows',
				'tags'        => array( 'summarization', 'summary', 'concise', 'snippet', 'meta' ),
			),
			array(
				'dataset'     => 'ag_news',
				'name'        => 'AG News',
				'category'    => 'nlp',
				'priority'    => 'high',
				'description' => 'News article classification with 4 categories',
				'size'        => '127K rows',
				'tags'        => array( 'classification', 'news', 'category', 'content' ),
			),
			array(
				'dataset'     => 'yelp_review_full',
				'name'        => 'Yelp Reviews',
				'category'    => 'nlp',
				'priority'    => 'high',
				'description' => 'Multi-class sentiment with 650K reviews (5-star scale)',
				'size'        => '650K rows',
				'tags'        => array( 'review', 'rating', 'sentiment', 'ecommerce', 'woocommerce' ),
			),
			array(
				'dataset'     => 'jigsaw_toxicity_pred',
				'name'        => 'Jigsaw Toxic Comments',
				'category'    => 'nlp',
				'priority'    => 'critical',
				'description' => 'Content moderation with 160K toxic comments',
				'size'        => '160K comments',
				'tags'        => array( 'moderation', 'toxic', 'comment', 'safety', 'filter' ),
			),
			array(
				'dataset'     => 'google/civil_comments',
				'name'        => 'Civil Comments',
				'category'    => 'nlp',
				'priority'    => 'critical',
				'description' => 'Nuanced moderation with 2M comments',
				'size'        => '2M comments',
				'tags'        => array( 'moderation', 'comment', 'civility', 'community', 'discussion' ),
			),
			// Vision Datasets.
			array(
				'dataset'     => 'detection-datasets/coco',
				'name'        => 'COCO',
				'category'    => 'vision',
				'priority'    => 'critical',
				'description' => 'Object detection with 330K images and 80 categories',
				'size'        => '330K images',
				'tags'        => array( 'image', 'object', 'detection', 'vision', 'media' ),
			),
			array(
				'dataset'     => 'zalando-datasets/fashion_mnist',
				'name'        => 'Fashion MNIST',
				'category'    => 'vision',
				'priority'    => 'high',
				'description' => 'Fashion item classification for e-commerce',
				'size'        => '70K images',
				'tags'        => array( 'fashion', 'ecommerce', 'woocommerce', 'product', 'clothing' ),
			),
			array(
				'dataset'     => 'ethz/food101',
				'name'        => 'Food-101',
				'category'    => 'vision',
				'priority'    => 'high',
				'description' => 'Food image classification with 101 categories',
				'size'        => '101K images',
				'tags'        => array( 'food', 'recipe', 'restaurant', 'culinary', 'blog' ),
			),
			// Multimodal Datasets.
			array(
				'dataset'     => 'nlphuji/flickr30k',
				'name'        => 'Flickr30k',
				'category'    => 'multimodal',
				'priority'    => 'critical',
				'description' => 'Image captioning with 31K images and captions',
				'size'        => '31K images',
				'tags'        => array( 'caption', 'alt', 'accessibility', 'image', 'description' ),
			),
			array(
				'dataset'     => 'yerevann/coco-captions',
				'name'        => 'MS COCO Captions',
				'category'    => 'multimodal',
				'priority'    => 'critical',
				'description' => 'Image-text understanding with 330K images',
				'size'        => '330K images',
				'tags'        => array( 'caption', 'image', 'text', 'multimodal', 'alt' ),
			),
			// Audio Datasets.
			array(
				'dataset'     => 'librispeech_asr',
				'name'        => 'LibriSpeech',
				'category'    => 'audio',
				'priority'    => 'critical',
				'description' => 'Speech recognition with 1000 hours of audio',
				'size'        => '1000 hours',
				'tags'        => array( 'speech', 'audio', 'transcription', 'accessibility', 'podcast' ),
			),
			array(
				'dataset'     => 'mozilla-foundation/common_voice_13_0',
				'name'        => 'Common Voice',
				'category'    => 'audio',
				'priority'    => 'critical',
				'description' => 'Multilingual speech recognition in 100+ languages',
				'size'        => 'Thousands of hours',
				'tags'        => array( 'speech', 'multilingual', 'audio', 'transcription', 'international' ),
			),
			// Multilingual & Specialized.
			array(
				'dataset'     => 'mc4',
				'name'        => 'mC4',
				'category'    => 'nlp',
				'priority'    => 'critical',
				'description' => 'Multilingual corpus in 101 languages',
				'size'        => '6.3TB',
				'tags'        => array( 'multilingual', 'international', 'translation', 'language', 'global' ),
			),
			array(
				'dataset'     => 'bigbio/med_qa',
				'name'        => 'MedQA',
				'category'    => 'nlp',
				'priority'    => 'high',
				'description' => 'Medical question answering dataset',
				'size'        => '60K+ Q&A pairs',
				'tags'        => array( 'medical', 'health', 'healthcare', 'qa', 'medicine' ),
			),
			array(
				'dataset'     => 'financial_phrasebank',
				'name'        => 'Financial PhraseBank',
				'category'    => 'nlp',
				'priority'    => 'high',
				'description' => 'Financial sentiment analysis dataset',
				'size'        => '4.8K sentences',
				'tags'        => array( 'finance', 'financial', 'sentiment', 'market', 'business' ),
			),
			array(
				'dataset'     => 'allenai/sciq',
				'name'        => 'SciQ',
				'category'    => 'nlp',
				'priority'    => 'high',
				'description' => 'Science question answering dataset',
				'size'        => '13K questions',
				'tags'        => array( 'science', 'education', 'qa', 'learning', 'stem' ),
			),
		);
	}
}
