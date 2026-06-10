<?php
/**
 * Classify Email Hygiene Tool — Analyses inbound emails for spam,
 * promotional/newsletter content, and priority signals.
 *
 * Enhances the existing heuristic classifier with email-specific
 * hygiene detection:
 *   - Spam detection (keyword + pattern heuristics)
 *   - Promotional/newsletter detection (bulk mail markers, unsubscribe links)
 *   - Priority detection (VIP domains, existing customers, high-intent)
 *   - Notification detection (automated alerts, receipts, confirmations)
 *
 * Returns a hygiene score (0–100) and actionable classification
 * so the import pipeline can auto-filter or auto-prioritise.
 *
 * ═══════════════════════════════════════════════════════════════════════
 * INDUSTRY STANDARDS REFERENCED
 * ═══════════════════════════════════════════════════════════════════════
 *
 *   Gmail category system: Primary, Promotions, Social, Updates, Forums
 *   (https://support.google.com/mail/answer/3094593)
 *
 *   CAN-SPAM Act (2003): commercial email must include unsubscribe
 *   mechanism and physical address — both are strong promotional signals.
 *
 *   Google Postmaster Tools / Yahoo Sender Hub (2024-2025):
 *   list hygiene (removing unengaged after 90-180 days) is the #1
 *   deliverability factor. Spam rate must stay < 0.1%.
 *
 *   Microsoft 2024 anti-spam update: AI-based detection models now
 *   penalize bulk-sender patterns regardless of content.
 *
 * @package   WP_MCP_AI_Pro
 * @subpackage CRM_Toolkit
 * @since     2.8.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Classifies emails for hygiene: spam, promotional, priority, notification.
 *
 * @since 2.8.0
 */
