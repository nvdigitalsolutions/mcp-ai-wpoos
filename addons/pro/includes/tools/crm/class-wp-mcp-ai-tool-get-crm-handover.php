<?php
/**
 * Tool for assembling a paste-ready CRM handover bundle.
 *
 * Flattens a lead or deal into a plain-text bundle (entity facts, BANT,
 * stage history with time-in-stage, last email signals, company profile,
 * recent activities, and the settings-driven closing ask) so it can be
 * handed to any AI chat or human without further lookups. No LLM call is
 * made at assembly time — the bundle is pure context.
 *
 * Inspired by JobNavigator's interview prep bundle
 * (GET /applications/{id}/prep).
 *
 * @package WP_MCP_AI_Pro
 * @since   3.2.0
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get CRM Handover Tool.
 *
 * @since 3.2.0
 */
class WP_MCP_AI_Tool_Get_CRM_Handover implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {

	use WP_MCP_AI_Tool_Envelope;

	/**
	 * Valid bundle sections.
	 *
	 * @var string[]
	 */
	const SECTIONS = array( 'lead', 'company', 'deals', 'activities', 'history' );

	/**
	 * Determine whether the tool is available.
	 *
	 * @since 3.2.0
	 * @return bool
	 */
	public static function is_available() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_crm_toolkit'] );
	}

	/**
	 * Message explaining why the tool is unavailable.
	 *
	 * @since 3.2.0
	 * @return string
	 */
	public static function get_unavailable_reason() {
		return __( 'The Get CRM Handover tool requires the CRM Toolkit to be enabled in plugin settings.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_crm_handover';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get CRM Handover', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Assemble a paste-ready plain-text handover bundle for a lead or deal: entity facts, BANT scores, stage history with time-in-stage, last email signals, company profile, recent activities, and a closing ask. No AI call is made.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get usage guidance for the tool.
	 *
	 * @return array
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Assembling a paste-ready briefing for a lead or deal to hand off to another assistant or a human.', 'mcp-ai-wpoos-pro' ),
			'when_not_to_use' => __( 'Editing records; this tool is read-only. Fetching one raw record; use get_deal or manage_crm_contact.', 'mcp-ai-wpoos-pro' ),
			'related_tools'   => array( 'get_deal', 'manage_crm_contact', 'get_contact_interactions' ),
			'notes'           => __( 'entity is lead or deal; include selects sections: lead, company, deals, activities, history. No LLM call is made.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'entity'    => array(
					'type'        => 'string',
					'description' => __( 'Entity kind: "lead" or "deal". Defaults to "deal".', 'mcp-ai-wpoos-pro' ),
				),
				'entity_id' => array(
					'type'        => 'integer',
					'description' => __( 'Lead or deal ID (required).', 'mcp-ai-wpoos-pro' ),
				),
				'include'   => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => __( 'Sections to include: lead, company, deals, activities, history. Defaults to all.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'entity_id' ),
		);
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
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'database-read',
			'requires-capability',
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Gateway 1: Sanitize inputs.
		$entity    = isset( $arguments['entity'] ) ? sanitize_key( $arguments['entity'] ) : 'deal';
		$entity_id = isset( $arguments['entity_id'] ) ? absint( $arguments['entity_id'] ) : 0;

		if ( ! in_array( $entity, array( 'lead', 'deal' ), true ) ) {
			return new WP_Error(
				'invalid_entity',
				__( 'entity must be "lead" or "deal".', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( ! $entity_id ) {
			return new WP_Error(
				'invalid_entity_id',
				__( 'A valid entity ID is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		$include = array( 'lead', 'company', 'deals', 'activities', 'history' );
		if ( isset( $arguments['include'] ) && is_array( $arguments['include'] ) ) {
			$requested = array_map( 'sanitize_key', $arguments['include'] );
			$include   = array_values( array_intersect( $requested, self::SECTIONS ) );
			if ( empty( $include ) ) {
				return new WP_Error(
					'invalid_include',
					__( 'include must contain at least one of: lead, company, deals, activities, history.', 'mcp-ai-wpoos-pro' )
				);
			}
		}

		// Resolve the subject lead.
		$lead_id = 0;
		$deal    = array();

		if ( 'deal' === $entity ) {
			if ( 'mcp_ai_deal' !== get_post_type( $entity_id ) ) {
				return new WP_Error(
					'entity_not_found',
					__( 'Deal not found.', 'mcp-ai-wpoos-pro' ),
					array( 'status' => 404 )
				);
			}
			$deal    = get_post_meta( $entity_id );
			$lead_id = isset( $deal['lead_id'][0] ) ? absint( $deal['lead_id'][0] ) : 0;
		} else {
			if ( 'mcp_ai_lead' !== get_post_type( $entity_id ) ) {
				return new WP_Error(
					'entity_not_found',
					__( 'Lead not found.', 'mcp-ai-wpoos-pro' ),
					array( 'status' => 404 )
				);
			}
			$lead_id = $entity_id;
		}

		$settings = class_exists( 'WP_MCP_AI_CRM_Engine' )
			? WP_MCP_AI_CRM_Engine::get_toolkit_settings()
			: array();
		$ask      = isset( $settings['handover_ask'] ) ? (string) $settings['handover_ask'] : '';

		$lines    = array();
		$sections = array();

		// ── Lead facts ──
		if ( in_array( 'lead', $include, true ) && $lead_id ) {
			$lead_meta = get_post_meta( $lead_id );
			$lead_post = get_post( $lead_id );
			$first     = isset( $lead_meta['first_name'][0] ) ? $lead_meta['first_name'][0] : '';
			$last      = isset( $lead_meta['last_name'][0] ) ? $lead_meta['last_name'][0] : '';
			$name      = trim( $first . ' ' . $last );

			$lines[] = '## Lead';
			$lines[] = '- Name: ' . ( '' !== $name ? $name : ( $lead_post ? $lead_post->post_title : 'Unknown' ) );
			$lines[] = '- Email: ' . ( isset( $lead_meta['email'][0] ) ? $lead_meta['email'][0] : '' );
			$lines[] = '- Phone: ' . ( isset( $lead_meta['phone'][0] ) ? $lead_meta['phone'][0] : '' );
			$lines[] = '- Company: ' . ( isset( $lead_meta['company_name'][0] ) ? $lead_meta['company_name'][0] : '' );
			$lines[] = '- Job title: ' . ( isset( $lead_meta['job_title'][0] ) ? $lead_meta['job_title'][0] : '' );
			$lines[] = '- Source: ' . ( isset( $lead_meta['source'][0] ) ? $lead_meta['source'][0] : '' );
			$lines[] = '- Lifecycle: ' . ( isset( $lead_meta['lifecycle_stage'][0] ) ? $lead_meta['lifecycle_stage'][0] : '' );
			$lines[] = '- Status: ' . ( isset( $lead_meta['lead_status'][0] ) ? $lead_meta['lead_status'][0] : '' );
			$lines[] = '- Score: ' . ( isset( $lead_meta['lead_score'][0] ) ? (string) $lead_meta['lead_score'][0] : '0' );

			$bant = isset( $lead_meta['bant_assessment'][0] ) ? maybe_unserialize( $lead_meta['bant_assessment'][0] ) : null;
			if ( is_array( $bant ) && ! empty( $bant ) ) {
				$lines[] = '- BANT: ' . wp_json_encode( $bant );
			}

			if ( isset( $lead_meta['last_email_received'][0] ) && $lead_meta['last_email_received'][0] ) {
				$lines[] = '- Last reply: ' . $lead_meta['last_email_received'][0]
					. ( isset( $lead_meta['last_email_sentiment'][0] ) ? ' (' . $lead_meta['last_email_sentiment'][0] . ')' : '' );
				if ( isset( $lead_meta['last_email_snippet'][0] ) && $lead_meta['last_email_snippet'][0] ) {
					$lines[] = '  "' . $lead_meta['last_email_snippet'][0] . '"';
				}
			}

			$sections['lead'] = $lines;
		}

		// ── Company profile ──
		$company_id = $lead_id ? absint( (string) get_post_meta( $lead_id, 'company_id', true ) ) : 0;
		if ( in_array( 'company', $include, true ) && $company_id ) {
			$company = get_post( $company_id );
			if ( $company && 'mcp_ai_company' === $company->post_type ) {
				$lines[] = '';
				$lines[] = '## Company';
				$lines[] = '- Name: ' . $company->post_title;
				$lines[] = '- Website: ' . (string) get_post_meta( $company_id, '_company_website', true );
				$lines[] = '- Industry: ' . (string) get_post_meta( $company_id, '_company_industry', true );
				$lines[] = '- Size: ' . (string) get_post_meta( $company_id, '_company_size', true );
				$lines[] = '- Status: ' . (string) get_post_meta( $company_id, '_company_status', true );

				$sections['company'] = $lines;
			}
		}

		// ── Deal / deals ──
		if ( in_array( 'deals', $include, true ) && $lead_id ) {
			$deal_ids = array();
			if ( 'deal' === $entity ) {
				$deal_ids = array( $entity_id );
			} else {
				$deal_ids = get_posts(
					array(
						'post_type'        => 'mcp_ai_deal',
						'post_status'      => 'publish',
						'posts_per_page'   => 50,
						'fields'           => 'ids',
						'no_found_rows'    => true,
						'suppress_filters' => true,
						'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Intentional lead linkage lookup.
							array(
								'key'   => 'lead_id',
								'value' => $lead_id,
							),
						),
					)
				);
			}

			if ( ! empty( $deal_ids ) ) {
				$lines[] = '';
				$lines[] = '## Deals';
				foreach ( $deal_ids as $deal_post_id ) {
					$deal_post_id = absint( $deal_post_id );
					$deal_meta    = get_post_meta( $deal_post_id );
					$deal_post    = get_post( $deal_post_id );
					$stage        = isset( $deal_meta['pipeline_stage'][0] ) ? $deal_meta['pipeline_stage'][0] : '';
					$amount       = isset( $deal_meta['amount'][0] ) ? (float) $deal_meta['amount'][0] : 0.0;
					$currency     = isset( $deal_meta['currency'][0] ) ? $deal_meta['currency'][0] : '';

					$lines[] = '- #' . $deal_post_id . ' ' . ( $deal_post ? $deal_post->post_title : '' )
						. ' [' . $stage . ']'
						. ( $amount > 0 ? ' — ' . WP_MCP_AI_CRM_Engine::format_currency( $amount, $currency ) : '' );
					$lines[] = '  Probability: ' . ( isset( $deal_meta['win_probability'][0] ) ? $deal_meta['win_probability'][0] : '' )
						. ', Close: ' . ( isset( $deal_meta['expected_close_date'][0] ) ? $deal_meta['expected_close_date'][0] : '' );

					if ( class_exists( 'WP_MCP_AI_CRM_Stage_History' ) ) {
						$in_stage = WP_MCP_AI_CRM_Stage_History::time_in_stage( $deal_post_id );
						if ( null !== $in_stage ) {
							$lines[] = '  In stage for ' . human_time_diff( time() - $in_stage, time() );
						}
					}
				}

				$sections['deals'] = $lines;
			}
		}

		// ── Stage history ──
		if ( in_array( 'history', $include, true ) && 'deal' === $entity && class_exists( 'WP_MCP_AI_CRM_Stage_History' ) ) {
			$history = WP_MCP_AI_CRM_Stage_History::get_history( $entity_id );
			if ( ! empty( $history ) ) {
				$lines[] = '';
				$lines[] = '## Stage history';
				foreach ( $history as $entry ) {
					$lines[] = '- ' . ( isset( $entry['at'] ) ? $entry['at'] : '' )
						. ' ' . ( isset( $entry['from'] ) && '' !== $entry['from'] ? $entry['from'] . ' → ' : '' )
						. ( isset( $entry['to'] ) ? $entry['to'] : '' )
						. ' (' . ( isset( $entry['source'] ) ? $entry['source'] : 'tool' ) . ')';
				}
				$sections['history'] = $lines;
			}
		}

		// ── Recent activities ──
		if ( in_array( 'activities', $include, true ) && $lead_id ) {
			$activity_ids = get_posts(
				array(
					'post_type'        => 'mcp_ai_crm_activity',
					'post_status'      => 'publish',
					'posts_per_page'   => 20,
					'fields'           => 'ids',
					'no_found_rows'    => true,
					'suppress_filters' => true,
					'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Intentional activity linkage lookup.
						'relation' => 'AND',
						array(
							'key'   => 'related_type',
							'value' => 'deal' === $entity ? 'deal' : 'lead',
						),
						array(
							'key'     => 'related_id',
							'value'   => 'deal' === $entity ? $entity_id : $lead_id,
							'compare' => '=',
						),
					),
				)
			);

			if ( ! empty( $activity_ids ) ) {
				$lines[] = '';
				$lines[] = '## Recent activities';
				foreach ( $activity_ids as $activity_id ) {
					$activity = get_post( $activity_id );
					if ( ! $activity ) {
						continue;
					}
					$type    = (string) get_post_meta( $activity_id, 'activity_type', true );
					$lines[] = '- [' . $type . '] ' . $activity->post_title
						. ( $activity->post_content ? ' — ' . wp_strip_all_tags( $activity->post_content ) : '' );
				}
				$sections['activities'] = $lines;
			}
		}

		// ── Closing ask ──
		$lines[] = '';
		$lines[] = '## What I need from you';
		$lines[] = '' !== $ask ? $ask : __( 'Summarize this record and propose the next action.', 'mcp-ai-wpoos-pro' );

		$text = implode( "\n", $lines );

		return $this->format_success_response(
			__( 'Handover bundle assembled.', 'mcp-ai-wpoos-pro' ),
			array(
				'entity'    => $entity,
				'entity_id' => $entity_id,
				'sections'  => array_keys( $sections ),
				'text'      => $text,
			)
		);
	}
}
