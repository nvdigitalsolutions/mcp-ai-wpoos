<?php
/**
 * QMS Workflow.
 *
 * Implements the controlled-document state machine required by ISO 9001:2015
 * Clause 7.5: draft → in_review → approved → released → superseded/obsolete.
 *
 * Every state transition is audited via WP_MCP_AI_QMS_Audit_Log and fires
 * before/after action hooks for extension.
 *
 * @package WP_MCP_AI_Pro
 * @since   1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * State-machine workflow.
 */
class WP_MCP_AI_QMS_Workflow {

	/**
	 * Allowed transitions: from_state => array of valid to_states.
	 *
	 * @return array<string,array<int,string>>
	 */
	public static function allowed_transitions() {
		return array(
			''                                             => array( WP_MCP_AI_QMS_Doc_Record_CPT::STATUS_DRAFT ),
			WP_MCP_AI_QMS_Doc_Record_CPT::STATUS_DRAFT     => array(
				WP_MCP_AI_QMS_Doc_Record_CPT::STATUS_IN_REVIEW,
				WP_MCP_AI_QMS_Doc_Record_CPT::STATUS_OBSOLETE,
			),
			WP_MCP_AI_QMS_Doc_Record_CPT::STATUS_IN_REVIEW => array(
				WP_MCP_AI_QMS_Doc_Record_CPT::STATUS_DRAFT,
				WP_MCP_AI_QMS_Doc_Record_CPT::STATUS_APPROVED,
				WP_MCP_AI_QMS_Doc_Record_CPT::STATUS_OBSOLETE,
			),
			WP_MCP_AI_QMS_Doc_Record_CPT::STATUS_APPROVED  => array(
				WP_MCP_AI_QMS_Doc_Record_CPT::STATUS_RELEASED,
				WP_MCP_AI_QMS_Doc_Record_CPT::STATUS_DRAFT,
				WP_MCP_AI_QMS_Doc_Record_CPT::STATUS_OBSOLETE,
			),
			WP_MCP_AI_QMS_Doc_Record_CPT::STATUS_RELEASED  => array(
				WP_MCP_AI_QMS_Doc_Record_CPT::STATUS_SUPERSEDED,
				WP_MCP_AI_QMS_Doc_Record_CPT::STATUS_OBSOLETE,
			),
			WP_MCP_AI_QMS_Doc_Record_CPT::STATUS_SUPERSEDED => array(
				WP_MCP_AI_QMS_Doc_Record_CPT::STATUS_OBSOLETE,
			),
			WP_MCP_AI_QMS_Doc_Record_CPT::STATUS_OBSOLETE  => array(),
		);
	}

