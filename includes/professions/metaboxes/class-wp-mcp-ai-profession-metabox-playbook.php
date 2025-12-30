<?php
/**
 * Playbook Metabox for Professions.
 *
 * Displays playbook status, preview, and regeneration controls for profession posts.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages playbook display and controls for profession posts.
 */
class WP_MCP_AI_Profession_Metabox_Playbook extends WP_MCP_AI_Profession_Metabox_Base {

	/**
	 * Get the metabox ID.
	 *
	 * @return string
	 */
	public function get_id() {
		return 'wp_mcp_ai_profession_playbook';
	}

	/**
	 * Get the metabox title.
	 *
	 * @return string
	 */
	public function get_title() {
		return __( 'Professional Playbook', 'wp-mcp-ai' );
	}

	/**
	 * Get the metabox context.
	 *
	 * @return string
	 */
	public function get_context() {
		return 'side';
	}

	/**
	 * Get the metabox priority.
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
		if ( ! $this->can_view( $post ) ) {
			wp_die( esc_html__( 'You do not have permission to edit this profession.', 'wp-mcp-ai' ), '', array( 'response' => 403 ) );
		}

		// Deduplicate playbook attachments before displaying.
		// This ensures we always show the most recent playbook.
		if ( class_exists( 'WP_MCP_AI_Profession_Playbook_Seeder' ) ) {
			try {
				$reflection = new ReflectionClass( 'WP_MCP_AI_Profession_Playbook_Seeder' );
				$method     = $reflection->getMethod( 'remove_duplicate_playbooks' );
				$method->setAccessible( true );
				$method->invoke( null, $post->ID );
			} catch ( ReflectionException $e ) {
				// Silently fail if method doesn't exist - backwards compatibility.
			}
		}

		// Find existing playbook attachment.
		$playbook_attachment = $this->find_playbook_attachment( $post->ID );

		?>
		<div class="wp-mcp-ai-profession-playbook">
			<p class="description" style="margin-bottom: 15px;">
				<?php esc_html_e( 'Playbooks are automatically assembled from modular text files (global + category + profession) and provide actionable instructions for AI assistants.', 'wp-mcp-ai' ); ?>
			</p>

			<?php if ( $playbook_attachment ) : ?>
				<?php
				$file_path     = get_attached_file( $playbook_attachment->ID );
				$file_size     = file_exists( $file_path ) ? size_format( filesize( $file_path ) ) : '—';
				$last_modified = get_post_modified_time( 'U', false, $playbook_attachment );
				$hash          = get_post_meta( $playbook_attachment->ID, '_wp_mcp_ai_playbook_hash', true );
				$file_url      = wp_get_attachment_url( $playbook_attachment->ID );
				?>
				
				<div class="wp-mcp-ai-playbook-status" style="padding: 12px; background: #f0f0f1; border-left: 3px solid #2271b1; margin-bottom: 15px;">
					<p style="margin: 0 0 8px 0;">
						<strong><?php esc_html_e( 'Status:', 'wp-mcp-ai' ); ?></strong>
						<span class="dashicons dashicons-yes-alt" style="color: #00a32a; vertical-align: middle;"></span>
						<span style="color: #00a32a;"><?php esc_html_e( 'Generated', 'wp-mcp-ai' ); ?></span>
					</p>
					<p style="margin: 0 0 4px 0; font-size: 12px; color: #646970;">
						<strong><?php esc_html_e( 'Size:', 'wp-mcp-ai' ); ?></strong> <?php echo esc_html( $file_size ); ?>
					</p>
					<p style="margin: 0 0 4px 0; font-size: 12px; color: #646970;">
						<strong><?php esc_html_e( 'Updated:', 'wp-mcp-ai' ); ?></strong> 
						<?php
						/* translators: %s: Time difference */
						echo esc_html( sprintf( __( '%s ago', 'wp-mcp-ai' ), human_time_diff( $last_modified ) ) );
						?>
					</p>
					<?php if ( $hash ) : ?>
						<p style="margin: 0; font-size: 12px; color: #646970;">
							<strong><?php esc_html_e( 'Hash:', 'wp-mcp-ai' ); ?></strong> 
							<code style="font-size: 10px;"><?php echo esc_html( substr( $hash, 0, 16 ) ); ?>...</code>
						</p>
					<?php endif; ?>
				</div>

				<p style="margin-bottom: 10px;">
					<a href="<?php echo esc_url( $file_url ); ?>" class="button button-secondary" target="_blank" style="width: 100%; text-align: center;">
						<span class="dashicons dashicons-visibility" style="vertical-align: middle;"></span>
						<?php esc_html_e( 'View Playbook', 'wp-mcp-ai' ); ?>
					</a>
				</p>

			<?php else : ?>
				
				<div class="wp-mcp-ai-playbook-status" style="padding: 12px; background: #fff3cd; border-left: 3px solid #f0b849; margin-bottom: 15px;">
					<p style="margin: 0;">
						<strong><?php esc_html_e( 'Status:', 'wp-mcp-ai' ); ?></strong>
						<span class="dashicons dashicons-warning" style="color: #f0b849; vertical-align: middle;"></span>
						<span style="color: #856404;"><?php esc_html_e( 'Not Generated', 'wp-mcp-ai' ); ?></span>
					</p>
				</div>

			<?php endif; ?>

