<?php
/**
 * CRM Pre-Send Gate
 *
 * Deterministic, non-LLM checks that run at send time on every outbound
 * channel. The gate is the single choke point between "a draft exists" and
 * "a prospect receives it": merge tags, banned phrases, length, link budget,
 * HTML/pixels, recipient mismatch, suppression and evidence are all checked
 * here in plain PHP.
 *
 * Prompt instructions are a floor, not a guarantee. Verification belongs in
 * code.
 *
 * @package WP_MCP_AI_Pro
 * @since   3.3.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pre-send gate.
 *
 * @since 3.3.0
 */
class WP_MCP_AI_CRM_Pre_Send_Gate {

	/**
	 * Settings option key.
	 *
	 * @var string
	 */
	const OPTION = 'wp_mcp_ai_crm_gate_settings';

	/**
	 * Stable machine reason codes.
	 *
	 * @var string
	 */
	const REASON_MISSING_FIELD   = 'missing_field';
	const REASON_UNKNOWN_CHANNEL = 'unknown_channel';
	const REASON_RECIPIENT       = 'recipient_invalid';
	const REASON_RECIPIENT_MATCH = 'recipient_mismatch';
	const REASON_NOT_DELIVERABLE = 'address_not_deliverable';
	const REASON_DNC             = 'do_not_contact';
	const REASON_SUBJECT_EMPTY   = 'subject_empty';
	const REASON_SUBJECT_LENGTH  = 'subject_too_long';
	const REASON_BODY_EMPTY      = 'body_empty';
	const REASON_BODY_LENGTH     = 'body_too_long';
	const REASON_CHANNEL_LENGTH  = 'channel_message_too_long';
	const REASON_MERGE_TAG       = 'merge_tag_unrendered';
	const REASON_BANNED_PHRASE   = 'banned_phrase';
	const REASON_LINK_BUDGET     = 'too_many_links';
	const REASON_HTML_BODY       = 'html_body';
	const REASON_TRACKING_PIXEL  = 'tracking_pixel';
	const REASON_POSTAL          = 'postal_address_missing';
	const REASON_EVIDENCE        = 'evidence_missing';

	/**
	 * Channels that carry an email address.
	 *
	 * @var string[]
	 */
	const EMAIL_CHANNELS = array( 'email' );

	/**
	 * Channels the gate understands.
	 *
	 * @var string[]
	 */
	const CHANNELS = array( 'email', 'sms', 'whatsapp', 'linkedin_dm', 'instagram_dm', 'phone_call' );

	/**
	 * Cached settings.
	 *
	 * @var array|null
	 */
	private static $settings_cache = null;

	/**
	 * Default gate settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'enabled'                => true,
			'max_subject_chars'      => 90,
			'max_body_words'         => 220,
			'soft_body_words'        => 125,
			'first_touch_words'      => 75,
			'max_links'              => 1,
			'first_touch_links'      => 0,
			'max_sms_chars'          => 1600,
			'require_plain_text'     => false,
			'require_postal_address' => false,
			'require_evidence'       => false,
			'require_verified'       => false,
			'allow_risky'            => false,
			'banned_phrases'         => array(
				'act now',
				'buy now',
				'click here',
				'click below',
				'limited time',
				'limited offer',
				'exclusive deal',
				'special offer',
				'risk-free',
				'no obligation',
				'100% free',
				'make money',
				'guaranteed results',
				'congratulations you',
				'i hope this finds you well',
				'hope this email finds you well',
				'picture this',
				'what if i told you',
				"in today's fast-paced world",
				'game-changer',
				'game changer',
				'cutting-edge solution',
				'revolutionize your',
				'unlock your',
				'supercharge your',
				'as an ai',
				"i'm an ai",
				'i am an ai',
				'as a language model',
			),
			'merge_tokens'           => array(
				'first_name',
				'last_name',
				'full_name',
				'company',
				'company_name',
				'job_title',
				'city',
				'country',
				'booking_url',
				'unsubscribe_url',
				'sender_name',
				'sender_company',
				'sender_email',
				'signature',
			),
			'allowed_link_domains'   => array(),
		);
	}

	/**
	 * Resolved gate settings.
	 *
	 * @return array
	 */
	public static function get_settings() {
		if ( null !== self::$settings_cache ) {
			return self::$settings_cache;
		}

		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$settings = wp_parse_args( $stored, self::defaults() );

		// Normalise list values that may arrive as newline-separated strings.
		foreach ( array( 'banned_phrases', 'merge_tokens', 'allowed_link_domains' ) as $key ) {
			if ( is_string( $settings[ $key ] ) ) {
				$settings[ $key ] = array_values(
					array_filter(
						array_map( 'trim', preg_split( '/[\r\n,]+/', $settings[ $key ] ) )
					)
				);
			}
			if ( ! is_array( $settings[ $key ] ) ) {
				$settings[ $key ] = self::defaults()[ $key ];
			}
		}

		/**
		 * Filter the resolved pre-send gate settings.
		 *
		 * @param array $settings Gate settings.
		 */
		$settings = apply_filters( 'wp_mcp_ai_crm_gate_settings', $settings );

		self::$settings_cache = $settings;
		return $settings;
	}

