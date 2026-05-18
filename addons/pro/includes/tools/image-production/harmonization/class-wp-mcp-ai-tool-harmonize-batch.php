<?php
/**
 * Tool: harmonize_batch.
 *
 * Runs `harmonize_image_into_background` over a list of subjects against a
 * shared background and style spec.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-wp-mcp-ai-tool-harmonization-base.php';

/**
 * Batch harmonization across multiple subjects.
 */
class WP_MCP_AI_Tool_Harmonize_Batch extends WP_MCP_AI_Tool_Harmonization_Base {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'harmonize_batch';
	}

	/**
	 * {\@inheritdoc}
	 *
	 * @return string WordPress capability string.
	 */
	public function get_required_capability() {
		return 'upload_files';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Harmonize Batch', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Run end-to-end harmonization across a list of subjects sharing one background and style spec. Useful for product catalog hero treatments. Returns one composite per subject plus a per-call cost summary.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Async + long-running.
	 *
	 * @return array
	 */
	public function get_capability_flags() {
		$flags   = $this->harmonization_capability_flags();
		$flags[] = 'async';
		$flags[] = 'long-running';
		$flags[] = 'batch';
		return $flags;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		$item_id = $this->harmonization_get_image_input_schema( 'subject' )['attachment_id'];
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'subjects'                 => array(
					'type'        => 'array',
					'description' => __( 'Array of subject inputs (attachment IDs, URLs, or file_ids).', 'mcp-ai-wpoos-pro' ),
					'items'       => $item_id,
				),
				'background_attachment_id' => $this->harmonization_get_image_input_schema( 'shared background image' )['attachment_id'],
				'background_prompt'        => array( 'type' => 'string' ),
				'aspect_ratio'             => array(
					'type'    => 'string',
					'default' => '16:9',
				),
				'enable_color_harmonize'   => array(
					'type'    => 'boolean',
					'default' => true,
				),
				'enable_relight'           => array(
					'type'    => 'boolean',
					'default' => false,
				),
				'enable_shadow'            => array(
					'type'    => 'boolean',
					'default' => true,
				),
				'enable_boundary_refine'   => array(
					'type'    => 'boolean',
					'default' => true,
				),
				'polish_strength'          => array(
					'type'    => 'number',
					'minimum' => 0,
					'maximum' => 1,
					'default' => 0.0,
				),
				'max_subjects'             => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 50,
					'default' => 10,
				),
				'provider'                 => array(
					'type'    => 'string',
					'enum'    => array( 'auto', 'gemini', 'openai' ),
					'default' => 'auto',
				),
			),
			'required'             => array( 'subjects' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Execute the tool body.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @param int   $user_id   Authorized user id (0 for token auth).
	 *
	 * @return array|WP_Error
	 */
	protected function execute_harmonization( array $arguments, array $context, $user_id ) {
		if ( empty( $arguments['subjects'] ) || ! is_array( $arguments['subjects'] ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_subjects', __( 'subjects array is required.', 'mcp-ai-wpoos-pro' ), array( 'status' => 400 ) );
		}
		if ( empty( $arguments['background_attachment_id'] ) && empty( $arguments['background_prompt'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_background',
				__( 'Either background_attachment_id or background_prompt is required.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		$max      = isset( $arguments['max_subjects'] ) ? max( 1, min( 50, (int) $arguments['max_subjects'] ) ) : 10;
		$subjects = array_slice( array_values( $arguments['subjects'] ), 0, $max );

		$shared = $arguments;
		unset( $shared['subjects'], $shared['max_subjects'] );

		$results      = array();
		$succeeded    = 0;
		$failed       = 0;
		$orchestrator = new WP_MCP_AI_Tool_Harmonize_Image_Into_Background();

		foreach ( $subjects as $subject_input ) {
			$args                          = $shared;
			$args['subject_attachment_id'] = $subject_input;
			$result                        = $orchestrator->execute( $args, $context );
			if ( is_wp_error( $result ) ) {
				++$failed;
				$results[] = array(
					'subject_input' => is_scalar( $subject_input ) ? (string) $subject_input : '',
					'success'       => false,
					'error'         => array(
						'code'    => $result->get_error_code(),
						'message' => $result->get_error_message(),
					),
				);
				continue;
			}
			++$succeeded;
			$results[] = array(
				'subject_input' => is_scalar( $subject_input ) ? (string) $subject_input : '',
				'success'       => true,
				'attachment_id' => isset( $result['attachment_id'] ) ? (int) $result['attachment_id'] : 0,
				'url'           => isset( $result['url'] ) ? (string) $result['url'] : '',
			);
		}

		return array(
			'success'   => $succeeded > 0,
			'stage'     => $this->get_slug(),
			'total'     => count( $subjects ),
			'succeeded' => $succeeded,
			'failed'    => $failed,
			'results'   => $results,
			'text'      => sprintf(
				/* translators: 1: succeeded count, 2: total count */
				__( 'Harmonized %1$d / %2$d subjects.', 'mcp-ai-wpoos-pro' ),
				$succeeded,
				count( $subjects )
			),
		);
	}
}
