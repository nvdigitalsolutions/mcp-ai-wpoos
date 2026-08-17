<?php
declare(strict_types=1);

namespace NvoosContentGraph\Admin\Sections;

use NvoosContentGraph\Admin\Section;

/**
 * Sources — External Tables section.
 *
 * Placeholder in the core plugin. The NV oOS Content Graph AI Platform
 * addon populates this section with checkboxes for NV oOS custom
 * database tables (slash-command audit, metric events, compliance
 * evidence, etc.).
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
		return __( 'External Tables', 'nvoos-content-graph' );
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
		return __( 'Choose which database tables should be indexed into the knowledge graph.', 'nvoos-content-graph' );
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
	 * Delegates to {@see \NvoosContentGraph\Admin\Bridge::renderExtTableCheckboxes()}.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( class_exists( '\NvoosContentGraph\Admin\Bridge' ) && method_exists( '\NvoosContentGraph\Admin\Bridge', 'renderExtTableCheckboxes' ) ) {
			\NvoosContentGraph\Admin\Bridge::renderExtTableCheckboxes();
		} else {
			echo '<p>' . \esc_html__( 'No external tables available.', 'nvoos-content-graph' ) . '</p>';
		}
	}

	/**
	 * Sanitize the external table checkbox input from the Sources tab.
	 *
	 * Reads `$_POST['nvoos_ext_table']` and translates the checked
	 * tables into `external_tables` and `disabled_external_tables`
	 * arrays.
	 *
	 * @inheritDoc
	 */
	public function sanitize( array $input ): array {
		$sanitized = parent::sanitize( $input );

		$enabled = array();
		if ( isset( $input['nvoos_ext_table'] ) && \is_array( $input['nvoos_ext_table'] ) ) {
			$enabled = \array_keys( \array_filter( $input['nvoos_ext_table'] ) );
			$enabled = \array_values( \array_map( 'sanitize_key', $enabled ) );
		}

		$sanitized['external_tables'] = $enabled;

		// Build the disabled list from all known external tables minus enabled ones.
		$known = array();
		if ( function_exists( '\nvoos_content_graph_get_external_tables' ) ) {
			$known = \nvoos_content_graph_get_external_tables();
		}
		$sanitized['disabled_external_tables'] = array_values( \array_diff( $known, $enabled ) );

		return $sanitized;
	}
}
