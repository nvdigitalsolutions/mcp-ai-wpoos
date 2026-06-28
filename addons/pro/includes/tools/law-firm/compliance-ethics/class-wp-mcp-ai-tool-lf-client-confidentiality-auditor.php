<?php
/**
 * Client Confidentiality Auditor Tool
 *
 * Audits client confidentiality safeguards for legal matters per ABA Rule 1.6.
 *
 * @package WP_MCP_AI_Pro
 * @since 2.0.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Audits client confidentiality safeguards for compliance with ABA Rule 1.6.
 */
class WP_MCP_AI_Tool_LF_Client_Confidentiality_Auditor implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	const DISCLAIMER = 'This is not legal advice. Consult a licensed attorney for specific legal matters.';

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Check if the tool is available.
	 *
	 * @return bool
	 */
	public static function is_available(): bool {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_law_firm_toolkit'] );
	}

	/**
	 * Get the reason the tool is unavailable.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason(): string {
		return __( 'Law Firm toolkit is not enabled.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_slug() {
		return 'lf_client_confidentiality_auditor';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return __( 'Client Confidentiality Auditor', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return __( 'Audits client confidentiality safeguards across communications, documents, and access controls for a legal matter, checking compliance with ABA Model Rule 1.6.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'matter_id'   => array(
					'type'        => 'integer',
					'description' => __( 'Post ID of the legal matter to audit (mcp_ai_lf_matter CPT).', 'mcp-ai-wpoos-pro' ),
				),
				'audit_scope' => array(
					'type'        => 'string',
					'description' => __( 'Scope of the confidentiality audit.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'communications', 'documents', 'access_controls', 'all' ),
					'default'     => 'all',
				),
			),
			'required'   => array(),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_capability_flags(): array {
		return array( 'pro', 'read-only' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$uid = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $uid || ! user_can( $uid, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( ! self::is_available() ) {
			return new WP_Error( 'tool_not_available', self::get_unavailable_reason() );
		}

		$matter_id   = isset( $arguments['matter_id'] ) ? absint( $arguments['matter_id'] ) : 0;
		$audit_scope = isset( $arguments['audit_scope'] ) ? sanitize_text_field( $arguments['audit_scope'] ) : 'all';

		$valid_scopes = array( 'communications', 'documents', 'access_controls', 'all' );
		if ( ! in_array( $audit_scope, $valid_scopes, true ) ) {
			$audit_scope = 'all';
		}

		$audit_results    = array();
		$issues_found     = 0;
		$total_checks     = 0;
		$compliance_notes = array();

		// If a specific matter is provided, audit it.
		if ( $matter_id > 0 ) {
			$matter = get_post( $matter_id );
			if ( ! $matter || 'mcp_ai_lf_matter' !== $matter->post_type ) {
				return new WP_Error( 'invalid_matter', __( 'Matter not found or invalid post type.', 'mcp-ai-wpoos-pro' ) );
			}
		}

		// Communications audit.
		if ( 'all' === $audit_scope || 'communications' === $audit_scope ) {
			$comm_results                    = $this->audit_communications( $matter_id );
			$audit_results['communications'] = $comm_results;
			$issues_found                   += $comm_results['issues_count'];
			$total_checks                   += $comm_results['checks_performed'];
		}

		// Documents audit.
		if ( 'all' === $audit_scope || 'documents' === $audit_scope ) {
			$doc_results                = $this->audit_documents( $matter_id );
			$audit_results['documents'] = $doc_results;
			$issues_found              += $doc_results['issues_count'];
			$total_checks              += $doc_results['checks_performed'];
		}

		// Access controls audit.
		if ( 'all' === $audit_scope || 'access_controls' === $audit_scope ) {
			$access_results                   = $this->audit_access_controls( $matter_id );
			$audit_results['access_controls'] = $access_results;
			$issues_found                    += $access_results['issues_count'];
			$total_checks                    += $access_results['checks_performed'];
		}

		// Determine overall risk level.
		$issue_ratio = $total_checks > 0 ? ( $issues_found / $total_checks ) : 0;
		if ( $issue_ratio >= 0.5 ) {
			$risk_level = 'high';
		} elseif ( $issue_ratio >= 0.2 ) {
			$risk_level = 'medium';
		} else {
			$risk_level = 'low';
		}

		// ABA Rule 1.6 compliance notes.
		$compliance_notes[] = array(
			'rule'    => 'ABA Model Rule 1.6(a)',
			'summary' => __( 'A lawyer shall not reveal information relating to the representation of a client unless the client gives informed consent, the disclosure is impliedly authorized, or permitted by paragraph (b).', 'mcp-ai-wpoos-pro' ),
			'status'  => 0 === $issues_found ? 'compliant' : 'review_needed',
		);
		$compliance_notes[] = array(
			'rule'    => 'ABA Model Rule 1.6(c)',
			'summary' => __( 'A lawyer shall make reasonable efforts to prevent the inadvertent or unauthorized disclosure of, or unauthorized access to, information relating to the representation of a client.', 'mcp-ai-wpoos-pro' ),
			'status'  => $issues_found > 3 ? 'at_risk' : ( $issues_found > 0 ? 'review_needed' : 'compliant' ),
		);
		$compliance_notes[] = array(
			'rule'    => 'Comment [18] to Rule 1.6',
			'summary' => __( 'Factors to consider include sensitivity of information, likelihood of disclosure, cost of safeguards, difficulty of implementation, and impact on the lawyer\'s ability to represent clients.', 'mcp-ai-wpoos-pro' ),
			'status'  => 'informational',
		);

		$matter_title = $matter_id ? get_the_title( $matter_id ) : __( 'General Audit', 'mcp-ai-wpoos-pro' );

		return array(
			'success'    => true,
			'message'    => sprintf(
				/* translators: 1: checks count, 2: issues count, 3: risk level */
				__( 'Confidentiality audit complete: %1$d checks performed, %2$d issues found (%3$s risk).', 'mcp-ai-wpoos-pro' ),
				$total_checks,
				$issues_found,
				$risk_level
			) . ' ' . self::DISCLAIMER,
			'data'       => array(
				'matter_id'        => $matter_id,
				'matter_title'     => $matter_title,
				'audit_scope'      => $audit_scope,
				'risk_level'       => $risk_level,
				'total_checks'     => $total_checks,
				'issues_found'     => $issues_found,
				'audit_results'    => $audit_results,
				'compliance_notes' => $compliance_notes,
			),
			'disclaimer' => self::DISCLAIMER,
		);
	}

	/**
	 * Audit communications for confidentiality risks.
	 *
	 * @param int $matter_id Matter post ID (0 for general audit).
	 * @return array
	 */
	private function audit_communications( $matter_id ) {
		$checks_performed = 0;
		$issues           = array();

		// Check 1: Encrypted email usage.
		++$checks_performed;
		$email_encryption = $matter_id
			? get_post_meta( $matter_id, '_lf_email_encryption_enabled', true )
			: get_option( 'wp_mcp_ai_lf_email_encryption', '' );
		if ( empty( $email_encryption ) || 'no' === $email_encryption ) {
			$issues[] = array(
				'check'          => 'email_encryption',
				'severity'       => 'high',
				'description'    => __( 'Email encryption is not configured for client communications.', 'mcp-ai-wpoos-pro' ),
				'recommendation' => __( 'Enable TLS encryption for all client emails and consider end-to-end encryption for sensitive matters.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Check 2: Communication disclaimer presence.
		++$checks_performed;
		$disclaimer_configured = get_option( 'wp_mcp_ai_lf_email_disclaimer', '' );
		if ( empty( $disclaimer_configured ) ) {
			$issues[] = array(
				'check'          => 'email_disclaimer',
				'severity'       => 'medium',
				'description'    => __( 'No confidentiality disclaimer configured for outgoing email.', 'mcp-ai-wpoos-pro' ),
				'recommendation' => __( 'Add a confidentiality notice to all outgoing email communications.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Check 3: Client communication consent.
		++$checks_performed;
		if ( $matter_id ) {
			$comm_consent = get_post_meta( $matter_id, '_lf_communication_consent', true );
			if ( empty( $comm_consent ) ) {
				$issues[] = array(
					'check'          => 'communication_consent',
					'severity'       => 'medium',
					'description'    => __( 'No documented client consent for preferred communication methods.', 'mcp-ai-wpoos-pro' ),
					'recommendation' => __( 'Obtain and document client consent for communication methods and channels used.', 'mcp-ai-wpoos-pro' ),
				);
			}
		}

		// Check 4: Unencrypted messaging platforms.
		++$checks_performed;
		if ( $matter_id ) {
			$comm_channels = get_post_meta( $matter_id, '_lf_communication_channels', true );
			if ( is_array( $comm_channels ) ) {
				$insecure = array_intersect( $comm_channels, array( 'sms', 'social_media', 'unencrypted_chat' ) );
				if ( ! empty( $insecure ) ) {
					$issues[] = array(
						'check'          => 'insecure_channels',
						'severity'       => 'high',
						'description'    => sprintf(
							/* translators: %s: channel names */
							__( 'Potentially insecure communication channels in use: %s.', 'mcp-ai-wpoos-pro' ),
							implode( ', ', $insecure )
						),
						'recommendation' => __( 'Migrate sensitive communications to encrypted channels. If insecure channels must be used, obtain informed client consent.', 'mcp-ai-wpoos-pro' ),
					);
				}
			}
		}

		return array(
			'scope'            => 'communications',
			'checks_performed' => $checks_performed,
			'issues_count'     => count( $issues ),
			'issues'           => $issues,
		);
	}

	/**
	 * Audit documents for confidentiality risks.
	 *
	 * @param int $matter_id Matter post ID (0 for general audit).
	 * @return array
	 */
	private function audit_documents( $matter_id ) {
		$checks_performed = 0;
		$issues           = array();

		// Check 1: Document storage security.
		++$checks_performed;
		$storage_encrypted = get_option( 'wp_mcp_ai_lf_storage_encryption', '' );
		if ( empty( $storage_encrypted ) || 'no' === $storage_encrypted ) {
			$issues[] = array(
				'check'          => 'storage_encryption',
				'severity'       => 'high',
				'description'    => __( 'Document storage encryption is not confirmed.', 'mcp-ai-wpoos-pro' ),
				'recommendation' => __( 'Ensure all client documents are stored with encryption at rest.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Check 2: Document retention policy.
		++$checks_performed;
		$retention_policy = get_option( 'wp_mcp_ai_lf_retention_policy', '' );
		if ( empty( $retention_policy ) ) {
			$issues[] = array(
				'check'          => 'retention_policy',
				'severity'       => 'medium',
				'description'    => __( 'No document retention policy is configured.', 'mcp-ai-wpoos-pro' ),
				'recommendation' => __( 'Establish and document a retention policy for client files and communications.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Check 3: Matter-specific document labeling.
		++$checks_performed;
		if ( $matter_id ) {
			$confidential_label = get_post_meta( $matter_id, '_lf_confidentiality_label', true );
			if ( empty( $confidential_label ) ) {
				$issues[] = array(
					'check'          => 'confidentiality_label',
					'severity'       => 'low',
					'description'    => __( 'Matter documents lack confidentiality classification labels.', 'mcp-ai-wpoos-pro' ),
					'recommendation' => __( 'Apply confidentiality classification labels (e.g., Confidential, Privileged, Work Product) to matter documents.', 'mcp-ai-wpoos-pro' ),
				);
			}
		}

		// Check 4: Backup procedures.
		++$checks_performed;
		$backup_configured = get_option( 'wp_mcp_ai_lf_backup_encryption', '' );
		if ( empty( $backup_configured ) ) {
			$issues[] = array(
				'check'          => 'backup_encryption',
				'severity'       => 'medium',
				'description'    => __( 'Encrypted backup procedures are not confirmed.', 'mcp-ai-wpoos-pro' ),
				'recommendation' => __( 'Ensure all backups containing client data are encrypted and stored securely.', 'mcp-ai-wpoos-pro' ),
			);
		}

		return array(
			'scope'            => 'documents',
			'checks_performed' => $checks_performed,
			'issues_count'     => count( $issues ),
			'issues'           => $issues,
		);
	}

	/**
	 * Audit access controls for confidentiality risks.
	 *
	 * @param int $matter_id Matter post ID (0 for general audit).
	 * @return array
	 */
	private function audit_access_controls( $matter_id ) {
		$checks_performed = 0;
		$issues           = array();

		// Check 1: Role-based access controls.
		++$checks_performed;
		if ( $matter_id ) {
			$access_list = get_post_meta( $matter_id, '_lf_access_list', true );
			if ( empty( $access_list ) || ! is_array( $access_list ) ) {
				$issues[] = array(
					'check'          => 'access_list',
					'severity'       => 'high',
					'description'    => __( 'No explicit access control list defined for this matter.', 'mcp-ai-wpoos-pro' ),
					'recommendation' => __( 'Define and maintain an explicit list of personnel authorized to access this matter\'s information.', 'mcp-ai-wpoos-pro' ),
				);
			}
		}

		// Check 2: Two-factor authentication.
		++$checks_performed;
		$tfa_enabled = get_option( 'wp_mcp_ai_lf_2fa_required', '' );
		if ( empty( $tfa_enabled ) || 'no' === $tfa_enabled ) {
			$issues[] = array(
				'check'          => 'two_factor_auth',
				'severity'       => 'high',
				'description'    => __( 'Two-factor authentication is not confirmed as mandatory for system access.', 'mcp-ai-wpoos-pro' ),
				'recommendation' => __( 'Require two-factor authentication for all personnel accessing client data.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Check 3: Session management.
		++$checks_performed;
		$session_timeout = get_option( 'wp_mcp_ai_lf_session_timeout', 0 );
		if ( empty( $session_timeout ) || absint( $session_timeout ) > 480 ) {
			$issues[] = array(
				'check'          => 'session_timeout',
				'severity'       => 'medium',
				'description'    => __( 'Session timeout is not configured or exceeds 8 hours.', 'mcp-ai-wpoos-pro' ),
				'recommendation' => __( 'Configure automatic session timeout to prevent unauthorized access from unattended workstations.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Check 4: Departed personnel access review.
		++$checks_performed;
		$last_access_review = get_option( 'wp_mcp_ai_lf_last_access_review', '' );
		if ( empty( $last_access_review ) || ( time() - strtotime( $last_access_review ) ) > 90 * DAY_IN_SECONDS ) {
			$issues[] = array(
				'check'          => 'access_review',
				'severity'       => 'medium',
				'description'    => __( 'Access control review has not been performed in the last 90 days.', 'mcp-ai-wpoos-pro' ),
				'recommendation' => __( 'Conduct quarterly access reviews to ensure departed personnel have been removed and access remains appropriate.', 'mcp-ai-wpoos-pro' ),
			);
		}

		return array(
			'scope'            => 'access_controls',
			'checks_performed' => $checks_performed,
			'issues_count'     => count( $issues ),
			'issues'           => $issues,
		);
	}
}
