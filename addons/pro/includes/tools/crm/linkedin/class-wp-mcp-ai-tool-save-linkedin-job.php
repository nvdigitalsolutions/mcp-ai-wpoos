<?php
/**
 * Tool for saving a LinkedIn job posting as a CRM Deal or Project.
 *
 * Takes a LinkedIn job posting (URL or structured data) and creates
 * a Deal or Project record in the CRM for pipeline tracking.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage CRM_Toolkit
 * @since 2.10.0
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Saves a LinkedIn job posting as a CRM Deal or Project.
 *
 * @since 2.10.0
 */
class WP_MCP_AI_Tool_Save_LinkedIn_Job implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Determine whether CRM toolkit is enabled.
	 *
	 * @since 2.10.0
	 * @return bool
	 */
	public static function is_available() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_crm_toolkit'] );
	}

	/**
	 * {@inheritdoc}
	 */
	public static function get_unavailable_reason() {
		return __( 'The Save LinkedIn Job tool requires the CRM Toolkit to be enabled.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'save_linkedin_job';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Save LinkedIn Job', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Save a LinkedIn job posting as a CRM Deal or Project for pipeline tracking and follow-up.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'job_url'         => array(
					'type'        => 'string',
					'description' => __( 'URL of the LinkedIn job posting.', 'mcp-ai-wpoos-pro' ),
				),
				'job_title'       => array(
					'type'        => 'string',
					'description' => __( 'Title of the job / project.', 'mcp-ai-wpoos-pro' ),
				),
				'job_description' => array(
					'type'        => 'string',
					'description' => __( 'Description of the job or project opportunity.', 'mcp-ai-wpoos-pro' ),
				),
				'company'         => array(
					'type'        => 'string',
					'description' => __( 'Company or client name associated with this opportunity.', 'mcp-ai-wpoos-pro' ),
				),
				'estimated_value' => array(
					'type'        => 'number',
					'description' => __( 'Estimated deal value in your default currency.', 'mcp-ai-wpoos-pro' ),
				),
				'skills'          => array(
					'type'        => 'array',
					'description' => __( 'Skills or requirements for the job.', 'mcp-ai-wpoos-pro' ),
					'items'       => array( 'type' => 'string' ),
				),
				'save_as'         => array(
					'type'        => 'string',
					'description' => __( 'CRM entity type to create.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'deal', 'project', 'task' ),
					'default'     => 'deal',
				),
				'notes'           => array(
					'type'        => 'string',
					'description' => __( 'Additional notes about this opportunity.', 'mcp-ai-wpoos-pro' ),
				),
			),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * {@inheritdoc}
	 */
	public function requires_base_pro() {
		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'write',
			'state-changing',
			'requires-capability',
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or WP_Error on failure.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id || ! user_can( $user_id, $this->get_required_capability() ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to save LinkedIn jobs.', 'mcp-ai-wpoos-pro' )
			);
		}

		$job_title       = ! empty( $arguments['job_title'] ) ? sanitize_text_field( $arguments['job_title'] ) : '';
		$job_description = ! empty( $arguments['job_description'] ) ? sanitize_textarea_field( $arguments['job_description'] ) : '';
		$company         = ! empty( $arguments['company'] ) ? sanitize_text_field( $arguments['company'] ) : '';
		$estimated_value = isset( $arguments['estimated_value'] ) ? (float) $arguments['estimated_value'] : 0;
		$save_as         = ! empty( $arguments['save_as'] ) ? sanitize_text_field( $arguments['save_as'] ) : 'deal';
		$notes           = ! empty( $arguments['notes'] ) ? sanitize_textarea_field( $arguments['notes'] ) : '';
		$job_url         = ! empty( $arguments['job_url'] ) ? esc_url_raw( $arguments['job_url'] ) : '';

		if ( empty( $job_title ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_title',
				__( 'Please provide a job_title for the opportunity.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Determine the default pipeline stage from engine settings.
		$default_stage = 'qualification';
		if ( class_exists( 'WP_MCP_AI_CRM_Engine' ) ) {
			$settings      = WP_MCP_AI_CRM_Engine::get_toolkit_settings();
			$pipeline      = isset( $settings['pipeline']['stages'] ) ? $settings['pipeline']['stages'] : array();
			$stage_keys    = array_keys( $pipeline );
			$default_stage = ! empty( $stage_keys[0] ) ? $stage_keys[0] : 'qualification';
		}

		$skills_text = '';
		if ( ! empty( $arguments['skills'] ) && is_array( $arguments['skills'] ) ) {
			$skills_text = implode( ', ', array_map( 'sanitize_text_field', $arguments['skills'] ) );
		}

		// Build the description with all metadata.
		$description = '';
		if ( ! empty( $job_description ) ) {
			$description .= $job_description . "\n\n";
		}
		if ( ! empty( $company ) ) {
			$description .= sprintf(
				/* translators: %s: company name */
				__( 'Company: %s', 'mcp-ai-wpoos-pro' ) . "\n",
				$company
			);
		}
		if ( ! empty( $skills_text ) ) {
			$description .= sprintf(
				/* translators: %s: skills list */
				__( 'Skills: %s', 'mcp-ai-wpoos-pro' ) . "\n",
				$skills_text
			);
		}
		if ( ! empty( $job_url ) ) {
			$description .= sprintf(
				/* translators: %s: LinkedIn URL */
				__( 'Source: %s', 'mcp-ai-wpoos-pro' ) . "\n",
				$job_url
			);
		}

		if ( 'deal' === $save_as && class_exists( 'WP_MCP_AI_Deal_CPT' ) ) {
			$entity_data = array(
				'name'    => $job_title,
				'company' => $company,
				'value'   => $estimated_value,
				'stage'   => $default_stage,
				'source'  => 'linkedin',
				'notes'   => $notes,
			);

			$entity_data = array_filter(
				$entity_data,
				function ( $v ) {
					return ! empty( $v ) || is_numeric( $v );
				}
			);

			$post_id = WP_MCP_AI_Deal_CPT::create( $entity_data );
		} elseif ( 'project' === $save_as && class_exists( 'WP_MCP_AI_Project_CPT' ) ) {
			$entity_data = array(
				'name'        => $job_title,
				'description' => $description,
				'client'      => $company,
				'budget'      => $estimated_value,
				'source'      => 'linkedin',
				'notes'       => $notes,
			);

			$entity_data = array_filter(
				$entity_data,
				function ( $v ) {
					return ! empty( $v ) || is_numeric( $v );
				}
			);

			$post_id = WP_MCP_AI_Project_CPT::create( $entity_data );
		} elseif ( 'task' === $save_as && class_exists( 'WP_MCP_AI_Task_CPT' ) ) {
			$post_data = array(
				'post_type'    => 'mcp_ai_task',
				'post_title'   => $job_title,
				'post_content' => $description,
				'post_status'  => 'publish',
				'meta_input'   => array(
					'_wp_mcp_ai_task_due_date' => '',
					'_wp_mcp_ai_task_priority' => 'medium',
					'_wp_mcp_ai_task_source'   => 'linkedin',
					'_wp_mcp_ai_task_url'      => $job_url,
				),
			);
			$post_id   = wp_insert_post( $post_data, true );
		} else {
			// Generic post fallback.
			$post_data = array(
				'post_type'    => 'post',
				'post_title'   => $job_title,
				'post_content' => $description,
				'post_status'  => 'publish',
				'meta_input'   => array(
					'_wp_mcp_ai_deal_stage'  => $default_stage,
					'_wp_mcp_ai_deal_value'  => $estimated_value,
					'_wp_mcp_ai_deal_source' => 'linkedin',
					'_wp_mcp_ai_deal_url'    => $job_url,
				),
			);
			$post_id   = wp_insert_post( $post_data, true );
		}

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		return array(
			'success'   => true,
			'save_as'   => $save_as,
			'entity_id' => $post_id,
			'stage'     => $default_stage,
			'message'   => sprintf(
				/* translators: 1: entity type, 2: job title */
				__( 'LinkedIn job saved as a %1$s: "%2$s".', 'mcp-ai-wpoos-pro' ),
				$save_as,
				$job_title
			),
		);
	}
}
