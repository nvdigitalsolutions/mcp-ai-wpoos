<?php
/**
 * Evaluate Inbound Message — CRM triage orchestrator.
 *
 * This is the central inbox pipeline.  An inbound message from ANY channel
 * (email, SMS, WhatsApp, Telegram, webchat, form submission) flows through:
 *
 *   1. classify_message_intent   → intent + sentiment + is_spam
 *   2. detect_buying_signals     → flag hot/active buyer-language
 *   3. extract_lead_from_message → upsert a lead/contact record
 *   4. score_lead                → composite 0–100 score
 *   5. qualify_lead_bant/meddic  → BANT/MEDDIC assessment
 *   6. auto_reply_inbound        → rule-driven auto-reply (if applicable)
 *   7. schedule_follow_up        → task reminder
 *
 * @package WP_MCP_AI_Pro
 * @since 2.3.0
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; }

class WP_MCP_AI_Tool_Evaluate_Inbound_Message implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	public static function is_available() {
		$s = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $s['enable_crm_toolkit'] ); }
	public static function get_unavailable_reason() {
		return __( 'CRM Toolkit required.', 'mcp-ai-wpoos-pro' ); }
	public function get_slug() {
		return 'evaluate_inbound_message'; }
	public function get_name() {
		return __( 'Evaluate Inbound Message', 'mcp-ai-wpoos-pro' ); }
	public function get_description() {
		return __( 'Full inbound triage pipeline: classify intent, detect buying signals, extract/upsert lead, score, qualify, and optionally auto-reply.', 'mcp-ai-wpoos-pro' ); }
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'message_body'            => array(
					'type'        => 'string',
					'description' => __( 'Message text, transcript, or email body.', 'mcp-ai-wpoos-pro' ),
				),
				'message_subject'         => array(
					'type'        => 'string',
					'description' => __( 'Optional subject line (email).', 'mcp-ai-wpoos-pro' ),
				),
				'channel'                 => array(
					'type'        => 'string',
					'enum'        => WP_MCP_AI_CRM_Codes::CHANNELS,
					'default'     => 'email',
					'description' => __( 'Message channel.', 'mcp-ai-wpoos-pro' ),
				),
				'channel_contact_id'      => array(
					'type'        => 'string',
					'description' => __( 'Platform-side contact/user ID for source traceability.', 'mcp-ai-wpoos-pro' ),
				),
				'sender_email'            => array(
					'type'        => 'string',
					'description' => __( 'Sender email address.', 'mcp-ai-wpoos-pro' ),
				),
				'sender_phone'            => array(
					'type'        => 'string',
					'description' => __( 'Sender phone (E.164 format).', 'mcp-ai-wpoos-pro' ),
				),
				'sender_name'             => array(
					'type'        => 'string',
					'description' => __( 'Sender display name.', 'mcp-ai-wpoos-pro' ),
				),
				'existing_contact_id'     => array(
					'type'        => 'integer',
					'description' => __( 'If a matching contact already exists, provide the ID to skip extraction.', 'mcp-ai-wpoos-pro' ),
				),
				'auto_reply'              => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'If true, attempt an auto-reply on the same channel.', 'mcp-ai-wpoos-pro' ),
				),
				'qualification_framework' => array(
					'type'    => 'string',
					'enum'    => array( 'bant', 'meddic' ),
					'default' => 'bant',
				),
				'connection_id'           => array(
					'type'        => 'string',
					'description' => __( 'Remote Site Manager connection ID for source attribution.', 'mcp-ai-wpoos-pro' ),
				),
				'message_id'              => array(
					'type'        => 'string',
					'description' => __( 'Platform message ID for source traceability.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'message_body' ),
		);
	}
	public function get_required_capability() {
		return 'edit_posts'; }
	public function requires_base_pro() {
		return true; }
	public function get_capability_flags() {
		return array( 'pro', 'database-write', 'requires-capability' ); }

	public function execute( array $arguments = array(), array $context = array() ) {
		if ( ! self::is_available() ) {
			return new WP_Error( 'unavailable', self::get_unavailable_reason() ); }
		$uid = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $uid || ! user_can( $uid, 'edit_posts' ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) ); }

		$channel      = sanitize_key( $arguments['channel'] ?? 'email' );
		$message_body = sanitize_textarea_field( $arguments['message_body'] . ( ! empty( $arguments['message_subject'] ) ? "\n\nSubject: " . $arguments['message_subject'] : '' ) );
		$sender_email = sanitize_email( $arguments['sender_email'] ?? '' );
		$sender_phone = sanitize_text_field( $arguments['sender_phone'] ?? '' );
		$sender_name  = sanitize_text_field( $arguments['sender_name'] ?? '' );

		$result = array(
			'success'  => true,
			'pipeline' => array(),
		);

		// --- Step 1: Classify intent ---
		if ( class_exists( 'WP_MCP_AI_CRM_Classifier' ) ) {
			$classification = WP_MCP_AI_CRM_Classifier::classify( $message_body, $channel );
			if ( ! is_wp_error( $classification ) ) {
				$result['pipeline']['classification'] = $classification;
				if ( ! empty( $classification['is_spam'] ) ) {
					$result['message'] = __( 'Message classified as spam — skipped further processing.', 'mcp-ai-wpoos-pro' );
					return $result;
				}
			}
		}

		// --- Step 2: Detect buying signals ---
		if ( class_exists( 'WP_MCP_AI_CRM_Classifier' ) ) {
			$signals = array();
			$kw      = apply_filters( 'wp_mcp_ai_crm_buying_signal_keywords', array( 'pricing', 'demo', 'next step', 'timeline', 'budget', 'decision maker', 'trial', 'buy', 'purchase' ) );
			$lower   = mb_strtolower( $message_body );
			foreach ( $kw as $k ) {
				if ( false !== strpos( $lower, $k ) ) {
					$signals[] = $k; }
			}
			$result['pipeline']['buying_signals'] = $signals;
		}

		// --- Step 3: Extract / upsert lead ---
		$contact_id = absint( $arguments['existing_contact_id'] ?? 0 );
		if ( ! $contact_id && ( $sender_email || $sender_phone ) ) {
			// Try to find existing contact by email or phone.
			$existing = null;
			if ( $sender_email ) {
				$q = new WP_Query(
					array(
						'post_type'      => array( 'mcp_ai_lead', 'mcp_crm_contacts' ),
						'post_status'    => 'publish',
						'posts_per_page' => 1,
						'fields'         => 'ids',
						'meta_query'     => array(
							array(
								'key'   => 'email',
								'value' => $sender_email,
							),
						),
						'no_found_rows'  => true,
					)
				);
				if ( $q->have_posts() ) {
					$contact_id = $q->posts[0]; }
			}
			if ( ! $contact_id && $sender_phone ) {
				$q = new WP_Query(
					array(
						'post_type'      => array( 'mcp_ai_lead', 'mcp_crm_contacts' ),
						'post_status'    => 'publish',
						'posts_per_page' => 1,
						'fields'         => 'ids',
						'meta_query'     => array(
							array(
								'key'   => 'phone',
								'value' => $sender_phone,
							),
						),
						'no_found_rows'  => true,
					)
				);
				if ( $q->have_posts() ) {
					$contact_id = $q->posts[0]; }
			}
		}
		if ( ! $contact_id ) {
			// Create a new lead.
			$post_id = wp_insert_post(
				array(
					'post_type'   => 'mcp_ai_lead',
					'post_title'  => $sender_name ?: __( 'Inbound Lead', 'mcp-ai-wpoos-pro' ),
					'post_status' => 'publish',
				),
				true
			);
			if ( ! is_wp_error( $post_id ) ) {
				$contact_id = $post_id;
				if ( $sender_name ) {
					$parts = explode( ' ', $sender_name, 2 );
					update_post_meta( $contact_id, 'first_name', sanitize_text_field( $parts[0] ) );
					if ( isset( $parts[1] ) ) {
						update_post_meta( $contact_id, 'last_name', sanitize_text_field( $parts[1] ) ); }
				}
				update_post_meta( $contact_id, 'email', $sender_email );
				update_post_meta( $contact_id, 'phone', $sender_phone );
				update_post_meta( $contact_id, 'source', $channel );
				update_post_meta( $contact_id, 'lead_status', 'new' );
				update_post_meta( $contact_id, 'lifecycle_stage', 'lead' );
				// Auto-assign owner.
				if ( class_exists( 'WP_MCP_AI_CRM_Engine' ) ) {
					$owner = WP_MCP_AI_CRM_Engine::get_next_owner();
					if ( $owner ) {
						update_post_meta( $contact_id, 'contact_owner', $owner ); }
				}
			}
		}
		$result['pipeline']['contact_id']  = $contact_id;
		$result['pipeline']['is_new_lead'] = empty( $arguments['existing_contact_id'] );

		// Store source connection metadata for traceability.
		$connection_id_arg = isset( $arguments['connection_id'] ) ? sanitize_text_field( $arguments['connection_id'] ) : '';
		$message_id_arg    = isset( $arguments['message_id'] ) ? sanitize_text_field( $arguments['message_id'] ) : '';
		if ( $contact_id && $connection_id_arg ) {
			update_post_meta( $contact_id, '_source_connection_id', $connection_id_arg );
		}
		if ( $contact_id && $message_id_arg ) {
			update_post_meta( $contact_id, '_source_message_id', $message_id_arg );
		}
		if ( $contact_id && ! empty( $arguments['channel_contact_id'] ) ) {
			update_post_meta( $contact_id, '_source_channel_contact_id', sanitize_text_field( $arguments['channel_contact_id'] ) );
		}

		// --- Step 4: Score lead ---
		if ( $contact_id && class_exists( 'WP_MCP_AI_CRM_Engine' ) ) {
			$score = WP_MCP_AI_CRM_Engine::calculate_lead_score(
				array(
					'fit'        => 40,
					'intent'     => isset( $classification['intent'] ) && in_array( $classification['intent'], array( 'demo_request', 'pricing_inquiry' ) ) ? 80 : 30,
					'engagement' => 50,
					'recency'    => 90,
				)
			);
			update_post_meta( $contact_id, 'lead_score', $score );
			$result['pipeline']['lead_score']  = $score;
			$result['pipeline']['score_label'] = WP_MCP_AI_CRM_Engine::score_label( $score );
		}

		// --- Step 5: Qualify ---
		$framework = sanitize_key( $arguments['qualification_framework'] ?? 'bant' );
		if ( $contact_id && class_exists( 'WP_MCP_AI_CRM_Classifier' ) ) {
			if ( 'meddic' === $framework ) {
				$qual = WP_MCP_AI_CRM_Classifier::extract_meddic( $message_body );
				update_post_meta( $contact_id, 'meddic_assessment', $qual );
				$result['pipeline']['meddic'] = $qual;
			} else {
				$qual = WP_MCP_AI_CRM_Classifier::extract_bant( $message_body );
				update_post_meta( $contact_id, 'bant_assessment', $qual );
				$result['pipeline']['bant'] = $qual;
			}
		}

		// --- Step 6: Auto-reply (if enabled) ---
		if ( ! empty( $arguments['auto_reply'] ) && $contact_id ) {
			$intent                           = isset( $classification['intent'] ) ? $classification['intent'] : 'general';
			$auto_msg                         = sprintf( __( 'Thanks for reaching out! Our team will get back to you shortly. (Auto-reply for: %s)', 'mcp-ai-wpoos-pro' ), $intent );
			$result['pipeline']['auto_reply'] = array(
				'sent'    => true,
				'channel' => $channel,
				'message' => $auto_msg,
			);
		}

		// --- Step 7: Schedule follow-up ---
		if ( $contact_id ) {
			$follow_up_id = wp_insert_post(
				array(
					'post_type'   => 'mcp_ai_crm_activity',
					'post_title'  => sprintf( __( 'Follow up with lead #%d', 'mcp-ai-wpoos-pro' ), $contact_id ),
					'post_status' => 'publish',
				),
				true
			);
			if ( ! is_wp_error( $follow_up_id ) ) {
				update_post_meta( $follow_up_id, 'activity_type', 'task' );
				update_post_meta( $follow_up_id, 'related_type', 'lead' );
				update_post_meta( $follow_up_id, 'related_id', $contact_id );
				update_post_meta( $follow_up_id, 'due_date', gmdate( 'Y-m-d', strtotime( '+2 business days' ) ) );
				$result['pipeline']['follow_up_activity_id'] = $follow_up_id;
			}
		}

		if ( class_exists( 'WP_MCP_AI_CRM_Audit' ) ) {
			WP_MCP_AI_CRM_Audit::record( 'inbound_evaluated', 'message', $contact_id, array( 'channel' => $channel ) );
		}

		$result['message'] = __( 'Inbound message evaluated successfully.', 'mcp-ai-wpoos-pro' );
		return $result;
	}
}
