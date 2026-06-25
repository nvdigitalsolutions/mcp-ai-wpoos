<?php
declare(strict_types=1);

namespace NvoosGraphify\Admin;

/**
 * Bridge between the core Graphify plugin and data source providers.
 *
 * Renders the CPT and external-table checkbox grids on the Sources
 * settings tab. The core plugin handles generic WordPress post types
 * out of the box; NV oOS-specific types and tables are added by the
 * `nvoos-graphify-ai-platform` plugin via section registration or
 * by replacing this bridge through the plugin's extension points.
 *
 * @since 1.0.0
 */
class Bridge {

	/**
	 * Render the CPT inclusion checkboxes.
	 *
	 * Shows all public WordPress post types (post, page, and any
	 * custom post types registered by other plugins/themes).
	 * Internal WP types (revision, nav_menu_item, etc.) are skipped.
	 *
	 * @return void
	 */
	public static function renderCptCheckboxes(): void {
		$s        = \NvoosGraphify\Settings::all();
		$excluded = isset( $s['excluded_post_types'] ) && is_array( $s['excluded_post_types'] )
			? $s['excluded_post_types'] : array();
		$extra    = isset( $s['extra_post_types'] ) && is_array( $s['extra_post_types'] )
			? $s['extra_post_types'] : array();

		$all_cpts = \get_post_types( array( 'public' => true ), 'objects' );

		// Skip internal WordPress types that don't make sense to index.
		$skip = array(
			'attachment',
			'revision',
			'nav_menu_item',
			'custom_css',
			'customize_changeset',
			'oembed_cache',
			'user_request',
			'wp_block',
			'wp_template',
			'wp_template_part',
			'wp_global_styles',
			'wp_navigation',
		);
		foreach ( $skip as $slug ) {
			unset( $all_cpts[ $slug ] );
		}

		if ( empty( $all_cpts ) ) {
			echo '<p>' . \esc_html__( 'No public post types found on this site.', 'nvoos-graphify' ) . '</p>';
			return;
		}

		$builtin_defaults = array( 'post', 'page' );

		echo '<table class="widefat striped" style="max-width:700px">';
		echo '<thead><tr>';
		echo '<th>' . \esc_html__( 'Post Type', 'nvoos-graphify' ) . '</th>';
		echo '<th>' . \esc_html__( 'Include', 'nvoos-graphify' ) . '</th>';
		echo '<th>' . \esc_html__( 'Notes', 'nvoos-graphify' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $all_cpts as $slug => $cpt ) {
			$is_builtin = in_array( $slug, $builtin_defaults, true );

			if ( $is_builtin ) {
				// post and page are included unless explicitly excluded.
				$checked = ! in_array( $slug, $excluded, true );
				$note    = \esc_html__( 'Included by default', 'nvoos-graphify' );
			} else {
				// Custom post types are excluded unless explicitly opted in.
				$checked = in_array( $slug, $extra, true );
				$note    = \esc_html__( 'Opt-in', 'nvoos-graphify' );
			}

			echo '<tr>';
			echo '<td><strong>' . \esc_html( $cpt->label ) . '</strong> <code style="font-size:11px">' . \esc_html( $slug ) . '</code></td>';
			echo '<td><input type="checkbox" name="' . \esc_attr( \NvoosGraphify\Schema::OPTION_SETTINGS ) . '[nvoos_cpt_include][' . \esc_attr( $slug ) . ']" value="1"' . \checked( $checked, true, false ) . '></td>';
			echo '<td>' . \wp_kses_post( $note ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '<p class="description">' . \esc_html__( 'Uncheck to exclude a post type; check to include it. Changes take effect on the next graph build.', 'nvoos-graphify' ) . '</p>';
	}

	/**
	 * Render the external table inclusion checkboxes.
	 *
	 * In the core plugin there are no external tables to index —
	 * this is populated by the `nvoos-graphify-ai-platform` plugin
	 * which knows about NV oOS custom database tables.
	 *
	 * @return void
	 */
	public static function renderExtTableCheckboxes(): void {
		echo '<p>' . \esc_html__( 'No external tables configured. Install the NV oOS Graphify AI Platform addon to index database tables.', 'nvoos-graphify' ) . '</p>';
	}
}
