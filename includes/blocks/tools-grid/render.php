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
	echo '<p class="wp-block-mcp-ai-wpoos-tools-grid__notice">' . esc_html__( 'You do not have permission to view tools.', 'mcp-ai-wpoos' ) . '</p>';
	return;
}

$title             = isset( $attributes['title'] ) && '' !== $attributes['title']
	? $attributes['title']
	: __( 'Available Tools', 'mcp-ai-wpoos' );
$description       = isset( $attributes['description'] ) && '' !== $attributes['description']
	? $attributes['description']
	: __( 'Select or deselect tools to customize what capabilities the assistant can use.', 'mcp-ai-wpoos' );
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
		$group_labels['other'] = __( 'Other tools', 'mcp-ai-wpoos' );
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

		$grouped[ $group_id ]['tools'][] = array(
			'slug'        => $slug,
			'name'        => $tool->get_name() ?: $slug,
			'description' => $tool->get_description() ?: '',
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
	echo '<p class="wp-block-mcp-ai-wpoos-tools-grid__notice">' . esc_html__( 'No tools are currently registered.', 'mcp-ai-wpoos' ) . '</p>';
	return;
}

// Get wrapper attributes - handle both block and non-block contexts.
// Check if we're in a proper block rendering context (has $block object).
if ( function_exists( 'get_block_wrapper_attributes' ) && isset( $block ) && is_object( $block ) ) {
	$wrapper_attributes = get_block_wrapper_attributes(
		array(
			'class'         => 'wp-block-mcp-ai-wpoos-tools-grid',
			'data-block-id' => $unique_id,
		)
	);
} else {
	// Non-block context fallback (e.g., direct include in admin pages).
	$wrapper_attributes = sprintf(
		'class="%s" data-block-id="%s"',
		esc_attr( 'wp-block-mcp-ai-wpoos-tools-grid' ),
		esc_attr( $unique_id )
	);
}
?>
<div <?php echo $wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<?php if ( $title ) : ?>
		<h3 class="wp-block-mcp-ai-wpoos-tools-grid__title"><?php echo esc_html( $title ); ?></h3>
	<?php endif; ?>

	<?php if ( $description ) : ?>
		<p class="wp-block-mcp-ai-wpoos-tools-grid__description"><?php echo esc_html( $description ); ?></p>
	<?php endif; ?>

	<?php if ( $show_actions ) : ?>
		<?php
		// Render tool presets if helper class is available.
		if ( class_exists( 'WP_MCP_AI_Tool_Presets_Helper' ) && class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
			$registry        = WP_MCP_AI_Tool_Registry::get_instance();
			$available_tools = array();
			foreach ( $registry->get_tools() as $tool ) {
				if ( $tool instanceof WP_MCP_AI_Tool_Interface ) {
					$available_tools[] = $tool->get_slug();
				}
			}

			WP_MCP_AI_Tool_Presets_Helper::render_presets(
				array(
					'available_tools'   => $available_tools,
					'container_class'   => 'wp-block-mcp-ai-wpoos-tools-grid__presets',
					'checkbox_selector' => '.wp-mcp-ai-tools-grid__checkbox',
				)
			);
		}
		?>

		<div class="wp-block-mcp-ai-wpoos-tools-grid__filter-bar">
			<label for="<?php echo esc_attr( $unique_id . '-search' ); ?>" class="wp-block-mcp-ai-wpoos-tools-grid__filter-label">
				<?php esc_html_e( 'Search:', 'mcp-ai-wpoos' ); ?>
			</label>
			<input
				type="search"
				id="<?php echo esc_attr( $unique_id . '-search' ); ?>"
				class="wp-mcp-ai-tools-grid__search-input"
				placeholder="<?php esc_attr_e( 'Search tools...', 'mcp-ai-wpoos' ); ?>"
				aria-label="<?php esc_attr_e( 'Search tools', 'mcp-ai-wpoos' ); ?>"
			>
			<label for="<?php echo esc_attr( $unique_id . '-group' ); ?>" class="wp-block-mcp-ai-wpoos-tools-grid__filter-label">
				<?php esc_html_e( 'Category:', 'mcp-ai-wpoos' ); ?>
			</label>
			<select id="<?php echo esc_attr( $unique_id . '-group' ); ?>" class="wp-mcp-ai-tools-grid__group-select" aria-label="<?php esc_attr_e( 'Filter by group', 'mcp-ai-wpoos' ); ?>">
				<option value=""><?php esc_html_e( 'All Categories', 'mcp-ai-wpoos' ); ?></option>
				<?php foreach ( $groups as $group ) : ?>
					<option value="<?php echo esc_attr( $group['id'] ); ?>"><?php echo esc_html( $group['label'] ); ?></option>
				<?php endforeach; ?>
			</select>
			<button type="button" class="button wp-mcp-ai-tools-grid__clear-filters" style="display: none;">
				<?php esc_html_e( 'Clear', 'mcp-ai-wpoos' ); ?>
			</button>
		</div>
		<div class="wp-block-mcp-ai-wpoos-tools-grid__actions">
			<button type="button" class="button wp-mcp-ai-tools-grid__select-all">
				<?php esc_html_e( 'Select All', 'mcp-ai-wpoos' ); ?>
			</button>
			<button type="button" class="button wp-mcp-ai-tools-grid__deselect-all">
				<?php esc_html_e( 'Deselect All', 'mcp-ai-wpoos' ); ?>
			</button>
			<span class="wp-mcp-ai-tools-grid__count">
				<strong class="wp-mcp-ai-tools-grid__selected-count">0</strong>
				<?php esc_html_e( 'tools selected', 'mcp-ai-wpoos' ); ?>
			</span>
			<span class="wp-mcp-ai-tools-grid__visible-count" style="display: none;">
				<span class="wp-mcp-ai-tools-grid__visible-count-text"></span>
			</span>
		</div>
	<?php endif; ?>

	<div class="wp-block-mcp-ai-wpoos-tools-grid__groups">
		<?php foreach ( $groups as $group ) : ?>
			<?php
			$open_attr = $start_collapsed ? '' : ' open';
			?>
			<details class="wp-block-mcp-ai-wpoos-tools-grid__group"<?php echo $open_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure. ?>>
				<summary>
					<span class="wp-block-mcp-ai-wpoos-tools-grid__group-title"><?php echo esc_html( $group['label'] ); ?></span>
					<span class="wp-block-mcp-ai-wpoos-tools-grid__group-count">
						<span class="wp-mcp-ai-tools-grid__group-selected">0</span> / <?php echo esc_html( count( $group['tools'] ) ); ?>
					</span>
				</summary>
				<ul class="wp-block-mcp-ai-wpoos-tools-grid__list">
					<?php foreach ( $group['tools'] as $tool ) : ?>
						<?php
						$is_selected = in_array( $tool['slug'], $selected_tools, true );
						$item_class  = 'wp-block-mcp-ai-wpoos-tools-grid__item';
						if ( $is_selected ) {
							$item_class .= ' wp-block-mcp-ai-wpoos-tools-grid__item--selected';
						}
						?>
						<li class="<?php echo esc_attr( $item_class ); ?>" data-tool-slug="<?php echo esc_attr( $tool['slug'] ); ?>">
							<div class="wp-block-mcp-ai-wpoos-tools-grid__item-header">
								<input
									type="checkbox"
									class="wp-mcp-ai-tools-grid__checkbox"
									id="<?php echo esc_attr( $unique_id . '-' . $tool['slug'] ); ?>"
									value="<?php echo esc_attr( $tool['slug'] ); ?>"
									<?php checked( $is_selected ); ?>
								>
								<label for="<?php echo esc_attr( $unique_id . '-' . $tool['slug'] ); ?>">
									<span class="wp-block-mcp-ai-wpoos-tools-grid__item-name"><?php echo esc_html( $tool['name'] ); ?></span>
								</label>
							</div>
							<?php if ( $show_descriptions && ! empty( $tool['description'] ) ) : ?>
								<p class="wp-block-mcp-ai-wpoos-tools-grid__item-description"><?php echo esc_html( $tool['description'] ); ?></p>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			</details>
		<?php endforeach; ?>
	</div>
</div>
