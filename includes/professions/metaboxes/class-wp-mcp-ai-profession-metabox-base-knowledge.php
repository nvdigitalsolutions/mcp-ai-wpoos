<?php
/**
 * Base Knowledge Metabox for Professions.
 *
 * Handles media files, vector store configuration, and MIME type restrictions
 * for profession templates.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages base knowledge configuration for profession posts.
 *
 * This metabox follows separation of concerns - it handles only the UI
 * rendering and user interaction. Data sanitization and storage are
 * handled by the CPT class.
 */
class WP_MCP_AI_Profession_Metabox_Base_Knowledge extends WP_MCP_AI_Profession_Metabox_Base {

	/**
	 * Get the metabox ID.
	 *
	 * @return string
	 */
	public function get_id() {
		return 'wp_mcp_ai_profession_base_knowledge';
	}

	/**
	 * Get the metabox title.
	 *
	 * @return string
	 */
	public function get_title() {
		return __( 'Base Knowledge & Media', 'wp-mcp-ai' );
	}

	/**
	 * Get the metabox context.
	 *
	 * @return string
	 */
	public function get_context() {
		return 'normal';
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

		// Enqueue media library scripts.
		wp_enqueue_media();
		wp_enqueue_script( 'jquery' );

		// Get existing values.
		$memory_files         = get_post_meta( $post->ID, WP_MCP_AI_Profession_CPT::META_MEMORY_FILES, true );
		$vector_store_id      = get_post_meta( $post->ID, WP_MCP_AI_Profession_CPT::META_VECTOR_STORE_ID, true );
		$supported_mime_types = get_post_meta( $post->ID, WP_MCP_AI_Profession_CPT::META_SUPPORTED_MIME_TYPES, true );

		// Ensure proper types.
		if ( ! is_array( $memory_files ) ) {
			$memory_files = array();
		}

		if ( ! is_string( $vector_store_id ) ) {
			$vector_store_id = '';
		}

		if ( ! is_array( $supported_mime_types ) ) {
			$supported_mime_types = array();
		}

		// Build memory file entries.
		$memory_entries    = array();
		$memory_size_bytes = 0;

		foreach ( $memory_files as $file_id ) {
			$file_id    = absint( $file_id );
			$attachment = get_post( $file_id );

			if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
				continue;
			}

			$file_size_bytes = 0;
			$file_size_label = '';
			$file_path       = get_attached_file( $file_id );

			if ( $file_path && file_exists( $file_path ) ) {
				$file_size = filesize( $file_path );
				if ( false !== $file_size ) {
					$file_size_bytes    = (int) $file_size;
					$file_size_label    = size_format( $file_size_bytes );
					$memory_size_bytes += $file_size_bytes;
				}
			}

			$memory_entries[] = array(
				'id'    => $file_id,
				'title' => get_the_title( $attachment ),
				'size'  => $file_size_label,
			);
		}

		$memory_size_label = size_format( $memory_size_bytes );

		// Common MIME type categories.
		$mime_categories = array(
			'documents' => array(
				'label' => __( 'Documents', 'wp-mcp-ai' ),
				'types' => array(
					'application/pdf',
					'application/msword',
					'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
					'text/plain',
				),
			),
			'images'    => array(
				'label' => __( 'Images', 'wp-mcp-ai' ),
				'types' => array(
					'image/jpeg',
					'image/png',
					'image/gif',
					'image/webp',
				),
			),
			'audio'     => array(
				'label' => __( 'Audio', 'wp-mcp-ai' ),
				'types' => array(
					'audio/mpeg',
					'audio/wav',
					'audio/ogg',
				),
			),
			'video'     => array(
				'label' => __( 'Video', 'wp-mcp-ai' ),
				'types' => array(
					'video/mp4',
					'video/webm',
					'video/ogg',
				),
			),
		);

		?>
		<div class="wp-mcp-ai-profession-base-knowledge">
			<!-- Memory Files Section -->
			<h4><?php esc_html_e( 'Knowledge Base Files', 'wp-mcp-ai' ); ?></h4>
			<p class="description">
				<?php esc_html_e( 'Select Media Library items that should be included as reference material when creating assistants with this profession.', 'wp-mcp-ai' ); ?>
			</p>

