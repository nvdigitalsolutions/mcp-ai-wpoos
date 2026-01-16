<?php
/**
 * Information Labelling System
 *
 * Implements ISO 27001:2022 Control A.5.13 - Labelling of Information
 * Provides automated classification labeling and visual indicators for data sensitivity.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Information Labelling System class.
 *
 * Implements automated classification labeling for plugin data structures.
 */
class WP_MCP_AI_Information_Labelling {
	/**
	 * Classification levels.
	 */
	const CLASSIFICATION_PUBLIC       = 'public';
	const CLASSIFICATION_INTERNAL     = 'internal';
	const CLASSIFICATION_CONFIDENTIAL = 'confidential';
	const CLASSIFICATION_RESTRICTED   = 'restricted';

	/**
	 * Singleton instance.
	 *
	 * @var WP_MCP_AI_Information_Labelling
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return WP_MCP_AI_Information_Labelling Singleton instance.
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		// Add classification meta to posts.
		add_action( 'add_meta_boxes', array( $this, 'add_classification_meta_box' ) );
		add_action( 'save_post', array( $this, 'save_classification_meta' ) );

		// Add classification column to post lists.
		add_filter( 'manage_mcp_ai_assistant_posts_columns', array( $this, 'add_classification_column' ) );
		add_action( 'manage_mcp_ai_assistant_posts_custom_column', array( $this, 'render_classification_column' ), 10, 2 );

		// Add visual indicators.
		add_action( 'admin_head', array( $this, 'add_classification_styles' ) );
	}

	/**
	 * Get classification levels with labels and colors.
	 *
	 * @return array Classification level definitions.
	 */
	public function get_classification_levels() {
		return array(
			self::CLASSIFICATION_PUBLIC       => array(
				'label'       => __( 'Public', 'mcp-ai-wpoos' ),
				'description' => __( 'Information that can be freely shared publicly', 'mcp-ai-wpoos' ),
				'color'       => '#4caf50',
				'icon'        => '🌐',
			),
			self::CLASSIFICATION_INTERNAL     => array(
				'label'       => __( 'Internal', 'mcp-ai-wpoos' ),
				'description' => __( 'Information for internal use only', 'mcp-ai-wpoos' ),
				'color'       => '#2196f3',
				'icon'        => '🏢',
			),
			self::CLASSIFICATION_CONFIDENTIAL => array(
				'label'       => __( 'Confidential', 'mcp-ai-wpoos' ),
				'description' => __( 'Sensitive information requiring protection', 'mcp-ai-wpoos' ),
				'color'       => '#ff9800',
				'icon'        => '🔒',
			),
			self::CLASSIFICATION_RESTRICTED   => array(
				'label'       => __( 'Restricted', 'mcp-ai-wpoos' ),
				'description' => __( 'Highly sensitive information with strict access controls', 'mcp-ai-wpoos' ),
				'color'       => '#f44336',
				'icon'        => '🛡️',
			),
		);
	}

	/**
	 * Add classification meta box to posts.
	 *
	 * @param string $post_type Post type.
	 */
	public function add_classification_meta_box( $post_type ) {
		$post_types = array( 'mcp_ai_assistant', 'mcp_ai_training' );

		if ( in_array( $post_type, $post_types, true ) ) {
			add_meta_box(
				'wp_mcp_ai_classification',
				__( 'Information Classification', 'mcp-ai-wpoos' ),
				array( $this, 'render_classification_meta_box' ),
				$post_type,
				'side',
				'high'
			);
		}
	}

	/**
	 * Render classification meta box.
	 *
	 * @param WP_Post $post Current post object.
	 */
	public function render_classification_meta_box( $post ) {
		wp_nonce_field( 'wp_mcp_ai_classification_nonce', 'wp_mcp_ai_classification_nonce' );

		$current_classification = get_post_meta( $post->ID, '_wp_mcp_ai_classification', true );
		if ( empty( $current_classification ) ) {
			$current_classification = self::CLASSIFICATION_INTERNAL;
		}

		$levels = $this->get_classification_levels();
		?>
		<div class="wp-mcp-ai-classification-selector">
			<?php foreach ( $levels as $level => $data ) : ?>
				<label class="wp-mcp-ai-classification-option">
					<input type="radio"
						   name="wp_mcp_ai_classification"
						   value="<?php echo esc_attr( $level ); ?>"
						   <?php checked( $current_classification, $level ); ?>>
					<span class="wp-mcp-ai-classification-label"
						  style="color: <?php echo esc_attr( $data['color'] ); ?>;">
						<span class="wp-mcp-ai-classification-icon"><?php echo esc_html( $data['icon'] ); ?></span>
						<strong><?php echo esc_html( $data['label'] ); ?></strong>
					</span>
					<p class="description" style="margin-left: 25px;">
						<?php echo esc_html( $data['description'] ); ?>
					</p>
				</label>
			<?php endforeach; ?>
		</div>

		<p class="description">
			<?php esc_html_e( 'Select the appropriate classification level based on the sensitivity of this information.', 'mcp-ai-wpoos' ); ?>
		</p>
		<?php
	}

