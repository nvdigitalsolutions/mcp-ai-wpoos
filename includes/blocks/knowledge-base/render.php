<?php
/**
 * Server-side rendering of the `wp-mcp-ai/knowledge-base` block.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Check user permissions.
if ( ! current_user_can( 'upload_files' ) ) {
	echo '<p class="wp-block-wp-mcp-ai-knowledge-base__notice">' . esc_html__( 'You do not have permission to upload files.', 'wp-mcp-ai' ) . '</p>';
	return;
}

$title         = isset( $attributes['title'] ) && '' !== $attributes['title']
	? $attributes['title']
	: __( 'Knowledge Base', 'wp-mcp-ai' );
$description   = isset( $attributes['description'] ) && '' !== $attributes['description']
	? $attributes['description']
	: __( 'Upload files to include in the assistant\'s knowledge base.', 'wp-mcp-ai' );
$allowed_types = isset( $attributes['allowedTypes'] ) ? $attributes['allowedTypes'] : '.pdf,.txt,.md,.doc,.docx,.csv,.json';
$max_files     = isset( $attributes['maxFiles'] ) ? absint( $attributes['maxFiles'] ) : 10;
$max_file_size = isset( $attributes['maxFileSizeMB'] ) ? absint( $attributes['maxFileSizeMB'] ) : 10;

$unique_id       = wp_unique_id( 'wp-mcp-ai-knowledge-base-' );
$max_upload_size = min( wp_max_upload_size(), $max_file_size * 1024 * 1024 );

// Get file type display names.
$type_names = array_map(
	function ( $ext ) {
		return strtoupper( ltrim( trim( $ext ), '.' ) );
	},
	explode( ',', $allowed_types )
);

// Get wrapper attributes.
$wrapper_attributes = get_block_wrapper_attributes(
	array(
		'class'              => 'wp-block-wp-mcp-ai-knowledge-base',
		'data-block-id'      => $unique_id,
		'data-allowed-types' => $allowed_types,
		'data-max-files'     => $max_files,
		'data-max-size'      => $max_upload_size,
		'data-nonce'         => wp_create_nonce( 'wp_rest' ),
		'data-upload-url'    => rest_url( 'wp/v2/media' ),
	)
);
?>
<div <?php echo $wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>

	<?php if ( $title ) : ?>
		<h3 class="wp-block-wp-mcp-ai-knowledge-base__title"><?php echo esc_html( $title ); ?></h3>
	<?php endif; ?>

	<?php if ( $description ) : ?>
		<p class="wp-block-wp-mcp-ai-knowledge-base__description"><?php echo esc_html( $description ); ?></p>
	<?php endif; ?>

	<!-- Upload Area -->
	<div class="wp-block-wp-mcp-ai-knowledge-base__upload-area">
		<div class="wp-block-wp-mcp-ai-knowledge-base__dropzone" tabindex="0" role="button" aria-label="<?php esc_attr_e( 'Upload files', 'wp-mcp-ai' ); ?>">
			<svg class="wp-block-wp-mcp-ai-knowledge-base__upload-icon" viewBox="0 0 24 24" aria-hidden="true">
				<path d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m6.75 12l-3-3m0 0l-3 3m3-3v6m-1.5-15H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z"/>
			</svg>
			<p class="wp-block-wp-mcp-ai-knowledge-base__dropzone-text">
				<?php esc_html_e( 'Drop files here or click to upload', 'wp-mcp-ai' ); ?>
			</p>
			<p class="wp-block-wp-mcp-ai-knowledge-base__dropzone-hint">
				<?php
				printf(
					/* translators: 1: file types, 2: max file size */
					esc_html__( 'Accepted: %1$s • Max %2$s per file', 'wp-mcp-ai' ),
					esc_html( implode( ', ', $type_names ) ),
					esc_html( size_format( $max_upload_size ) )
				);
				?>
			</p>
			<input 
				type="file" 
				class="wp-block-wp-mcp-ai-knowledge-base__file-input" 
				id="<?php echo esc_attr( $unique_id ); ?>-input"
				accept="<?php echo esc_attr( $allowed_types ); ?>"
				multiple
				hidden
			>
		</div>
	</div>

	<!-- File List -->
	<div class="wp-block-wp-mcp-ai-knowledge-base__files">
		<div class="wp-block-wp-mcp-ai-knowledge-base__files-header">
			<span class="wp-block-wp-mcp-ai-knowledge-base__files-count">
				<strong class="wp-mcp-ai-knowledge-base__count">0</strong> / <?php echo esc_html( $max_files ); ?>
				<?php esc_html_e( 'files', 'wp-mcp-ai' ); ?>
			</span>
			<button type="button" class="wp-block-wp-mcp-ai-knowledge-base__clear-all button button-link" style="display: none;">
				<?php esc_html_e( 'Remove All', 'wp-mcp-ai' ); ?>
			</button>
		</div>
		<ul class="wp-block-wp-mcp-ai-knowledge-base__file-list" role="list">
			<!-- Files will be added here dynamically -->
		</ul>
	</div>

	<!-- Hidden input to store file IDs -->
	<input type="hidden" class="wp-block-wp-mcp-ai-knowledge-base__file-ids" name="knowledge_base_files" value="">

	<!-- Progress indicator -->
	<div class="wp-block-wp-mcp-ai-knowledge-base__progress" style="display: none;">
		<div class="wp-block-wp-mcp-ai-knowledge-base__progress-bar">
			<div class="wp-block-wp-mcp-ai-knowledge-base__progress-fill"></div>
		</div>
		<span class="wp-block-wp-mcp-ai-knowledge-base__progress-text"><?php esc_html_e( 'Uploading...', 'wp-mcp-ai' ); ?></span>
	</div>
</div>
