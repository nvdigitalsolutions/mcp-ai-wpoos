<?php
/**
 * Server-side rendering of the `wp-mcp-ai/assistant-selector` block.
 *
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$default_id        = isset( $attributes['defaultAssistantId'] ) ? absint( $attributes['defaultAssistantId'] ) : 0;
$label             = isset( $attributes['label'] ) && '' !== $attributes['label']
	? $attributes['label']
	: __( 'Select an Assistant:', 'wp-mcp-ai' );
$show_start_button = isset( $attributes['showStartButton'] ) ? $attributes['showStartButton'] : true;
$start_button_text = isset( $attributes['startButtonText'] ) && '' !== $attributes['startButtonText']
	? $attributes['startButtonText']
	: __( 'Start Chat', 'wp-mcp-ai' );

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

$unique_id = wp_unique_id( 'wp-mcp-ai-assistant-selector-' );

// Get wrapper attributes - handle both block and non-block contexts.
if ( function_exists( 'get_block_wrapper_attributes' ) ) {
	$wrapper_attributes = get_block_wrapper_attributes(
		array(
			'class'         => 'wp-block-wp-mcp-ai-assistant-selector',
			'data-block-id' => $unique_id,
		)
	);
} else {
	// Non-block context fallback.
	$wrapper_attributes = sprintf(
		'class="%s" data-block-id="%s"',
		esc_attr( 'wp-block-wp-mcp-ai-assistant-selector' ),
		esc_attr( $unique_id )
	);
}
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
				data-shortcuts="<?php echo esc_attr( wp_json_encode( $assistant['shortcuts'] ) ); ?>"
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