	/**
	 * Validate that a transition is permitted.
	 *
	 * @param string $from From state.
	 * @param string $to   To state.
	 * @return true|WP_Error
	 */
	public static function can_transition( $from, $to ) {
		$transitions = self::allowed_transitions();
		$from        = (string) $from;
		$to          = (string) $to;
		$valid_from  = array_key_exists( $from, $transitions ) ? $transitions[ $from ] : null;
		if ( null === $valid_from ) {
			return new WP_Error( 'wp_mcp_ai_qms_invalid_state', __( 'Unknown source state.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( ! in_array( $to, $valid_from, true ) ) {
			return new WP_Error(
				'wp_mcp_ai_qms_invalid_transition',
				sprintf(
					/* translators: 1: from state, 2: to state */
					__( 'Transition from %1$s to %2$s is not permitted.', 'mcp-ai-wpoos-pro' ),
					$from ? $from : 'none',
					$to
				)
			);
		}
		return true;
	}

	/**
	 * Perform a state transition with validation, audit, and hook firing.
	 *
	 * @param int    $post_id  Document record post ID.
	 * @param string $to_state Target state.
	 * @param array  $context  Optional context (reason, actor_id, meta).
	 * @return true|WP_Error
	 */
	public static function transition( $post_id, $to_state, array $context = array() ) {
		$post_id = absint( $post_id );
		$post    = $post_id ? get_post( $post_id ) : null;
		if ( ! $post || WP_MCP_AI_QMS_Doc_Record_CPT::POST_TYPE !== $post->post_type ) {
			return new WP_Error( 'wp_mcp_ai_qms_invalid_record', __( 'Document record not found.', 'mcp-ai-wpoos-pro' ) );
		}

		$from_state = (string) get_post_meta( $post_id, '_qms_status', true );
		$can        = self::can_transition( $from_state, $to_state );
		if ( is_wp_error( $can ) ) {
			return $can;
		}

		$actor_id = isset( $context['actor_id'] ) ? absint( $context['actor_id'] ) : get_current_user_id();
		$reason   = isset( $context['reason'] ) ? sanitize_textarea_field( $context['reason'] ) : '';

		// Pre-conditions for specific target states.
		$gate = self::check_state_preconditions( $post_id, $to_state );
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}

		$before_hash = (string) get_post_meta( $post_id, '_qms_content_hash', true );

		/**
		 * Fires before a controlled document state transition.
		 *
		 * @since 1.2.0
		 *
		 * @param int    $post_id    Record ID.
		 * @param string $from_state From state.
		 * @param string $to_state   To state.
		 * @param array  $context    Context.
		 */
		do_action( 'wp_mcp_ai_qms_before_state_transition', $post_id, $from_state, $to_state, $context );

		update_post_meta( $post_id, '_qms_status', $to_state );

		// Recompute content hash on transitions that mutate releasable content.
		if ( in_array( $to_state, array( WP_MCP_AI_QMS_Doc_Record_CPT::STATUS_APPROVED, WP_MCP_AI_QMS_Doc_Record_CPT::STATUS_RELEASED ), true ) ) {
			WP_MCP_AI_QMS_Doc_Record_CPT::recompute_hash( $post_id );
		}

		$after_hash = (string) get_post_meta( $post_id, '_qms_content_hash', true );

		WP_MCP_AI_QMS_Audit_Log::record(
			array(
				'subsystem'   => 'qms',
				'event'       => 'state_transition',
				'actor_id'    => $actor_id,
				'post_id'     => $post_id,
				'doc_id'      => (string) get_post_meta( $post_id, '_qms_document_id', true ),
				'revision'    => (string) get_post_meta( $post_id, '_qms_revision', true ),
				'from_state'  => $from_state,
				'to_state'    => $to_state,
				'before_hash' => $before_hash,
				'after_hash'  => $after_hash,
				'meta'        => array( 'reason' => $reason ),
			)
		);

		/**
		 * Fires after a controlled document state transition.
		 *
		 * @since 1.2.0
		 */
		do_action( 'wp_mcp_ai_qms_after_state_transition', $post_id, $from_state, $to_state, $context );

		return true;
	}

	/**
	 * State-specific preconditions (e.g. approver list when transitioning to approved/released).
	 *
	 * @param int    $post_id  Record ID.
	 * @param string $to_state Target state.
	 * @return true|WP_Error
	 */
	protected static function check_state_preconditions( $post_id, $to_state ) {
		switch ( $to_state ) {
			case WP_MCP_AI_QMS_Doc_Record_CPT::STATUS_IN_REVIEW:
				$reviewers = (array) ( get_post_meta( $post_id, '_qms_reviewer_ids', true ) ? get_post_meta( $post_id, '_qms_reviewer_ids', true ) : array() );
				if ( empty( $reviewers ) ) {
					return new WP_Error(
						'wp_mcp_ai_qms_no_reviewers',
						__( 'At least one reviewer must be assigned before submitting for review.', 'mcp-ai-wpoos-pro' )
					);
				}
				break;
			case WP_MCP_AI_QMS_Doc_Record_CPT::STATUS_APPROVED:
				$approvers = (array) ( get_post_meta( $post_id, '_qms_approver_ids', true ) ? get_post_meta( $post_id, '_qms_approver_ids', true ) : array() );
				if ( empty( $approvers ) ) {
					return new WP_Error(
						'wp_mcp_ai_qms_no_approvers',
						__( 'At least one approver must be assigned before approval.', 'mcp-ai-wpoos-pro' )
					);
				}
				break;
			case WP_MCP_AI_QMS_Doc_Record_CPT::STATUS_RELEASED:
				$signatures       = (array) ( get_post_meta( $post_id, '_qms_signatures', true ) ? get_post_meta( $post_id, '_qms_signatures', true ) : array() );
				$has_approval_sig = false;
				foreach ( $signatures as $sig ) {
					if ( isset( $sig['intent'] ) && 'approved' === $sig['intent'] ) {
						$has_approval_sig = true;
						break;
					}
				}
				/**
				 * Filter whether release requires an approval e-signature.
				 *
				 * @since 1.2.0
				 *
				 * @param bool $require Default true.
				 * @param int  $post_id Record ID.
				 */
				if ( apply_filters( 'wp_mcp_ai_qms_require_release_signature', true, $post_id ) && ! $has_approval_sig ) {
					return new WP_Error(
						'wp_mcp_ai_qms_no_approval_signature',
						__( 'A signed approval is required before release.', 'mcp-ai-wpoos-pro' )
					);
				}
				break;
		}
		return true;
	}

	/**
	 * Append a signature to a record.
	 *
	 * @param int    $post_id    Record ID.
	 * @param string $intent     reviewed|approved|witnessed.
	 * @param int    $user_id    Signer user ID.
	 * @param string $password   The user's current WP password (re-prompt).
	 * @return true|WP_Error
	 */
	public static function sign( $post_id, $intent, $user_id, $password ) {
		$post = get_post( $post_id );
		if ( ! $post || WP_MCP_AI_QMS_Doc_Record_CPT::POST_TYPE !== $post->post_type ) {
			return new WP_Error( 'wp_mcp_ai_qms_invalid_record', __( 'Document record not found.', 'mcp-ai-wpoos-pro' ) );
		}

		$intent = sanitize_key( $intent );
		$valid  = array( 'reviewed', 'approved', 'witnessed' );
		if ( ! in_array( $intent, $valid, true ) ) {
			return new WP_Error( 'wp_mcp_ai_qms_invalid_intent', __( 'Invalid signature intent.', 'mcp-ai-wpoos-pro' ) );
		}

		$user = get_user_by( 'id', absint( $user_id ) );
		if ( ! $user ) {
			return new WP_Error( 'wp_mcp_ai_qms_invalid_user', __( 'Invalid signer.', 'mcp-ai-wpoos-pro' ) );
		}

		// Re-authenticate with the current password.
		$auth = wp_authenticate( $user->user_login, (string) $password );
		if ( is_wp_error( $auth ) || ! $auth || $auth->ID !== $user->ID ) {
			return new WP_Error( 'wp_mcp_ai_qms_auth_failed', __( 'Signature authentication failed.', 'mcp-ai-wpoos-pro' ) );
		}

		// Compute hash binding signature → document content.
		$content_hash      = WP_MCP_AI_QMS_Doc_Record_CPT::recompute_hash( $post_id );
		$timestamp         = current_time( 'mysql', true );
		$signature_payload = $intent . '|' . $user->ID . '|' . $timestamp . '|' . $content_hash;
		$signature_hash    = hash( 'sha256', $signature_payload );

		$ip = '';
		if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}

		$signature = array(
			'user_id'        => (int) $user->ID,
			'user_login'     => $user->user_login,
			'role'           => ! empty( $user->roles ) ? (string) $user->roles[0] : '',
			'intent'         => $intent,
			'timestamp'      => $timestamp,
			'ip'             => $ip,
			'content_hash'   => $content_hash,
			'signature_hash' => $signature_hash,
		);

		$existing   = (array) ( get_post_meta( $post_id, '_qms_signatures', true ) ? get_post_meta( $post_id, '_qms_signatures', true ) : array() );
		$existing[] = $signature;
		update_post_meta( $post_id, '_qms_signatures', $existing );

		WP_MCP_AI_QMS_Audit_Log::record(
			array(
				'subsystem'  => 'qms',
				'event'      => 'signed',
				'actor_id'   => (int) $user->ID,
				'post_id'    => (int) $post_id,
				'doc_id'     => (string) get_post_meta( $post_id, '_qms_document_id', true ),
				'revision'   => (string) get_post_meta( $post_id, '_qms_revision', true ),
				'after_hash' => $content_hash,
				'meta'       => array(
					'intent'         => $intent,
					'signature_hash' => $signature_hash,
				),
			)
		);

		/**
		 * Fires after a controlled document is signed.
		 *
		 * @since 1.2.0
		 *
		 * @param int   $post_id   Record ID.
		 * @param array $signature Signature record.
		 */
		do_action( 'wp_mcp_ai_qms_document_signed', (int) $post_id, $signature );

		return true;
	}
}