			<ul id="wp-mcp-ai-profession-memory-files-list" class="wp-mcp-ai-memory-files">
				<?php
				foreach ( $memory_entries as $entry ) :
					$file_id = $entry['id'];
					$title   = $entry['title'];
					$size    = isset( $entry['size'] ) ? $entry['size'] : '';
					?>
					<li data-id="<?php echo esc_attr( $file_id ); ?>">
						<span class="wp-mcp-ai-memory-file-title">
							<?php
							/* translators: %d: attachment ID */
							echo esc_html( $title ? $title : sprintf( __( 'Attachment #%d', 'wp-mcp-ai' ), $file_id ) );
							?>
						</span>
						<?php if ( '' !== $size ) : ?>
							<span class="wp-mcp-ai-memory-file-size">(<?php echo esc_html( $size ); ?>)</span>
						<?php endif; ?>
						<button type="button" class="button-link wp-mcp-ai-remove-memory"><?php esc_html_e( 'Remove', 'wp-mcp-ai' ); ?></button>
						<input type="hidden" name="wp_mcp_ai_profession_memory_files[]" value="<?php echo esc_attr( $file_id ); ?>" />
					</li>
				<?php endforeach; ?>
			</ul>

			<p class="description">
				<?php
				printf(
					/* translators: %s: Human-readable size of the memory payload. */
					esc_html__( 'Total knowledge base size: %s', 'wp-mcp-ai' ),
					esc_html( $memory_size_label )
				);
				?>
			</p>

			<p>
				<button type="button" class="button" id="wp-mcp-ai-profession-memory-select">
					<?php esc_html_e( 'Add Knowledge Files', 'wp-mcp-ai' ); ?>
				</button>
			</p>

			<hr style="margin: 20px 0;">

			<!-- Vector Store ID Section -->
			<h4><?php esc_html_e( 'Vector Store Integration', 'wp-mcp-ai' ); ?></h4>
			<p>
				<label for="wp-mcp-ai-profession-vector-store-id">
					<strong><?php esc_html_e( 'Vector Store ID', 'wp-mcp-ai' ); ?></strong>
				</label>
				<input 
					type="text" 
					id="wp-mcp-ai-profession-vector-store-id" 
					name="wp_mcp_ai_profession_vector_store_id" 
					value="<?php echo esc_attr( $vector_store_id ); ?>" 
					class="widefat" 
					placeholder="<?php esc_attr_e( 'e.g., vs_abc123', 'wp-mcp-ai' ); ?>"
				/>
				<span class="description">
					<?php esc_html_e( 'Optional identifier for an external vector store (e.g., OpenAI, Pinecone) that assistants using this profession should access.', 'wp-mcp-ai' ); ?>
				</span>
			</p>

			<hr style="margin: 20px 0;">

			<!-- Supported MIME Types Section -->
			<h4><?php esc_html_e( 'Allowed File Types', 'wp-mcp-ai' ); ?></h4>
			<p class="description">
				<?php esc_html_e( 'Restrict the types of files that can be used with assistants based on this profession. Leave unchecked to allow all file types.', 'wp-mcp-ai' ); ?>
			</p>

			<div style="margin-top: 15px;">
				<?php foreach ( $mime_categories as $category_key => $category ) : ?>
					<div style="margin-bottom: 15px;">
						<strong><?php echo esc_html( $category['label'] ); ?>:</strong><br>
						<?php foreach ( $category['types'] as $mime_type ) : ?>
							<label style="display: inline-block; margin-right: 15px; margin-top: 5px;">
								<input 
									type="checkbox" 
									name="wp_mcp_ai_profession_mime_types[]" 
									value="<?php echo esc_attr( $mime_type ); ?>"
									<?php checked( in_array( $mime_type, $supported_mime_types, true ) ); ?>
								/>
								<?php echo esc_html( $mime_type ); ?>
							</label>
						<?php endforeach; ?>
					</div>
				<?php endforeach; ?>
			</div>

			<p class="description" style="margin-top: 10px;">
				<?php esc_html_e( 'When creating assistants with this profession, only the selected MIME types will be allowed for file uploads. This helps ensure that assistants receive appropriate file formats for their expertise.', 'wp-mcp-ai' ); ?>
			</p>
		</div>

		<style type="text/css">
			.wp-mcp-ai-memory-files {
				list-style: none;
				margin: 10px 0;
				padding: 0;
				border: 1px solid #ddd;
				background: #f9f9f9;
				max-height: 200px;
				overflow-y: auto;
			}

			.wp-mcp-ai-memory-files li {
				padding: 8px 12px;
				border-bottom: 1px solid #ddd;
				display: flex;
				align-items: center;
				justify-content: space-between;
			}

			.wp-mcp-ai-memory-files li:last-child {
				border-bottom: none;
			}

