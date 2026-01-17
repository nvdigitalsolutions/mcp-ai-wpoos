<?php
/**
 * Media Collection Items Metabox.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the Media Items metabox for media collections.
 *
 * Manages selection and organization of media items in the collection.
 */
class WP_MCP_AI_Media_Collection_Metabox_Items extends WP_MCP_AI_Media_Template_Metabox_Base {

	/**
	 * Get the metabox ID.
	 *
	 * @return string
	 */
	public function get_id() {
		return 'wp_mcp_ai_media_collection_items';
	}

	/**
	 * Get the metabox title.
	 *
	 * @return string
	 */
	public function get_title() {
		return __( 'Collection Media Items', 'mcp-ai-wpoos-pro' );
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
		return 'high';
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

		// Get existing items.
		$items = get_post_meta( $post->ID, '_mcp_ai_collection_items', true );
		if ( ! is_array( $items ) ) {
			$items = array();
		}

		// Nonce for security.
		wp_nonce_field( 'wp_mcp_ai_media_collection_items_nonce', 'wp_mcp_ai_media_collection_items_nonce' );
		?>
		<div class="wp-mcp-ai-media-collection-items">
			<div style="margin-bottom: 15px;">
				<button type="button" class="button button-primary" id="add-collection-items">
					<span class="dashicons dashicons-plus-alt" style="margin-top: 3px;"></span>
					<?php esc_html_e( 'Add Media Items', 'mcp-ai-wpoos-pro' ); ?>
				</button>
				<button type="button" class="button" id="remove-all-items" style="margin-left: 10px;">
					<span class="dashicons dashicons-trash" style="margin-top: 3px;"></span>
					<?php esc_html_e( 'Clear All', 'mcp-ai-wpoos-pro' ); ?>
				</button>
				<span style="margin-left: 15px; color: #646970;">
					<?php
					printf(
						/* translators: %d: number of items */
						esc_html( _n( '%d item in collection', '%d items in collection', count( $items ), 'mcp-ai-wpoos-pro' ) ),
						esc_html( number_format_i18n( count( $items ) ) )
					);
					?>
				</span>
			</div>

			<div id="collection-items-container" class="collection-items-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 15px; margin-top: 15px;">
				<?php foreach ( $items as $attachment_id ) : ?>
					<?php $this->render_item_thumbnail( $attachment_id ); ?>
				<?php endforeach; ?>
			</div>

			<?php if ( empty( $items ) ) : ?>
				<div id="empty-collection-message" style="padding: 40px; text-align: center; background: #f9f9f9; border: 2px dashed #dcdcde; border-radius: 4px; margin-top: 15px;">
					<p style="font-size: 16px; color: #646970; margin: 0;">
						<span class="dashicons dashicons-images-alt2" style="font-size: 48px; width: 48px; height: 48px; display: block; margin: 0 auto 10px;"></span>
						<?php esc_html_e( 'No media items in this collection yet', 'mcp-ai-wpoos-pro' ); ?>
					</p>
					<p style="color: #646970; margin: 10px 0 0;">
						<?php esc_html_e( 'Click "Add Media Items" to get started', 'mcp-ai-wpoos-pro' ); ?>
					</p>
				</div>
			<?php endif; ?>

			<input type="hidden" name="wp_mcp_ai_collection_items" id="collection-items-input" value="<?php echo esc_attr( wp_json_encode( $items ) ); ?>">
		</div>

		<script type="text/javascript">
		jQuery(document).ready(function($) {
			var frame;
			var collectionItems = <?php echo wp_json_encode( $items ); ?>;

			// Add media items button
			$('#add-collection-items').on('click', function(e) {
				e.preventDefault();

				if (frame) {
					frame.open();
					return;
				}

				frame = wp.media({
					title: '<?php echo esc_js( __( 'Select Media Items for Collection', 'mcp-ai-wpoos-pro' ) ); ?>',
					button: {
						text: '<?php echo esc_js( __( 'Add to Collection', 'mcp-ai-wpoos-pro' ) ); ?>'
					},
					multiple: true,
					library: {
						type: 'image'
					}
				});

				frame.on('select', function() {
					var selection = frame.state().get('selection');
					var newItems = [];

					selection.map(function(attachment) {
						attachment = attachment.toJSON();
						if (collectionItems.indexOf(attachment.id) === -1) {
							collectionItems.push(attachment.id);
							newItems.push(attachment);
						}
					});

					// Add thumbnails
					newItems.forEach(function(attachment) {
						var html = '<div class="collection-item" data-id="' + attachment.id + '" style="position: relative; border: 1px solid #dcdcde; border-radius: 4px; overflow: hidden; background: #fff;">';
						html += '<img src="' + (attachment.sizes && attachment.sizes.thumbnail ? attachment.sizes.thumbnail.url : attachment.url) + '" style="width: 100%; height: 150px; object-fit: cover; display: block;">';
						html += '<button type="button" class="remove-item" style="position: absolute; top: 5px; right: 5px; background: rgba(0,0,0,0.7); color: #fff; border: none; border-radius: 3px; padding: 5px 8px; cursor: pointer; font-size: 12px;">×</button>';
						html += '<div style="padding: 8px; font-size: 11px; color: #646970; text-align: center; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">' + attachment.filename + '</div>';
						html += '</div>';
						$('#collection-items-container').append(html);
					});

					$('#empty-collection-message').hide();
					$('#collection-items-input').val(JSON.stringify(collectionItems));
				});

				frame.open();
			});

			// Remove item
			$(document).on('click', '.remove-item', function(e) {
				e.preventDefault();
				var $item = $(this).closest('.collection-item');
				var itemId = parseInt($item.data('id'));
				
				collectionItems = collectionItems.filter(function(id) {
					return id !== itemId;
				});

				$item.fadeOut(300, function() {
					$(this).remove();
					if (collectionItems.length === 0) {
						$('#empty-collection-message').show();
					}
				});

				$('#collection-items-input').val(JSON.stringify(collectionItems));
			});

			// Clear all
			$('#remove-all-items').on('click', function(e) {
				e.preventDefault();
				if (confirm('<?php echo esc_js( __( 'Are you sure you want to remove all items from this collection?', 'mcp-ai-wpoos-pro' ) ); ?>')) {
					collectionItems = [];
					$('.collection-item').fadeOut(300, function() {
						$(this).remove();
					});
					$('#empty-collection-message').show();
					$('#collection-items-input').val('[]');
				}
			});
		});
		</script>

		<style>
		.collection-item:hover .remove-item {
			opacity: 1;
		}
		.remove-item {
			opacity: 0.8;
			transition: opacity 0.2s;
		}
		.remove-item:hover {
			background: rgba(204, 0, 0, 0.9) !important;
		}
		</style>
		<?php
	}

