<?php
declare(strict_types=1);

namespace NvoosContentGraph\Admin\Sections;

use NvoosContentGraph\Admin\Section;

/**
 * Sources — Custom Content Types (JetEngine) section.
 *
 * Renders checkboxes for every registered JetEngine Custom Content Type.
 * CCTs are stored in dedicated database tables (`{prefix}jet_cct_{slug}`)
 * and are not WordPress post types, so they need their own grid — and
 * their own sanitization — alongside the post-type checkboxes.
 *
 * Every CCT is included by default; unchecking one stores its slug in
 * the `excluded_cct_slugs` setting, which the graph Detector honors.
 *
 * @since 1.0.7
 */
class SourcesCctsSection extends Section {

	/**
	 * @inheritDoc
	 */
	public function get_id(): string {
		return 'sources_ccts';
	}

	/**
	 * @inheritDoc
	 */
	public function get_title(): string {
		return __( 'Custom Content Types (JetEngine)', 'nvoos-content-graph' );
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
		return 15;
	}

	/**
	 * @inheritDoc
	 */
	public function get_description(): string {
		return __( 'Choose which JetEngine Custom Content Types should be indexed into the knowledge graph.', 'nvoos-content-graph' );
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
	 * Render the CCT checkbox grid.
	 *
	 * Delegates to {@see \NvoosContentGraph\Admin\Bridge::renderCctCheckboxes()}.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( class_exists( '\NvoosContentGraph\Admin\Bridge' ) && method_exists( '\NvoosContentGraph\Admin\Bridge', 'renderCctCheckboxes' ) ) {
			\NvoosContentGraph\Admin\Bridge::renderCctCheckboxes();
		} else {
			echo '<p>' . \esc_html__( 'No Custom Content Types available.', 'nvoos-content-graph' ) . '</p>';
		}
	}

	/**
	 * Sanitize the CCT checkbox input from the Sources tab.
	 *
	 * Reads `$_POST['nvoos_cct_include']` and translates the checked
	 * slugs into `excluded_cct_slugs` (every registered CCT minus the
	 * checked ones). The grid emits a hidden marker field, so an empty
	 * submitted value still recomputes the exclusions — otherwise
	 * unchecking every CCT would silently keep the old exclusions.
	 *
	 * When the marker is absent entirely (JetEngine inactive or no CCTs
	 * registered), the existing exclusions are preserved untouched.
	 *
	 * @inheritDoc
	 */
	public function sanitize( array $input ): array {
		$sanitized = parent::sanitize( $input );

		if ( ! array_key_exists( 'nvoos_cct_include', $input ) ) {
			return $sanitized;
		}

		$checked = array();
		if ( \is_array( $input['nvoos_cct_include'] ) ) {
			$checked = \array_keys( \array_filter( $input['nvoos_cct_include'] ) );
			$checked = \array_values( \array_map( 'sanitize_key', $checked ) );
		}

		$known = array();
		if ( class_exists( '\NvoosContentGraph\Graph\Detector' ) ) {
			$known = \wp_list_pluck( \NvoosContentGraph\Graph\Detector::getCctTypes(), 'slug' );
		}

		$sanitized['excluded_cct_slugs'] = \array_values( \array_diff( $known, $checked ) );

		return $sanitized;
	}
}