	/**
	 * Save classification meta.
	 *
	 * @param int $post_id Post ID.
	 */
	public function save_classification_meta( $post_id ) {
		// Check nonce.
		if ( ! isset( $_POST['wp_mcp_ai_classification_nonce'] ) ||
			 ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wp_mcp_ai_classification_nonce'] ) ), 'wp_mcp_ai_classification_nonce' ) ) {
			return;
		}

		// Check autosave.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// Check permissions.
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Save classification.
		if ( isset( $_POST['wp_mcp_ai_classification'] ) ) {
			$classification = sanitize_text_field( wp_unslash( $_POST['wp_mcp_ai_classification'] ) );
			$valid_levels   = array_keys( $this->get_classification_levels() );

			if ( in_array( $classification, $valid_levels, true ) ) {
				update_post_meta( $post_id, '_wp_mcp_ai_classification', $classification );
			}
		}
	}

	/**
	 * Add classification column to post list.
	 *
	 * @param array $columns Existing columns.
	 * @return array Modified columns.
	 */
	public function add_classification_column( $columns ) {
		$new_columns = array();

		foreach ( $columns as $key => $value ) {
			$new_columns[ $key ] = $value;

			// Add classification after title.
			if ( 'title' === $key ) {
				$new_columns['classification'] = __( 'Classification', 'mcp-ai-wpoos' );
			}
		}

		return $new_columns;
	}

	/**
	 * Render classification column content.
	 *
	 * @param string $column  Column name.
	 * @param int    $post_id Post ID.
	 */
	public function render_classification_column( $column, $post_id ) {
		if ( 'classification' === $column ) {
			$classification = get_post_meta( $post_id, '_wp_mcp_ai_classification', true );
			if ( empty( $classification ) ) {
				$classification = self::CLASSIFICATION_INTERNAL;
			}

			$levels = $this->get_classification_levels();
			if ( isset( $levels[ $classification ] ) ) {
				$data = $levels[ $classification ];
				printf(
					'<span class="wp-mcp-ai-classification-badge" style="background-color: %s; color: white; padding: 3px 8px; border-radius: 3px; font-size: 11px; font-weight: 600;">%s %s</span>',
					esc_attr( $data['color'] ),
					esc_html( $data['icon'] ),
					esc_html( $data['label'] )
				);
			}
		}
	}

	/**
	 * Add classification styles to admin.
	 */
	public function add_classification_styles() {
		?>
		<style>
		.wp-mcp-ai-classification-selector {
			margin: 10px 0;
		}
		.wp-mcp-ai-classification-option {
			display: block;
			margin: 10px 0;
			cursor: pointer;
		}
		.wp-mcp-ai-classification-option input[type="radio"] {
			margin-right: 5px;
		}
		.wp-mcp-ai-classification-label {
			font-size: 13px;
		}
		.wp-mcp-ai-classification-icon {
			font-size: 16px;
			margin-right: 5px;
		}
		.wp-mcp-ai-classification-badge {
			white-space: nowrap;
			display: inline-block;
		}
		</style>
		<?php
	}

	/**
	 * Get classification for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return string Classification level.
	 */
	public function get_post_classification( $post_id ) {
		$classification = get_post_meta( $post_id, '_wp_mcp_ai_classification', true );
		if ( empty( $classification ) ) {
			return self::CLASSIFICATION_INTERNAL;
		}
		return $classification;
	}

	/**
	 * Set classification for a post.
	 *
	 * @param int    $post_id        Post ID.
	 * @param string $classification Classification level.
	 * @return bool Success status.
	 */
	public function set_post_classification( $post_id, $classification ) {
		$valid_levels = array_keys( $this->get_classification_levels() );

		if ( ! in_array( $classification, $valid_levels, true ) ) {
			return false;
		}

		return update_post_meta( $post_id, '_wp_mcp_ai_classification', $classification );
	}

	/**
	 * Get classification badge HTML.
	 *
	 * @param string $classification Classification level.
	 * @return string HTML badge.
	 */
	public function get_classification_badge( $classification ) {
		$levels = $this->get_classification_levels();

		if ( ! isset( $levels[ $classification ] ) ) {
			return '';
		}

		$data = $levels[ $classification ];

		return sprintf(
			'<span class="wp-mcp-ai-classification-badge" style="background-color: %s; color: white; padding: 4px 10px; border-radius: 4px; font-size: 12px; font-weight: 600;">%s %s</span>',
			esc_attr( $data['color'] ),
			esc_html( $data['icon'] ),
			esc_html( $data['label'] )
		);
	}

	/**
	 * Auto-classify content based on patterns.
	 *
	 * @param string $content Content to analyze.
	 * @return string Suggested classification level.
	 */
	public function auto_classify_content( $content ) {
		// Restricted indicators.
		$restricted_patterns = array(
			'/api[_\s]key/i',
			'/password/i',
			'/secret/i',
			'/private[_\s]key/i',
			'/token/i',
			'/credential/i',
		);

		// Confidential indicators.
		$confidential_patterns = array(
			'/user[_\s]data/i',
			'/personal[_\s]information/i',
			'/sensitive/i',
			'/proprietary/i',
		);

		// Check for restricted content.
		foreach ( $restricted_patterns as $pattern ) {
			if ( preg_match( $pattern, $content ) ) {
				return self::CLASSIFICATION_RESTRICTED;
			}
		}

		// Check for confidential content.
		foreach ( $confidential_patterns as $pattern ) {
			if ( preg_match( $pattern, $content ) ) {
				return self::CLASSIFICATION_CONFIDENTIAL;
			}
		}

		// Default to internal.
		return self::CLASSIFICATION_INTERNAL;
	}
}