			.wp-mcp-ai-memory-file-title {
				flex: 1;
				font-weight: 500;
			}

			.wp-mcp-ai-memory-file-size {
				color: #646970;
				font-size: 0.9em;
				margin-left: 0.5em;
			}

			.wp-mcp-ai-remove-memory {
				color: #b32d2e;
				text-decoration: none;
				cursor: pointer;
			}

			.wp-mcp-ai-remove-memory:hover {
				color: #dc3232;
			}
		</style>

		<script type="text/javascript">
		jQuery(document).ready(function($) {
			// Media uploader for memory files.
			var memoryFrame;
			$('#wp-mcp-ai-profession-memory-select').on('click', function(e) {
				e.preventDefault();

				if (memoryFrame) {
					memoryFrame.open();
					return;
				}

				memoryFrame = wp.media({
					title: '<?php echo esc_js( __( 'Select Knowledge Files', 'wp-mcp-ai' ) ); ?>',
					button: {
						text: '<?php echo esc_js( __( 'Add to Knowledge Base', 'wp-mcp-ai' ) ); ?>'
					},
					multiple: true
				});

				memoryFrame.on('select', function() {
					var selection = memoryFrame.state().get('selection');
					var list = $('#wp-mcp-ai-profession-memory-files-list');

					selection.map(function(attachment) {
						attachment = attachment.toJSON();
						
						// Check if already added.
						if (list.find('li[data-id="' + attachment.id + '"]').length > 0) {
							return;
						}

						var sizeLabel = attachment.filesizeHumanReadable || '';
						var title = attachment.title || '<?php echo esc_js( __( 'Untitled', 'wp-mcp-ai' ) ); ?>';

						var listItem = $('<li>').attr('data-id', attachment.id);
						listItem.append(
							$('<span>').addClass('wp-mcp-ai-memory-file-title').text(title)
						);
						
						if (sizeLabel) {
							listItem.append(
								$('<span>').addClass('wp-mcp-ai-memory-file-size').text('(' + sizeLabel + ')')
							);
						}

						listItem.append(
							$('<button>').attr('type', 'button')
								.addClass('button-link wp-mcp-ai-remove-memory')
								.text('<?php echo esc_js( __( 'Remove', 'wp-mcp-ai' ) ); ?>')
						);

						listItem.append(
							$('<input>').attr({
								type: 'hidden',
								name: 'wp_mcp_ai_profession_memory_files[]',
								value: attachment.id
							})
						);

						list.append(listItem);
					});
				});

				memoryFrame.open();
			});

			// Remove memory file.
			$(document).on('click', '.wp-mcp-ai-remove-memory', function(e) {
				e.preventDefault();
				$(this).closest('li').remove();
			});
		});
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
		if ( ! $this->can_save( $post_id ) ) {
			return;
		}

		// Save memory files.
		if ( isset( $_POST['wp_mcp_ai_profession_memory_files'] ) ) {
			$memory_files = array_map( 'absint', (array) $_POST['wp_mcp_ai_profession_memory_files'] );
			$memory_files = array_filter( $memory_files ); // Remove zeros.
			update_post_meta( $post_id, WP_MCP_AI_Profession_CPT::META_MEMORY_FILES, $memory_files );
		} else {
			delete_post_meta( $post_id, WP_MCP_AI_Profession_CPT::META_MEMORY_FILES );
		}

		// Save vector store ID.
		if ( isset( $_POST['wp_mcp_ai_profession_vector_store_id'] ) ) {
			$vector_store_id = sanitize_text_field( wp_unslash( $_POST['wp_mcp_ai_profession_vector_store_id'] ) );
			update_post_meta( $post_id, WP_MCP_AI_Profession_CPT::META_VECTOR_STORE_ID, $vector_store_id );
		} else {
			delete_post_meta( $post_id, WP_MCP_AI_Profession_CPT::META_VECTOR_STORE_ID );
		}

		// Save supported MIME types.
		if ( isset( $_POST['wp_mcp_ai_profession_mime_types'] ) ) {
			$mime_types = array_map( 'sanitize_text_field', (array) $_POST['wp_mcp_ai_profession_mime_types'] );
			update_post_meta( $post_id, WP_MCP_AI_Profession_CPT::META_SUPPORTED_MIME_TYPES, $mime_types );
		} else {
			delete_post_meta( $post_id, WP_MCP_AI_Profession_CPT::META_SUPPORTED_MIME_TYPES );
		}
	}
}
