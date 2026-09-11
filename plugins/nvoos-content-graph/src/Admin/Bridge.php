<?php
declare(strict_types=1);

namespace NvoosContentGraph\Admin;

use NvoosContentGraph\Schema;
use function checked;
use function esc_attr;
use function esc_html;
use function get_post_types;
use function in_array;
use function sanitize_key;

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
	 * Render the JetEngine CCT checkbox grid for the Sources tab.
	 *
	 * JetEngine Custom Content Types live in dedicated database tables
	 * and never appear in {@see get_post_types()}, so they get their own
	 * grid. Every CCT is included by default; unchecking one stores its
	 * slug in the `excluded_cct_slugs` setting, which the graph Detector
	 * honors on the next build.
	 *
	 * @return void
	 */
	public static function renderCctCheckboxes(): void {
		$settings = \NvoosContentGraph\Settings::all();
		$excluded = isset( $settings['excluded_cct_slugs'] ) && is_array( $settings['excluded_cct_slugs'] )
			? $settings['excluded_cct_slugs'] : array();

		if ( ! class_exists( '\NvoosContentGraph\Graph\Detector' ) ) {
			echo '<p>' . esc_html__( 'No Custom Content Types available.', 'nvoos-content-graph' ) . '</p>';
			return;
		}

		$types = \NvoosContentGraph\Graph\Detector::getCctTypes();

		if ( empty( $types ) ) {
			$reason = \NvoosContentGraph\Graph\Detector::getLastCctsSkipReason();
			if ( 'jetengine_not_active' === $reason ) {
				echo '<p>' . esc_html__( 'JetEngine is not active. Custom Content Types become available when the JetEngine plugin is enabled.', 'nvoos-content-graph' ) . '</p>';
			} else {
				echo '<p>' . esc_html__( 'No JetEngine Custom Content Types are registered.', 'nvoos-content-graph' ) . '</p>';
			}
			return;
		}

		// Hidden marker so the sanitizer can tell "user unchecked every
		// CCT" (field present, empty) apart from "grid not rendered"
		// (field absent — JetEngine inactive). Without it, unchecking all
		// boxes would submit nothing and silently keep the old exclusions.
		echo '<input type="hidden" name="' . esc_attr( Schema::OPTION_SETTINGS ) . '[nvoos_cct_include]" value="">';

		// Per-type snapshot: distinguishes CCTs that are ready to index from
		// those whose JetEngine table is missing or empty — both are silently
		// invisible in the graph, so the grid must say so explicitly.
		$statuses = \NvoosContentGraph\Graph\Detector::inspectCctTypes();

		echo '<table class="widefat striped" style="max-width:700px">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Content Type', 'nvoos-content-graph' ) . '</th>';
		echo '<th>' . esc_html__( 'Include', 'nvoos-content-graph' ) . '</th>';
		echo '<th>' . esc_html__( 'Notes', 'nvoos-content-graph' ) . '</th>';
		echo '</tr></thead>';
		echo '<tbody>';

		foreach ( $types as $type ) {
			$slug = sanitize_key( $type['slug'] );
			if ( '' === $slug ) {
				continue;
			}
			$checked = ! in_array( $slug, $excluded, true );
			$note    = __( 'Included by default', 'nvoos-content-graph' );

			if ( isset( $statuses[ $slug ] ) ) {
				switch ( $statuses[ $slug ]['status'] ) {
					case \NvoosContentGraph\Graph\Detector::CCT_STATUS_EXCLUDED:
						$note = __( 'Excluded', 'nvoos-content-graph' );
						break;
					case \NvoosContentGraph\Graph\Detector::CCT_STATUS_TABLE_MISSING:
						$note .= ' — ' . __( 'table not created yet', 'nvoos-content-graph' );
						break;
					case \NvoosContentGraph\Graph\Detector::CCT_STATUS_EMPTY:
						$note .= ' — ' . __( 'no items yet', 'nvoos-content-graph' );
						break;
					case \NvoosContentGraph\Graph\Detector::CCT_STATUS_DB_UNAVAILABLE:
					case \NvoosContentGraph\Graph\Detector::CCT_STATUS_QUERY_FAILED:
						$note .= ' — ' . __( 'unavailable', 'nvoos-content-graph' );
						break;
					case \NvoosContentGraph\Graph\Detector::CCT_STATUS_INDEXED:
						$note .= ' — ' . sprintf(
							/* translators: %d: number of indexed items. */
							_n( '%d item indexed', '%d items indexed', $statuses[ $slug ]['items'], 'nvoos-content-graph' ),
							$statuses[ $slug ]['items']
						);
						break;
				}
			}

			echo '<tr>';
			echo '<td><strong>' . esc_html( $type['name'] ) . '</strong> <code style="font-size:11px">' . esc_html( $slug ) . '</code></td>';
			echo '<td><input type="checkbox" name="' . esc_attr( Schema::OPTION_SETTINGS ) . '[nvoos_cct_include][' . esc_attr( $slug ) . ']" value="1" ' . checked( $checked, true, false ) . '></td>';
			echo '<td>' . esc_html( $note ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'Uncheck to exclude a Custom Content Type; check to include it. Changes take effect on the next graph build.', 'nvoos-content-graph' ) . '</p>';
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
