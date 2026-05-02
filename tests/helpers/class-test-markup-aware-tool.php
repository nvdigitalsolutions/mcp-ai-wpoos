<?php
/**
 * Test fixture: markup-aware tool double for interceptor tests.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test fixture: minimal markup-aware tool used by the assertions below.
 */
class Test_Markup_Aware_Tool implements WP_MCP_AI_Markup_Aware_Tool_Interface {

	/**
	 * Test fixture method.
	 *
	 * @return mixed
	 */
	public function get_slug() {
		return 'test_markup_aware_tool';
	}

	/**
	 * Test fixture method.
	 *
	 * @return mixed
	 */
	public function get_definition() {
		return array(
			'name'        => 'Test Markup Aware Tool',
			'description' => 'Test fixture for markup interceptor.',
		);
	}

	/**
	 * Test fixture method.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return mixed
	 */
	public function execute( $arguments, $context ) {
		return array(
			'success' => true,
			'message' => 'should not reach here without markup',
		);
	}

	/**
	 * Test fixture method.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return mixed
	 */
	public function needs_markup( array $arguments, array $context ) {
		if ( ! empty( $arguments['skip_markup'] ) ) {
			return null;
		}
		return new WP_MCP_AI_Markup_Request(
			array(
				'tool_slug'      => $this->get_slug(),
				'target'         => array( 'attachment_id' => 1 ),
				'target_type'    => WP_MCP_AI_Markup_Request::TARGET_TYPE_IMAGE,
				'mode'           => WP_MCP_AI_Markup_Request::MODE_MASK,
				'instructions'   => 'Mark up please.',
				'tool_arguments' => $arguments,
				'tool_context'   => $context,
				'assistant_id'   => isset( $context['assistant_id'] ) ? (int) $context['assistant_id'] : 0,
			)
		);
	}

	/**
	 * Test fixture method.
	 *
	 * @param array                   $arguments Tool arguments.
	 * @param WP_MCP_AI_Markup_Result $result    Markup result.
	 * @param array                   $context   Execution context.
	 * @return mixed
	 */
	public function consume_markup( array $arguments, WP_MCP_AI_Markup_Result $result, array $context ) {
		return array(
			'success'    => true,
			'message'    => 'consumed markup',
			'request_id' => $result->get_request()->get_request_id(),
			'has_mask'   => null !== $result->get_artifact( 'mask_attachment_id' ),
		);
	}
}