			<p style="margin-bottom: 10px;">
				<button type="button" class="button button-primary wp-mcp-ai-regenerate-playbook" data-profession-id="<?php echo esc_attr( $post->ID ); ?>" style="width: 100%;">
					<span class="dashicons dashicons-update" style="vertical-align: middle;"></span>
					<?php esc_html_e( 'Regenerate Playbook', 'wp-mcp-ai' ); ?>
				</button>
			</p>

			<div id="wp-mcp-ai-playbook-message" class="notice" style="display: none; margin: 10px 0; padding: 8px 12px;">
				<p style="margin: 0;"></p>
			</div>

			<details style="margin-top: 15px;">
				<summary style="cursor: pointer; color: #2271b1; font-weight: 500;">
					<?php esc_html_e( 'How Playbooks Work', 'wp-mcp-ai' ); ?>
				</summary>
				<div style="margin-top: 10px; padding: 10px; background: #f9f9f9; font-size: 12px; line-height: 1.6;">
					<p style="margin: 0 0 8px 0;">
						<?php esc_html_e( 'Playbooks are assembled from three layers:', 'wp-mcp-ai' ); ?>
					</p>
					<ol style="margin: 0 0 8px 20px; padding: 0;">
						<li><strong><?php esc_html_e( 'Global Guidelines', 'wp-mcp-ai' ); ?></strong> - <?php esc_html_e( 'Universal professional conduct principles', 'wp-mcp-ai' ); ?></li>
						<li><strong><?php esc_html_e( 'Category Workflows', 'wp-mcp-ai' ); ?></strong> - <?php esc_html_e( 'Standards shared across this profession category', 'wp-mcp-ai' ); ?></li>
						<li><strong><?php esc_html_e( 'Profession-Specific', 'wp-mcp-ai' ); ?></strong> - <?php esc_html_e( 'Detailed instructions unique to this profession', 'wp-mcp-ai' ); ?></li>
					</ol>
					<p style="margin: 0 0 8px 0;">
						<?php esc_html_e( 'The system also adds intelligent tool recommendations and region-specific context automatically.', 'wp-mcp-ai' ); ?>
					</p>
					<p style="margin: 0;">
						<a href="<?php echo esc_url( WP_MCP_AI_URL . 'includes/knowledge-base/profession-playbooks/README.md' ); ?>" target="_blank">
							<?php esc_html_e( 'Learn more about playbooks', 'wp-mcp-ai' ); ?> →
						</a>
					</p>
				</div>
			</details>
		</div>

		<style type="text/css">
			.wp-mcp-ai-regenerate-playbook .dashicons {
				margin-top: 3px;
			}
			.wp-mcp-ai-regenerate-playbook.updating .dashicons {
				animation: rotation 1s linear infinite;
			}
			@keyframes rotation {
				from { transform: rotate(0deg); }
				to { transform: rotate(359deg); }
			}
		</style>

		<script type="text/javascript">
		jQuery(document).ready(function($) {
			$('.wp-mcp-ai-regenerate-playbook').on('click', function(e) {
				e.preventDefault();
				
				var $button = $(this);
				var $message = $('#wp-mcp-ai-playbook-message');
				var professionId = $button.data('profession-id');
				
				if ($button.hasClass('updating')) {
					return;
				}
				
				$button.addClass('updating').prop('disabled', true);
				$message.hide().removeClass('notice-success notice-error notice-warning');
				
				$.ajax({
					url: ajaxurl,
					type: 'POST',
					data: {
						action: 'wp_mcp_ai_regenerate_playbook',
						profession_id: professionId,
						nonce: <?php echo wp_json_encode( wp_create_nonce( 'wp_mcp_ai_regenerate_playbook' ) ); ?>
					},
					success: function(response) {
						if (response.success) {
							$message
								.removeClass('notice-error notice-warning')
								.addClass('notice-success')
								.find('p').html(response.data.message || <?php echo wp_json_encode( __( 'Playbook regenerated successfully!', 'wp-mcp-ai' ) ); ?>);
							$message.show();
							
							// Reload the page after a short delay to show updated info.
							setTimeout(function() {
								location.reload();
							}, 1500);
						} else {
							$message
								.removeClass('notice-success notice-warning')
								.addClass('notice-error')
								.find('p').html(response.data.message || <?php echo wp_json_encode( __( 'Failed to regenerate playbook.', 'wp-mcp-ai' ) ); ?>);
							$message.show();
							
							$button.removeClass('updating').prop('disabled', false);
						}
					},
					error: function(xhr, status, error) {
						$message
							.removeClass('notice-success notice-warning')
							.addClass('notice-error')
							.find('p').html(<?php echo wp_json_encode( __( 'AJAX error: ', 'wp-mcp-ai' ) ); ?> + error);
						$message.show();
						
						$button.removeClass('updating').prop('disabled', false);
					}
				});
			});
		});
		</script>
		<?php
	}

	/**
	 * Find existing playbook attachment for a profession.
	 *
	 * @param int $profession_id Profession post ID.
	 * @return WP_Post|null Attachment post or null if not found.
	 */
	protected function find_playbook_attachment( $profession_id ) {
		$args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => 1,
			'meta_query'     => array(
				array(
					'key'     => '_wp_mcp_ai_playbook_profession_id',
					'value'   => $profession_id,
					'compare' => '=',
				),
			),
		);

		$query = new WP_Query( $args );

		if ( $query->have_posts() ) {
			return $query->posts[0];
		}

		return null;
	}

	/**
	 * Save metabox data.
	 *
	 * This metabox is read-only, so no save action needed.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 * @return void
	 */
	public function save( $post_id, $post ) {
		// No save action needed for this metabox - it's informational only.
	}
}
