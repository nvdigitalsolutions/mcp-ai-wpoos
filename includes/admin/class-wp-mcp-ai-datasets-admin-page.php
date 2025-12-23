<?php
/**
 * HuggingFace Datasets Admin Page
 *
 * Enhanced UI for browsing, downloading, and managing datasets.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Datasets_Admin_Page' ) ) {
	/**
	 * Admin page for HuggingFace datasets management.
	 *
	 * @since 1.0.0
	 */
	class WP_MCP_AI_Datasets_Admin_Page {

		/**
		 * Page hook suffix.
		 *
		 * @var string
		 */
		private $page_hook;

		/**
		 * Initialize the admin page.
		 */
		public function __construct() {
			add_action( 'admin_menu', array( $this, 'add_menu_page' ), 20 );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
			add_action( 'wp_ajax_wp_mcp_ai_load_dataset_preview', array( $this, 'ajax_load_dataset_preview' ) );
			add_action( 'wp_ajax_wp_mcp_ai_search_datasets', array( $this, 'ajax_search_datasets' ) );
		}

		/**
		 * Add admin menu page.
		 */
		public function add_menu_page() {
			$this->page_hook = add_submenu_page(
				'wp-mcp-ai-dashboard',
				__( 'HuggingFace Datasets', 'wp-mcp-ai' ),
				__( 'HF Datasets', 'wp-mcp-ai' ),
				'manage_options',
				'wp-mcp-ai-datasets',
				array( $this, 'render_page' )
			);
		}

		/**
		 * Enqueue scripts and styles.
		 *
		 * @param string $hook Current page hook.
		 */
		public function enqueue_scripts( $hook ) {
			if ( $this->page_hook !== $hook ) {
				return;
			}

			wp_enqueue_style(
				'wp-mcp-ai-datasets-admin',
				WP_MCP_AI_URL . 'assets/css/datasets-admin.css',
				array(),
				WP_MCP_AI_VERSION
			);

			wp_enqueue_script(
				'wp-mcp-ai-datasets-admin',
				WP_MCP_AI_URL . 'assets/js/datasets-admin.js',
				array( 'jquery', 'wp-util' ),
				WP_MCP_AI_VERSION,
				true
			);

			wp_localize_script(
				'wp-mcp-ai-datasets-admin',
				'wpMcpAiDatasets',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( 'wp_mcp_ai_datasets' ),
					'i18n'    => array(
						'loading'       => __( 'Loading...', 'wp-mcp-ai' ),
						'error'         => __( 'Error loading dataset', 'wp-mcp-ai' ),
						'noResults'     => __( 'No datasets found', 'wp-mcp-ai' ),
						'preview'       => __( 'Preview', 'wp-mcp-ai' ),
						'download'      => __( 'Download Info', 'wp-mcp-ai' ),
						'copied'        => __( 'Copied!', 'wp-mcp-ai' ),
						'searchPlaceholder' => __( 'Search datasets by name or use case...', 'wp-mcp-ai' ),
					),
				)
			);
		}

		/**
		 * Render the admin page.
		 */
		public function render_page() {
			// Check if datasets are enabled.
			$settings = WP_MCP_AI_Admin_Settings::get_settings();
			$enabled  = ! empty( $settings['enable_huggingface_datasets'] );

			?>
			<div class="wrap wp-mcp-ai-datasets-page">
				<h1><?php esc_html_e( 'HuggingFace Datasets Browser', 'wp-mcp-ai' ); ?></h1>

				<?php if ( ! $enabled ) : ?>
					<div class="notice notice-warning">
						<p>
							<?php
							printf(
								/* translators: %s: settings page URL */
								esc_html__( 'HuggingFace Datasets integration is not enabled. %s to activate it.', 'wp-mcp-ai' ),
								'<a href="' . esc_url( admin_url( 'admin.php?page=wp-mcp-ai-dashboard&tab=providers&subtab=huggingface_datasets' ) ) . '">' . esc_html__( 'Go to Settings', 'wp-mcp-ai' ) . '</a>'
							);
							?>
						</p>
					</div>
				<?php endif; ?>

				<div class="wp-mcp-ai-datasets-header">
					<p class="description">
						<?php esc_html_e( 'Browse and explore 50+ top free datasets from HuggingFace Hub. Use these datasets for AI assistant training, few-shot learning, and data exploration.', 'wp-mcp-ai' ); ?>
					</p>
					
					<div class="wp-mcp-ai-datasets-search">
						<input type="text" id="wp-mcp-ai-datasets-search" placeholder="<?php esc_attr_e( 'Search datasets by name, category, or use case...', 'wp-mcp-ai' ); ?>" />
						<button type="button" class="button" id="wp-mcp-ai-datasets-search-btn">
							<?php esc_html_e( 'Search', 'wp-mcp-ai' ); ?>
						</button>
					</div>

					<div class="wp-mcp-ai-datasets-filters">
						<label for="wp-mcp-ai-datasets-category">
							<?php esc_html_e( 'Category:', 'wp-mcp-ai' ); ?>
						</label>
						<select id="wp-mcp-ai-datasets-category">
							<option value="all"><?php esc_html_e( 'All Categories', 'wp-mcp-ai' ); ?></option>
							<option value="nlp"><?php esc_html_e( 'Natural Language Processing', 'wp-mcp-ai' ); ?></option>
							<option value="vision"><?php esc_html_e( 'Computer Vision', 'wp-mcp-ai' ); ?></option>
							<option value="audio"><?php esc_html_e( 'Audio & Speech', 'wp-mcp-ai' ); ?></option>
							<option value="multimodal"><?php esc_html_e( 'Multimodal', 'wp-mcp-ai' ); ?></option>
						</select>

						<label for="wp-mcp-ai-datasets-priority">
							<?php esc_html_e( 'Priority:', 'wp-mcp-ai' ); ?>
						</label>
						<select id="wp-mcp-ai-datasets-priority">
							<option value="all"><?php esc_html_e( 'All Priorities', 'wp-mcp-ai' ); ?></option>
							<option value="critical"><?php esc_html_e( 'Critical (Must Have)', 'wp-mcp-ai' ); ?></option>
							<option value="high"><?php esc_html_e( 'High (Should Have)', 'wp-mcp-ai' ); ?></option>
							<option value="medium"><?php esc_html_e( 'Medium (Nice to Have)', 'wp-mcp-ai' ); ?></option>
						</select>
					</div>
				</div>

				<div class="wp-mcp-ai-datasets-grid" id="wp-mcp-ai-datasets-grid">
					<?php $this->render_dataset_cards(); ?>
				</div>

				<!-- Dataset Preview Modal -->
				<div id="wp-mcp-ai-dataset-modal" class="wp-mcp-ai-modal" style="display: none;">
					<div class="wp-mcp-ai-modal-content">
						<span class="wp-mcp-ai-modal-close">&times;</span>
						<div class="wp-mcp-ai-modal-body" id="wp-mcp-ai-modal-body">
							<div class="wp-mcp-ai-loading"><?php esc_html_e( 'Loading dataset preview...', 'wp-mcp-ai' ); ?></div>
						</div>
					</div>
				</div>
			</div>
			<?php
		}

		/**
		 * Render dataset cards.
		 */
		private function render_dataset_cards() {
			$datasets = $this->get_featured_datasets();

			foreach ( $datasets as $dataset ) {
				$this->render_dataset_card( $dataset );
			}
		}

		/**
		 * Render a single dataset card.
		 *
		 * @param array $dataset Dataset information.
		 */
		private function render_dataset_card( $dataset ) {
			$priority_class = 'priority-' . esc_attr( $dataset['priority'] );
			$category_class = 'category-' . esc_attr( $dataset['category'] );
			?>
			<div class="wp-mcp-ai-dataset-card <?php echo esc_attr( $priority_class . ' ' . $category_class ); ?>" 
				 data-dataset="<?php echo esc_attr( $dataset['dataset'] ); ?>"
				 data-category="<?php echo esc_attr( $dataset['category'] ); ?>"
				 data-priority="<?php echo esc_attr( $dataset['priority'] ); ?>">
				
				<div class="dataset-card-header">
					<h3><?php echo esc_html( $dataset['name'] ); ?></h3>
					<span class="dataset-badge priority-<?php echo esc_attr( $dataset['priority'] ); ?>">
						<?php echo esc_html( ucfirst( $dataset['priority'] ) ); ?>
					</span>
				</div>

				<div class="dataset-card-body">
					<p class="dataset-description"><?php echo esc_html( $dataset['description'] ); ?></p>
					
					<div class="dataset-meta">
						<span class="dataset-size">
							<span class="dashicons dashicons-database"></span>
							<?php echo esc_html( $dataset['size'] ); ?>
						</span>
						<span class="dataset-category">
							<span class="dashicons dashicons-category"></span>
							<?php echo esc_html( ucfirst( $dataset['category'] ) ); ?>
						</span>
					</div>

					<div class="dataset-tags">
						<?php foreach ( array_slice( $dataset['tags'], 0, 3 ) as $tag ) : ?>
							<span class="dataset-tag"><?php echo esc_html( $tag ); ?></span>
						<?php endforeach; ?>
					</div>
				</div>

				<div class="dataset-card-footer">
					<button type="button" class="button button-primary wp-mcp-ai-dataset-preview" 
							data-dataset="<?php echo esc_attr( $dataset['dataset'] ); ?>">
						<span class="dashicons dashicons-visibility"></span>
						<?php esc_html_e( 'Preview', 'wp-mcp-ai' ); ?>
					</button>
					
					<button type="button" class="button wp-mcp-ai-dataset-copy-code" 
							data-code="<?php echo esc_attr( $dataset['example'] ); ?>">
						<span class="dashicons dashicons-clipboard"></span>
						<?php esc_html_e( 'Copy Code', 'wp-mcp-ai' ); ?>
					</button>

					<a href="https://huggingface.co/datasets/<?php echo esc_attr( $dataset['dataset'] ); ?>" 
					   target="_blank" 
					   class="button">
						<span class="dashicons dashicons-external"></span>
						<?php esc_html_e( 'View on HF', 'wp-mcp-ai' ); ?>
					</a>
				</div>
			</div>
			<?php
		}

		/**
		 * Get featured datasets catalog.
		 *
		 * @return array
		 */
		private function get_featured_datasets() {
			// This is a subset of the full catalog for the admin UI.
			return array(
				array(
					'dataset'     => 'rajpurkar/squad',
					'name'        => 'SQuAD',
					'category'    => 'nlp',
					'priority'    => 'critical',
					'description' => 'Question answering dataset with 100K+ question-answer pairs',
					'size'        => '100K rows',
					'tags'        => array( 'qa', 'question', 'answer', 'chatbot' ),
					'example'     => 'huggingface_dataset_preview_rows(dataset="rajpurkar/squad", split="train", limit=5)',
				),
				array(
					'dataset'     => 'stanfordnlp/imdb',
					'name'        => 'IMDB Reviews',
					'category'    => 'nlp',
					'priority'    => 'critical',
					'description' => 'Sentiment analysis with 50K movie reviews',
					'size'        => '50K rows',
					'tags'        => array( 'sentiment', 'review', 'moderation' ),
					'example'     => 'huggingface_dataset_preview_rows(dataset="stanfordnlp/imdb", split="train", limit=5)',
				),
				array(
					'dataset'     => 'abisee/cnn_dailymail',
					'name'        => 'CNN/DailyMail',
					'category'    => 'nlp',
					'priority'    => 'critical',
					'description' => 'Text summarization with 300K news articles',
					'size'        => '300K rows',
					'tags'        => array( 'summarization', 'news', 'content' ),
					'example'     => 'huggingface_dataset_preview_rows(dataset="abisee/cnn_dailymail", config="3.0.0", split="train", limit=5)',
				),
				array(
					'dataset'     => 'detection-datasets/coco',
					'name'        => 'COCO',
					'category'    => 'vision',
					'priority'    => 'critical',
					'description' => 'Object detection with 330K images',
					'size'        => '330K images',
					'tags'        => array( 'image', 'object', 'detection' ),
					'example'     => 'huggingface_dataset_preview_rows(dataset="detection-datasets/coco", config="2017", split="train", limit=5)',
				),
				array(
					'dataset'     => 'nlphuji/flickr30k',
					'name'        => 'Flickr30k',
					'category'    => 'multimodal',
					'priority'    => 'critical',
					'description' => 'Image captioning with 31K images and captions',
					'size'        => '31K images',
					'tags'        => array( 'caption', 'alt', 'accessibility' ),
					'example'     => 'huggingface_dataset_preview_rows(dataset="nlphuji/flickr30k", split="test", limit=5)',
				),
				array(
					'dataset'     => 'librispeech_asr',
					'name'        => 'LibriSpeech',
					'category'    => 'audio',
					'priority'    => 'critical',
					'description' => 'Speech recognition with 1000 hours of audio',
					'size'        => '1000 hours',
					'tags'        => array( 'speech', 'audio', 'transcription' ),
					'example'     => 'huggingface_dataset_preview_rows(dataset="librispeech_asr", config="clean", split="train.100", limit=5)',
				),
				array(
					'dataset'     => 'jigsaw_toxicity_pred',
					'name'        => 'Jigsaw Toxic',
					'category'    => 'nlp',
					'priority'    => 'critical',
					'description' => 'Content moderation with 160K toxic comments',
					'size'        => '160K comments',
					'tags'        => array( 'moderation', 'toxic', 'safety' ),
					'example'     => 'huggingface_dataset_preview_rows(dataset="jigsaw_toxicity_pred", split="train", limit=5)',
				),
				array(
					'dataset'     => 'ag_news',
					'name'        => 'AG News',
					'category'    => 'nlp',
					'priority'    => 'high',
					'description' => 'News classification with 4 categories',
					'size'        => '127K rows',
					'tags'        => array( 'classification', 'news', 'category' ),
					'example'     => 'huggingface_dataset_preview_rows(dataset="ag_news", split="train", limit=5)',
				),
				array(
					'dataset'     => 'yelp_review_full',
					'name'        => 'Yelp Reviews',
					'category'    => 'nlp',
					'priority'    => 'high',
					'description' => 'Review sentiment with 650K reviews (5-star scale)',
					'size'        => '650K rows',
					'tags'        => array( 'review', 'rating', 'ecommerce' ),
					'example'     => 'huggingface_dataset_preview_rows(dataset="yelp_review_full", split="train", limit=5)',
				),
				array(
					'dataset'     => 'zalando-datasets/fashion_mnist',
					'name'        => 'Fashion MNIST',
					'category'    => 'vision',
					'priority'    => 'high',
					'description' => 'Fashion item classification for e-commerce',
					'size'        => '70K images',
					'tags'        => array( 'fashion', 'ecommerce', 'product' ),
					'example'     => 'huggingface_dataset_preview_rows(dataset="zalando-datasets/fashion_mnist", split="train", limit=5)',
				),
				array(
					'dataset'     => 'ethz/food101',
					'name'        => 'Food-101',
					'category'    => 'vision',
					'priority'    => 'high',
					'description' => 'Food classification with 101 categories',
					'size'        => '101K images',
					'tags'        => array( 'food', 'recipe', 'restaurant' ),
					'example'     => 'huggingface_dataset_preview_rows(dataset="ethz/food101", split="train", limit=5)',
				),
				array(
					'dataset'     => 'bigbio/med_qa',
					'name'        => 'MedQA',
					'category'    => 'nlp',
					'priority'    => 'high',
					'description' => 'Medical question answering dataset',
					'size'        => '60K+ Q&A',
					'tags'        => array( 'medical', 'health', 'qa' ),
					'example'     => 'huggingface_dataset_preview_rows(dataset="bigbio/med_qa", config="med_qa_en_source", split="train", limit=5)',
				),
			);
		}

		/**
		 * AJAX handler for loading dataset preview.
		 */
		public function ajax_load_dataset_preview() {
			check_ajax_referer( 'wp_mcp_ai_datasets', 'nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => __( 'Permission denied', 'wp-mcp-ai' ) ) );
			}

			$dataset = isset( $_POST['dataset'] ) ? sanitize_text_field( wp_unslash( $_POST['dataset'] ) ) : '';

			if ( empty( $dataset ) ) {
				wp_send_json_error( array( 'message' => __( 'Dataset name required', 'wp-mcp-ai' ) ) );
			}

			try {
				$client = WP_MCP_AI_Container::get( 'client.huggingface_datasets' );

				// Get dataset info and preview.
				$splits  = $client->get_splits( $dataset );
				$info    = $client->get_info( $dataset );

				// Try to get first split for preview.
				$preview = null;
				if ( ! is_wp_error( $splits ) && ! empty( $splits['splits'] ) ) {
					$first_split = $splits['splits'][0];
					$preview     = $client->preview_rows(
						$dataset,
						isset( $first_split['config'] ) ? $first_split['config'] : 'default',
						isset( $first_split['split'] ) ? $first_split['split'] : 'train',
						5
					);
				}

				ob_start();
				$this->render_dataset_preview( $dataset, $splits, $info, $preview );
				$html = ob_get_clean();

				wp_send_json_success( array( 'html' => $html ) );

			} catch ( Exception $e ) {
				wp_send_json_error( array( 'message' => $e->getMessage() ) );
			}
		}

		/**
		 * Render dataset preview HTML.
		 *
		 * @param string $dataset Dataset name.
		 * @param mixed  $splits  Splits data.
		 * @param mixed  $info    Info data.
		 * @param mixed  $preview Preview data.
		 */
		private function render_dataset_preview( $dataset, $splits, $info, $preview ) {
			?>
			<h2><?php echo esc_html( $dataset ); ?></h2>

			<?php if ( ! is_wp_error( $info ) && isset( $info['dataset_info'] ) ) : ?>
				<div class="dataset-info-section">
					<h3><?php esc_html_e( 'Dataset Information', 'wp-mcp-ai' ); ?></h3>
					<?php if ( isset( $info['dataset_info']['description'] ) ) : ?>
						<p><?php echo esc_html( $info['dataset_info']['description'] ); ?></p>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<?php if ( ! is_wp_error( $splits ) && ! empty( $splits['splits'] ) ) : ?>
				<div class="dataset-splits-section">
					<h3><?php esc_html_e( 'Available Splits', 'wp-mcp-ai' ); ?></h3>
					<table class="widefat">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Split', 'wp-mcp-ai' ); ?></th>
								<th><?php esc_html_e( 'Config', 'wp-mcp-ai' ); ?></th>
								<th><?php esc_html_e( 'Rows', 'wp-mcp-ai' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $splits['splits'] as $split ) : ?>
								<tr>
									<td><?php echo esc_html( $split['split'] ); ?></td>
									<td><?php echo esc_html( isset( $split['config'] ) ? $split['config'] : 'default' ); ?></td>
									<td><?php echo esc_html( isset( $split['num_rows'] ) ? number_format( $split['num_rows'] ) : 'N/A' ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>

			<?php if ( ! is_wp_error( $preview ) && ! empty( $preview['rows'] ) ) : ?>
				<div class="dataset-preview-section">
					<h3><?php esc_html_e( 'Sample Rows', 'wp-mcp-ai' ); ?></h3>
					<div class="dataset-preview-data">
						<pre><?php echo esc_html( wp_json_encode( $preview['rows'], JSON_PRETTY_PRINT ) ); ?></pre>
					</div>
				</div>
			<?php endif; ?>

			<div class="dataset-usage-section">
				<h3><?php esc_html_e( 'Usage Example', 'wp-mcp-ai' ); ?></h3>
				<p><?php esc_html_e( 'Use this code in your AI assistant prompts:', 'wp-mcp-ai' ); ?></p>
				<code>huggingface_dataset_preview_rows(dataset="<?php echo esc_html( $dataset ); ?>", split="train", limit=10)</code>
			</div>
			<?php
		}

		/**
		 * AJAX handler for searching datasets.
		 */
		public function ajax_search_datasets() {
			check_ajax_referer( 'wp_mcp_ai_datasets', 'nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => __( 'Permission denied', 'wp-mcp-ai' ) ) );
			}

			$query    = isset( $_POST['query'] ) ? sanitize_text_field( wp_unslash( $_POST['query'] ) ) : '';
			$category = isset( $_POST['category'] ) ? sanitize_text_field( wp_unslash( $_POST['category'] ) ) : 'all';
			$priority = isset( $_POST['priority'] ) ? sanitize_text_field( wp_unslash( $_POST['priority'] ) ) : 'all';

			$datasets = $this->get_featured_datasets();
			$filtered = array();

			foreach ( $datasets as $dataset ) {
				// Filter by category.
				if ( 'all' !== $category && $dataset['category'] !== $category ) {
					continue;
				}

				// Filter by priority.
				if ( 'all' !== $priority && $dataset['priority'] !== $priority ) {
					continue;
				}

				// Filter by query.
				if ( ! empty( $query ) ) {
					$searchable = strtolower( $dataset['name'] . ' ' . $dataset['description'] . ' ' . implode( ' ', $dataset['tags'] ) );
					if ( false === strpos( $searchable, strtolower( $query ) ) ) {
						continue;
					}
				}

				$filtered[] = $dataset;
			}

			ob_start();
			foreach ( $filtered as $dataset ) {
				$this->render_dataset_card( $dataset );
			}
			$html = ob_get_clean();

			wp_send_json_success( array( 'html' => $html, 'count' => count( $filtered ) ) );
		}
	}

	// Initialize the admin page.
	new WP_MCP_AI_Datasets_Admin_Page();
}