	/**
	 * Whether the gate is enabled.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		$settings = self::get_settings();

		/**
		 * Filter whether the pre-send gate runs at all.
		 *
		 * @param bool  $enabled  Whether the gate is enabled.
		 * @param array $settings Gate settings.
		 */
		return (bool) apply_filters( 'wp_mcp_ai_crm_gate_enabled', ! empty( $settings['enabled'] ), $settings );
	}

	/**
	 * Clear the settings cache (tests and settings saves).
	 *
	 * @return void
	 */
	public static function flush_cache() {
		self::$settings_cache = null;
	}

	/**
	 * Check an outbound payload.
	 *
	 * @param array $payload {
	 *     Outbound payload.
	 *
	 *     @type int    $lead_id Lead/contact post ID.
	 *     @type string $channel Channel slug.
	 *     @type string $kind    sequence|one_off|reply|transactional|marketing.
	 *     @type string $to      Recipient address or number.
	 *     @type string $subject Subject (email).
	 *     @type string $body    Rendered body.
	 *     @type int    $step    Sequence step index (0-based).
	 *     @type array  $context Optional extra context.
	 * }
	 * @return bool|WP_Error True on pass, WP_Error on block.
	 */
	public static function check( array $payload ) {
		if ( ! self::is_enabled() ) {
			return true;
		}

		$result = self::evaluate( $payload );
		if ( empty( $result['reasons'] ) ) {
			return true;
		}

		$message = __( 'This message did not pass the pre-send gate.', 'mcp-ai-wpoos-pro' );
		return new WP_Error(
			'wp_mcp_ai_crm_gate_blocked',
			$message,
			array(
				'reasons' => $result['reasons'],
				'codes'   => wp_list_pluck( $result['reasons'], 'code' ),
			)
		);
	}

	/**
	 * Dry-run the gate. Never writes and never sends.
	 *
	 * @param array $payload Same shape as check().
	 * @return array {
	 *     @type bool  $ok           Whether the payload passes.
	 *     @type array $reasons      List of { code, detail }.
	 *     @type array $normalized   Normalised payload used by the checks.
	 *     @type array $warnings     Non-blocking observations.
	 * }
	 */
	public static function preview( array $payload ) {
		$result = self::evaluate( $payload );
		return array(
			'ok'         => empty( $result['reasons'] ),
			'reasons'    => $result['reasons'],
			'normalized' => $result['normalized'],
			'warnings'   => $result['warnings'],
		);
	}

	/**
	 * Evaluate every rule.
	 *
	 * @param array $payload Payload.
	 * @return array
	 */
	private static function evaluate( array $payload ) {
		$settings = self::get_settings();
		$lead_id  = isset( $payload['lead_id'] ) ? absint( $payload['lead_id'] ) : 0;
		$channel  = isset( $payload['channel'] ) ? sanitize_key( $payload['channel'] ) : '';
		$kind     = isset( $payload['kind'] ) ? sanitize_key( $payload['kind'] ) : 'one_off';
		$to       = isset( $payload['to'] ) ? trim( (string) $payload['to'] ) : '';
		$subject  = isset( $payload['subject'] ) ? trim( (string) $payload['subject'] ) : '';
		$body     = isset( $payload['body'] ) ? trim( (string) $payload['body'] ) : '';
		$step     = isset( $payload['step'] ) ? absint( $payload['step'] ) : 0;
		$context  = isset( $payload['context'] ) && is_array( $payload['context'] ) ? $payload['context'] : array();

		$channel  = self::normalize_channel( $channel );
		$reasons  = array();
		$warnings = array();

		$normalized = array(
			'lead_id' => $lead_id,
			'channel' => $channel,
			'kind'    => $kind,
			'to'      => $to,
			'subject' => $subject,
			'body'    => $body,
			'step'    => $step,
			'context' => $context,
		);

		$add = function ( $code, $detail ) use ( &$reasons ) {
			$reasons[] = array(
				'code'   => $code,
				'detail' => (string) $detail,
			);
		};

		// 1. Required fields.
		if ( ! $lead_id ) {
			$add( self::REASON_MISSING_FIELD, __( 'Missing lead_id.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( '' === $to && ( '' === $channel || in_array( $channel, array( 'email', 'sms', 'whatsapp', 'phone_call' ), true ) ) ) {
			$add( self::REASON_MISSING_FIELD, __( 'Missing recipient.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( '' === $channel ) {
			$add( self::REASON_MISSING_FIELD, __( 'Missing channel.', 'mcp-ai-wpoos-pro' ) );
		}

		// 2. Channel.
		if ( '' !== $channel && ! in_array( $channel, self::CHANNELS, true ) ) {
			$add( self::REASON_UNKNOWN_CHANNEL, sprintf( /* translators: %s: channel slug */ __( 'Unknown channel "%s".', 'mcp-ai-wpoos-pro' ), $channel ) );
		}

		// 3. Recipient syntax.
		if ( '' !== $to && 'email' === $channel && ! is_email( $to ) ) {
			$add( self::REASON_RECIPIENT, __( 'Recipient is not a valid email address.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( '' !== $to && in_array( $channel, array( 'sms', 'whatsapp', 'phone_call' ), true ) && ! preg_match( '/^\+?[0-9][0-9\s().-]{5,}$/', $to ) ) {
			$add( self::REASON_RECIPIENT, __( 'Recipient is not a valid phone number.', 'mcp-ai-wpoos-pro' ) );
		}

		// 4. Recipient ↔ record match (email only; a mismatch is how a
		// template bug sends Jane's email to John).
		if ( 'email' === $channel && $lead_id && '' !== $to ) {
			$stored = get_post_meta( $lead_id, 'email', true );
			if ( $stored && strtolower( trim( (string) $stored ) ) !== strtolower( $to ) ) {
				$add( self::REASON_RECIPIENT_MATCH, __( 'Recipient does not match the lead record.', 'mcp-ai-wpoos-pro' ) );
			}
		}

		// 5. Deliverability state (only when the operator has opted in to
		// requiring it; the status is still surfaced as a warning).
		if ( 'email' === $channel && $lead_id && class_exists( 'WP_MCP_AI_CRM_Deliverability' ) ) {
			$status = WP_MCP_AI_CRM_Deliverability::get_status( $lead_id );
			$state  = isset( $status['status'] ) ? $status['status'] : 'unknown';
			if ( ! empty( $settings['require_verified'] ) && 'sequence' === $kind ) {
				$allowed = ! empty( $settings['allow_risky'] ) ? array( 'verified', 'risky' ) : array( 'verified' );
				if ( ! in_array( $state, $allowed, true ) ) {
					$add(
						self::REASON_NOT_DELIVERABLE,
						sprintf(
							/* translators: %s: address status */
							__( 'Address status "%s" is not deliverable.', 'mcp-ai-wpoos-pro' ),
							$state
						)
					);
				}
			} elseif ( ! in_array( $state, array( 'verified', 'risky' ), true ) ) {
				$warnings[] = array(
					'code'   => self::REASON_NOT_DELIVERABLE,
					'detail' => sprintf(
						/* translators: %s: address status */
						__( 'Address status is "%s" (not verified).', 'mcp-ai-wpoos-pro' ),
						$state
					),
				);
			}
		}

		// 6. DNC / suppression.
		if ( '' !== $to && 'email' === $channel && class_exists( 'WP_MCP_AI_CRM_Engine' ) && WP_MCP_AI_CRM_Engine::check_dnc( $to, 'email' ) ) {
			$add( self::REASON_DNC, __( 'Recipient is on the Do Not Contact list.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( '' !== $to && in_array( $channel, array( 'sms', 'whatsapp', 'phone_call' ), true ) && class_exists( 'WP_MCP_AI_CRM_Engine' ) && WP_MCP_AI_CRM_Engine::check_dnc( $to, $channel ) ) {
			$add( self::REASON_DNC, __( 'Recipient is on the Do Not Contact list.', 'mcp-ai-wpoos-pro' ) );
		}

		// 7–8. Email content.
		if ( 'email' === $channel ) {
			if ( '' === $subject ) {
				$add( self::REASON_SUBJECT_EMPTY, __( 'Subject is empty.', 'mcp-ai-wpoos-pro' ) );
			}
			if ( mb_strlen( $subject ) > (int) $settings['max_subject_chars'] ) {
				$add(
					self::REASON_SUBJECT_LENGTH,
					sprintf(
						/* translators: 1: actual length, 2: maximum length */
						__( 'Subject is too long (%1$d > %2$d characters).', 'mcp-ai-wpoos-pro' ),
						mb_strlen( $subject ),
						(int) $settings['max_subject_chars']
					)
				);
			}
			if ( '' === $body ) {
				$add( self::REASON_BODY_EMPTY, __( 'Body is empty.', 'mcp-ai-wpoos-pro' ) );
			}

			$word_count = self::word_count( $body );
			if ( $word_count > (int) $settings['max_body_words'] ) {
				$add(
					self::REASON_BODY_LENGTH,
					sprintf(
						/* translators: 1: word count, 2: maximum words */
						__( 'Body is too long (%1$d > %2$d words).', 'mcp-ai-wpoos-pro' ),
						$word_count,
						(int) $settings['max_body_words']
					)
				);
			} elseif ( $word_count > (int) $settings['soft_body_words'] ) {
				$warnings[] = array(
					'code'   => self::REASON_BODY_LENGTH,
					'detail' => sprintf(
						/* translators: 1: word count, 2: soft limit */
						__( 'Body is longer than the soft limit (%1$d > %2$d words).', 'mcp-ai-wpoos-pro' ),
						$word_count,
						(int) $settings['soft_body_words']
					),
				);
			}

			$first_touch = ( 0 === $step && 'sequence' === $kind );
			if ( $first_touch && $word_count > (int) $settings['first_touch_words'] ) {
				$warnings[] = array(
					'code'   => self::REASON_BODY_LENGTH,
					'detail' => sprintf(
						/* translators: 1: word count, 2: first-touch target */
						__( 'First touch is longer than the target (%1$d > %2$d words).', 'mcp-ai-wpoos-pro' ),
						$word_count,
						(int) $settings['first_touch_words']
					),
				);
			}
		}

		// 9. SMS / WhatsApp length.
		if ( in_array( $channel, array( 'sms', 'whatsapp' ), true ) && '' !== $body ) {
			$length = mb_strlen( $body );
			if ( $length > (int) $settings['max_sms_chars'] ) {
				$add(
					self::REASON_CHANNEL_LENGTH,
					sprintf(
						/* translators: 1: actual length, 2: maximum length */
						__( 'Message is too long (%1$d > %2$d characters).', 'mcp-ai-wpoos-pro' ),
						$length,
						(int) $settings['max_sms_chars']
					)
				);
			}
		}

		// 10. Unrendered merge tags.
		$merge_hits = self::find_merge_tags( $subject . "\n" . $body, $settings['merge_tokens'] );
		if ( ! empty( $merge_hits ) ) {
			$add(
				self::REASON_MERGE_TAG,
				sprintf(
					/* translators: %s: comma-separated token list */
					__( 'Unrendered merge tags: %s', 'mcp-ai-wpoos-pro' ),
					implode( ', ', array_slice( array_unique( $merge_hits ), 0, 5 ) )
				)
			);
		}

		// 11. Banned phrases.
		if ( ! empty( $settings['banned_phrases'] ) ) {
			$text = self::normalize_text( $subject . "\n" . $body );
			$hits = array();
			foreach ( $settings['banned_phrases'] as $phrase ) {
				$phrase = self::normalize_text( (string) $phrase );
				if ( '' === $phrase ) {
					continue;
				}
				if ( false !== strpos( $text, $phrase ) ) {
					$hits[] = $phrase;
				}
			}
			if ( ! empty( $hits ) ) {
				$add(
					self::REASON_BANNED_PHRASE,
					sprintf(
						/* translators: %s: comma-separated phrase list */
						__( 'Banned phrases: %s', 'mcp-ai-wpoos-pro' ),
						implode( ', ', array_slice( array_unique( $hits ), 0, 5 ) )
					)
				);
			}
		}

		// 12. Link budget.
		$link_count = self::count_links( $body );
		$max_links  = (int) $settings['max_links'];
		if ( 0 === $step && 'sequence' === $kind ) {
			$max_links = (int) $settings['first_touch_links'];
		}
		if ( isset( $context['link_budget'] ) && '' !== $context['link_budget'] ) {
			$max_links = max( 0, (int) $context['link_budget'] );
		}
		if ( $link_count > $max_links ) {
			$add(
				self::REASON_LINK_BUDGET,
				sprintf(
					/* translators: 1: actual link count, 2: maximum links */
					__( 'Too many links (%1$d > %2$d).', 'mcp-ai-wpoos-pro' ),
					$link_count,
					$max_links
				)
			);
		}

		// 13. HTML / tracking pixels.
		if ( self::has_tracking_pixel( $body ) ) {
			$add( self::REASON_TRACKING_PIXEL, __( 'Body contains a tracking pixel.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( ! empty( $settings['require_plain_text'] ) && self::has_html_markup( $body ) ) {
			$add( self::REASON_HTML_BODY, __( 'Body contains HTML markup; plain text is required.', 'mcp-ai-wpoos-pro' ) );
		}

		// 14. Postal address (cold email only).
		if ( ! empty( $settings['require_postal_address'] ) && 'email' === $channel && in_array( $kind, array( 'sequence', 'marketing' ), true ) ) {
			$settings_all = class_exists( 'WP_MCP_AI_CRM_Engine' ) ? WP_MCP_AI_CRM_Engine::get_toolkit_settings() : array();
			$postal       = isset( $settings_all['consent']['physical_address'] ) ? trim( (string) $settings_all['consent']['physical_address'] ) : '';
			if ( '' === $postal || false === strpos( $body, $postal ) ) {
				$add( self::REASON_POSTAL, __( 'Physical postal address is missing from the cold email.', 'mcp-ai-wpoos-pro' ) );
			}
		}

		// 15. Evidence for AI-written personalisation.
		if ( ! empty( $settings['require_evidence'] ) && 'email' === $channel && 'sequence' === $kind && $lead_id ) {
			$evidence = get_post_meta( $lead_id, '_crm_personalization_evidence', true );
			if ( empty( $evidence ) ) {
				$add( self::REASON_EVIDENCE, __( 'No personalisation evidence is recorded for this lead.', 'mcp-ai-wpoos-pro' ) );
			}
		}

		/**
		 * Filter the gate result before it is returned.
		 *
		 * @param array $result    { reasons, warnings, normalized }.
		 * @param array $payload   Original payload.
		 * @param array $settings  Gate settings.
		 */
		$result = apply_filters(
			'wp_mcp_ai_crm_gate_result',
			array(
				'reasons'    => $reasons,
				'warnings'   => $warnings,
				'normalized' => $normalized,
			),
			$payload,
			$settings
		);

		return wp_parse_args(
			is_array( $result ) ? $result : array(),
			array(
				'reasons'    => array(),
				'warnings'   => array(),
				'normalized' => $normalized,
			)
		);
	}

	/**
	 * Normalise a channel slug.
	 *
	 * @param string $channel Raw channel.
	 * @return string
	 */
	private static function normalize_channel( $channel ) {
		$channel = sanitize_key( (string) $channel );
		$map     = array(
			'linkedin'  => 'linkedin_dm',
			'instagram' => 'instagram_dm',
			'dm'        => 'linkedin_dm',
			'phone'     => 'phone_call',
		);
		return isset( $map[ $channel ] ) ? $map[ $channel ] : $channel;
	}

	/**
	 * Count words in a body, ignoring HTML tags.
	 *
	 * @param string $body Body.
	 * @return int
	 */
	private static function word_count( $body ) {
		$text = wp_strip_all_tags( (string) $body );
		$text = preg_replace( '/https?:\/\/\S+/i', ' ', $text );
		$text = preg_replace( '/\s+/', ' ', $text );
		$text = trim( $text );
		if ( '' === $text ) {
			return 0;
		}
		return count( preg_split( '/\s+/', $text ) );
	}

	/**
	 * Find unrendered merge tags.
	 *
	 * `{{anything}}` is always a leftover. `{token}` is only flagged when the
	 * token is a known merge token, so code samples and prose braces pass.
	 *
	 * @param string $text   Text to scan.
	 * @param array  $tokens Known tokens.
	 * @return string[]
	 */
	private static function find_merge_tags( $text, array $tokens ) {
		$hits = array();

		if ( preg_match_all( '/\{\{\s*([a-z0-9_]+)\s*\}\}/i', $text, $m ) ) {
			$hits = array_merge( $hits, $m[1] );
		}

		if ( preg_match_all( '/(?<!\{)\{\s*([a-z0-9_]+)\s*\}(?!\})/i', $text, $m ) ) {
			$lower_tokens = array_map( 'strtolower', $tokens );
			foreach ( $m[1] as $token ) {
				if ( in_array( strtolower( $token ), $lower_tokens, true ) ) {
					$hits[] = $token;
				}
			}
		}

		return array_values( array_unique( $hits ) );
	}

	/**
	 * Normalise text for phrase matching.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function normalize_text( $text ) {
		$text = wp_strip_all_tags( (string) $text );
		$text = strtolower( $text );
		$text = preg_replace( '/[\x{2018}\x{2019}]/u', "'", $text );
		$text = preg_replace( '/[\x{201C}\x{201D}]/u', '"', $text );
		$text = preg_replace( '/\s+/', ' ', $text );
		return trim( $text );
	}

	/**
	 * Count links in a body.
	 *
	 * @param string $body Body.
	 * @return int
	 */
	private static function count_links( $body ) {
		$body = (string) $body;
		$urls = preg_match_all( '/\bhttps?:\/\/[^\s<>"\']+/i', $body );
		$www  = preg_match_all( '/\bwww\.[^\s<>"\']+/i', $body );
		return (int) $urls + (int) $www;
	}

	/**
	 * Detect a tracking pixel.
	 *
	 * @param string $body Body.
	 * @return bool
	 */
	private static function has_tracking_pixel( $body ) {
		return (bool) preg_match( '/<img\b[^>]*(?:width\s*=\s*["\']?1\b|height\s*=\s*["\']?1\b)[^>]*>/i', (string) $body );
	}

	/**
	 * Detect meaningful HTML markup.
	 *
	 * @param string $body Body.
	 * @return bool
	 */
	private static function has_html_markup( $body ) {
		return (bool) preg_match( '/<\s*(?:html|body|div|table|img|a|p|br|span|strong|em|h[1-6]|ul|ol|li)\b/i', (string) $body );
	}
}
