<?php
declare(strict_types=1);

namespace NvoosContentGraph\Admin;

use NvoosContentGraph\Schema;
use function checked;
use function esc_attr;
use function esc_html;
use function get_post_types;
use function in_array;

/**
 * Bridge between the standalone Content Graph admin and WordPress core.
 *
 * Renders CPT checkboxes and external table checkboxes directly
 * without requiring the NV oOS base plugin addon bridge.
 *
 * @since 1.0.0
 */
class Bridge {

	/**
	 * Render the CPT checkbox grid for the Sources tab.
	 *
	 * @return void
	 */
	public static function renderCptCheckboxes(): void {
		$settings = \NvoosContentGraph\Settings::all();
		$excluded = isset( $settings['excluded_post_types'] ) && is_array( $settings['excluded_post_types'] )
			? $settings['excluded_post_types'] : array();
		$extra    = isset( $settings['extra_post_types'] ) && is_array( $settings['extra_post_types'] )
			? $settings['extra_post_types'] : array();

		$all_cpts = get_post_types( array( 'public' => true ), 'objects' );

		echo '<table class="widefat striped" style="max-width:700px">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Post Type', 'nvoos-content-graph' ) . '</th>';
		echo '<th>' . esc_html__( 'Include', 'nvoos-content-graph' ) . '</th>';
		echo '<th>' . esc_html__( 'Notes', 'nvoos-content-graph' ) . '</th>';
		echo '</tr></thead>';
		echo '<tbody>';

		foreach ( $all_cpts as $slug => $cpt ) {
			$slug    = \sanitize_key( $slug );
			$builtin = in_array( $slug, array( 'post', 'page' ), true );

			if ( $builtin ) {
				// Post and Page are default-on; unchecked → excluded.
				$checked = ! in_array( $slug, $excluded, true );
				echo '<tr>';
				echo '<td><strong>' . esc_html( $cpt->label ) . '</strong> <code style="font-size:11px">' . esc_html( $slug ) . '</code></td>';
				echo '<td><input type="checkbox" name="' . esc_attr( Schema::OPTION_SETTINGS ) . '[nvoos_cpt_include][' . esc_attr( $slug ) . ']" value="1" ' . checked( $checked, true, false ) . '></td>';
				echo '<td>' . esc_html__( 'Included by default', 'nvoos-content-graph' ) . '</td>';
				echo '</tr>';
			} else {
				// All other CPTs are default-off; checked → extra (opt-in).
				$checked = in_array( $slug, $extra, true );
				$notes   = '';
				if ( in_array( $slug, array( 'attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation' ), true ) ) {
					$notes = esc_html__( 'Usually excluded', 'nvoos-content-graph' );
				} else {
					$notes = esc_html__( 'Opt-in', 'nvoos-content-graph' );
				}
				echo '<tr>';
				echo '<td><strong>' . esc_html( $cpt->label ) . '</strong> <code style="font-size:11px">' . esc_html( $slug ) . '</code></td>';
				echo '<td><input type="checkbox" name="' . esc_attr( Schema::OPTION_SETTINGS ) . '[nvoos_cpt_include][' . esc_attr( $slug ) . ']" value="1" ' . checked( $checked, true, false ) . '></td>';
				echo '<td>' . esc_html( $notes ) . '</td>';
				echo '</tr>';
			}
		}
		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'Uncheck to exclude a post type; check to include it. Changes take effect on the next graph build.', 'nvoos-content-graph' ) . '</p>';
	}

	/**
	 * Render the external table checkbox grid for the Sources tab.
	 *
	 * In the standalone core plugin, no external tables are known.
	 * The AI Platform addon hooks into this via nvoos_content_graph/admin/register_sections.
	 *
	 * @return void
	 */
	public static function renderExtTableCheckboxes(): void {
		echo '<p>' . esc_html__( 'No external database tables available in the core plugin. The NV oOS Content Graph AI Platform addon adds external table sources.', 'nvoos-content-graph' ) . '</p>';
	}
}
