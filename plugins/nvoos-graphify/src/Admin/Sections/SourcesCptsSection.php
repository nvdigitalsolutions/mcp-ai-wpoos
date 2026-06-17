<?php
declare(strict_types=1);

namespace NvoosGraphify\Admin\Sections;

use NvoosGraphify\Admin\Section;

/**
 * Sources — Custom Post Types section.
 *
 * This section renders checkboxes for NV oOS internal post types
 * and does not use the standard field-rendering pipeline.
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
		return __( 'Custom Post Types', 'nvoos-graphify' );
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
		return __( 'Choose which oOS internal post types should be indexed into the knowledge graph.', 'nvoos-graphify' );
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
	 * Shows a placeholder message when the NV oOS bridge addon
	 * is not active.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( class_exists( '\NvoosGraphify\Admin\Bridge' ) && method_exists( '\NvoosGraphify\Admin\Bridge', 'renderCptCheckboxes' ) ) {
			\NvoosGraphify\Admin\Bridge::renderCptCheckboxes();
		} else {
			echo '<p>' . \esc_html__( 'NV oOS integration not active. Install the NV oOS bridge addon to index oOS internal post types.', 'nvoos-graphify' ) . '</p>';
		}
	}

	/**
	 * Sanitize sources tab CPT checkbox input.
	 *
	 * Reads `$_POST['nvoos_cpt_include']` and translates the
	 * checked post types into `excluded_post_types` and
	 * `extra_post_types` arrays.
	 *
	 * @inheritDoc
	 */
	public function sanitize( array $input ): array {
		$sanitized = parent::sanitize( $input );

		if ( ! isset( $_POST['nvoos_cpt_include'] ) || ! \is_array( $_POST['nvoos_cpt_include'] ) ) {
			$sanitized['excluded_post_types'] = array( 'post', 'page' );
			$sanitized['extra_post_types']    = array();
			return $sanitized;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized via array_map below.
		$included    = \array_map( 'sanitize_key', \wp_unslash( $_POST['nvoos_cpt_include'] ) );
		$builtin     = array( 'post', 'page' );
		$extra       = array_diff( $included, $builtin );
		$all_cpts    = \get_post_types( array( 'public' => true ), 'names' );
		$excluded    = array_values( array_diff( $all_cpts, $included ) );

		$sanitized['excluded_post_types'] = $excluded;
		$sanitized['extra_post_types']    = array_values( $extra );

		return $sanitized;
	}
}
