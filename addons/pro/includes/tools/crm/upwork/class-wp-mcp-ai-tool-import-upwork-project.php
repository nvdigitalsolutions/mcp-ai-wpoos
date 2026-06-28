<?php
/**
 * Tool for importing an Upwork job/project into the CRM as a Deal or Project.
 *
 * Pulls job details via the Upwork GraphQL API when a valid OAuth connection
 * is configured, and creates a Deal or Project record in the CRM.
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
 * Imports an Upwork job as a CRM Deal or Project.
 *
 * @since 2.10.0
 */
class WP_MCP_AI_Tool_Import_Upwork_Project implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Determine whether CRM toolkit and Upwork client are available.
	 *
	 * @since 2.10.0
	 * @return bool
	 */
	public static function is_available() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_crm_toolkit'] ) && class_exists( 'WP_MCP_AI_Upwork_Client' );
	}

	/**
	 * {@inheritdoc}
	 */
	public static function get_unavailable_reason() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_crm_toolkit'] ) ) {
			return __( 'The Import Upwork Project tool requires the CRM Toolkit to be enabled.', 'mcp-ai-wpoos-pro' );
		}
		return __( 'The Import Upwork Project tool requires the Upwork client integration.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'import_upwork_project';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Import Upwork Project', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Import an Upwork job posting into the CRM as a Deal, Project, or Task for pipeline tracking.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'job_id'          => array(
					'type'        => 'string',
					'description' => __( 'Upwork job posting ID to import.', 'mcp-ai-wpoos-pro' ),
				),
				'job_title'       => array(
					'type'        => 'string',
					'description' => __( 'Job title override (used when job_id is unavailable).', 'mcp-ai-wpoos-pro' ),
				),
				'job_description' => array(
					'type'        => 'string',
					'description' => __( 'Job description (used when job_id is unavailable).', 'mcp-ai-wpoos-pro' ),
				),
				'estimated_value' => array(
					'type'        => 'number',
					'description' => __( 'Estimated project value in your default currency.', 'mcp-ai-wpoos-pro' ),
				),
				'save_as'         => array(
					'type'        => 'string',
					'description' => __( 'CRM entity type to create.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'deal', 'project', 'task' ),
					'default'     => 'deal',
				),
				'connection_id'   => array(
					'type'        => 'string',
					'description' => __( 'Optional Remote Sites Upwork connection ID.', 'mcp-ai-wpoos-pro' ),
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
			'external-api',
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
				__( 'You do not have permission to import Upwork projects.', 'mcp-ai-wpoos-pro' )
			);
		}

		$job_title       = ! empty( $arguments['job_title'] ) ? sanitize_text_field( $arguments['job_title'] ) : '';
		$job_description = ! empty( $arguments['job_description'] ) ? sanitize_textarea_field( $arguments['job_description'] ) : '';
		$estimated_value = isset( $arguments['estimated_value'] ) ? (float) $arguments['estimated_value'] : 0;
		$save_as         = ! empty( $arguments['save_as'] ) ? sanitize_text_field( $arguments['save_as'] ) : 'deal';

		// Attempt API fetch when a connection is available and job_id is provided.
		$api_fetched = false;
		if ( ! empty( $arguments['job_id'] ) ) {
			$api_data = $this->fetch_job_details( $arguments );
			if ( ! is_wp_error( $api_data ) && ! empty( $api_data ) ) {
				$job_title       = ! empty( $api_data['title'] ) ? $api_data['title'] : $job_title;
				$job_description = ! empty( $api_data['description'] ) ? $api_data['description'] : $job_description;
				$api_fetched     = true;
			}
		}

		if ( empty( $job_title ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_title',
				__( 'Please provide a job_id or job_title for the project.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Determine the default pipeline stage.
		$default_stage = 'qualification';
		if ( class_exists( 'WP_MCP_AI_CRM_Engine' ) ) {
			$settings      = WP_MCP_AI_CRM_Engine::get_toolkit_settings();
			$pipeline      = isset( $settings['pipeline']['stages'] ) ? $settings['pipeline']['stages'] : array();
			$stage_keys    = array_keys( $pipeline );
			$default_stage = ! empty( $stage_keys[0] ) ? $stage_keys[0] : 'qualification';
		}

		$description  = '';
		$description .= $job_description . "\n\n";
		$description .= sprintf(
			/* translators: %s: source platform */
			__( 'Source: Upwork (imported %s)', 'mcp-ai-wpoos-pro' ) . "\n",
			gmdate( 'Y-m-d H:i' )
		);

		if ( 'deal' === $save_as && class_exists( 'WP_MCP_AI_Deal_CPT' ) ) {
			$entity_data = array(
				'name'   => $job_title,
				'value'  => $estimated_value,
				'stage'  => $default_stage,
				'source' => 'upwork',
				'notes'  => $description,
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
				'budget'      => $estimated_value,
				'source'      => 'upwork',
			);

			$entity_data = array_filter(
				$entity_data,
				function ( $v ) {
					return ! empty( $v ) || is_numeric( $v );
				}
			);

			$post_id = WP_MCP_AI_Project_CPT::create( $entity_data );
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
					'_wp_mcp_ai_deal_source' => 'upwork',
				),
			);
			$post_id   = wp_insert_post( $post_data, true );
		}

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		return array(
			'success'     => true,
			'save_as'     => $save_as,
			'entity_id'   => $post_id,
			'stage'       => $default_stage,
			'api_fetched' => $api_fetched,
			'message'     => $api_fetched
				? sprintf(
					/* translators: 1: entity type, 2: job title */
					__( 'Upwork project imported via API as %1$s: "%2$s".', 'mcp-ai-wpoos-pro' ),
					$save_as,
					$job_title
				)
				: sprintf(
					/* translators: 1: entity type, 2: job title */
					__( 'Upwork project saved as %1$s: "%2$s". Connect an Upwork account for automatic data import.', 'mcp-ai-wpoos-pro' ),
					$save_as,
					$job_title
				),
		);
	}

	/**
	 * Fetch job details from the Upwork API.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array|WP_Error Job data or WP_Error.
	 */
	protected function fetch_job_details( $arguments ) {
		$connection_id = ! empty( $arguments['connection_id'] )
			? sanitize_text_field( $arguments['connection_id'] )
			: '';

		require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-upwork-client.php';
		$client = new WP_MCP_AI_Upwork_Client( $connection_id );

		$job_id = sanitize_text_field( $arguments['job_id'] );

		$query = '
			query GetJobDetails($id: ID!) {
				marketplaceJobPosting(id: $id) {
					id
					title
					description
					createdDateTime
					jobType
					engagement
					duration
					budget { amount currency }
					hourlyBudget { min max currency }
					skills { prettyName }
					client {
						totalFeedback
						totalHires
						location { country }
					}
					category { name }
				}
			}
		';

		return $client->graphql( $query, array( 'id' => $job_id ) );
	}
}
