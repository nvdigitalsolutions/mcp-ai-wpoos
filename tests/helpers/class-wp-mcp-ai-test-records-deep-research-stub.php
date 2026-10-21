<?php
/**
 * Stub deep-research tool for the records research pipeline test — returns
 * a canned report so the extractor gate is the only provider surface.
 *
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Canned-report deep_research stub.
 */
class WP_MCP_AI_Test_Records_Deep_Research_Stub implements WP_MCP_AI_Tool_Interface {
	use WP_MCP_AI_Tool_Default_Capability;

	/**
	 * Tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'deep_research';
	}

	/**
	 * Tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'Records Deep Research Stub';
	}

	/**
	 * Tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return 'Stub tool for exercising the records research pipeline.';
	}

	/**
	 * Parameters schema.
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array();
	}

	/**
	 * Execute — canned report.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		unset( $arguments, $context );
		return array(
			'report'  => 'Acme Ltd is a manufacturing company based in London.',
			'sources' => array(),
		);
	}
}
