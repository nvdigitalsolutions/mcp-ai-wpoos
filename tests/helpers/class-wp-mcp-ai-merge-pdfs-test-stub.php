<?php
/**
 * Test stub: merge PDFs tool with a stubbed sidecar check.
 *
 * The shell-tools gate in WP_MCP_AI_Tool_Merge_PDFs short-circuits before
 * argument validation. The test bootstrap defines WP_MCP_AI_ALLOW_SHELL_TOOLS
 * as false (PHP constants cannot be redefined), so validation tests use this
 * stub whose sidecar check always succeeds — mirroring a connected Media
 * Worker deployment where no shell command ever runs.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Merge tool stub for validation-path tests.
 */
class WP_MCP_AI_Merge_PDFs_Test_Stub extends WP_MCP_AI_Tool_Merge_PDFs {

	/**
	 * Always report the sidecar as available so the shell-tools gate passes
	 * and execute() reaches the argument-validation paths under test.
	 *
	 * @return bool True.
	 */
	public function is_sidecar_upload_supported() {
		return true;
	}
}
