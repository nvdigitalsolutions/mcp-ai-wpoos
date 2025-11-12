<?php
/**
 * Base Knowledge Metabox for Assistants.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the Base Knowledge metabox for assistant posts.
 *
 * Manages memory files and vector store configuration.
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Metabox_Base_Knowledge extends WP_MCP_AI_Metabox_Base {

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
		return 'wp_mcp_ai_base_knowledge';
	}

	/**
	 * Get the metabox title.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public function get_title() {
		return __( 'Base Knowledge', 'wp-mcp-ai' );
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

		wp_nonce_field( 'wp_mcp_ai_base_knowledge_meta', 'wp_mcp_ai_base_knowledge_meta_nonce' );

		wp_enqueue_media();
		wp_enqueue_script( 'jquery' );

		$memory_files    = get_post_meta( $post->ID, WP_MCP_AI_Assistant_CPT::META_MEMORY_FILES, true );
		$vector_store_id = get_post_meta( $post->ID, WP_MCP_AI_Assistant_CPT::META_VECTOR_STORE_ID, true );

		if ( ! is_array( $memory_files ) ) {
			$memory_files = array();
		}

		if ( ! is_string( $vector_store_id ) ) {
			$vector_store_id = '';
		}

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

		?>
	<p><?php esc_html_e( 'Select Media Library items that should be preloaded as reference material for this assistant.', 'wp-mcp-ai' ); ?></p>
	<ul id="wp-mcp-ai-memory-files-list" class="wp-mcp-ai-memory-files">
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
				<input type="hidden" name="wp_mcp_ai_memory_files[]" value="<?php echo esc_attr( $file_id ); ?>" />
			</li>
		<?php endforeach; ?>
	</ul>
	<p class="description">
			<?php
			printf(
			/* translators: %s: Human-readable size of the memory payload. */
				esc_html__( 'Total memory size sent with each request: %s.', 'wp-mcp-ai' ),
				esc_html( $memory_size_label )
			);
			?>
	</p>
	<p>
		<button type="button" class="button" id="wp-mcp-ai-memory-select">
			<?php esc_html_e( 'Add Knowledge Files', 'wp-mcp-ai' ); ?>
		</button>
	</p>
	<p>
		<label for="wp-mcp-ai-vector-store-id"><strong><?php esc_html_e( 'Vector Store ID', 'wp-mcp-ai' ); ?></strong></label>
		<input type="text" id="wp-mcp-ai-vector-store-id" name="wp_mcp_ai_vector_store_id" value="<?php echo esc_attr( $vector_store_id ); ?>" class="widefat" />
		<span class="description"><?php esc_html_e( 'Optional identifier for an external vector store that should be associated with this assistant.', 'wp-mcp-ai' ); ?></span>
	</p>
	<style type="text/css">
		.wp-mcp-ai-memory-file-size {
			color: #646970;
			font-size: 0.9em;
			margin-left: 0.5em;
		}
	</style>
	<script type="text/javascript">
	jQuery( function( $ ) {
		var frame;
		var list = $( '#wp-mcp-ai-memory-files-list' );

		function addAttachment( attachment ) {
			var id = attachment.id || attachment.ID;
			if ( ! id ) {
				return;
			}

			if ( list.find( 'li[data-id="' + id + '"]' ).length ) {
				return;
			}

			var title = attachment.title || attachment.filename || attachment.name || '<?php echo esc_js( __( 'Attachment', 'wp-mcp-ai' ) ); ?>';
			var label = title + ' (ID: ' + id + ')';
			var filesize = attachment.filesizeHumanReadable || '';

			var item = $( '<li />', { 'data-id': id } );
			item.append( $( '<span />', { 'class': 'wp-mcp-ai-memory-file-title', 'text': label } ) );
			if ( filesize ) {
				item.append( $( '<span />', { 'class': 'wp-mcp-ai-memory-file-size', 'text': '(' + filesize + ')' } ) );
			}
			item.append( $( '<button />', { 'type': 'button', 'class': 'button-link wp-mcp-ai-remove-memory', 'text': '<?php echo esc_js( __( 'Remove', 'wp-mcp-ai' ) ); ?>' } ) );
			item.append( $( '<input />', { 'type': 'hidden', 'name': 'wp_mcp_ai_memory_files[]', 'value': id } ) );

			list.append( item );
		}

		$( '#wp-mcp-ai-memory-select' ).on( 'click', function( event ) {
			event.preventDefault();

			if ( frame ) {
				frame.open();
				return;
			}

			frame = wp.media({
				title: '<?php echo esc_js( __( 'Select knowledge files', 'wp-mcp-ai' ) ); ?>',
				button: {
					text: '<?php echo esc_js( __( 'Use files', 'wp-mcp-ai' ) ); ?>'
				},
				multiple: true
			});

			frame.on( 'select', function() {
				var selection = frame.state().get( 'selection' );
				if ( ! selection ) {
					return;
				}

				selection.each( function( attachment ) {
					addAttachment( attachment.toJSON() );
				} );
			});

			frame.open();
		} );

		list.on( 'click', '.wp-mcp-ai-remove-memory', function( event ) {
			event.preventDefault();
			$( this ).closest( 'li' ).remove();
		} );
	} );
	</script>
		<?php
	}
}