	/**
	 * Render item thumbnail.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return void
	 */
	protected function render_item_thumbnail( $attachment_id ) {
		$image = wp_get_attachment_image_src( $attachment_id, 'thumbnail' );
		if ( ! $image ) {
			return;
		}

		$filename = basename( get_attached_file( $attachment_id ) );
		?>
		<div class="collection-item" data-id="<?php echo esc_attr( $attachment_id ); ?>" style="position: relative; border: 1px solid #dcdcde; border-radius: 4px; overflow: hidden; background: #fff;">
			<img src="<?php echo esc_url( $image[0] ); ?>" style="width: 100%; height: 150px; object-fit: cover; display: block;">
			<button type="button" class="remove-item" style="position: absolute; top: 5px; right: 5px; background: rgba(0,0,0,0.7); color: #fff; border: none; border-radius: 3px; padding: 5px 8px; cursor: pointer; font-size: 12px;">×</button>
			<div style="padding: 8px; font-size: 11px; color: #646970; text-align: center; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"><?php echo esc_html( $filename ); ?></div>
		</div>
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
		// Check nonce.
		if ( ! isset( $_POST['wp_mcp_ai_media_collection_items_nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wp_mcp_ai_media_collection_items_nonce'] ) ), 'wp_mcp_ai_media_collection_items_nonce' ) ) {
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

		// Save collection items.
		if ( isset( $_POST['wp_mcp_ai_collection_items'] ) ) {
			$items_json = sanitize_textarea_field( wp_unslash( $_POST['wp_mcp_ai_collection_items'] ) );
			$items      = json_decode( $items_json, true );

			if ( json_last_error() === JSON_ERROR_NONE && is_array( $items ) ) {
				// Validate each item is a valid attachment ID.
				$valid_items = array();
				foreach ( $items as $item_id ) {
					$item_id = absint( $item_id );
					if ( $item_id && get_post_type( $item_id ) === 'attachment' ) {
						$valid_items[] = $item_id;
					}
				}
				update_post_meta( $post_id, '_mcp_ai_collection_items', $valid_items );
			}
		}
	}
}
