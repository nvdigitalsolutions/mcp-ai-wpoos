<?php
/**
 * AI tools grid block for the Content Graph AI addon.
 *
 * Aligned port of the base plugin's `mcp-ai-wpoos/tools-grid` block: a
 * server-rendered, groupable checklist of registered tools with search,
 * category filtering, select/deselect-all actions, and live counts.
 * Behaviour kept (capability gate, group ordering, selected-state
 * rendering); CSS classes are ecosystem-prefixed (`nvoos-cg-*`) so the
 * base block and this one coexist in monolith installs.
 *
 * Decoupling (documented, additive):
 * - The tool registry delegates to the base `WP_MCP_AI_Tool_Registry`
 *   in monolith installs (group map + labels included) and the
 *   nvoos/core registry via `CoreBridge` standalone — camelCase core
 *   tools are wrapped to the base snake_case surface. The core registry
 *   has no group taxonomy, so standalone renders a single "Other tools"
 *   group (deviation).
 * - Tool presets (base `WP_MCP_AI_Tool_Presets_Helper`) have no CG
 *   counterpart yet and are not rendered (deviation; they land with the
 *   presets wave).
 *
 * @package NvoosContentGraphAi\Blocks
 * @since   1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary (commercial license required)
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Blocks;

use NvoosContentGraphAi\CoreBridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `nvoos-content-graph-ai/tools-grid` block.
 *
 * @since 1.1.0
 */
class ToolsGridBlock {

	/**
	 * Block name.
	 */
	const BLOCK_NAME = 'nvoos-content-graph-ai/tools-grid';

	/**
	 * Block metadata (title/icon/category/attributes).
	 *
	 * @return array
	 */
	public static function metadata(): array {
		return array(
			'apiVersion'  => 3,
			'title'       => __( 'AI Tools Grid', 'nvoos-content-graph-ai' ),
			'category'    => 'nvoos-content-graph-ai',
			'icon'        => 'admin-tools',
			'description' => __( 'Display a grid of available AI tools that users can enable or disable.', 'nvoos-content-graph-ai' ),
			'keywords'    => array( 'ai', 'tools', 'grid', 'capabilities', 'mcp' ),
			'attributes'  => array(
				'title'            => array(
					'type'    => 'string',
					'default' => '',
				),
				'description'      => array(
					'type'    => 'string',
					'default' => '',
				),
				'showDescriptions' => array(
					'type'    => 'boolean',
					'default' => true,
				),
				'startCollapsed'   => array(
					'type'    => 'boolean',
					'default' => true,
				),
				'showActions'      => array(
					'type'    => 'boolean',
					'default' => true,
				),
				'selectedTools'    => array(
					'type'    => 'array',
					'default' => array(),
				),
			),
			'supports'    => array(
				'anchor'  => true,
				'html'    => false,
				'spacing' => array(
					'margin'  => true,
					'padding' => true,
				),
			),
		);
	}

