<?php
declare(strict_types=1);

namespace NvoosContentGraph\Admin\Sections;

use NvoosContentGraph\Admin\Section;

/**
 * Sources — Custom Post Types section.
 *
 * Renders checkboxes for every public WordPress post type so site
 * owners can choose which content types the knowledge graph indexes.
 *
 * Post and Page are included by default; all other types are opt-in.
 *
 * @since 1.0.0
 */
class SourcesCptsSection extends Section {

	/**
	 * @inheritDoc
	 */
	public function get_id(): string {
		return 'sources_cpts';
	}

	/**
	 * @inheritDoc
	 */
	public function get_title(): string {
		return __( 'Custom Post Types', 'nvoos-content-graph' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_tab(): string {
		return 'sources';
	}

	/**
	 * @inheritDoc
	 */
	public function get_priority(): int {
		return 10;
	}

	/**
	 * @inheritDoc
	 */
	public function get_description(): string {
		return __( 'Choose which post types should be indexed into the knowledge graph.', 'nvoos-content-graph' );
	}

	/**
	 * @inheritDoc
	 *
	 * Returns an empty array — this section renders custom markup
	 * instead of standard field rows.
	 */
	public function get_fields(): array {
		return array();
	}

	/**
	 * Render the CPT checkbox grid.
	 *
	 * Delegates to {@see \NvoosContentGraph\Admin\Bridge::renderCptCheckboxes()}.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( class_exists( '\NvoosContentGraph\Admin\Bridge' ) && method_exists( '\NvoosContentGraph\Admin\Bridge', 'renderCptCheckboxes' ) ) {
			\NvoosContentGraph\Admin\Bridge::renderCptCheckboxes();
		} else {
			echo '<p>' . \esc_html__( 'No post types available.', 'nvoos-content-graph' ) . '</p>';
		}
	}

	/**
	 * Sanitize the CPT checkbox input from the Sources tab.
	 *
	 * Translates `$_POST['nvoos_cpt_include'][slug] = 1` into
	 * `excluded_post_types` and `extra_post_types` arrays.
	 *
	 *   - post and page are default-on — unchecked → excluded.
	 *   - All other public CPTs are default-off — checked → extra.
	 *
	 * @inheritDoc
	 */
	public function sanitize( array $input ): array {
		$sanitized = parent::sanitize( $input );

		// Read nvoos_cpt_include from the input array (WordPress nests form
		// fields under the option name: nvoos_content_graph_settings[nvoos_cpt_include][slug]).
		$checked_slugs = array();
		if ( isset( $input['nvoos_cpt_include'] ) && \is_array( $input['nvoos_cpt_include'] ) ) {
			// Filter out unchecked (0/empty) values, keep checked (1).
			$checked_slugs = \array_keys( \array_filter( $input['nvoos_cpt_include'] ) );
			$checked_slugs = \array_values( \array_map( 'sanitize_key', $checked_slugs ) );
		}

		$all_cpts = \get_post_types( array( 'public' => true ), 'names' );
		$builtin  = array( 'post', 'page' );

		// Build excluded: all CPTs not checked.
		$excluded = array_values( \array_diff( $all_cpts, $checked_slugs ) );

		// Build extra: non-builtin checked CPTs.
		$extra = array_values( \array_diff( $checked_slugs, $builtin ) );

		$sanitized['excluded_post_types'] = $excluded;
		$sanitized['extra_post_types']    = $extra;

		return $sanitized;
	}
}
