<?php
/**
 * Tool for updating allergies.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Updates an existing allergy record.
 */
class WP_MCP_AI_Tool_Update_Allergy implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'update_allergy';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Update Allergy', 'mcp-ai-wpoos-pro' );
	}


	/**

	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Updates an existing allergy record with new information.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get parameters schema.
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'allergy_id'         => array(
					'type'        => 'integer',
					'description' => __( 'Allergy ID to update (required)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'allergen'           => array(
					'type'        => 'string',
					'description' => __( 'Allergen name (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 200,
				),
				'severity'           => array(
					'type'        => 'string',
					'description' => __( 'Severity level (optional)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'mild', 'moderate', 'severe' ),
				),
				'allergy_type'       => array(
					'type'        => 'string',
					'description' => __( 'Allergy category (optional)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'food', 'drug', 'environmental', 'contact', 'latex', 'insect', 'other' ),
				),
				'reactions'          => array(
					'type'        => 'string',
					'description' => __( 'Typical reactions (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 1000,
				),
				'onset_type'         => array(
					'type'        => 'string',
					'description' => __( 'Onset type (optional)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'immediate', 'delayed', 'unknown' ),
				),
				'diagnosed_date'     => array(
					'type'        => 'string',
					'description' => __( 'Date diagnosed (YYYY-MM-DD) (optional)', 'mcp-ai-wpoos-pro' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'last_reaction_date' => array(
					'type'        => 'string',
					'description' => __( 'Date of most recent reaction (YYYY-MM-DD) (optional)', 'mcp-ai-wpoos-pro' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'treatment'          => array(
					'type'        => 'string',
					'description' => __( 'Treatment or management protocol (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 1000,
				),
				'notes'              => array(
					'type'        => 'string',
					'description' => __( 'Additional notes (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 5000,
				),
			),
			'required'             => array( 'allergy_id' ),
			'additionalProperties' => false,
		);
	}


	/**
	 * Get extended tool definition including toolkit metadata.
	 *
	 * @return array Tool definition with metadata.
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'health_wellness',
			'post_type'             => 'mcp_ai_allergy',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'profession_tags'       => array( 'healthcare_provider', 'caregiver' ),
			'risk_level'            => 'standard',
		);
	}

		/**
		 * Get capability flags for this tool.
		 *
		 * @return array
		 */
	public function get_capability_flags() {
		return array( 'pro', 'database-write' );
	}

	/**
	 * Check if tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_health_wellness_management'] );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$current_user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to update allergies.', 'mcp-ai-wpoos-pro' ) );
		}

		$allergy_id = isset( $arguments['allergy_id'] ) ? absint( $arguments['allergy_id'] ) : 0;

		if ( ! $allergy_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_id', __( 'Allergy ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$allergy = get_post( $allergy_id );

		if ( ! $allergy || 'mcp_ai_allergy' !== $allergy->post_type ) {
			return new WP_Error( 'wp_mcp_ai_not_found', __( 'Allergy not found.', 'mcp-ai-wpoos-pro' ) );
		}

		$allergen           = isset( $arguments['allergen'] ) ? sanitize_text_field( $arguments['allergen'] ) : '';
		$severity           = isset( $arguments['severity'] ) ? sanitize_key( $arguments['severity'] ) : '';
		$allergy_type       = isset( $arguments['allergy_type'] ) ? sanitize_key( $arguments['allergy_type'] ) : '';
		$reactions          = isset( $arguments['reactions'] ) ? sanitize_textarea_field( $arguments['reactions'] ) : '';
		$onset_type         = isset( $arguments['onset_type'] ) ? sanitize_key( $arguments['onset_type'] ) : '';
		$diagnosed_date     = isset( $arguments['diagnosed_date'] ) ? sanitize_text_field( $arguments['diagnosed_date'] ) : '';
		$last_reaction_date = isset( $arguments['last_reaction_date'] ) ? sanitize_text_field( $arguments['last_reaction_date'] ) : '';
		$treatment          = isset( $arguments['treatment'] ) ? sanitize_textarea_field( $arguments['treatment'] ) : '';
		$notes              = isset( $arguments['notes'] ) ? wp_kses_post( $arguments['notes'] ) : '';

		if ( $allergen || $notes ) {
			$post_data = array( 'ID' => $allergy_id );
			if ( $allergen ) {
				$post_data['post_title'] = $allergen;
			}
			if ( $notes ) {
				$post_data['post_content'] = $notes;
			}
			wp_update_post( $post_data, true );
		}

		if ( $severity ) {
			wp_set_object_terms( $allergy_id, $severity, 'mcp_ai_allergy_severity' );
			update_post_meta( $allergy_id, '_allergy_severity', $severity );
		}

		if ( $allergen ) {
			update_post_meta( $allergy_id, '_allergy_allergen', $allergen );
		}

		if ( $allergy_type ) {
			update_post_meta( $allergy_id, '_allergy_type', $allergy_type );
		}

		if ( $reactions ) {
			update_post_meta( $allergy_id, '_allergy_reactions', $reactions );
		}

		if ( $onset_type ) {
			update_post_meta( $allergy_id, '_allergy_onset_type', $onset_type );
		}

		if ( $diagnosed_date ) {
			update_post_meta( $allergy_id, '_allergy_diagnosed_date', $diagnosed_date );
		}

		if ( $last_reaction_date ) {
			update_post_meta( $allergy_id, '_allergy_last_reaction_date', $last_reaction_date );
		}

		if ( $treatment ) {
			update_post_meta( $allergy_id, '_allergy_treatment', $treatment );
		}

		return array(
			'success' => true,
			'message' => __( 'Allergy updated successfully.', 'mcp-ai-wpoos-pro' ),
			'allergy' => array(
				'id'         => $allergy_id,
				'updated_at' => current_time( 'mysql' ),
			),
		);
	}
}
