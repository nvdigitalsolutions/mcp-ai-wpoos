<?php
/**
 * Server-side rendering of the `wp-mcp-ai/assistant-builder` block.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Check user permissions.
if ( ! current_user_can( 'edit_posts' ) ) {
	echo '<div class="wp-block-wp-mcp-ai-assistant-builder__notice">';
	echo '<p>' . esc_html__( 'You do not have permission to use the Assistant Builder.', 'wp-mcp-ai' ) . '</p>';
	echo '</div>';
	return;
}

// Extract attributes with defaults.
$show_assistant_selector = isset( $attributes['showAssistantSelector'] ) ? $attributes['showAssistantSelector'] : true;
$show_tools_grid         = isset( $attributes['showToolsGrid'] ) ? $attributes['showToolsGrid'] : true;
$show_knowledge_base     = isset( $attributes['showKnowledgeBase'] ) ? $attributes['showKnowledgeBase'] : true;
$show_build_button       = isset( $attributes['showBuildButton'] ) ? $attributes['showBuildButton'] : true;
$default_assistant_id    = isset( $attributes['defaultAssistantId'] ) ? absint( $attributes['defaultAssistantId'] ) : 0;
$layout                  = isset( $attributes['layout'] ) ? $attributes['layout'] : 'stacked';
$tools_collapsed         = isset( $attributes['toolsCollapsed'] ) ? $attributes['toolsCollapsed'] : true;
$show_tool_descriptions  = isset( $attributes['showToolDescriptions'] ) ? $attributes['showToolDescriptions'] : true;
$enable_streaming        = isset( $attributes['enableStreaming'] ) ? $attributes['enableStreaming'] : true;
$chat_placeholder        = isset( $attributes['chatPlaceholder'] ) && '' !== $attributes['chatPlaceholder']
	? $attributes['chatPlaceholder']
	: __( 'Describe the assistant you want to create...', 'wp-mcp-ai' );
$allowed_file_types      = isset( $attributes['allowedFileTypes'] ) ? $attributes['allowedFileTypes'] : '.pdf,.txt,.md,.doc,.docx,.csv,.json';
$max_files               = isset( $attributes['maxFiles'] ) ? absint( $attributes['maxFiles'] ) : 10;
$max_file_size_mb        = isset( $attributes['maxFileSizeMB'] ) ? absint( $attributes['maxFileSizeMB'] ) : 10;

// Generate unique ID for this block instance.
$unique_id = wp_unique_id( 'wp-mcp-ai-assistant-builder-' );

// Build wrapper classes.
$wrapper_classes   = array( 'wp-block-wp-mcp-ai-assistant-builder' );
$wrapper_classes[] = 'wp-block-wp-mcp-ai-assistant-builder--' . sanitize_html_class( $layout );

// Get wrapper attributes - handle both block and non-block contexts.
if ( function_exists( 'get_block_wrapper_attributes' ) ) {
	$wrapper_attributes = get_block_wrapper_attributes(
		array(
			'class'         => implode( ' ', $wrapper_classes ),
			'data-block-id' => $unique_id,
		)
	);
} else {
	// Non-block context fallback.
	$wrapper_attributes = sprintf(
		'class="%s" data-block-id="%s"',
		esc_attr( implode( ' ', $wrapper_classes ) ),
		esc_attr( $unique_id )
	);
}

// Build configuration for JavaScript.
$config = array(
	'blockId'               => $unique_id,
	'showAssistantSelector' => $show_assistant_selector,
	'showToolsGrid'         => $show_tools_grid,
	'showKnowledgeBase'     => $show_knowledge_base,
	'showBuildButton'       => $show_build_button,
	'defaultAssistantId'    => $default_assistant_id,
	'enableStreaming'       => $enable_streaming,
	'chatPlaceholder'       => $chat_placeholder,
	'allowedFileTypes'      => $allowed_file_types,
	'maxFiles'              => $max_files,
	'maxFileSizeMB'         => $max_file_size_mb,
	'restUrl'               => rest_url( 'mcp-ai/v1' ),
	'wpRestUrl'             => rest_url( 'wp/v2' ),
	'nonce'                 => wp_create_nonce( 'wp_rest' ),
	'ajaxUrl'               => admin_url( 'admin-ajax.php' ),
	'createNonce'           => wp_create_nonce( 'wp_mcp_ai_create_assistant' ),
);
?>
<div <?php echo $wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<?php if ( $show_assistant_selector ) : ?>
		<?php
		// Render assistant selector inline.
		$selector_attributes = array(
			'defaultAssistantId' => $default_assistant_id,
			'showStartButton'    => true,
		);

		// Get assistants.
		$assistants = array();
		if ( class_exists( 'WP_MCP_AI_Assistant_CPT' ) ) {
			$posts = get_posts(
				array(
					'post_type'      => WP_MCP_AI_Assistant_CPT::POST_TYPE,
					'post_status'    => 'publish',
					'posts_per_page' => -1,
					'orderby'        => 'title',
					'order'          => 'ASC',
				)
			);

			foreach ( $posts as $post ) {
				$tools = get_post_meta( $post->ID, WP_MCP_AI_Assistant_CPT::META_TOOLS, true );
				if ( ! is_array( $tools ) ) {
					$tools = array();
				}

				$shortcuts = array();
				if ( class_exists( 'WP_MCP_AI_Shortcode' ) && method_exists( 'WP_MCP_AI_Shortcode', 'get_assistant_tool_shortcuts' ) ) {
					$shortcuts = WP_MCP_AI_Shortcode::get_assistant_tool_shortcuts( $post->ID );
				}

				$assistants[] = array(
					'id'        => $post->ID,
					'title'     => $post->post_title,
					'tools'     => $tools,
					'shortcuts' => $shortcuts,
				);
			}
		}
		?>
		<div class="wp-block-wp-mcp-ai-assistant-selector" data-block-id="<?php echo esc_attr( $unique_id ); ?>-selector">
			<label for="<?php echo esc_attr( $unique_id ); ?>-select">
				<?php esc_html_e( 'Select an Assistant:', 'wp-mcp-ai' ); ?>
			</label>
			<select id="<?php echo esc_attr( $unique_id ); ?>-select" class="wp-mcp-ai-assistant-selector__select">
				<option value=""><?php esc_html_e( '— Select an assistant —', 'wp-mcp-ai' ); ?></option>
				<?php foreach ( $assistants as $assistant ) : ?>
					<option 
						value="<?php echo esc_attr( $assistant['id'] ); ?>"
						data-tools="<?php echo esc_attr( wp_json_encode( $assistant['tools'] ) ); ?>"
						data-shortcuts="<?php echo esc_attr( wp_json_encode( $assistant['shortcuts'] ) ); ?>"
						<?php selected( $default_assistant_id, $assistant['id'] ); ?>
					>
						<?php echo esc_html( $assistant['title'] ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<button type="button" class="wp-mcp-ai-assistant-selector__start button button-primary" disabled>
				<?php esc_html_e( 'Start Chat', 'wp-mcp-ai' ); ?>
			</button>
		</div>
	<?php endif; ?>

	<?php if ( $show_tools_grid ) : ?>
		<div class="wp-block-wp-mcp-ai-assistant-builder__tools" style="display: none;">
			<?php
			// Include tools-grid render.php inline.
			$tools_attributes = array(
				'showDescriptions' => $show_tool_descriptions,
				'startCollapsed'   => $tools_collapsed,
				'showActions'      => true,
			);

			// Set attributes for the nested tools-grid block.
			// The included render.php expects $attributes as its input.
			$parent_attributes = $attributes;
			$attributes        = $tools_attributes;
			include WP_MCP_AI_PATH . 'includes/blocks/tools-grid/render.php';
			$attributes = $parent_attributes;
			?>
		</div>
	<?php endif; ?>

	<?php if ( $show_knowledge_base ) : ?>
		<div class="wp-block-wp-mcp-ai-assistant-builder__knowledge-base" style="display: none;">
			<?php
			// Include knowledge-base render.php inline.
			$kb_attributes = array(
				'allowedTypes'  => $allowed_file_types,
				'maxFiles'      => $max_files,
				'maxFileSizeMB' => $max_file_size_mb,
				'showPreview'   => true,
			);

			// Set attributes for the nested knowledge-base block.
			// The included render.php expects $attributes as its input.
			$parent_attributes = $attributes;
			$attributes        = $kb_attributes;
			include WP_MCP_AI_PATH . 'includes/blocks/knowledge-base/render.php';
			$attributes = $parent_attributes;
			?>
		</div>
	<?php endif; ?>

	<div class="wp-block-wp-mcp-ai-assistant-builder__chat" style="display: none;">
		<!-- Chat interface will be initialized via JavaScript -->
	</div>

	<script type="application/json" class="wp-mcp-ai-assistant-builder-config">
		<?php echo wp_json_encode( $config ); ?>
	</script>
</div>
