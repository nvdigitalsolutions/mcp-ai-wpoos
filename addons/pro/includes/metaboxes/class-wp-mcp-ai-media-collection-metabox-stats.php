<?php
/**
 * Media Collection Statistics Metabox.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the Statistics metabox for media collections.
 *
 * Displays collection statistics and processing history.
 */
class WP_MCP_AI_Media_Collection_Metabox_Stats extends WP_MCP_AI_Media_Template_Metabox_Base {

	/**
	 * Get the metabox ID.
	 *
	 * @return string
	 */
	public function get_id() {
		return 'wp_mcp_ai_media_collection_stats';
	}

	/**
	 * Get the metabox title.
	 *
	 * @return string
	 */
	public function get_title() {
		return __( 'Collection Statistics', 'mcp-ai-wpoos-pro' );
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

		$items          = get_post_meta( $post->ID, '_mcp_ai_collection_items', true );
		$templates      = get_post_meta( $post->ID, '_mcp_ai_collection_templates', true );
		$process_count  = get_post_meta( $post->ID, '_mcp_ai_collection_process_count', true );
		$last_processed = get_post_meta( $post->ID, '_mcp_ai_collection_last_processed', true );

		$item_count     = is_array( $items ) ? count( $items ) : 0;
		$template_count = is_array( $templates ) ? count( $templates ) : 0;
		$process_count  = $process_count ? absint( $process_count ) : 0;
		?>
		<div class="wp-mcp-ai-media-collection-stats">
			<div style="margin-bottom: 15px; padding-bottom: 15px; border-bottom: 1px solid #dcdcde;">
				<strong><?php esc_html_e( 'Media Items:', 'mcp-ai-wpoos-pro' ); ?></strong><br>
				<span style="font-size: 24px; font-weight: bold; color: #2271b1;">
					<?php echo esc_html( number_format_i18n( $item_count ) ); ?>
				</span>
			</div>

			<div style="margin-bottom: 15px; padding-bottom: 15px; border-bottom: 1px solid #dcdcde;">
				<strong><?php esc_html_e( 'Assigned Templates:', 'mcp-ai-wpoos-pro' ); ?></strong><br>
				<span style="font-size: 24px; font-weight: bold; color: <?php echo $template_count > 0 ? '#00a32a' : '#646970'; ?>;">
					<?php echo esc_html( number_format_i18n( $template_count ) ); ?>
				</span>
			</div>

			<div style="margin-bottom: 15px; padding-bottom: 15px; border-bottom: 1px solid #dcdcde;">
				<strong><?php esc_html_e( 'Times Processed:', 'mcp-ai-wpoos-pro' ); ?></strong><br>
				<span style="font-size: 20px; font-weight: bold; color: #646970;">
					<?php echo esc_html( number_format_i18n( $process_count ) ); ?>
				</span>
			</div>

			<?php if ( ! empty( $last_processed ) ) : ?>
				<div style="margin-bottom: 15px; padding-bottom: 15px; border-bottom: 1px solid #dcdcde;">
					<strong><?php esc_html_e( 'Last Processed:', 'mcp-ai-wpoos-pro' ); ?></strong><br>
					<?php
					$timestamp = is_numeric( $last_processed ) ? absint( $last_processed ) : strtotime( $last_processed );
					echo esc_html( human_time_diff( $timestamp, current_time( 'timestamp' ) ) . ' ' . __( 'ago', 'mcp-ai-wpoos-pro' ) );
					?>
				</div>
			<?php endif; ?>

			<?php if ( $item_count > 0 && $template_count > 0 ) : ?>
				<div style="margin-bottom: 15px; padding: 10px; background: #d5f4e6; border-left: 4px solid #00a32a; font-size: 12px;">
					<strong style="color: #00712b;">✓ <?php esc_html_e( 'Ready to Process', 'mcp-ai-wpoos-pro' ); ?></strong><br>
					<span style="color: #00712b;">
						<?php
						printf(
							/* translators: 1: items count, 2: templates count */
							esc_html__( '%1$d items × %2$d templates', 'mcp-ai-wpoos-pro' ),
							esc_html( $item_count ),
							esc_html( $template_count )
						);
						?>
					</span>
				</div>
			<?php elseif ( $item_count === 0 ) : ?>
				<div style="margin-bottom: 15px; padding: 10px; background: #fcf3cf; border-left: 4px solid #ffc107; font-size: 12px;">
					<strong style="color: #856404;">⚠ <?php esc_html_e( 'No Items', 'mcp-ai-wpoos-pro' ); ?></strong><br>
					<span style="color: #856404;">
						<?php esc_html_e( 'Add media items to start', 'mcp-ai-wpoos-pro' ); ?>
					</span>
				</div>
			<?php elseif ( $template_count === 0 ) : ?>
				<div style="margin-bottom: 15px; padding: 10px; background: #fcf3cf; border-left: 4px solid #ffc107; font-size: 12px;">
					<strong style="color: #856404;">⚠ <?php esc_html_e( 'No Templates', 'mcp-ai-wpoos-pro' ); ?></strong><br>
					<span style="color: #856404;">
						<?php esc_html_e( 'Assign templates to process', 'mcp-ai-wpoos-pro' ); ?>
					</span>
				</div>
			<?php endif; ?>

			<?php if ( $item_count > 0 && $template_count > 0 ) : ?>
				<div style="margin-bottom: 15px; padding: 10px; background: #f0f6fc; border-left: 4px solid #2271b1; font-size: 12px;">
					<strong><?php esc_html_e( 'Expected Output:', 'mcp-ai-wpoos-pro' ); ?></strong><br>
					<span style="color: #646970;">
						<?php
						$total_outputs = $item_count * $template_count;
						printf(
							/* translators: %d: total output images */
							esc_html( _n( '%d new image will be created', '%d new images will be created', $total_outputs, 'mcp-ai-wpoos-pro' ) ),
							esc_html( number_format_i18n( $total_outputs ) )
						);
						?>
					</span>
				</div>
			<?php endif; ?>

			<p style="margin-top: 15px; padding: 10px; background: #f9f9f9; border: 1px solid #dcdcde; border-radius: 4px; font-size: 11px; color: #646970;">
				<?php esc_html_e( 'Use AI tools to process this collection. Statistics update automatically after each batch operation.', 'mcp-ai-wpoos-pro' ); ?>
			</p>
		</div>
		<?php
	}
}
