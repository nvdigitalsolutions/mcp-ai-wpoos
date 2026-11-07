<?php
/**
 * CRM Opt-Out Detector
 *
 * Rule-based detection of opt-out wording in replies, in English, Spanish
 * and Hebrew. Rules run before the model classifier: an opt-out must be
 * immediate and permanent even when no model is available.
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
 * Multilingual opt-out detection.
 *
 * @since 3.3.0
 */
class WP_MCP_AI_CRM_Opt_Out_Detector {

	/**
	 * Register the inbound hook.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_mcp_ai_crm_message_logged', array( __CLASS__, 'on_message' ), 5, 2 );
	}

	/**
	 * Rule packs.
	 *
	 * @return array
	 */
	public static function rules() {
		$rules = array(
			'en' => array(
				'stop',
				'no thanks',
				'no thank you',
				'unsubscribe',
				'remove me',
				'opt out',
				'opt-out',
				"don't email",
				'do not email',
				'not interested',
				'take me off',
				'remove my email',
			),
			'es' => array(
				'baja',
				'no gracias',
				'no me escriba',
				'no me escribas',
				'dejen de enviarme',
				'deje de enviarme',
				'saquenme',
				'sáquenme',
				'no me interesa',
				'remuevame',
				'remuévame',
			),
			'he' => array(
				'הסר',
				'תורידו אותי',
				'תורידי אותי',
				'תפסיקו לשלוח',
				'תפסיק לשלוח',
				'אל תכתבו לי',
				'אל תכתוב לי',
				'לא מעוניין',
				'לא מעוניינת',
			),
		);

		/**
		 * Filter the opt-out rule packs.
		 *
		 * @param array $rules Language => phrases.
		 */
		return apply_filters( 'wp_mcp_ai_crm_opt_out_rules', $rules );
	}

	/**
	 * Legal-threat phrases (adjacent to an opt-out).
	 *
	 * @return array
	 */
	public static function legal_phrases() {
		$phrases = array(
			'attorney',
			'lawyer',
			'lawsuit',
			'sue you',
			'legal action',
			'file a complaint',
			'spam complaint',
			'ftc',
			'gdpr complaint',
			'עורכת הדין',
			'עורך הדין',
			'אתבע',
			'תלונה',
			'חוק הספאם',
		);

		/**
		 * Filter legal-threat phrases.
		 *
		 * @param array $phrases Phrases.
		 */
		return apply_filters( 'wp_mcp_ai_crm_opt_out_legal_phrases', $phrases );
	}

	/**
	 * Phrases that look like opt-outs but are not.
	 *
	 * @return array
	 */
	public static function exclusions() {
		$exclusions = array(
			'como no gracias',
			'no me interesa el plan anual',
			'dar de baja a nuestro proveedor',
			'no thanks to',
		);

		/**
		 * Filter opt-out exclusions.
		 *
		 * @param array $exclusions Phrases that suppress a match.
		 */
		return apply_filters( 'wp_mcp_ai_crm_opt_out_exclusions', $exclusions );
	}

	/**
	 * Detect an opt-out in a reply.
	 *
	 * @param string $text    Reply text.
	 * @param array  $context { prior_body: string }.
	 * @return array{matched:bool,phrase:string,rule:string,lang:string,legal_threat:bool}
	 */
	public static function detect( $text, array $context = array() ) {
		$clean      = self::strip_quoted( (string) $text, isset( $context['prior_body'] ) ? (string) $context['prior_body'] : '' );
		$normalized = self::normalize( $clean );

		$result = array(
			'matched'      => false,
			'phrase'       => '',
			'rule'         => '',
			'lang'         => '',
			'legal_threat' => false,
		);

		if ( '' === $normalized ) {
			return $result;
		}

		foreach ( self::exclusions() as $exclusion ) {
			if ( '' !== $exclusion && self::contains_phrase( $normalized, self::normalize( $exclusion ) ) ) {
				return $result;
			}
		}

		foreach ( self::rules() as $lang => $phrases ) {
			foreach ( (array) $phrases as $phrase ) {
				$phrase = self::normalize( $phrase );
				if ( '' === $phrase ) {
					continue;
				}
				if ( self::contains_phrase( $normalized, $phrase ) ) {
					$result['matched'] = true;
					$result['phrase']  = $phrase;
					$result['rule']    = 'opt_out_' . $lang;
					$result['lang']    = $lang;
					break 2;
				}
			}
		}

		if ( ! $result['matched'] ) {
			return $result;
		}

		foreach ( self::legal_phrases() as $phrase ) {
			if ( self::contains_phrase( $normalized, self::normalize( $phrase ) ) ) {
				$result['legal_threat'] = true;
				break;
			}
		}

		return $result;
	}

