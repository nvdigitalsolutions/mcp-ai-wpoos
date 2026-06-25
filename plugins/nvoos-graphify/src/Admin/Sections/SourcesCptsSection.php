<?php
declare(strict_types=1);

namespace NvoosGraphify\Admin\Sections;

use NvoosGraphify\Admin\Section;

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
		return __( 'Choose which post types should be indexed into the knowledge graph.', 'nvoos-graphify' );
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
	 * Delegates to {@see \NvoosGraphify\Admin\Bridge::renderCptCheckboxes()}.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( class_exists( '\NvoosGraphify\Admin\Bridge' ) && method_exists( '\NvoosGraphify\Admin\Bridge', 'renderCptCheckboxes' ) ) {
			\NvoosGraphify\Admin\Bridge::renderCptCheckboxes();
		} else {
			echo '<p>' . \esc_html__( 'No post types available.', 'nvoos-graphify' ) . '</p>';
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

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified by WP settings API before sanitization callbacks run.
		if ( ! isset( $_POST['nvoos_cpt_include'] ) || ! \is_array( $_POST['nvoos_cpt_include'] ) ) {
			$sanitized['excluded_post_types'] = array( 'post', 'page' );
			$sanitized['extra_post_types']    = array();
			return $sanitized;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$included = \array_map( 'sanitize_key', \wp_unslash( $_POST['nvoos_cpt_include'] ) );
		$builtin  = array( 'post', 'page' );
		$extra    = array_values( array_diff( $included, $builtin ) );
		$all_cpts = \get_post_types( array( 'public' => true ), 'names' );
		$excluded = array_values( array_diff( $all_cpts, $included ) );

		$sanitized['excluded_post_types'] = $excluded;
		$sanitized['extra_post_types']    = $extra;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		return $sanitized;
	}
}
