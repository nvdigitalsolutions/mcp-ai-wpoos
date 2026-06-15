<?php
/**
 * Process Opt-Out — channel-specific or global opt-out.
 *
 * When an identifier (email or phone) is submitted for opt-out this tool:
 *  1. Adds it to the DNC list for the requested channel(s).
 *  2. Finds all matching leads/contacts and revokes consent.
 *  3. Pseudonymises PII on each matching record (replaces email, name,
 *     phone with non-identifiable placeholders) to reclaim space while
 *     preserving the record skeleton for audit and compliance.
 *
 * The pseudonymisation step is the default ("safe delete") and can be
 * skipped by passing `preserve_record: true`.
 *
 * @package   WP_MCP_AI_Pro
 * @since     2.3.0
 * @since     2.x.x Added PII pseudonymisation of matched contacts.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Processes an opt-out request: adds to DNC list, revokes consent,
 * pseudonymises PII on matching leads, and logs for compliance.
 *
 * @since 2.3.0
 */
class WP_MCP_AI_Tool_Process_Opt_Out implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Whether the tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		$s = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $s['enable_crm_toolkit'] );
	}

	/**
	 * Reason the tool is unavailable.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason() {
		return __( 'CRM Toolkit required.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'process_opt_out';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Process Opt-Out', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Process an opt-out request: add to DNC list, revoke consent, pseudonymise PII on matching leads, and log for compliance.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the parameters schema.
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'identifier'      => array(
					'type'        => 'string',
					'description' => __( 'Email address or phone number.', 'mcp-ai-wpoos-pro' ),
				),
				'channel'         => array(
					'type'        => 'string',
					'default'     => 'all',
					'description' => __( 'Channel to opt out from. Use "all" for global opt-out.', 'mcp-ai-wpoos-pro' ),
				),
				'reason'          => array(
					'type'        => 'string',
					'default'     => 'user_request',
					'description' => __( 'Reason for the opt-out request.', 'mcp-ai-wpoos-pro' ),
				),
				'preserve_record' => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'If true, skip PII pseudonymisation — only add to DNC and revoke consent.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'identifier' ),
		);
	}

	/**
	 * Get the required capability.
	 *
	 * @return string
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Whether the tool requires Base Pro.
	 *
	 * @return bool
	 */
	public function requires_base_pro() {
		return true;
	}

	/**
	 * Get the capability flags.
	 *
	 * @return array
	 */
	public function get_capability_flags() {
		return array( 'pro', 'database-write', 'requires-capability' );
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$identifier      = strtolower( trim( sanitize_text_field( $arguments['identifier'] ) ) );
		$channel         = sanitize_key( $arguments['channel'] ?? 'all' );
		$reason          = sanitize_key( $arguments['reason'] ?? 'user_request' );
		$preserve_record = ! empty( $arguments['preserve_record'] );

		if ( ! class_exists( 'WP_MCP_AI_CRM_Engine' ) ) {
			return new WP_Error( 'engine_missing', __( 'CRM Engine not available.', 'mcp-ai-wpoos-pro' ) );
		}

		// Step 1: Add to DNC list.
		WP_MCP_AI_CRM_Engine::add_to_dnc( $identifier, $channel );

		$is_email      = ( strpos( $identifier, '@' ) !== false );
		$meta_key      = $is_email ? 'email' : 'phone';
		$pseudonymized = 0;
		$matched_ids   = array();
		$per_page      = 50;
		$page          = 1;

		// Step 2: Find ALL matching contacts (paginated).
		do {
			$q = new WP_Query(
				array(
					'post_type'      => array( 'mcp_ai_lead', 'mcp_crm_contacts' ),
					'post_status'    => 'publish',
					'posts_per_page' => $per_page,
					'paged'          => $page,
					'fields'         => 'ids',
					'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						array(
							'key'   => $meta_key,
							'value' => $identifier,
						),
					),
					'no_found_rows'  => false,
				)
			);

			foreach ( $q->posts as $contact_id ) {
				$matched_ids[] = $contact_id;

				// Revoke consent for every match.
				if ( class_exists( 'WP_MCP_AI_CRM_Consent' ) ) {
					WP_MCP_AI_CRM_Consent::revoke( $contact_id, $channel );
				}

				// Step 3: Pseudonymise PII ("safe delete").
				if ( ! $preserve_record ) {
					$now = time();

					// Replace email with a non-identifiable hash-based placeholder.
					$pseudo_email = 'suppressed_' . substr( hash( 'sha256', $identifier . $contact_id . $now ), 0, 12 ) . '@dnc.local';

					update_post_meta( $contact_id, 'email', $pseudo_email );
					update_post_meta( $contact_id, '_original_email', $identifier );

					// Clear PII fields.
					update_post_meta( $contact_id, 'first_name', '' );
					update_post_meta( $contact_id, 'last_name', '' );
					update_post_meta( $contact_id, 'phone', '' );
					update_post_meta( $contact_id, 'company_name', '' );
					update_post_meta( $contact_id, 'job_title', '' );
					update_post_meta( $contact_id, 'notes', '' );
					update_post_meta( $contact_id, 'tags', array() );

					// Mark lifecycle as suppressed.
					update_post_meta( $contact_id, 'lifecycle_stage', 'suppressed' );
					update_post_meta( $contact_id, 'lead_status', 'opted_out' );
					update_post_meta( $contact_id, '_suppressed_at', gmdate( 'c', $now ) );
					update_post_meta( $contact_id, '_suppression_reason', $reason );

					// Update post title so it's clear in admin listings.
					$current_post = get_post( $contact_id );
					if ( $current_post && false === strpos( $current_post->post_title, '[Suppressed]' ) ) {
						wp_update_post(
							array(
								'ID'         => $contact_id,
								'post_title' => '[Suppressed] ' . gmdate( 'Y-m-d' ),
							)
						);
					}

					// Audit each pseudonymisation.
					if ( class_exists( 'WP_MCP_AI_CRM_Audit' ) ) {
						WP_MCP_AI_CRM_Audit::record(
							'contact_pseudonymized',
							'contact',
							$contact_id,
							array(
								'original_identifier' => $identifier,
								'channel'             => $channel,
								'reason'              => $reason,
							)
						);
					}

					++$pseudonymized;
				}
			}

			++$page;
		} while ( $q->max_num_pages >= $page );

		wp_reset_postdata();

		// Step 4: Top-level audit entry.
		if ( class_exists( 'WP_MCP_AI_CRM_Audit' ) ) {
			WP_MCP_AI_CRM_Audit::record(
				'opt_out_processed',
				'dnc',
				0,
				array(
					'identifier'    => $identifier,
					'channel'       => $channel,
					'reason'        => $reason,
					'matched_ids'   => $matched_ids,
					'pseudonymized' => $pseudonymized,
				)
			);
		}

		return array(
			'success'          => true,
			'message'          => $preserve_record
				? __( 'Opt-out processed (record preserved).', 'mcp-ai-wpoos-pro' )
				: sprintf(
					/* translators: 1: number of contacts pseudonymised, 2: total matched. */
					_n(
						'Opt-out processed. %1$d of %2$d matching contact pseudonymised.',
						'Opt-out processed. %1$d of %2$d matching contacts pseudonymised.',
						$pseudonymized,
						'mcp-ai-wpoos-pro'
					),
					$pseudonymized,
					count( $matched_ids )
				),
			'identifier'       => $identifier,
			'channel'          => $channel,
			'matched_contacts' => count( $matched_ids ),
			'pseudonymized'    => $pseudonymized,
		);
	}
}
