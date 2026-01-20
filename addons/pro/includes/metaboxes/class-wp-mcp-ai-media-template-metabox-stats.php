<?php
/**
 * Media Template Statistics Metabox.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the Usage Statistics metabox for media templates.
 *
 * Displays usage count and last used timestamp.
 */
class WP_MCP_AI_Media_Template_Metabox_Stats extends WP_MCP_AI_Media_Template_Metabox_Base {

	/**
	 * Get the metabox ID.
	 *
	 * @return string
	 */
	public function get_id() {
		return 'wp_mcp_ai_media_template_stats';
	}

	/**
	 * Get the metabox title.
	 *
	 * @return string
	 */
	public function get_title() {
		return __( 'Usage Statistics', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get metabox context.
	 *
	 * @return string
	 */
	public function get_context() {
		return 'side';
	}

	/**
	 * Get metabox priority.
	 *
	 * @return string
	 */
	public function get_priority() {
		return 'default';
	}

	/**
	 * Render the metabox content.
	 *
	 * @param WP_Post $post The post object.
	 * @return void
	 */
	public function render( $post ) {
		if ( ! $this->can_view() ) {
			$this->render_permission_denied();
			return;
		}

		$usage_count = get_post_meta( $post->ID, '_mcp_ai_template_usage_count', true );
		$last_used   = get_post_meta( $post->ID, '_mcp_ai_template_last_used', true );

		$usage_count = $usage_count ? absint( $usage_count ) : 0;
		?>
		<div class="wp-mcp-ai-media-template-stats">
			<p>
				<strong><?php esc_html_e( 'Times Used:', 'mcp-ai-wpoos-pro' ); ?></strong><br>
				<span style="font-size: 24px; font-weight: bold; color: #2271b1;">
					<?php echo esc_html( number_format_i18n( $usage_count ) ); ?>
				</span>
			</p>

			<?php if ( ! empty( $last_used ) ) : ?>
				<p>
					<strong><?php esc_html_e( 'Last Used:', 'mcp-ai-wpoos-pro' ); ?></strong><br>
					<?php
					$timestamp = is_numeric( $last_used ) ? absint( $last_used ) : strtotime( $last_used );
					echo esc_html( human_time_diff( $timestamp, current_time( 'timestamp' ) ) . ' ' . __( 'ago', 'mcp-ai-wpoos-pro' ) );
					?>
				</p>
			<?php else : ?>
				<p>
					<strong><?php esc_html_e( 'Last Used:', 'mcp-ai-wpoos-pro' ); ?></strong><br>
					<em><?php esc_html_e( 'Never', 'mcp-ai-wpoos-pro' ); ?></em>
				</p>
			<?php endif; ?>

			<?php if ( $usage_count > 0 ) : ?>
				<p style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #dcdcde;">
					<strong><?php esc_html_e( 'Popularity:', 'mcp-ai-wpoos-pro' ); ?></strong><br>
					<?php
					if ( $usage_count >= 100 ) {
						echo '<span style="color: #00a32a;">⭐ ' . esc_html__( 'Very Popular', 'mcp-ai-wpoos-pro' ) . '</span>';
					} elseif ( $usage_count >= 50 ) {
						echo '<span style="color: #4ab866;">⭐ ' . esc_html__( 'Popular', 'mcp-ai-wpoos-pro' ) . '</span>';
					} elseif ( $usage_count >= 10 ) {
						echo '<span style="color: #007cba;">✓ ' . esc_html__( 'Frequently Used', 'mcp-ai-wpoos-pro' ) . '</span>';
					} else {
						echo '<span style="color: #646970;">• ' . esc_html__( 'Occasionally Used', 'mcp-ai-wpoos-pro' ) . '</span>';
					}
					?>
				</p>
			<?php endif; ?>

			<p style="margin-top: 15px; padding: 10px; background: #f0f6fc; border-left: 4px solid #2271b1;">
				<small>
					<?php esc_html_e( 'Statistics are updated automatically when templates are applied via AI tools or the media library.', 'mcp-ai-wpoos-pro' ); ?>
				</small>
			</p>
		</div>
		<?php
	}
}
