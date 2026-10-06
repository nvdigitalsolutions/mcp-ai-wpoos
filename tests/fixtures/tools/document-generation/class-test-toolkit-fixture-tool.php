<?php
/**
 * Fixture tool class declared under a `tools/document-generation/` folder.
 *
 * Used by tests/test-tool-toolkit-resolution.php to exercise the registry's
 * directory-derived toolkit fallback. Deliberately NOT a real tool — it is
 * only loaded by that test file.
 *
 * @package MCP_AI_WPooS
 */

/**
 * Legacy-format fixture without a declared toolkit.
 */
class Test_Toolkit_Fixture_Tool {

	/**
	 * Tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'test_toolkit_fixture_tool';
	}

	/**
	 * Legacy definition without a `toolkit` key.
	 *
	 * @return array
	 */
	public function get_definition() {
		return array(
			'name'        => 'Toolkit Fixture',
			'description' => 'Fixture tool declared under tools/document-generation/.',
		);
	}

	/**
	 * Execute stub.
	 *
	 * @return array
	 */
	public function execute() {
		return array( 'success' => true );
	}
}