	/**
	 * Handle an opt-out for a lead.
	 *
	 * @param int    $lead_id Lead post ID.
	 * @param string $text    Reply text.
	 * @param array  $context Context.
	 * @return array
	 */
	public static function handle( $lead_id, $text, array $context = array() ) {
		$lead_id = absint( $lead_id );
		$result  = self::detect( $text, $context );
		if ( ! $lead_id || empty( $result['matched'] ) ) {
			return $result;
		}

		if ( class_exists( 'WP_MCP_AI_CRM_Consent' ) ) {
			WP_MCP_AI_CRM_Consent::revoke( $lead_id, 'all' );
		}

		update_post_meta( $lead_id, '_sequence_paused', '1' );
		update_post_meta( $lead_id, '_crm_opt_out_at', gmdate( 'c' ) );
		update_post_meta( $lead_id, '_crm_opt_out_phrase', sanitize_text_field( $result['phrase'] ) );
		update_post_meta( $lead_id, '_crm_opt_out_rule', sanitize_key( $result['rule'] ) );

		if ( class_exists( 'WP_MCP_AI_CRM_Audit' ) ) {
			WP_MCP_AI_CRM_Audit::record(
				'opt_out_detected',
				'lead',
				$lead_id,
				array(
					'phrase'       => $result['phrase'],
					'rule'         => $result['rule'],
					'lang'         => $result['lang'],
					'legal_threat' => $result['legal_threat'] ? '1' : '0',
				)
			);
		}

		if ( $result['legal_threat'] ) {
			/**
			 * Fires when an opt-out also contains a legal threat.
			 *
			 * @param int   $lead_id Lead post ID.
			 * @param array $result  Detection result.
			 */
			do_action( 'wp_mcp_ai_crm_opt_out_escalated', $lead_id, $result );
		}

		return $result;
	}

	/**
	 * Handle a logged inbound message.
	 *
	 * @param int   $post_id Message post ID.
	 * @param array $args    Message args.
	 * @return void
	 */
	public static function on_message( $post_id, $args ) {
		if ( ! is_array( $args ) || empty( $args['body'] ) ) {
			return;
		}

		$lead_id = absint( $args['contact_id'] ?? 0 );
		if ( ! $lead_id && ! empty( $args['sender_email'] ) && class_exists( 'WP_MCP_AI_CRM_Identity' ) ) {
			$lead_id = WP_MCP_AI_CRM_Identity::find_lead_by_email( $args['sender_email'] );
		}
		if ( ! $lead_id ) {
			return;
		}

		self::handle(
			$lead_id,
			(string) $args['body'],
			array( 'message_post_id' => absint( $post_id ) )
		);
	}

	/**
	 * Strip quoted history from a reply.
	 *
	 * @param string $text       Reply.
	 * @param string $prior_body Prior outbound body, when known.
	 * @return string
	 */
	private static function strip_quoted( $text, $prior_body = '' ) {
		$text = str_replace( "\r\n", "\n", (string) $text );

		if ( '' !== $prior_body ) {
			$text = str_replace( $prior_body, ' ', $text );
		}

		$lines = explode( "\n", $text );
		$out   = array();
		foreach ( $lines as $line ) {
			$trimmed = ltrim( $line );
			if ( 0 === strpos( $trimmed, '>' ) ) {
				continue;
			}
			if ( preg_match( '/^-{2,}\s*(original message|forwarded message)/i', $trimmed ) ) {
				break;
			}
			if ( preg_match( '/^(on .+ wrote:|from:\s|sent:\s|to:\s|subject:\s)/i', $trimmed ) ) {
				break;
			}
			if ( preg_match( '/^(sent from my|get outlook for)/i', $trimmed ) ) {
				continue;
			}
			$out[] = $line;
		}

		return implode( "\n", $out );
	}

	/**
	 * Normalise text for matching.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function normalize( $text ) {
		$text = wp_strip_all_tags( (string) $text );
		$text = remove_accents( $text );
		$text = mb_strtolower( $text, 'UTF-8' );
		$text = preg_replace( '/[\x{2018}\x{2019}]/u', "'", $text );
		$text = preg_replace( '/[\x{201C}\x{201D}]/u', '"', $text );
		$text = preg_replace( '/[^\p{L}\p{N}\' ]+/u', ' ', $text );
		$text = preg_replace( '/\s+/u', ' ', $text );
		return trim( (string) $text );
	}

	/**
	 * Whole-word / whole-phrase match.
	 *
	 * @param string $haystack Normalised haystack.
	 * @param string $needle   Normalised needle.
	 * @return bool
	 */
	private static function contains_phrase( $haystack, $needle ) {
		$pattern = '/(?<![\p{L}\p{N}])' . preg_quote( $needle, '/' ) . '(?![\p{L}\p{N}])/u';
		return (bool) preg_match( $pattern, $haystack );
	}
}