	/**
	 * Server-side render callback.
	 *
	 * The third argument is nullable so admin pages (e.g. the Build
	 * Assistant Prompt tab) can embed the same markup outside a block
	 * context without constructing a WP_Block instance.
	 *
	 * @param array         $attributes Block attributes.
	 * @param string        $content    Inner block content (unused).
	 * @param \WP_Block|null $block     Block instance (null in admin embeds).
	 * @return string Rendered block HTML.
	 */
	public static function render( array $attributes, string $content, ?\WP_Block $block = null ): string {
		unset( $content );

		if ( ! current_user_can( 'edit_posts' ) ) {
			return '<p class="nvoos-cg-tools-grid__notice">' . esc_html__( 'You do not have permission to view tools.', 'nvoos-content-graph-ai' ) . '</p>';
		}

		$block_title       = isset( $attributes['title'] ) && '' !== $attributes['title']
			? sanitize_text_field( (string) $attributes['title'] )
			: __( 'Available Tools', 'nvoos-content-graph-ai' );
		$description       = isset( $attributes['description'] ) && '' !== $attributes['description']
			? sanitize_text_field( (string) $attributes['description'] )
			: __( 'Select or deselect tools to customize what capabilities the assistant can use.', 'nvoos-content-graph-ai' );
		$show_descriptions = ! isset( $attributes['showDescriptions'] ) || ! empty( $attributes['showDescriptions'] );
		$start_collapsed   = ! isset( $attributes['startCollapsed'] ) || ! empty( $attributes['startCollapsed'] );
		$show_actions      = ! isset( $attributes['showActions'] ) || ! empty( $attributes['showActions'] );
		$selected_tools    = isset( $attributes['selectedTools'] ) && is_array( $attributes['selectedTools'] )
			? array_map( 'sanitize_key', $attributes['selectedTools'] )
			: array();

		$groups = self::collect_tool_groups();

		$unique_id = wp_unique_id( 'nvoos-cg-tools-grid-' );

		if ( empty( $groups ) ) {
			return '<p class="nvoos-cg-tools-grid__notice">' . esc_html__( 'No tools are currently registered.', 'nvoos-content-graph-ai' ) . '</p>';
		}

		$wrapper_attributes = self::wrapper_attributes( $block, $unique_id );

		Blocks::enqueue_assistant_assets();

		$html = '<div ' . $wrapper_attributes . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Sanitised by get_block_wrapper_attributes() or the esc_attr() fallback.

		if ( '' !== $block_title ) {
			$html .= '<h3 class="nvoos-cg-tools-grid__title">' . esc_html( $block_title ) . '</h3>';
		}

		if ( '' !== $description ) {
			$html .= '<p class="nvoos-cg-tools-grid__description">' . esc_html( $description ) . '</p>';
		}

		if ( $show_actions ) {
			$html .= '<div class="nvoos-cg-tools-grid__filter-bar">';
			$html .= '<label for="' . esc_attr( $unique_id . '-search' ) . '" class="nvoos-cg-tools-grid__filter-label">' . esc_html__( 'Search:', 'nvoos-content-graph-ai' ) . '</label>';
			$html .= '<input type="search" id="' . esc_attr( $unique_id . '-search' ) . '" class="nvoos-cg-tools-grid__search-input" placeholder="' . esc_attr__( 'Search tools...', 'nvoos-content-graph-ai' ) . '" aria-label="' . esc_attr__( 'Search tools', 'nvoos-content-graph-ai' ) . '">';
			$html .= '<label for="' . esc_attr( $unique_id . '-group' ) . '" class="nvoos-cg-tools-grid__filter-label">' . esc_html__( 'Category:', 'nvoos-content-graph-ai' ) . '</label>';
			$html .= '<select id="' . esc_attr( $unique_id . '-group' ) . '" class="nvoos-cg-tools-grid__group-select" aria-label="' . esc_attr__( 'Filter by group', 'nvoos-content-graph-ai' ) . '">';
			$html .= '<option value="">' . esc_html__( 'All Categories', 'nvoos-content-graph-ai' ) . '</option>';
			foreach ( $groups as $group ) {
				$html .= '<option value="' . esc_attr( $group['id'] ) . '">' . esc_html( $group['label'] ) . '</option>';
			}
			$html .= '</select>';
			$html .= '<button type="button" class="button nvoos-cg-tools-grid__clear-filters" style="display: none;">' . esc_html__( 'Clear', 'nvoos-content-graph-ai' ) . '</button>';
			$html .= '</div>';

			$html .= '<div class="nvoos-cg-tools-grid__actions">';
			$html .= '<button type="button" class="button nvoos-cg-tools-grid__select-all">' . esc_html__( 'Select All', 'nvoos-content-graph-ai' ) . '</button>';
			$html .= '<button type="button" class="button nvoos-cg-tools-grid__deselect-all">' . esc_html__( 'Deselect All', 'nvoos-content-graph-ai' ) . '</button>';
			$html .= '<span class="nvoos-cg-tools-grid__count"><strong class="nvoos-cg-tools-grid__selected-count">' . esc_html( (string) count( $selected_tools ) ) . '</strong> ' . esc_html__( 'tools selected', 'nvoos-content-graph-ai' ) . '</span>';
			$html .= '</div>';
		}

		$html .= '<div class="nvoos-cg-tools-grid__groups">';
		foreach ( $groups as $group ) {
			$open_attr = $start_collapsed ? '' : ' open';
			$html     .= '<details class="nvoos-cg-tools-grid__group" data-group-id="' . esc_attr( $group['id'] ) . '"' . $open_attr . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			$html     .= '<summary><span class="nvoos-cg-tools-grid__group-title">' . esc_html( $group['label'] ) . '</span>';
			$html     .= '<span class="nvoos-cg-tools-grid__group-count"><span class="nvoos-cg-tools-grid__group-selected">' . esc_html( (string) self::count_selected( $group, $selected_tools ) ) . '</span> / ' . esc_html( (string) count( $group['tools'] ) ) . '</span></summary>';
			$html     .= '<ul class="nvoos-cg-tools-grid__list">';

			foreach ( $group['tools'] as $tool ) {
				$is_selected = in_array( $tool['slug'], $selected_tools, true );
				$item_class  = 'nvoos-cg-tools-grid__item';
				if ( $is_selected ) {
					$item_class .= ' nvoos-cg-tools-grid__item--selected';
				}

				$html .= '<li class="' . esc_attr( $item_class ) . '" data-tool-slug="' . esc_attr( $tool['slug'] ) . '">';
				$html .= '<div class="nvoos-cg-tools-grid__item-header">';
				$html .= sprintf(
					'<input type="checkbox" class="nvoos-cg-tools-grid__checkbox" id="%1$s-%2$s" value="%2$s"%3$s>',
					esc_attr( $unique_id ),
					esc_attr( $tool['slug'] ),
					checked( $is_selected, true, false )
				);
				$html .= '<label for="' . esc_attr( $unique_id . '-' . $tool['slug'] ) . '"><span class="nvoos-cg-tools-grid__item-name">' . esc_html( $tool['name'] ) . '</span></label>';
				$html .= '</div>';

				if ( $show_descriptions && '' !== $tool['description'] ) {
					$html .= '<p class="nvoos-cg-tools-grid__item-description">' . esc_html( $tool['description'] ) . '</p>';
				}

				$html .= '</li>';
			}

			$html .= '</ul></details>';
		}
		$html .= '</div></div>';

		return $html;
	}

