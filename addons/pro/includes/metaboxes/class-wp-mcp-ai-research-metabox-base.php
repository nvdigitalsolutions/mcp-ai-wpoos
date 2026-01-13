<?php
/**
 * Base class for CPT Research Metaboxes.
 *
 * Provides AI-powered research functionality for CPT edit screens,
 * allowing users to research entities before creating them.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abstract base class for research metaboxes.
 *
 * Child classes should extend this and implement abstract methods
 * to provide research functionality specific to their CPT.
 */
abstract class WP_MCP_AI_Research_Metabox_Base {

	/**
	 * Metabox ID prefix.
	 *
	 * @var string
	 */
	const METABOX_ID_PREFIX = 'wp_mcp_ai_research_';

	/**
	 * Post type this metabox applies to.
	 *
	 * @var string
	 */
	protected $post_type;

	/**
	 * Metabox title.
	 *
	 * @var string
	 */
	protected $title;

	/**
	 * Research tool slug to use.
	 *
	 * @var string
	 */
	protected $research_tool;

	/**
	 * Constructor.
	 *
	 * @param string $post_type     Post type slug.
	 * @param string $title         Metabox title.
	 * @param string $research_tool Research tool slug.
	 */
	public function __construct( $post_type, $title, $research_tool = 'deep_research' ) {
		$this->post_type     = $post_type;
		$this->title         = $title;
		$this->research_tool = $research_tool;

		add_action( 'add_meta_boxes', array( $this, 'register_metabox' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Register the metabox.
	 */
	public function register_metabox() {
		// Only show on "Add New" screens or when specifically requested.
		global $pagenow;
		if ( 'post-new.php' !== $pagenow && ! $this->should_show_on_edit() ) {
			return;
		}

		add_meta_box(
			$this->get_metabox_id(),
			$this->title,
			array( $this, 'render' ),
			$this->post_type,
			'side',
			'high'
		);
	}

	/**
	 * Get metabox ID.
	 *
	 * @return string
	 */
	protected function get_metabox_id() {
		return self::METABOX_ID_PREFIX . $this->post_type;
	}

	/**
	 * Check if metabox should show on edit screens.
	 *
	 * By default, only show on new post screens.
	 * Child classes can override to show on edit screens too.
	 *
	 * @return bool
	 */
	protected function should_show_on_edit() {
		return false;
	}

	/**
	 * Enqueue assets for the research metabox.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		// Only load on post edit/new screens.
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		global $post;
		if ( ! $post || $post->post_type !== $this->post_type ) {
			return;
		}

		// Enqueue CSS.
		wp_enqueue_style(
			'wp-mcp-ai-research-metabox',
			WP_MCP_AI_PRO_URL . 'assets/css/research-metabox.css',
			array(),
			WP_MCP_AI_PRO_VERSION
		);

		// Enqueue JavaScript.
		wp_enqueue_script(
			'wp-mcp-ai-research-metabox',
			WP_MCP_AI_PRO_URL . 'assets/js/research-metabox.js',
			array( 'jquery', 'wp-api' ),
			WP_MCP_AI_PRO_VERSION,
			true
		);

		// Localize script.
		wp_localize_script(
			'wp-mcp-ai-research-metabox',
			'wpMcpAiResearch',
			array(
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'restUrl'      => rest_url( 'mcp-ai/v1/' ),
				'nonce'        => wp_create_nonce( 'wp_rest' ),
				'postType'     => $this->post_type,
				'researchTool' => $this->research_tool,
				'strings'      => array(
					'researching'       => __( 'Researching...', 'mcp-ai-wpoos-pro' ),
					'error'             => __( 'An error occurred. Please try again.', 'mcp-ai-wpoos-pro' ),
					'emptyQuery'        => __( 'Please enter a search query.', 'mcp-ai-wpoos-pro' ),
					'applyData'         => __( 'Apply Research Data', 'mcp-ai-wpoos-pro' ),
					'dataApplied'       => __( 'Research data applied! Review and save when ready.', 'mcp-ai-wpoos-pro' ),
					'closeModal'        => __( 'Close', 'mcp-ai-wpoos-pro' ),
					'noResults'         => __( 'No results found.', 'mcp-ai-wpoos-pro' ),
				),
				'fieldMap'     => $this->get_field_map(),
			)
		);
	}

	/**
	 * Render the metabox.
	 *
	 * @param WP_Post $post Current post object.
	 */
	public function render( $post ) {
		?>
		<div class="wp-mcp-ai-research-wrapper">
			<div class="wp-mcp-ai-research-intro">
				<p><?php echo esc_html( $this->get_intro_text() ); ?></p>
			</div>

			<div class="wp-mcp-ai-research-search">
				<label for="wp-mcp-ai-research-query">
					<?php echo esc_html( $this->get_search_label() ); ?>
				</label>
				<input 
					type="text" 
					id="wp-mcp-ai-research-query" 
					class="widefat" 
					placeholder="<?php echo esc_attr( $this->get_search_placeholder() ); ?>"
				/>
				<button 
					type="button" 
					class="button button-primary button-large wp-mcp-ai-research-btn"
					style="margin-top: 10px; width: 100%;"
				>
					<span class="dashicons dashicons-search" style="margin-top: 3px;"></span>
					<?php esc_html_e( 'Research', 'mcp-ai-wpoos-pro' ); ?>
				</button>
			</div>

			<div class="wp-mcp-ai-research-status" style="display: none; margin-top: 10px;"></div>
		</div>

		<!-- Research Results Modal -->
		<div id="wp-mcp-ai-research-modal" class="wp-mcp-ai-modal" style="display: none;">
			<div class="wp-mcp-ai-modal__backdrop"></div>
			<div class="wp-mcp-ai-modal__panel">
				<div class="wp-mcp-ai-modal__header">
					<h2><?php esc_html_e( 'Research Results', 'mcp-ai-wpoos-pro' ); ?></h2>
					<button type="button" class="wp-mcp-ai-modal__close" aria-label="<?php esc_attr_e( 'Close', 'mcp-ai-wpoos-pro' ); ?>">
						<span class="dashicons dashicons-no-alt"></span>
					</button>
				</div>
				<div class="wp-mcp-ai-modal__body">
					<div id="wp-mcp-ai-research-results"></div>
					<div class="wp-mcp-ai-research-actions" style="margin-top: 20px;">
						<button type="button" class="button button-primary button-large wp-mcp-ai-apply-research">
							<span class="dashicons dashicons-yes" style="margin-top: 3px;"></span>
							<?php esc_html_e( 'Use This Data', 'mcp-ai-wpoos-pro' ); ?>
						</button>
						<button type="button" class="button button-secondary button-large wp-mcp-ai-close-research">
							<?php esc_html_e( 'Cancel', 'mcp-ai-wpoos-pro' ); ?>
						</button>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Get introductory text for the metabox.
	 *
	 * Child classes should override this.
	 *
	 * @return string
	 */
	abstract protected function get_intro_text();

	/**
	 * Get search input label.
	 *
	 * Child classes should override this.
	 *
	 * @return string
	 */
	abstract protected function get_search_label();

	/**
	 * Get search input placeholder.
	 *
	 * Child classes should override this.
	 *
	 * @return string
	 */
	abstract protected function get_search_placeholder();

	/**
	 * Get field mapping for applying research data to form fields.
	 *
	 * Returns array mapping research data keys to WordPress form field IDs/names.
	 * Child classes should override this.
	 *
	 * Example:
	 * array(
	 *   'title' => '#title',
	 *   'content' => '#content',
	 *   'meta.address' => '#_place_address',
	 * )
	 *
	 * @return array
	 */
	abstract protected function get_field_map();
}
