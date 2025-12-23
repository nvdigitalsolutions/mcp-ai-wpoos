<?php
/**
 * Datasets Metabox for Assistants.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the Datasets metabox for assistant posts.
 *
 * Allows selecting preferred HuggingFace datasets for the assistant.
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Metabox_Datasets extends WP_MCP_AI_Metabox_Base {

	/**
	 * Reference to the Assistant CPT class for constants.
	 *
	 * @var WP_MCP_AI_Assistant_CPT
	 */
	protected $cpt;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 * @param WP_MCP_AI_Assistant_CPT $cpt Assistant CPT instance.
	 */
	public function __construct( $cpt ) {
		$this->cpt = $cpt;
	}

	/**
	 * Get the metabox ID.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public function get_id() {
		return 'wp_mcp_ai_preferred_datasets';
	}

	/**
	 * Get the metabox title.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public function get_title() {
		return __( 'Preferred Datasets', 'wp-mcp-ai' );
	}

	/**
	 * Check if current user can view this metabox.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	protected function can_view() {
		global $post;
		return current_user_can( 'edit_post', $post->ID );
	}

	/**
	 * Render the metabox content.
	 *
	 * @since 1.0.0
	 * @param WP_Post $post The post object.
	 * @return void
	 */
	public function render( $post ) {
		if ( ! $this->can_view() ) {
			wp_die( esc_html__( 'You do not have permission to edit this assistant.', 'wp-mcp-ai' ), '', array( 'response' => 403 ) );
		}

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

		wp_nonce_field( 'wp_mcp_ai_datasets_meta', 'wp_mcp_ai_datasets_meta_nonce' );

		// Get currently assigned datasets.
		$preferred_datasets = get_post_meta( $post->ID, WP_MCP_AI_Assistant_CPT::META_PREFERRED_DATASETS, true );
		if ( ! is_array( $preferred_datasets ) ) {
			$preferred_datasets = array();
		}

		// Get available datasets from the catalog.
		$available_datasets = $this->get_dataset_catalog();

		?>
		<div class="wp-mcp-ai-datasets">
			<p class="description">
				<?php esc_html_e( 'Select up to 10 HuggingFace datasets that this assistant should prefer when making recommendations. These datasets will be prioritized in the huggingface_recommended_datasets tool.', 'wp-mcp-ai' ); ?>
			</p>

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
					<input type="text" id="wp-mcp-ai-dataset-search" placeholder="<?php esc_attr_e( 'Search datasets...', 'wp-mcp-ai' ); ?>" style="width: 250px; margin-left: 5px;" />
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
					<?php foreach ( $available_datasets as $dataset_info ) : ?>
						<?php
						$dataset_id  = $dataset_info['dataset'];
						$is_selected = false;
						foreach ( $preferred_datasets as $selected ) {
							if ( isset( $selected['dataset'] ) && $selected['dataset'] === $dataset_id ) {
								$is_selected = true;
								break;
							}
						}
						?>
						<tr class="wp-mcp-ai-dataset-row" 
							data-category="<?php echo esc_attr( $dataset_info['category'] ); ?>"
							data-name="<?php echo esc_attr( strtolower( $dataset_info['name'] ) ); ?>"
							data-description="<?php echo esc_attr( strtolower( $dataset_info['description'] ) ); ?>"
							data-tags="<?php echo esc_attr( strtolower( implode( ' ', $dataset_info['tags'] ) ) ); ?>">
							<td>
								<input 
									type="checkbox" 
									name="wp_mcp_ai_preferred_datasets[]" 
									value="<?php echo esc_attr( wp_json_encode( array(
										'dataset'  => $dataset_info['dataset'],
										'name'     => $dataset_info['name'],
										'category' => $dataset_info['category'],
										'priority' => $dataset_info['priority'],
									) ) ); ?>"
									class="wp-mcp-ai-dataset-checkbox"
									<?php checked( $is_selected ); ?>
								/>
							</td>
							<td>
								<strong><?php echo esc_html( $dataset_info['name'] ); ?></strong>
								<br />
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
								<br />
								<small style="color: #666;">
									<?php
									/* translators: %s: dataset size */
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
	 * Get the dataset catalog (reusing the logic from the recommended datasets tool).
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