class WP_MCP_AI_Tool_Classify_Email_Hygiene implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * Determine whether the CRM toolkit is enabled.
	 *
	 * @since 2.8.0
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
		return __( 'CRM Toolkit required.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'classify_email_hygiene';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Classify Email Hygiene', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Analyse an email for spam, promotional/newsletter content, priority signals, and automated notifications. Returns a hygiene score (0–100) and classification tags to help the import pipeline auto-filter noise and auto-prioritise important mail.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'subject'            => array(
					'type'        => 'string',
					'description' => __( 'Email subject line.', 'mcp-ai-wpoos-pro' ),
				),
				'body'               => array(
					'type'        => 'string',
					'description' => __( 'Email body text (plain text or stripped HTML).', 'mcp-ai-wpoos-pro' ),
				),
				'from_email'         => array(
					'type'        => 'string',
					'description' => __( 'Sender email address for domain-based filtering.', 'mcp-ai-wpoos-pro' ),
				),
				'from_name'          => array(
					'type'        => 'string',
					'description' => __( 'Sender display name.', 'mcp-ai-wpoos-pro' ),
				),
				'gmail_labels'       => array(
					'type'        => 'array',
					'description' => __( 'Gmail label/category IDs if available (CATEGORY_PROMOTIONS, CATEGORY_SOCIAL, etc.).', 'mcp-ai-wpoos-pro' ),
					'items'       => array( 'type' => 'string' ),
				),
				'headers'            => array(
					'type'        => 'object',
					'description' => __( 'Email headers for additional signals: List-Unsubscribe, Precedence, X-Mailer, etc.', 'mcp-ai-wpoos-pro' ),
				),
				'contact_is_customer' => array(
					'type'        => 'boolean',
					'description' => __( 'Whether the sender is already a known customer in the CRM.', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
			),
			'required'             => array( 'body' ),
			'additionalProperties' => false,
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
	 * Get extended tool definition including toolkit metadata.
	 *
	 * @since 2.8.0
	 * @return array
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'crm',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'profession_tags'       => array( 'sales_manager', 'sdr', 'crm_viewer' ),
			'risk_level'            => 'standard',
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		if ( ! self::is_available() ) {
			return new WP_Error( 'unavailable', self::get_unavailable_reason(), array( 'status' => 403 ) );
		}

		// --- Gate 1: Sanitise at entry ---

		$subject            = isset( $arguments['subject'] ) ? sanitize_text_field( $arguments['subject'] ) : '';
		$body               = isset( $arguments['body'] ) ? sanitize_textarea_field( $arguments['body'] ) : '';
		$from_email         = isset( $arguments['from_email'] ) ? sanitize_email( $arguments['from_email'] ) : '';
		$from_name          = isset( $arguments['from_name'] ) ? sanitize_text_field( $arguments['from_name'] ) : '';
		$gmail_labels       = isset( $arguments['gmail_labels'] ) ? (array) $arguments['gmail_labels'] : array();
		$headers            = isset( $arguments['headers'] ) ? (array) $arguments['headers'] : array();
		$contact_is_customer = ! empty( $arguments['contact_is_customer'] );

		if ( empty( $body ) ) {
			return new WP_Error(
				'empty_body',
				__( 'Email body is required for hygiene classification.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		$lower_body    = mb_strtolower( $body );
		$lower_subject = mb_strtolower( $subject );
		$combined      = $lower_subject . ' ' . $lower_body;
		$from_domain   = $from_email ? strtolower( substr( strrchr( $from_email, '@' ), 1 ) ) : '';

		// Load hygiene settings.
		$hygiene = class_exists( 'WP_MCP_AI_CRM_Engine' )
			? WP_MCP_AI_CRM_Engine::get_hygiene_settings()
			: array();

		// --- Detection Phase 1: Gmail category signals ---
		$gmail_signals = $this->detect_gmail_categories( $gmail_labels );

		// --- Detection Phase 2: Spam detection ---
		$spam_result = $this->detect_spam( $combined, $from_domain, $hygiene );

		// --- Detection Phase 3: Promotional / newsletter detection ---
		$promo_result = $this->detect_promotional( $combined, $from_domain, $headers, $hygiene );

		// --- Detection Phase 4: Notification detection ---
		$notif_result = $this->detect_notification( $combined, $from_domain );

		// --- Detection Phase 5: Priority detection ---
		$priority_result = $this->detect_priority( $combined, $from_domain, $from_email, $contact_is_customer, $hygiene );

		// --- Check exclude/priority lists ---
		$is_excluded = $this->is_in_exclude_list( $from_email, $from_domain, $hygiene );
		$is_priority = $this->is_in_priority_list( $from_email, $from_domain, $hygiene );

		if ( $is_priority ) {
			$priority_result['is_priority'] = true;
			$priority_result['reason'][]    = 'domain_or_email_in_priority_list';
		}

		// --- Master classification ---
		if ( $is_excluded ) {
			$hygiene_class = 'excluded';
		} elseif ( $spam_result['is_spam'] ) {
			$hygiene_class = 'spam';
		} elseif ( $promo_result['is_promotional'] || $gmail_signals['is_promotions'] ) {
			$hygiene_class = 'promotional';
		} elseif ( $gmail_signals['is_social'] || $gmail_signals['is_forums'] ) {
			$hygiene_class = 'social_noise';
		} elseif ( $notif_result['is_notification'] ) {
			$hygiene_class = 'notification';
		} elseif ( $priority_result['is_priority'] ) {
			$hygiene_class = 'priority';
		} else {
			$hygiene_class = 'normal';
		}

		// --- Hygiene score (0–100, higher = better/cleaner) ---
		$hygiene_score = $this->calculate_hygiene_score(
			$spam_result,
			$promo_result,
			$notif_result,
			$priority_result,
			$gmail_signals,
			$is_excluded,
			$is_priority
		);

		// --- Recommended action ---
		$action = $this->recommend_action( $hygiene_class, $hygiene_score, $is_excluded );

		$result = array(
			'hygiene_class'      => $hygiene_class,
			'hygiene_score'      => $hygiene_score,
			'recommended_action' => $action,
			'is_spam'            => $spam_result['is_spam'],
			'spam_probability'   => $spam_result['probability'],
			'spam_reasons'       => $spam_result['reasons'],
			'is_promotional'     => $promo_result['is_promotional'],
			'promo_probability'  => $promo_result['probability'],
			'promo_reasons'      => $promo_result['reasons'],
			'is_notification'    => $notif_result['is_notification'],
			'notif_probability'  => $notif_result['probability'],
			'is_priority'        => $priority_result['is_priority'],
			'priority_reasons'   => $priority_result['reason'],
			'is_excluded'        => $is_excluded,
			'gmail_signals'      => $gmail_signals,
			'from_domain'        => $from_domain,
			'classification'     => array(
				'intent'            => $hygiene_class,
				'intent_confidence' => round( max(
					$spam_result['probability'],
					$promo_result['probability'],
					$notif_result['probability'],
					$is_priority ? 0.95 : 0.5
				), 2 ),
				'sentiment'         => 'neutral',
			),
		);

		// --- Gate 2: Escape at exit ---
		return $this->format_success_response(
			sprintf(
				/* translators: %s: hygiene classification */
				__( 'Email classified as %s.', 'mcp-ai-wpoos-pro' ),
				$hygiene_class
			),
			$result
		);
	}

	/**
	 * Detect Gmail category signals from label IDs.
	 *
	 * @param array $labels Gmail label IDs.
	 * @return array
	 */
	private function detect_gmail_categories( array $labels ) {
		$result = array(
			'is_promotions' => false,
			'is_social'     => false,
			'is_updates'    => false,
			'is_forums'     => false,
		);

		$lower_labels = array_map( 'strtolower', $labels );
		$all_labels   = implode( ' ', $lower_labels );

		if ( false !== strpos( $all_labels, 'category_promotions' ) ) {
			$result['is_promotions'] = true;
		}
		if ( false !== strpos( $all_labels, 'category_social' ) ) {
			$result['is_social'] = true;
		}
		if ( false !== strpos( $all_labels, 'category_updates' ) ) {
			$result['is_updates'] = true;
		}
		if ( false !== strpos( $all_labels, 'category_forums' ) ) {
			$result['is_forums'] = true;
		}

		return $result;
	}

	/**
	 * Detect spam signals.
	 *
	 * @param string $combined  Lowercase subject + body.
	 * @param string $from_domain Sender domain.
	 * @param array  $hygiene   Hygiene settings.
	 * @return array
	 */
	private function detect_spam( $combined, $from_domain, array $hygiene ) {
		$reasons     = array();
		$probability = 0;
		$is_spam     = false;

		// Hard spam keywords (high-confidence).
		$hard_spam = array(
			'viagra', 'cialis', 'casino', 'lottery', 'you won',
			'nigerian prince', 'wire transfer', 'click here to claim',
			'work from home earn', 'make money fast', 'guaranteed income',
			'seo services', 'buy now', 'limited offer', 'act now',
			'congratulations you have been selected',
		);

		$hard_hits = 0;
		foreach ( $hard_spam as $kw ) {
			if ( false !== strpos( $combined, $kw ) ) {
				++$hard_hits;
				$reasons[] = 'hard_spam_keyword:' . $kw;
			}
		}

		if ( $hard_hits >= 2 ) {
			$is_spam      = true;
			$probability  = 0.95;
		} elseif ( 1 === $hard_hits ) {
			$probability = 0.60;
		}

		// Phishing patterns.
		$phish_patterns = array(
			'verify your account', 'confirm your identity', 'suspended',
			'security alert', 'unusual activity', 'login attempt',
			'urgent action required', 'your account will be',
			'click the link below to restore',
		);

		$phish_hits = 0;
		foreach ( $phish_patterns as $pattern ) {
			if ( false !== strpos( $combined, $pattern ) ) {
				++$phish_hits;
				$reasons[] = 'phishing_pattern:' . $pattern;
			}
		}

		if ( $phish_hits >= 2 ) {
			$is_spam     = true;
			$probability = max( $probability, 0.90 );
		} elseif ( 1 === $phish_hits ) {
			$probability = max( $probability, 0.55 );
		}

		// Known spam domains (configurable).
		$spam_domains = isset( $hygiene['spam_domains'] ) ? (array) $hygiene['spam_domains'] : array();
		if ( ! empty( $from_domain ) && ! empty( $spam_domains ) ) {
			foreach ( $spam_domains as $spam_domain ) {
				$spam_domain = trim( strtolower( $spam_domain ) );
				if ( '' !== $spam_domain && false !== strpos( $from_domain, $spam_domain ) ) {
					$is_spam     = true;
					$probability = max( $probability, 0.95 );
					$reasons[]   = 'spam_domain:' . $spam_domain;
					break;
				}
			}
		}

		return array(
			'is_spam'     => $is_spam,
			'probability' => $probability,
			'reasons'     => $reasons,
		);
	}

	/**
	 * Detect promotional / newsletter content.
	 *
	 * @param string $combined     Lowercase subject + body.
	 * @param string $from_domain  Sender domain.
	 * @param array  $headers      Email headers.
	 * @param array  $hygiene      Hygiene settings.
	 * @return array
	 */
	private function detect_promotional( $combined, $from_domain, array $headers, array $hygiene ) {
		$reasons     = array();
		$probability = 0;
		$is_promotional = false;

		// List-Unsubscribe header (strongest promotional signal per CAN-SPAM).
		$has_unsubscribe_header = false;
		if ( ! empty( $headers ) ) {
			$lower_headers = array_change_key_case( $headers, CASE_LOWER );
			if ( isset( $lower_headers['list-unsubscribe'] ) && ! empty( $lower_headers['list-unsubscribe'] ) ) {
				$has_unsubscribe_header = true;
				$reasons[]  = 'list_unsubscribe_header';
				$probability += 0.30;
			}

			// Precedence: bulk header.
			if ( isset( $lower_headers['precedence'] ) && 'bulk' === strtolower( trim( $lower_headers['precedence'] ) ) ) {
				$reasons[]  = 'precedence_bulk';
				$probability += 0.25;
			}

			// X-Mailer with known bulk senders.
			if ( isset( $lower_headers['x-mailer'] ) ) {
				$mailer = strtolower( $lower_headers['x-mailer'] );
				$bulk_mailers = array( 'mailchimp', 'constantcontact', 'sendgrid', 'mailgun', 'campaign', 'aweber', 'getresponse', 'activecampaign' );
				foreach ( $bulk_mailers as $bm ) {
					if ( false !== strpos( $mailer, $bm ) ) {
						$reasons[]  = 'bulk_mailer:' . $bm;
						$probability += 0.20;
						break;
					}
				}
			}
		}

		// Unsubscribe links in body.
		$unsub_patterns = array(
			'unsubscribe', 'opt-out', 'opt out', 'manage your preferences',
			'update your email preferences', 'email preferences',
			'you are receiving this email because', 'if you no longer wish',
			'to stop receiving', 'received this email',
		);

		$unsub_hits = 0;
		foreach ( $unsub_patterns as $pattern ) {
			if ( false !== strpos( $combined, $pattern ) ) {
				++$unsub_hits;
			}
		}

		if ( $unsub_hits >= 2 ) {
			$reasons[]  = 'unsubscribe_language';
			$probability += 0.25;
		} elseif ( 1 === $unsub_hits ) {
			$probability += 0.15;
		}

		// Promotional language.
		$promo_keywords = array(
			'sale', 'discount', 'offer ends', 'save', 'free shipping',
			'shop now', 'buy one get', 'clearance', 'coupon code',
			'promo code', 'exclusive deal', 'flash sale', 'limited time',
			'special offer', 'new arrivals', 'best sellers',
			'newsletter', 'weekly digest', 'monthly roundup',
			'this week in', 'top stories', 'latest updates',
		);

		// Configurable promotional keywords from settings.
		$custom_promo = isset( $hygiene['promotional_keywords'] ) ? (array) $hygiene['promotional_keywords'] : array();
		$all_promo    = array_merge( $promo_keywords, $custom_promo );

		$promo_hits = 0;
		foreach ( $all_promo as $kw ) {
			if ( false !== strpos( $combined, strtolower( trim( $kw ) ) ) ) {
				++$promo_hits;
			}
		}

		if ( $promo_hits >= 3 ) {
			$reasons[]  = 'promotional_language';
			$probability += 0.30;
			$is_promotional = true;
		} elseif ( $promo_hits >= 1 ) {
			$probability += 0.15;
		}

		// "View in browser" link — strong newsletter signal.
		if ( false !== strpos( $combined, 'view in browser' ) || false !== strpos( $combined, 'view this email in' ) ) {
			$reasons[]  = 'view_in_browser';
			$probability += 0.20;
		}

		// Physical address in footer (CAN-SPAM compliance signal).
		if ( preg_match( '/\d+\s+[\w\s]+\s+(street|st|avenue|ave|road|rd|blvd|drive|dr|lane|ln|way|place|pl|court|ct)/i', $combined ) ) {
			$reasons[]  = 'physical_address';
			$probability += 0.10;
		}

		// Known promotional domains.
		$promo_domains = isset( $hygiene['promotional_domains'] ) ? (array) $hygiene['promotional_domains'] : array();
		if ( ! empty( $from_domain ) && ! empty( $promo_domains ) ) {
			foreach ( $promo_domains as $pd ) {
				$pd = trim( strtolower( $pd ) );
				if ( '' !== $pd && false !== strpos( $from_domain, $pd ) ) {
					$reasons[]  = 'promotional_domain:' . $pd;
					$probability += 0.35;
					$is_promotional = true;
					break;
				}
			}
		}

		$probability = min( 1.0, $probability );

		if ( $probability >= 0.45 ) {
			$is_promotional = true;
		}

		return array(
			'is_promotional' => $is_promotional,
			'probability'    => round( $probability, 2 ),
			'reasons'        => $reasons,
		);
	}

	/**
	 * Detect automated notifications (receipts, confirmations, alerts).
	 *
	 * @param string $combined    Lowercase subject + body.
	 * @param string $from_domain Sender domain.
	 * @return array
	 */
	private function detect_notification( $combined, $from_domain ) {
		$reasons     = array();
		$probability = 0;
		$is_notif    = false;

		$notif_patterns = array(
			'order confirmation', 'order confirmed', 'your order',
			'receipt', 'invoice', 'payment received',
			'shipping confirmation', 'your package', 'tracking number',
			'account statement', 'your bill', 'payment due',
			'password reset', 'password changed',
			'new sign-in', 'new login', 'logged in',
			'delivery notification', 'delivery status',
			'automatic reply', 'out of office', 'vacation',
			'do not reply', 'do-not-reply', 'noreply@', 'no-reply@',
		);

		$hits = 0;
		foreach ( $notif_patterns as $pattern ) {
			if ( false !== strpos( $combined, $pattern ) ) {
				++$hits;
				$reasons[] = 'notification_pattern:' . $pattern;
			}
		}

		if ( $hits >= 2 ) {
			$is_notif    = true;
			$probability = 0.85;
		} elseif ( 1 === $hits ) {
			$probability = 0.50;
		}

		// No-reply sender address.
		if ( false !== strpos( $from_domain, 'noreply' ) || false !== strpos( $from_domain, 'no-reply' ) ) {
			$reasons[] = 'noreply_domain';
			$probability += 0.30;
		}

		$probability = min( 1.0, $probability );

		if ( $probability >= 0.50 ) {
			$is_notif = true;
		}

		return array(
			'is_notification' => $is_notif,
			'probability'     => round( $probability, 2 ),
			'reasons'         => $reasons,
		);
	}

	/**
	 * Detect priority signals (VIP, high-intent, existing customers).
	 *
	 * @param string $combined            Lowercase subject + body.
	 * @param string $from_domain         Sender domain.
	 * @param string $from_email          Sender email.
	 * @param bool   $contact_is_customer Whether sender is a known customer.
	 * @param array  $hygiene             Hygiene settings.
	 * @return array
	 */
	private function detect_priority( $combined, $from_domain, $from_email, $contact_is_customer, array $hygiene ) {
		$reasons  = array();
		$is_priority = false;

		// Existing customer always priority.
		if ( $contact_is_customer ) {
			$is_priority = true;
			$reasons[]   = 'existing_customer';
		}

		// High-intent keywords.
		$high_intent = array(
			'demo', 'pricing', 'quote', 'proposal', 'contract',
			'urgent', 'asap', 'immediately', 'today',
			'sign up', 'get started', 'trial', 'purchase',
		);

		foreach ( $high_intent as $kw ) {
			if ( false !== strpos( $combined, $kw ) ) {
				$is_priority = true;
				$reasons[]   = 'high_intent:' . $kw;
				break;
			}
		}

		// Priority domains from settings.
		$priority_domains = isset( $hygiene['priority_domains'] ) ? (array) $hygiene['priority_domains'] : array();
		if ( ! empty( $from_domain ) && ! empty( $priority_domains ) ) {
			foreach ( $priority_domains as $pd ) {
				$pd = trim( strtolower( $pd ) );
				if ( '' !== $pd && false !== strpos( $from_domain, $pd ) ) {
					$is_priority = true;
					$reasons[]   = 'priority_domain:' . $pd;
					break;
				}
			}
		}

		return array(
			'is_priority' => $is_priority,
			'reason'      => $reasons,
		);
	}

	/**
	 * Check if sender is in the exclude list.
	 *
	 * @param string $from_email  Sender email.
	 * @param string $from_domain Sender domain.
	 * @param array  $hygiene     Hygiene settings.
	 * @return bool
	 */
	private function is_in_exclude_list( $from_email, $from_domain, array $hygiene ) {
		$exclude_list = isset( $hygiene['exclude_list'] ) ? (array) $hygiene['exclude_list'] : array();

		if ( empty( $exclude_list ) ) {
			return false;
		}

		$from_email_lower = strtolower( trim( $from_email ) );
		$from_domain_lower = strtolower( trim( $from_domain ) );

		foreach ( $exclude_list as $entry ) {
			$entry = strtolower( trim( $entry ) );
			if ( '' === $entry ) {
				continue;
			}

			// Exact email match.
			if ( $from_email_lower === $entry ) {
				return true;
			}

			// Domain match (entry starts with @).
			if ( 0 === strpos( $entry, '@' ) ) {
				$domain = substr( $entry, 1 );
				if ( $from_domain_lower === $domain || false !== strpos( $from_domain_lower, '.' . $domain ) ) {
					return true;
				}
			}

			// Substring match for domain patterns.
			if ( '' !== $from_domain_lower && false !== strpos( $from_domain_lower, $entry ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if sender is in the priority list.
	 *
	 * @param string $from_email  Sender email.
	 * @param string $from_domain Sender domain.
	 * @param array  $hygiene     Hygiene settings.
	 * @return bool
	 */
	private function is_in_priority_list( $from_email, $from_domain, array $hygiene ) {
		$priority_list = isset( $hygiene['priority_list'] ) ? (array) $hygiene['priority_list'] : array();

		if ( empty( $priority_list ) ) {
			return false;
		}

		$from_email_lower  = strtolower( trim( $from_email ) );
		$from_domain_lower = strtolower( trim( $from_domain ) );

		foreach ( $priority_list as $entry ) {
			$entry = strtolower( trim( $entry ) );
			if ( '' === $entry ) {
				continue;
			}

			if ( $from_email_lower === $entry ) {
				return true;
			}

			if ( 0 === strpos( $entry, '@' ) ) {
				$domain = substr( $entry, 1 );
				if ( $from_domain_lower === $domain || false !== strpos( $from_domain_lower, '.' . $domain ) ) {
					return true;
				}
			}

			if ( '' !== $from_domain_lower && false !== strpos( $from_domain_lower, $entry ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Calculate hygiene score (0–100, higher = cleaner/better).
	 *
	 * @param array $spam_result     Spam detection result.
	 * @param array $promo_result    Promotional detection result.
	 * @param array $notif_result    Notification detection result.
	 * @param array $priority_result Priority detection result.
	 * @param array $gmail_signals   Gmail category signals.
	 * @param bool  $is_excluded     Whether in exclude list.
	 * @param bool  $is_priority     Whether in priority list.
	 * @return int
	 */
	private function calculate_hygiene_score( $spam_result, $promo_result, $notif_result, $priority_result, $gmail_signals, $is_excluded, $is_priority ) {
		$score = 100;

		// Spam penalty: -50 to -100.
		if ( $spam_result['is_spam'] ) {
			$score -= (int) round( $spam_result['probability'] * 80 );
		} else {
			$score -= (int) round( $spam_result['probability'] * 20 );
		}

		// Promotional penalty: -20 to -50.
		if ( $promo_result['is_promotional'] ) {
			$score -= (int) round( $promo_result['probability'] * 40 );
		} else {
			$score -= (int) round( $promo_result['probability'] * 15 );
		}

		// Notification penalty: -5 to -20.
		if ( $notif_result['is_notification'] ) {
			$score -= (int) round( $notif_result['probability'] * 15 );
		}

		// Gmail category penalties.
		if ( $gmail_signals['is_promotions'] ) {
			$score -= 25;
		}
		if ( $gmail_signals['is_social'] ) {
			$score -= 15;
		}
		if ( $gmail_signals['is_forums'] ) {
			$score -= 15;
		}

		// Exclude: instant 0.
		if ( $is_excluded ) {
			$score = 0;
		}

		// Priority: boost.
		if ( $is_priority || $priority_result['is_priority'] ) {
			$score = min( 100, $score + 30 );
		}

		return max( 0, min( 100, $score ) );
	}

	/**
	 * Recommend an action based on classification.
	 *
	 * @param string $hygiene_class Classification.
	 * @param int    $hygiene_score Hygiene score.
	 * @param bool   $is_excluded   Whether excluded.
	 * @return string
	 */
	private function recommend_action( $hygiene_class, $hygiene_score, $is_excluded ) {
		if ( $is_excluded ) {
			return 'skip';
		}

		switch ( $hygiene_class ) {
			case 'spam':
				return 'skip_and_flag';
			case 'promotional':
			case 'social_noise':
				return $hygiene_score <= 30 ? 'skip' : 'low_priority';
			case 'notification':
				return 'skip';
			case 'priority':
				return 'fast_track';
			case 'excluded':
				return 'skip';
			default:
				return 'normal';
		}
	}
}
