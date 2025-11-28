<?php
/**
 * Server-side rendering of the `wp-mcp-ai/tools-grid` block.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Check user permissions.
if ( ! current_user_can( 'edit_posts' ) ) {
	echo '<p class="wp-block-wp-mcp-ai-tools-grid__notice">' . esc_html__( 'You do not have permission to view tools.', 'wp-mcp-ai' ) . '</p>';
	return;
}

$title             = isset( $attributes['title'] ) && '' !== $attributes['title']
	? $attributes['title']
	: __( 'Available Tools', 'wp-mcp-ai' );
$description       = isset( $attributes['description'] ) && '' !== $attributes['description']
	? $attributes['description']
	: __( 'Select or deselect tools to customize what capabilities the assistant can use.', 'wp-mcp-ai' );
$show_descriptions = isset( $attributes['showDescriptions'] ) ? $attributes['showDescriptions'] : true;
$start_collapsed   = isset( $attributes['startCollapsed'] ) ? $attributes['startCollapsed'] : true;
$show_actions      = isset( $attributes['showActions'] ) ? $attributes['showActions'] : true;
$selected_tools    = isset( $attributes['selectedTools'] ) ? $attributes['selectedTools'] : array();

// Get tool groups.
$groups = array();
if ( class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
	$registry = WP_MCP_AI_Tool_Registry::get_instance();
	$tools    = $registry->get_tools();

	$group_map    = array();
	$group_labels = array();

	if ( method_exists( $registry, 'get_tool_group_map' ) ) {
		$group_map = $registry->get_tool_group_map();
	}
	if ( method_exists( $registry, 'get_tool_group_labels' ) ) {
		$group_labels = $registry->get_tool_group_labels();
	}

	if ( ! is_array( $group_map ) ) {
		$group_map = array();
	}
	if ( ! is_array( $group_labels ) ) {
		$group_labels = array();
	}
	if ( ! isset( $group_labels['other'] ) ) {
		$group_labels['other'] = __( 'Other tools', 'wp-mcp-ai' );
	}

	$grouped = array();

	foreach ( $tools as $tool ) {
		if ( ! $tool instanceof WP_MCP_AI_Tool_Interface ) {
			continue;
		}

		$slug = $tool->get_slug();
		if ( '' === $slug ) {
			continue;
		}

		$group_id = isset( $group_map[ $slug ] ) ? (string) $group_map[ $slug ] : 'other';
		if ( '' === $group_id ) {
			$group_id = 'other';
		}

		if ( ! isset( $grouped[ $group_id ] ) ) {
			$grouped[ $group_id ] = array(
				'id'    => $group_id,
				'label' => isset( $group_labels[ $group_id ] ) ? $group_labels[ $group_id ] : ucfirst( $group_id ),
				'tools' => array(),
			);
		}

		$definition = $tool->get_definition();

		$grouped[ $group_id ]['tools'][] = array(
			'slug'        => $slug,
			'name'        => isset( $definition['name'] ) ? $definition['name'] : $slug,
			'description' => isset( $definition['description'] ) ? $definition['description'] : '',
		);
	}

	// Order by group labels.
	foreach ( $group_labels as $group_id => $label ) {
		if ( isset( $grouped[ $group_id ] ) ) {
			$groups[] = $grouped[ $group_id ];
			unset( $grouped[ $group_id ] );
		}
	}
	foreach ( $grouped as $group ) {
		$groups[] = $group;
	}
}

$unique_id = wp_unique_id( 'wp-mcp-ai-tools-grid-' );

if ( empty( $groups ) ) {
	echo '<p class="wp-block-wp-mcp-ai-tools-grid__notice">' . esc_html__( 'No tools are currently registered.', 'wp-mcp-ai' ) . '</p>';
	return;
}

// Get wrapper attributes.
$wrapper_attributes = get_block_wrapper_attributes(
	array(
		'class'         => 'wp-block-wp-mcp-ai-tools-grid',
		'data-block-id' => $unique_id,
	)
);
?>
<div <?php echo $wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<?php if ( $title ) : ?>
		<h3 class="wp-block-wp-mcp-ai-tools-grid__title"><?php echo esc_html( $title ); ?></h3>
	<?php endif; ?>

	<?php if ( $description ) : ?>
		<p class="wp-block-wp-mcp-ai-tools-grid__description"><?php echo esc_html( $description ); ?></p>
	<?php endif; ?>

	<?php if ( $show_actions ) : ?>
		<div class="wp-block-wp-mcp-ai-tools-grid__actions">
			<button type="button" class="button wp-mcp-ai-tools-grid__select-all">
				<?php esc_html_e( 'Select All', 'wp-mcp-ai' ); ?>
			</button>
			<button type="button" class="button wp-mcp-ai-tools-grid__deselect-all">
				<?php esc_html_e( 'Deselect All', 'wp-mcp-ai' ); ?>
			</button>
			<span class="wp-mcp-ai-tools-grid__count">
				<strong class="wp-mcp-ai-tools-grid__selected-count">0</strong>
				<?php esc_html_e( 'tools selected', 'wp-mcp-ai' ); ?>
			</span>
		</div>
	<?php endif; ?>

	<div class="wp-block-wp-mcp-ai-tools-grid__groups">
		<?php foreach ( $groups as $group ) : ?>
			<?php $open_attr = $start_collapsed ? '' : ' open'; ?>
			<details class="wp-block-wp-mcp-ai-tools-grid__group"<?php echo $open_attr; ?>>
				<summary>
					<span class="wp-block-wp-mcp-ai-tools-grid__group-title"><?php echo esc_html( $group['label'] ); ?></span>
					<span class="wp-block-wp-mcp-ai-tools-grid__group-count">
						<span class="wp-mcp-ai-tools-grid__group-selected">0</span> / <?php echo esc_html( count( $group['tools'] ) ); ?>
					</span>
				</summary>
				<ul class="wp-block-wp-mcp-ai-tools-grid__list">
					<?php foreach ( $group['tools'] as $tool ) : ?>
						<?php
						$is_selected = in_array( $tool['slug'], $selected_tools, true );
						$item_class  = 'wp-block-wp-mcp-ai-tools-grid__item';
						if ( $is_selected ) {
							$item_class .= ' wp-block-wp-mcp-ai-tools-grid__item--selected';
						}
						?>
						<li class="<?php echo esc_attr( $item_class ); ?>" data-tool-slug="<?php echo esc_attr( $tool['slug'] ); ?>">
							<div class="wp-block-wp-mcp-ai-tools-grid__item-header">
								<input 
									type="checkbox" 
									class="wp-mcp-ai-tools-grid__checkbox"
									id="<?php echo esc_attr( $unique_id . '-' . $tool['slug'] ); ?>"
									value="<?php echo esc_attr( $tool['slug'] ); ?>"
									<?php checked( $is_selected ); ?>
								>
								<label for="<?php echo esc_attr( $unique_id . '-' . $tool['slug'] ); ?>">
									<span class="wp-block-wp-mcp-ai-tools-grid__item-name"><?php echo esc_html( $tool['name'] ); ?></span>
								</label>
							</div>
							<?php if ( $show_descriptions && ! empty( $tool['description'] ) ) : ?>
								<p class="wp-block-wp-mcp-ai-tools-grid__item-description"><?php echo esc_html( $tool['description'] ); ?></p>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			</details>
		<?php endforeach; ?>
	</div>
</div>
