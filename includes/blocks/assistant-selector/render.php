<?php
/**
 * Server-side rendering of the `wp-mcp-ai/assistant-selector` block.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the `wp-mcp-ai/assistant-selector` block on the server.
 *
 * @param array    $attributes Block attributes.
 * @param string   $content    Block default content.
 * @param WP_Block $block      Block instance.
 *
 * @return string Returns the block HTML.
 */
function wp_mcp_ai_render_assistant_selector_block( $attributes, $content = '', $block = null ) {
	$default_id        = isset( $attributes['defaultAssistantId'] ) ? absint( $attributes['defaultAssistantId'] ) : 0;
	$label             = isset( $attributes['label'] ) && '' !== $attributes['label']
		? $attributes['label']
		: __( 'Select an Assistant:', 'wp-mcp-ai' );
	$show_start_button = isset( $attributes['showStartButton'] ) ? $attributes['showStartButton'] : true;
	$start_button_text = isset( $attributes['startButtonText'] ) && '' !== $attributes['startButtonText']
		? $attributes['startButtonText']
		: __( 'Start Chat', 'wp-mcp-ai' );

	// Get assistants.
	$assistants = wp_mcp_ai_get_assistants_for_blocks();
	$unique_id  = wp_unique_id( 'wp-mcp-ai-assistant-selector-' );

	// Get wrapper attributes.
	$wrapper_attributes = '';
	if ( null !== $block && function_exists( 'get_block_wrapper_attributes' ) ) {
		$wrapper_attributes = get_block_wrapper_attributes(
			array(
				'class'         => 'wp-block-wp-mcp-ai-assistant-selector',
				'data-block-id' => $unique_id,
			)
		);
	} else {
		$wrapper_attributes = 'class="wp-block-wp-mcp-ai-assistant-selector" data-block-id="' . esc_attr( $unique_id ) . '"';
	}

	ob_start();
	?>
	<div <?php echo $wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
		<label for="<?php echo esc_attr( $unique_id ); ?>-select">
			<?php echo esc_html( $label ); ?>
		</label>
		<select id="<?php echo esc_attr( $unique_id ); ?>-select" class="wp-mcp-ai-assistant-selector__select">
			<option value=""><?php esc_html_e( '— Select an assistant —', 'wp-mcp-ai' ); ?></option>
			<?php foreach ( $assistants as $assistant ) : ?>
				<option 
					value="<?php echo esc_attr( $assistant['id'] ); ?>"
					data-tools="<?php echo esc_attr( wp_json_encode( $assistant['tools'] ) ); ?>"
					data-shortcuts="<?php echo esc_attr( wp_json_encode( isset( $assistant['shortcuts'] ) ? $assistant['shortcuts'] : array() ) ); ?>"
					<?php selected( $default_id, $assistant['id'] ); ?>
				>
					<?php echo esc_html( $assistant['title'] ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<?php if ( $show_start_button ) : ?>
			<button type="button" class="wp-mcp-ai-assistant-selector__start button button-primary" disabled>
				<?php echo esc_html( $start_button_text ); ?>
			</button>
		<?php endif; ?>
	</div>
	<?php
	return ob_get_clean();
}

/**
 * Get assistants data for blocks.
 *
 * @return array
 */
function wp_mcp_ai_get_assistants_for_blocks() {
	$assistants = array();

	if ( ! class_exists( 'WP_MCP_AI_Assistant_CPT' ) ) {
		return $assistants;
	}

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

	return $assistants;
}
