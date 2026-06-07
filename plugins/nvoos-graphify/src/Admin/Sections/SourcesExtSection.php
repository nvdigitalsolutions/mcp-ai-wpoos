<?php
declare(strict_types=1);

namespace NvoosGraphify\Admin\Sections;

use NvoosGraphify\Admin\Section;

/**
 * Sources — External Tables section.
 *
 * This section renders checkboxes for NV oOS internal database
 * tables and does not use the standard field-rendering pipeline.
 *
 * @since 1.0.0
 */
class SourcesExtSection extends Section {

	/**
	 * @inheritDoc
	 */
	public function get_id(): string {
		return 'sources_ext';
	}

	/**
	 * @inheritDoc
	 */
	public function get_title(): string {
		return __( 'External Tables', 'nvoos-graphify' );
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
		return 20;
	}

	/**
	 * @inheritDoc
	 */
	public function get_description(): string {
		return __( 'Choose which oOS internal database tables should be indexed into the knowledge graph.', 'nvoos-graphify' );
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
	 * Render the external table checkbox grid.
	 *
	 * Shows a placeholder message when the NV oOS bridge addon
	 * is not active.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( class_exists( '\NvoosGraphify\Admin\Bridge' ) && method_exists( '\NvoosGraphify\Admin\Bridge', 'renderExtTableCheckboxes' ) ) {
			\NvoosGraphify\Admin\Bridge::renderExtTableCheckboxes();
		} else {
			echo '<p>' . \esc_html__( 'NV oOS integration not active. Install the NV oOS bridge addon to index oOS internal database tables.', 'nvoos-graphify' ) . '</p>';
		}
	}

	/**
	 * Sanitize sources tab external table checkbox input.
	 *
	 * Reads `$_POST['nvoos_ext_table']` and translates the
	 * checked tables into `external_tables` and
	 * `disabled_external_tables` arrays.
	 *
	 * @inheritDoc
	 */
	public function sanitize( array $input ): array {
		$sanitized = parent::sanitize( $input );

		if ( ! isset( $_POST['nvoos_ext_table'] ) || ! \is_array( $_POST['nvoos_ext_table'] ) ) {
			$sanitized['external_tables']          = array();
			$sanitized['disabled_external_tables'] = array();
			return $sanitized;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized via array_map below.
		$enabled = \array_map( 'sanitize_key', \wp_unslash( $_POST['nvoos_ext_table'] ) );

		$sanitized['external_tables'] = array_values( $enabled );

		// Build the disabled list from all known external tables minus enabled ones.
		$known = array();
		if ( function_exists( '\nvoos_graphify_get_external_tables' ) ) {
			$known = \nvoos_graphify_get_external_tables();
		}
		$sanitized['disabled_external_tables'] = array_values( array_diff( $known, $enabled ) );

		return $sanitized;
	}
}
