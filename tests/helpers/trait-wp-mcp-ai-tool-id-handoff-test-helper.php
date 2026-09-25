<?php
/**
 * Shared assertions for the deterministic ID-handoff (L2) round-trip suite.
 *
 * Models the multi-step workflow failure mode described in the P3 rollout
 * plan: a create tool returns an identifier and the consuming tool must
 * accept that exact value under the exact same key name.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

trait WP_MCP_AI_Tool_Id_Handoff_Test_Helper {

	/**
	 * Assert a producer returned the contract key in its success envelope
	 * and return its value.
	 *
	 * @param array|WP_Error $result    Producer result.
	 * @param string         $key       Contract key name (e.g. 'job_id').
	 * @param string         $tool_slug Producer slug for failure messages.
	 * @return mixed The produced value.
	 */
	protected function assert_produces_key( $result, $key, $tool_slug ) {
		$this->assertNotWPError( $result, $tool_slug . ' should succeed.' );
		$this->assertIsArray( $result, $tool_slug . ' should return an array envelope.' );
		$this->assertArrayHasKey(
			$key,
			$result,
			sprintf( '%s must return "%s" in its success envelope.', $tool_slug, $key )
		);
		$this->assertNotEmpty(
			$result[ $key ],
			sprintf( '%s returned an empty "%s".', $tool_slug, $key )
		);

		return $result[ $key ];
	}

	/**
	 * Assert a consumer succeeded when fed the produced value and echoed the
	 * same identifier back under the same key.
	 *
	 * @param array|WP_Error $result    Consumer result.
	 * @param string         $key       Contract key name.
	 * @param mixed          $expected  The value produced upstream.
	 * @param string         $tool_slug Consumer slug for failure messages.
	 */
	protected function assert_id_round_trip( $result, $key, $expected, $tool_slug ) {
		$this->assertNotWPError(
			$result,
			sprintf( '%s should succeed when fed the produced "%s".', $tool_slug, $key )
		);
		$this->assertIsArray( $result, $tool_slug . ' should return an array envelope.' );
		$this->assertSame(
			$expected,
			$result[ $key ],
			sprintf( '%s should echo back the "%s" it was given.', $tool_slug, $key )
		);
	}

	/**
	 * Assert a consumer rejects an unknown identifier with a WP_Error.
	 *
	 * @param array|WP_Error $result    Consumer result.
	 * @param string         $tool_slug Consumer slug for failure messages.
	 * @param string         $key       Contract key name.
	 */
	protected function assert_id_not_found( $result, $tool_slug, $key ) {
		$this->assertWPError(
			$result,
			sprintf( '%s should fail for an unknown "%s".', $tool_slug, $key )
		);
	}
}