	/**
	 * Count selected tools inside a group.
	 *
	 * @param array $group          Group shape from collect_tool_groups().
	 * @param array $selected_tools Selected tool slugs.
	 * @return int
	 */
	protected static function count_selected( array $group, array $selected_tools ): int {
		$count = 0;
		foreach ( $group['tools'] as $tool ) {
			if ( in_array( $tool['slug'], $selected_tools, true ) ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Collect registered tools grouped by category (per-install-mode seam).
	 *
	 * @return array<int, array{id: string, label: string, tools: array<int, array{slug: string, name: string, description: string}>}>
	 */
	public static function collect_tool_groups(): array {
		$groups       = array();
		$grouped      = array();
		$group_labels = array();

		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
			$registry = \WP_MCP_AI_Tool_Registry::get_instance();
			$tools    = $registry->get_tools();

			if ( method_exists( $registry, 'get_tool_group_map' ) ) {
				$group_map = $registry->get_tool_group_map();
				$group_map = is_array( $group_map ) ? $group_map : array();
			} else {
				$group_map = array();
			}

			if ( method_exists( $registry, 'get_tool_group_labels' ) ) {
				$group_labels = $registry->get_tool_group_labels();
				$group_labels = is_array( $group_labels ) ? $group_labels : array();
			}

			foreach ( $tools as $tool ) {
				if ( ! is_object( $tool ) || ! method_exists( $tool, 'get_slug' ) ) {
					continue;
				}

				$slug = (string) $tool->get_slug();
				if ( '' === $slug ) {
					continue;
				}

				$group_id = isset( $group_map[ $slug ] ) ? (string) $group_map[ $slug ] : 'other';
				if ( '' === $group_id ) {
					$group_id = 'other';
				}

				$grouped = self::add_tool_to_group(
					$grouped,
					$group_id,
					$slug,
					method_exists( $tool, 'get_name' ) && $tool->get_name() ? (string) $tool->get_name() : $slug,
					method_exists( $tool, 'get_description' ) && $tool->get_description() ? (string) $tool->get_description() : ''
				);
			}
		} else {
			foreach ( CoreBridge::instance()->tools->enabled() as $tool ) {
				$slug = method_exists( $tool, 'getSlug' ) ? (string) $tool->getSlug() : '';
				if ( '' === $slug ) {
					continue;
				}

				$grouped = self::add_tool_to_group(
					$grouped,
					'other',
					$slug,
					method_exists( $tool, 'getName' ) && $tool->getName() ? (string) $tool->getName() : $slug,
					method_exists( $tool, 'getDescription' ) && $tool->getDescription() ? (string) $tool->getDescription() : ''
				);
			}
		}

		if ( ! isset( $group_labels['other'] ) ) {
			$group_labels['other'] = __( 'Other tools', 'nvoos-content-graph-ai' );
		}

		// Fill every bucket's display label (labelled groups keep their
		// registered label; unknown groups fall back to ucfirst).
		foreach ( $grouped as $group_id => $bucket ) {
			$grouped[ $group_id ]['label'] = isset( $group_labels[ $group_id ] )
				? $group_labels[ $group_id ]
				: ucfirst( (string) $group_id );
		}

		// Order by group labels first (stable), then any unlabelled groups.
		foreach ( $group_labels as $group_id => $label ) {
			if ( isset( $grouped[ $group_id ] ) ) {
				$groups[] = $grouped[ $group_id ];
				unset( $grouped[ $group_id ] );
			}
		}
		foreach ( $grouped as $group ) {
			$groups[] = $group;
		}

		return $groups;
	}

	/**
	 * Append a tool to its group bucket.
	 *
	 * @param array  $grouped     Group buckets.
	 * @param string $group_id    Group identifier.
	 * @param string $slug        Tool slug.
	 * @param string $name        Tool display name.
	 * @param string $description Tool description.
	 * @return array Updated buckets.
	 */
	protected static function add_tool_to_group( array $grouped, string $group_id, string $slug, string $name, string $description ): array {
		if ( ! isset( $grouped[ $group_id ] ) ) {
			$grouped[ $group_id ] = array(
				'id'    => $group_id,
				'label' => '',
				'tools' => array(),
			);
		}

		$grouped[ $group_id ]['tools'][] = array(
			'slug'        => $slug,
			'name'        => $name,
			'description' => $description,
		);

		return $grouped;
	}

	/**
	 * Wrapper attributes, block-context aware.
	 *
	 * @param \WP_Block|null $block     Block instance or null.
	 * @param string         $unique_id Unique instance ID.
	 * @return string Sanitised attribute string.
	 */
	protected static function wrapper_attributes( ?\WP_Block $block, string $unique_id ): string {
		$classes = array( 'wp-block-nvoos-content-graph-ai-tools-grid', 'nvoos-cg-tools-grid' );

		if ( function_exists( 'get_block_wrapper_attributes' ) && $block instanceof \WP_Block ) {
			return get_block_wrapper_attributes(
				array(
					'class'         => implode( ' ', $classes ),
					'data-block-id' => $unique_id,
				)
			);
		}

		return sprintf(
			'class="%s" data-block-id="%s"',
			esc_attr( implode( ' ', $classes ) ),
			esc_attr( $unique_id )
		);
	}
}
