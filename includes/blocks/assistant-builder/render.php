<?php
/**
 * Server-side rendering of the `wp-mcp-ai/assistant-builder` block.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the `wp-mcp-ai/assistant-builder` block on the server.
 *
 * @param array    $attributes Block attributes.
 * @param string   $content    Block default content.
 * @param WP_Block $block      Block instance.
 *
 * @return string Returns the block HTML.
 */
function wp_mcp_ai_render_assistant_builder_block( $attributes, $content, $block ) {
	// Check user permissions.
	if ( ! current_user_can( 'edit_posts' ) ) {
		return '<div class="wp-block-wp-mcp-ai-assistant-builder__notice">'
			. '<p>' . esc_html__( 'You do not have permission to use the Assistant Builder.', 'wp-mcp-ai' ) . '</p>'
			. '</div>';
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

	// Get wrapper attributes.
	$wrapper_attributes = get_block_wrapper_attributes(
		array(
			'class'         => implode( ' ', $wrapper_classes ),
			'data-block-id' => $unique_id,
		)
	);

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

	ob_start();
	?>
	<div <?php echo $wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
		<?php if ( $show_assistant_selector ) : ?>
			<?php echo wp_mcp_ai_render_assistant_selector_block( array( 'defaultAssistantId' => $default_assistant_id, 'showStartButton' => true ) ); ?>
		<?php endif; ?>

		<?php if ( $show_tools_grid ) : ?>
			<div class="wp-block-wp-mcp-ai-assistant-builder__tools" style="display: none;">
				<?php
				echo wp_mcp_ai_render_tools_grid_block(
					array(
						'showDescriptions' => $show_tool_descriptions,
						'startCollapsed'   => $tools_collapsed,
						'showActions'      => true,
					)
				);
				?>
			</div>
		<?php endif; ?>

		<?php if ( $show_knowledge_base ) : ?>
			<div class="wp-block-wp-mcp-ai-assistant-builder__knowledge-base" style="display: none;">
				<?php
				echo wp_mcp_ai_render_knowledge_base_block(
					array(
						'allowedTypes'  => $allowed_file_types,
						'maxFiles'      => $max_files,
						'maxFileSizeMB' => $max_file_size_mb,
						'showPreview'   => true,
					)
				);
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
	<?php
	return ob_get_clean();
}
